<?php
/**
 * The plugin run state.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * What the plugin has already notified about and when it last checked.
 *
 * @internal
 * @phpstan-type Generic_Notified array<string, array<string, string>>
 * @phpstan-type Legacy_Notified array{core: string, plugin: array<string, string>, theme: array<string, string>}
 * @phpstan-type Notified_Meta array<string, array<string, array{name: string, current_version: string, links: array<string, string>, time: int, snoozed?: bool}>>
 * @phpstan-type Last_Result array{success: bool, message: string, time: int}
 * @phpstan-type Channel_State array{notified: Generic_Notified, skip_pending: bool, notified_meta?: Notified_Meta, last_result?: Last_Result, retry_at?: int, held_until?: int, last_reminder?: int}
 * @phpstan-type StateData array{channels: array<string, Channel_State>, last_check_time: int|false, slack_notice_sent: bool, legacy_notified?: Legacy_Notified, ended_mutes?: Generic_Notified, ended_snoozes?: Generic_Notified}
 */
class State {
	const OPT_FIELD = 'sc_wpun_state';

	/**
	 * The longest, in seconds, that a failed delivery's Retry-After can defer an instance.
	 */
	const MAX_RETRY_AFTER = 604800; // 7 days.

	/**
	 * Where the options are stored.
	 *
	 * @var Network_Context
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param Network_Context|null $context Where the options are stored; defaults to the current site setup.
	 */
	public function __construct( ?Network_Context $context = null ) {
		$this->context = $context ?? new Network_Context();
	}

	/**
	 * What the plugin has already notified about and when it last checked.
	 *
	 * Kept apart from the settings, in an option that is not autoloaded, so a run
	 * never overwrites settings saved meanwhile and the settings form never sees it.
	 *
	 * @return StateData
	 */
	public function get(): array {
		/** This filter is documented in includes/class-settings.php */
		return $this->normalise( apply_filters( 'sc_wpun_get_options_filter', $this->context->get_option( self::OPT_FIELD ), self::OPT_FIELD ) );
	}

	/**
	 * Store the run state, creating the option without autoload if it is missing.
	 *
	 * @param array<string, mixed> $state Run state.
	 *
	 * @return void
	 */
	public function save( array $state ): void {
		/** This filter is documented in includes/class-settings.php */
		$state = apply_filters( 'sc_wpun_put_options_filter', $state, self::OPT_FIELD );

		if ( false === $this->context->get_option( self::OPT_FIELD ) ) {
			$this->context->add_option( self::OPT_FIELD, $state, false );
		} else {
			$this->context->update_option( self::OPT_FIELD, $state );
		}
	}

	/**
	 * Give the run state its expected shape and types.
	 *
	 * What was notified is kept per channel instance, by item type and then item key
	 * (`core`/`core`, `plugin`/<plugin file>, `theme`/<stylesheet>, or any type an add-on
	 * reports). State from before 2.0.0 has one global `notified` list; it is kept as
	 * `legacy_notified` until the next update check hands it to the stored instances.
	 *
	 * @param mixed $state Stored state.
	 *
	 * @return StateData
	 */
	public function normalise( $state ): array {
		$state    = is_array( $state ) ? $state : array();
		$channels = array();

		foreach ( isset( $state['channels'] ) && is_array( $state['channels'] ) ? $state['channels'] : array() as $id => $entry ) {
			$entry = is_array( $entry ) ? $entry : array();
			$clean = array(
				'notified'     => $this->normalise_generic( $entry['notified'] ?? array() ),
				'skip_pending' => ! empty( $entry['skip_pending'] ),
			);
			$meta  = $this->normalise_meta( $entry['notified_meta'] ?? null );
			if ( array() !== $meta ) {
				$clean['notified_meta'] = $meta;
			}
			$last_result = $this->normalise_last_result( $entry['last_result'] ?? null );
			if ( null !== $last_result ) {
				$clean['last_result'] = $last_result;
			}
			if ( isset( $entry['retry_at'] ) && is_numeric( $entry['retry_at'] ) && (int) $entry['retry_at'] > 0 ) {
				$clean['retry_at'] = (int) $entry['retry_at'];
			}
			if ( isset( $entry['held_until'] ) && is_numeric( $entry['held_until'] ) && (int) $entry['held_until'] > 0 ) {
				$clean['held_until'] = (int) $entry['held_until'];
			}
			if ( isset( $entry['last_reminder'] ) && is_numeric( $entry['last_reminder'] ) && (int) $entry['last_reminder'] > 0 ) {
				$clean['last_reminder'] = (int) $entry['last_reminder'];
			}
			$channels[ (string) $id ] = $clean;
		}

		$normalised = array(
			'channels'          => $channels,
			'last_check_time'   => ! empty( $state['last_check_time'] ) && is_numeric( $state['last_check_time'] ) ? (int) $state['last_check_time'] : false,
			'slack_notice_sent' => ! empty( $state['slack_notice_sent'] ),
		);

		if ( isset( $state['legacy_notified'] ) && is_array( $state['legacy_notified'] ) ) {
			$normalised['legacy_notified'] = $this->normalise_legacy( $state['legacy_notified'] );
		} elseif ( ! isset( $state['channels'] ) && isset( $state['notified'] ) && is_array( $state['notified'] ) ) {
			$normalised['legacy_notified'] = $this->normalise_legacy( $state['notified'] );
		}

		$ended = $this->normalise_generic( $state['ended_mutes'] ?? array() ); // Item type => key => the version of the mute that ended.
		if ( array() !== $ended ) {
			$normalised['ended_mutes'] = $ended;
		}

		$snoozes = $this->normalise_generic( $state['ended_snoozes'] ?? array() ); // Item type => key => the expiry time of the snooze that ended, as a string.
		if ( array() !== $snoozes ) {
			$normalised['ended_snoozes'] = $snoozes;
		}

		return $normalised;
	}

	/**
	 * Whether an item is new to an instance: its version was not notified, or it was notified before a snooze.
	 *
	 * The update check flags the entry of a snoozed item, so that the version is sent again when the snooze ends.
	 * Sending or recording the item clears the flag.
	 *
	 * @internal
	 *
	 * @param array<string, mixed> $entry The instance's state, `notified` and `notified_meta` are read.
	 * @param Notification_Item    $item  The item.
	 *
	 * @return bool
	 */
	public function is_new( array $entry, Notification_Item $item ): bool {
		$type = $item->get_type();
		$key  = $item->get_key();

		return ( $entry['notified'][ $type ][ $key ] ?? null ) !== $item->get_new_version() || ! empty( $entry['notified_meta'][ $type ][ $key ]['snoozed'] );
	}

	/**
	 * Forget everything notified through a channel instance.
	 *
	 * Used when an instance is deleted, so a new instance with the same id starts clean.
	 *
	 * @param string $id Instance id.
	 *
	 * @return void
	 */
	public function forget_channel( string $id ): void {
		$state = $this->get();
		if ( ! isset( $state['channels'][ $id ] ) ) {
			return;
		}

		unset( $state['channels'][ $id ] );
		$this->save( $state );
	}

	/**
	 * Make the next update check record what is pending for an instance as notified, without sending it.
	 *
	 * For an instance added or re-enabled when the site owner chose not to send the updates that are already waiting.
	 * Its reminder interval starts again at that check.
	 *
	 * @param string $id Instance id.
	 *
	 * @return void
	 */
	public function skip_pending( string $id ): void {
		$state                 = $this->get();
		$entry                 = $state['channels'][ $id ] ?? array();
		$entry['notified']     = array();
		$entry['skip_pending'] = true;
		unset( $entry['notified_meta'] );
		unset( $entry['last_reminder'] ); // The skip run starts the reminder interval afresh, so skipped updates are not reminded about at once.
		$state['channels'][ $id ] = $entry;
		$this->save( $state );
	}

	/**
	 * Remember the outcome of the latest delivery through a channel instance.
	 *
	 * Hooked on `sc_wpun_notification_sent`, `sc_wpun_notification_failed` and `sc_wpun_notification_deferred`. A test send never counts.
	 * A failure with a `retry_after` also stores when the instance may be tried again, `retry_at`, capped at `MAX_RETRY_AFTER`.
	 * A deferred result only stores `held_until` (see hold_until()): the last result and `retry_at` stay as they were.
	 * Any sent or failed result lifts a hold.
	 *
	 * @param mixed $result       The delivery result.
	 * @param mixed $notification The notification.
	 * @param mixed $instance     The channel instance.
	 *
	 * @return void
	 */
	public function record_delivery( $result, $notification, $instance ): void {
		if ( ! $result instanceof Delivery_Result || ! $notification instanceof Notification || ! $instance instanceof Channel_Instance || $notification->is_test() ) {
			return;
		}

		$state = $this->get();
		$id    = $instance->get_id();
		$entry = $state['channels'][ $id ] ?? array(
			'notified'     => array(),
			'skip_pending' => false,
		);

		if ( $result->is_deferred() ) {
			$held = $this->hold_until( $result );
			if ( $held > 0 ) {
				$entry['held_until'] = $held;
			} else {
				unset( $entry['held_until'] );
			}
			$state['channels'][ $id ] = $entry;
			$this->save( $state );

			return;
		}

		unset( $entry['held_until'] );
		$entry['last_result'] = array(
			'success' => $result->is_success(),
			'message' => $result->get_message(),
			'time'    => time(),
		);

		// A service that asked to be left alone defers the instance; any other outcome lifts that.
		$retry_after = $result->is_success() ? null : $result->get_retry_after();
		if ( null !== $retry_after && $retry_after > 0 ) {
			$entry['retry_at'] = time() + min( $retry_after, self::MAX_RETRY_AFTER );
		} else {
			unset( $entry['retry_at'] );
		}

		$state['channels'][ $id ] = $entry;
		$this->save( $state );
	}

	/**
	 * Until when a deferred result holds an instance: its time, at most `MAX_RETRY_AFTER` from now.
	 *
	 * @param Delivery_Result $result The result.
	 *
	 * @return int A Unix time, or 0 for no hold (not deferred, or a time that is not in the future).
	 */
	public function hold_until( Delivery_Result $result ): int {
		$until = $result->get_deferred_until();
		if ( null === $until || $until <= time() ) {
			return 0;
		}

		return min( $until, time() + self::MAX_RETRY_AFTER );
	}

	/**
	 * When an instance may next be delivered to: the later of its Retry-After time and its hold.
	 *
	 * @param array<string, mixed> $entry The instance's stored state.
	 *
	 * @return int A Unix time in the future, or 0 when the instance is not waiting.
	 */
	public function waiting_until( array $entry ): int {
		$until = max( (int) ( $entry['retry_at'] ?? 0 ), (int) ( $entry['held_until'] ?? 0 ) );

		return $until > time() ? $until : 0;
	}

	/**
	 * The details kept with what was notified, by item type and key: name, installed version, links and the time it was notified.
	 *
	 * @param mixed $meta Stored value.
	 *
	 * @return Notified_Meta
	 */
	private function normalise_meta( $meta ): array {
		if ( ! is_array( $meta ) ) {
			return array();
		}

		$clean = array();
		foreach ( $meta as $type => $keys ) {
			foreach ( is_array( $keys ) ? $keys : array() as $key => $details ) {
				if ( ! is_array( $details ) ) {
					continue;
				}
				$entry = array(
					'name'            => isset( $details['name'] ) && is_scalar( $details['name'] ) ? (string) $details['name'] : '',
					'current_version' => isset( $details['current_version'] ) && is_scalar( $details['current_version'] ) ? (string) $details['current_version'] : '',
					'links'           => $this->normalise_versions( $details['links'] ?? array() ),
					'time'            => isset( $details['time'] ) && is_numeric( $details['time'] ) && (int) $details['time'] > 0 ? (int) $details['time'] : 0,
				);
				if ( ! empty( $details['snoozed'] ) ) {
					$entry['snoozed'] = true;
				}
				$clean[ (string) $type ][ (string) $key ] = $entry;
			}
		}

		return $clean;
	}

	/**
	 * A stored last delivery result with its expected types, or null if it is not usable.
	 *
	 * @param mixed $last_result Stored value.
	 *
	 * @return Last_Result|null
	 */
	private function normalise_last_result( $last_result ): ?array {
		if ( ! is_array( $last_result ) || ! isset( $last_result['time'] ) || ! is_numeric( $last_result['time'] ) || (int) $last_result['time'] <= 0 ) {
			return null;
		}

		return array(
			'success' => ! empty( $last_result['success'] ),
			'message' => isset( $last_result['message'] ) && is_string( $last_result['message'] ) ? $last_result['message'] : '',
			'time'    => (int) $last_result['time'],
		);
	}

	/**
	 * The old global list of what was notified.
	 *
	 * @param array<mixed> $notified Stored list.
	 *
	 * @return Legacy_Notified
	 */
	private function normalise_legacy( array $notified ): array {
		return array(
			'core'   => isset( $notified['core'] ) && is_scalar( $notified['core'] ) ? (string) $notified['core'] : '',
			'plugin' => $this->normalise_versions( $notified['plugin'] ?? array() ),
			'theme'  => $this->normalise_versions( $notified['theme'] ?? array() ),
		);
	}

	/**
	 * Notified versions by item type and key, keeping only scalar versions.
	 *
	 * @param mixed $notified Stored list.
	 *
	 * @return Generic_Notified
	 */
	private function normalise_generic( $notified ): array {
		if ( ! is_array( $notified ) ) {
			return array();
		}

		$clean = array();
		foreach ( $notified as $type => $versions ) {
			$versions = $this->normalise_versions( $versions );
			if ( array() !== $versions ) {
				$clean[ (string) $type ] = $versions;
			}
		}

		return $clean;
	}

	/**
	 * Keep only scalar versions, as strings, keyed by plugin file or theme.
	 *
	 * @param mixed $versions Stored versions.
	 *
	 * @return array<string, string>
	 */
	public function normalise_versions( $versions ): array {
		if ( ! is_array( $versions ) ) {
			return array();
		}

		$clean = array();
		foreach ( $versions as $key => $version ) {
			if ( is_scalar( $version ) ) {
				$clean[ (string) $key ] = (string) $version;
			}
		}

		return $clean;
	}
}
