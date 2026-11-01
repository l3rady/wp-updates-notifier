<?php
/**
 * Storage of the mutes.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use InvalidArgumentException;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Stores which plugins, themes and other items are muted.
 *
 * The methods all() and get() return the effective mutes: the stored ones plus those defined in code
 * (the `sc_wpun_mutes` filter), without a "skip this version" mute that a newer version has ended and
 * without a snooze whose time has passed.
 * A mute defined in code cannot be changed or removed here. The check never writes the stored mutes;
 * an ended or expired mute is left out when read and removed the next time a mute is saved or removed. Part of
 * the extension API.
 *
 * @since 3.0.0
 */
final class Mute_Store {
	const OPT_FIELD = 'sc_wpun_muted';

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
	 * The effective mutes, including ended ones, by "type key".
	 *
	 * @return array<string, Mute>
	 */
	private function effective(): array {
		$stored = $this->stored();
		$list   = array_values( $stored );

		/**
		 * Filters the mutes, to define mutes in code.
		 *
		 * Receives the stored mutes. Add a `WPUN\Mute` to mute an item from code, for example in an
		 * mu-plugin managed by an agency or by Composer. A mute added here applies like a stored one, is
		 * listed with it and is marked as set in code (`Mute::is_from_code()`), and nobody can unmute it
		 * through the store. It replaces a stored mute of the same item. Values that are not a `Mute`, mutes
		 * of the `core` type and mutes of a type that is not registered with `sc_wpun_item_types` are dropped.
		 * When two mutes are for the same item, the later one wins. A stored mute cannot be hidden: one
		 * the returned list leaves out is put back, so use `sc_wpun_should_notify_item` to report an item
		 * that is muted.
		 *
		 * A mute can carry an `expires` time (a Unix timestamp) to end by itself, like a snooze, and a
		 * `source` that says where it came from. Once the time has passed the mute counts as ended.
		 *
		 * Example, mute a plugin for good:
		 *
		 *     add_filter(
		 *         'sc_wpun_mutes',
		 *         function ( $mutes ) {
		 *             $mutes[] = new \WPUN\Mute(
		 *                 array(
		 *                     'type' => 'plugin',
		 *                     'key'  => 'noisy/noisy.php',
		 *                 )
		 *             );
		 *             return $mutes;
		 *         }
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param Mute[] $mutes The stored mutes.
		 */
		$filtered = apply_filters( 'sc_wpun_mutes', $list );

		$effective = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $mute ) {
			if ( ! $mute instanceof Mute || 'core' === $mute->get_type() || ! Item_Types::exists( $mute->get_type() ) ) {
				continue;
			}
			if ( ! in_array( $mute, $list, true ) ) { // Not one of the stored objects: defined in code.
				$mute = new Mute(
					array(
						'type'      => $mute->get_type(),
						'key'       => $mute->get_key(),
						'until'     => $mute->get_until(),
						'time'      => $mute->get_time(),
						'user'      => $mute->get_user_id(),
						'expires'   => $mute->get_expires(),
						'source'    => $mute->get_source(),
						'from_code' => true,
					)
				);
			}
			$effective[ self::id( $mute->get_type(), $mute->get_key() ) ] = $mute;
		}

		// A filter cannot hide a stored mute.
		foreach ( $stored as $id => $mute ) {
			if ( ! isset( $effective[ $id ] ) && 'core' !== $mute->get_type() && Item_Types::exists( $mute->get_type() ) ) {
				$effective[ $id ] = $mute;
			}
		}

		return $effective;
	}

	/**
	 * The stored mutes by "type key", normalised, ended ones included.
	 *
	 * @return array<string, Mute>
	 */
	private function stored(): array {
		/** This filter is documented in includes/class-settings.php */
		$option = apply_filters( 'sc_wpun_get_options_filter', $this->context->get_option( self::OPT_FIELD ), self::OPT_FIELD );

		$mutes = array();
		foreach ( is_array( $option ) ? $option : array() as $type => $entries ) {
			if ( 'core' === (string) $type ) {
				continue; // WordPress core cannot be muted, whatever is in the option.
			}
			foreach ( is_array( $entries ) ? $entries : array() as $key => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$until = $entry['until'] ?? null;
				if ( is_int( $until ) || is_float( $until ) ) {
					$until = (string) $until;
				}
				try {
					$mute = new Mute(
						array(
							'type'    => (string) $type,
							'key'     => (string) $key,
							'until'   => $until,
							'time'    => $entry['time'] ?? 0,
							'user'    => $entry['user'] ?? 0,
							'expires' => $entry['expires'] ?? null,
							'source'  => $entry['source'] ?? '',
						)
					);
				} catch ( InvalidArgumentException $e ) {
					continue;
				}
				$mutes[ self::id( $mute->get_type(), $mute->get_key() ) ] = $mute;
			}
		}

		return $mutes;
	}

	/**
	 * Store the mutes, creating the option without autoload if it is missing.
	 *
	 * Mutes that a newer version or their expiry has ended are left out.
	 *
	 * @param array<string, Mute> $mutes Mutes by "type key".
	 *
	 * @return void
	 */
	private function write( array $mutes ): void {
		$stored = array();
		foreach ( $mutes as $mute ) {
			if ( $this->has_ended( $mute ) ) {
				continue;
			}
			$row = array(
				'until' => $mute->get_until(),
				'time'  => $mute->get_time(),
				'user'  => $mute->get_user_id(),
			);
			if ( null !== $mute->get_expires() ) {
				$row['expires'] = $mute->get_expires();
			}
			if ( '' !== $mute->get_source() ) {
				$row['source'] = $mute->get_source();
			}

			$stored[ $mute->get_type() ][ $mute->get_key() ] = $row;
		}

		/** This filter is documented in includes/class-settings.php */
		$stored = apply_filters( 'sc_wpun_put_options_filter', $stored, self::OPT_FIELD );

		if ( false === $this->context->get_option( self::OPT_FIELD ) ) {
			$this->context->add_option( self::OPT_FIELD, $stored, false );
		} else {
			$this->context->update_option( self::OPT_FIELD, $stored );
		}
	}

	/**
	 * Key of a mute in the lists.
	 *
	 * @param string $type Item type.
	 * @param string $key  Item key.
	 *
	 * @return string
	 */
	private static function id( string $type, string $key ): string {
		return $type . "\n" . $key;
	}

	/**
	 * Whether a mute has ended: its time has passed, or a "skip this version" mute no longer covers the version WordPress offers.
	 *
	 * @param Mute $mute The mute.
	 *
	 * @return bool
	 */
	private function has_ended( Mute $mute ): bool {
		if ( $mute->is_expired() ) {
			return true;
		}

		$offered = $this->offered_version( $mute->get_type(), $mute->get_key() );

		return null !== $offered && ! $mute->applies_to( $offered );
	}

	/**
	 * The version WordPress offers for a plugin or theme now.
	 *
	 * @internal
	 *
	 * @param string $type Item type.
	 * @param string $key  Plugin file or theme stylesheet.
	 *
	 * @return string|null Null if no update is waiting, or the type is not plugin or theme.
	 */
	public function offered_version( string $type, string $key ): ?string {
		if ( 'plugin' !== $type && 'theme' !== $type ) {
			return null;
		}

		$transient = get_site_transient( 'plugin' === $type ? 'update_plugins' : 'update_themes' );
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) || ! isset( $transient->response[ $key ] ) ) {
			return null;
		}

		$update  = $transient->response[ $key ];
		$version = is_object( $update ) ? ( $update->new_version ?? null ) : ( is_array( $update ) ? ( $update['new_version'] ?? null ) : null );

		return is_string( $version ) && '' !== $version ? $version : null;
	}

	/**
	 * Every effective mute, without those a newer version or their expiry has ended.
	 *
	 * @since 3.0.0
	 *
	 * @return Mute[]
	 */
	public function all(): array {
		return array_values(
			array_filter(
				$this->effective(),
				fn( Mute $mute ) => ! $this->has_ended( $mute )
			)
		);
	}

	/**
	 * The effective mute of an item, unless a newer version or its expiry has ended it.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Item type.
	 * @param string $key  Plugin file or theme stylesheet.
	 *
	 * @return Mute|null
	 */
	public function get( string $type, string $key ): ?Mute {
		$mute = $this->find( $type, $key );

		return null === $mute || $this->has_ended( $mute ) ? null : $mute;
	}

	/**
	 * The effective mute of an item, ended ones included.
	 *
	 * @internal
	 *
	 * @param string $type Item type.
	 * @param string $key  Plugin file or theme stylesheet.
	 *
	 * @return Mute|null
	 */
	public function find( string $type, string $key ): ?Mute {
		return $this->effective()[ self::id( $type, $key ) ] ?? null;
	}

	/**
	 * Whether an item is muted for the version it reports.
	 *
	 * An expired mute does not mute, even before the clean-up removes it.
	 *
	 * Works for every item type, also for items that filters added.
	 *
	 * @since 3.0.0
	 *
	 * @param Notification_Item $item The item.
	 *
	 * @return bool
	 */
	public function is_muted( Notification_Item $item ): bool {
		$mute = $this->find( $item->get_type(), $item->get_key() );

		return null !== $mute && ! $mute->is_expired() && $mute->applies_to( $item->get_new_version() );
	}

	/**
	 * Mute an item, replacing its stored mute if it has one.
	 *
	 * @since 3.0.0
	 *
	 * @param string      $type   Item type: plugin, theme or a registered type. Not core.
	 * @param string      $key    Plugin file or theme stylesheet.
	 * @param string|null $until  The newest version to cover, or null (the default) for always.
	 * @param string      $source Where the change came from: `plugins_screen`, `settings`, `cli` or `api`.
	 * @param int|null    $expires When the mute ends, as a Unix timestamp (a snooze), or null (the default) for no time limit. A time that has passed is refused.
	 *
	 * @return Mute|WP_Error The mute, or the reason it was refused.
	 */
	public function mute( string $type, string $key, ?string $until = null, string $source = 'api', ?int $expires = null ) {
		$result = $this->build( $type, $key, $until, $source, $expires );

		/**
		 * Filters a mute before it is saved, to apply a policy.
		 *
		 * Receives the mute, or the `WP_Error` that says why it is refused. Return a `WP_Error` to refuse
		 * it; the message is shown on the screen or by WP-CLI. A returned mute must be for the same item,
		 * and a refusal cannot be turned into a mute. Return a changed mute to, for example, cap how long
		 * a mute lasts. A mute whose `expires` has passed is refused, also when the filter made it so.
		 *
		 * Example, refuse muting a security plugin:
		 *
		 *     add_filter(
		 *         'sc_wpun_pre_mute',
		 *         function ( $mute ) {
		 *             if ( $mute instanceof \WPUN\Mute && 'security/security.php' === $mute->get_key() ) {
		 *                 return new \WP_Error( 'my_policy', 'Security updates must be reported.' );
		 *             }
		 *             return $mute;
		 *         }
		 *     );
		 *
		 * Example, cap a snooze at 30 days:
		 *
		 *     add_filter(
		 *         'sc_wpun_pre_mute',
		 *         function ( $mute ) {
		 *             $latest = time() + 30 * DAY_IN_SECONDS;
		 *             if ( $mute instanceof \WPUN\Mute && null !== $mute->get_expires() && $mute->get_expires() > $latest ) {
		 *                 return new \WPUN\Mute(
		 *                     array(
		 *                         'type'    => $mute->get_type(),
		 *                         'key'     => $mute->get_key(),
		 *                         'until'   => $mute->get_until(),
		 *                         'time'    => $mute->get_time(),
		 *                         'user'    => $mute->get_user_id(),
		 *                         'source'  => $mute->get_source(),
		 *                         'expires' => $latest,
		 *                     )
		 *                 );
		 *             }
		 *             return $mute;
		 *         }
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param Mute|WP_Error $mute   The mute, or the error that refuses it.
		 * @param string        $source Where the change came from: `plugins_screen`, `settings`, `cli` or `api`.
		 */
		$filtered = apply_filters( 'sc_wpun_pre_mute', $result, $source );

		if ( $result instanceof WP_Error ) {
			return $filtered instanceof WP_Error ? $filtered : $result;
		}
		if ( $filtered instanceof WP_Error ) {
			return $filtered;
		}
		if ( ! $filtered instanceof Mute ) {
			return new WP_Error( 'sc_wpun_mute_refused', __( 'The mute was refused.', 'wp-updates-notifier' ) );
		}
		if ( $filtered->get_type() !== $type || $filtered->get_key() !== $key || 'core' === $type || ! Item_Types::exists( $type ) ) {
			return new WP_Error( 'sc_wpun_mute_changed_item', __( 'The mute was refused: it is for another item.', 'wp-updates-notifier' ) );
		}

		if ( $filtered->is_expired() ) {
			return $this->expired_error();
		}

		$mute = new Mute(
			array(
				'type'    => $filtered->get_type(),
				'key'     => $filtered->get_key(),
				'until'   => $filtered->get_until(),
				'time'    => $filtered->get_time(),
				'user'    => $filtered->get_user_id(),
				'expires' => $filtered->get_expires(),
				'source'  => $filtered->get_source(),
			)
		);

		$id            = self::id( $mute->get_type(), $mute->get_key() );
		$stored        = $this->stored();
		$stored[ $id ] = $mute;
		$this->write( $stored );

		/**
		 * Fires after an item was muted.
		 *
		 * It fires again when a mute is replaced, for example "skip this version" by "always". Use it to log
		 * who muted what, or to keep other sites in step.
		 *
		 * @since 3.0.0
		 *
		 * @param Mute   $mute   The mute that was saved.
		 * @param string $source Where the change came from: `plugins_screen`, `settings`, `cli` or `api`.
		 */
		do_action( 'sc_wpun_item_muted', $mute, $source );

		return $mute;
	}

	/**
	 * Unmute an item.
	 *
	 * @since 3.0.0
	 *
	 * @param string $type   Item type.
	 * @param string $key    Plugin file or theme stylesheet.
	 * @param string $source Where the change came from: `plugins_screen`, `settings`, `cli` or `api`.
	 *
	 * @return true|WP_Error True, or the reason it was refused.
	 */
	public function unmute( string $type, string $key, string $source = 'api' ) {
		$mute = $this->get( $type, $key );
		if ( null === $mute ) {
			return new WP_Error( 'sc_wpun_unmute_not_muted', __( 'That item is not muted.', 'wp-updates-notifier' ) );
		}
		if ( $mute->is_from_code() ) {
			return new WP_Error( 'sc_wpun_unmute_from_code', __( 'That mute is set in code and cannot be removed here.', 'wp-updates-notifier' ) );
		}

		/**
		 * Filters whether a mute may be removed, to apply a policy.
		 *
		 * Return a `WP_Error` to refuse; the message is shown on the screen or by WP-CLI. Anything else
		 * than true refuses too. Deleting the plugin or theme removes its mute without asking.
		 *
		 * Example, keep the mutes the site's maintainer set:
		 *
		 *     add_filter(
		 *         'sc_wpun_pre_unmute',
		 *         function ( $allow, $mute ) {
		 *             return 'maintained/maintained.php' === $mute->get_key() ? new \WP_Error( 'my_policy', 'Ask the maintainer.' ) : $allow;
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param true|WP_Error $allow  Whether to unmute. Default true.
		 * @param Mute          $mute   The mute.
		 * @param string        $source Where the change came from: `plugins_screen`, `settings`, `cli` or `api`.
		 */
		$allow = apply_filters( 'sc_wpun_pre_unmute', true, $mute, $source );
		if ( $allow instanceof WP_Error ) {
			return $allow;
		}
		if ( true !== $allow ) {
			return new WP_Error( 'sc_wpun_unmute_refused', __( 'Removing the mute was refused.', 'wp-updates-notifier' ) );
		}

		$this->remove( $type, $key, $source, $mute );

		return true;
	}

	/**
	 * Remove the mute of a plugin that was deleted. Hooked on `deleted_plugin`.
	 *
	 * @internal
	 *
	 * @param mixed $file    Plugin file.
	 * @param mixed $deleted Whether the plugin was deleted.
	 *
	 * @return void
	 */
	public function plugin_deleted( $file, $deleted ): void {
		if ( true === $deleted && is_string( $file ) ) {
			$this->deleted( 'plugin', $file );
		}
	}

	/**
	 * Remove the mute of a theme that was deleted. Hooked on `deleted_theme`.
	 *
	 * @internal
	 *
	 * @param mixed $stylesheet Theme stylesheet.
	 * @param mixed $deleted    Whether the theme was deleted.
	 *
	 * @return void
	 */
	public function theme_deleted( $stylesheet, $deleted ): void {
		if ( true === $deleted && is_string( $stylesheet ) ) {
			$this->deleted( 'theme', $stylesheet );
		}
	}

	/**
	 * Remove the stored mute of a deleted item, without asking `sc_wpun_pre_unmute`.
	 *
	 * @param string $type Item type.
	 * @param string $key  Item key.
	 *
	 * @return void
	 */
	private function deleted( string $type, string $key ): void {
		$mute = $this->stored()[ self::id( $type, $key ) ] ?? null;
		if ( null === $mute ) {
			return;
		}

		$this->remove( $type, $key, 'deleted', $this->has_ended( $mute ) ? null : $mute );
	}

	/**
	 * Remove a stored mute, and fire the action if there is a mute to report.
	 *
	 * @param string    $type     Item type.
	 * @param string    $key      Item key.
	 * @param string    $source   Where the change came from.
	 * @param Mute|null $previous The mute that was in effect, if any.
	 *
	 * @return void
	 */
	private function remove( string $type, string $key, string $source, ?Mute $previous ): void {
		$stored = $this->stored();
		unset( $stored[ self::id( $type, $key ) ] );
		$this->write( $stored );

		if ( null !== $previous ) {
			/**
			 * Fires after an item was unmuted, or its plugin or theme was deleted.
			 *
			 * `$source` is `plugins_screen`, `settings`, `cli` or `api` for an unmute, and `deleted` when the
			 * plugin or theme was deleted.
			 *
			 * @since 3.0.0
			 *
			 * @param Mute   $previous The mute that was removed.
			 * @param string $source   Where the change came from.
			 */
			do_action( 'sc_wpun_item_unmuted', $previous, $source );
		}
	}

	/**
	 * Check a mute before it goes through the `sc_wpun_pre_mute` filter.
	 *
	 * @param string      $type  Item type.
	 * @param string      $key   Item key.
	 * @param string|null $until Version, or null for always.
	 * @param string      $source Where the change came from.
	 * @param int|null    $expires When the mute ends, or null for no time limit.
	 *
	 * @return Mute|WP_Error
	 */
	private function build( string $type, string $key, ?string $until, string $source, ?int $expires ) {
		if ( 'core' === $type ) {
			return new WP_Error( 'sc_wpun_mute_core', __( 'WordPress core cannot be muted. Use the core notification setting instead.', 'wp-updates-notifier' ) );
		}
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/D', $type ) || ! Item_Types::exists( $type ) ) {
			return new WP_Error( 'sc_wpun_mute_unknown_type', __( 'That kind of item cannot be muted.', 'wp-updates-notifier' ) );
		}
		if ( '' === $key ) {
			return new WP_Error( 'sc_wpun_mute_empty_key', __( 'Say which item to mute.', 'wp-updates-notifier' ) );
		}
		if ( '' === $until ) {
			return new WP_Error( 'sc_wpun_mute_empty_version', __( 'The version to skip cannot be empty.', 'wp-updates-notifier' ) );
		}

		if ( null !== $expires && $expires <= time() ) {
			return $this->expired_error();
		}

		$existing = $this->find( $type, $key );
		if ( null !== $existing && $existing->is_from_code() ) {
			return new WP_Error( 'sc_wpun_mute_from_code', __( 'That mute is set in code and cannot be changed here.', 'wp-updates-notifier' ) );
		}

		return new Mute(
			array(
				'type'    => $type,
				'key'     => $key,
				'until'   => $until,
				'time'    => time(),
				'user'    => get_current_user_id(),
				'expires' => $expires,
				'source'  => $source,
			)
		);
	}

	/**
	 * The error for a mute whose end time has passed.
	 *
	 * @return WP_Error
	 */
	private function expired_error(): WP_Error {
		return new WP_Error( 'sc_wpun_mute_expired', __( 'The end of the mute must be in the future.', 'wp-updates-notifier' ) );
	}

	/**
	 * The lengths a snooze can have, to offer in a list.
	 *
	 * @since 3.0.0
	 *
	 * @return array<int, string> Label by number of seconds, in the order given.
	 */
	public static function snooze_durations(): array {
		$defaults = array(
			DAY_IN_SECONDS      => __( '1 day', 'wp-updates-notifier' ),
			WEEK_IN_SECONDS     => __( '1 week', 'wp-updates-notifier' ),
			30 * DAY_IN_SECONDS => __( '30 days', 'wp-updates-notifier' ),
		);

		/**
		 * Filters the lengths of a snooze offered on the Plugins screen, the Muted tab and in WP-CLI.
		 *
		 * A list of seconds => label. Entries whose seconds are not a positive whole number or whose label is
		 * not a non-empty string are dropped. Only these lengths are accepted from the Plugins screen links.
		 *
		 * Example, offer 4 hours as well:
		 *
		 *     add_filter(
		 *         'sc_wpun_snooze_durations',
		 *         function ( $durations ) {
		 *             return array( 4 * HOUR_IN_SECONDS => __( '4 hours', 'my-plugin' ) ) + $durations;
		 *         }
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param array<int, string> $durations Label by number of seconds.
		 */
		$filtered = apply_filters( 'sc_wpun_snooze_durations', $defaults );

		$durations = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $seconds => $label ) {
			if ( is_numeric( $seconds ) && (int) $seconds > 0 && (string) (int) $seconds === (string) $seconds && is_string( $label ) && '' !== $label ) {
				$durations[ (int) $seconds ] = $label;
			}
		}

		return $durations;
	}

	/**
	 * A name for where a mute was set from, to show next to the user.
	 *
	 * @since 3.0.0
	 *
	 * @param string $source `plugins_screen`, `settings`, `cli`, `api`, an add-on's slug, or empty.
	 *
	 * @return string The label; empty if the source is not known.
	 */
	public static function source_label( string $source ): string {
		switch ( $source ) {
			case '':
				$label = '';
				break;
			case 'plugins_screen':
				$label = __( 'Plugins screen', 'wp-updates-notifier' );
				break;
			case 'settings':
				$label = __( 'Muted tab', 'wp-updates-notifier' );
				break;
			case 'cli':
				$label = __( 'WP-CLI', 'wp-updates-notifier' );
				break;
			case 'api':
				$label = __( 'Code', 'wp-updates-notifier' );
				break;
			default:
				$label = $source;
		}

		/**
		 * Filters the name shown for where a mute was set from.
		 *
		 * Example, show "Helpdesk" for an add-on's slug:
		 *
		 *     add_filter(
		 *         'sc_wpun_mute_source_label',
		 *         function ( $label, $source ) {
		 *             return 'helpdesk' === $source ? __( 'Helpdesk', 'my-plugin' ) : $label;
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param string $label  The label. Empty for an unknown source.
		 * @param string $source The source as stored.
		 */
		$filtered = apply_filters( 'sc_wpun_mute_source_label', $label, $source );

		return is_string( $filtered ) ? $filtered : $label;
	}
}
