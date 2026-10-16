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
 * @phpstan-type Channel_State array{notified: Generic_Notified, skip_pending: bool}
 * @phpstan-type StateData array{channels: array<string, Channel_State>, last_check_time: int|false, slack_notice_sent: bool, legacy_notified?: Legacy_Notified}
 */
class State {
	const OPT_FIELD = 'sc_wpun_state';

	/**
	 * What the plugin has already notified about and when it last checked.
	 *
	 * Kept apart from the settings, in an option that is not autoloaded, so a run
	 * never overwrites settings saved meanwhile and the settings form never sees it.
	 *
	 * @return StateData
	 */
	public function get(): array {
		return $this->normalise( apply_filters( 'sc_wpun_get_options_filter', get_option( self::OPT_FIELD ), self::OPT_FIELD ) );
	}

	/**
	 * Store the run state, creating the option without autoload if it is missing.
	 *
	 * @param array<string, mixed> $state Run state.
	 *
	 * @return void
	 */
	public function save( array $state ): void {
		$state = apply_filters( 'sc_wpun_put_options_filter', $state, self::OPT_FIELD );

		if ( false === get_option( self::OPT_FIELD ) ) {
			add_option( self::OPT_FIELD, $state, '', false );
		} else {
			update_option( self::OPT_FIELD, $state );
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
			$entry                    = is_array( $entry ) ? $entry : array();
			$channels[ (string) $id ] = array(
				'notified'     => $this->normalise_generic( $entry['notified'] ?? array() ),
				'skip_pending' => ! empty( $entry['skip_pending'] ),
			);
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

		return $normalised;
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
	 *
	 * @param string $id Instance id.
	 *
	 * @return void
	 */
	public function skip_pending( string $id ): void {
		$state                    = $this->get();
		$state['channels'][ $id ] = array(
			'notified'     => array(),
			'skip_pending' => true,
		);
		$this->save( $state );
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
