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
 * (the `sc_wpun_mutes` filter), without a "skip this version" mute that a newer version has ended.
 * A mute defined in code cannot be changed or removed here. The check never writes the stored mutes;
 * an ended mute is left out when read and removed the next time a mute is saved or removed. Part of
 * the extension API.
 *
 * @since 3.0.0
 */
final class Mute_Store {
	const OPT_FIELD = 'sc_wpun_muted';

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
		$option = apply_filters( 'sc_wpun_get_options_filter', get_option( self::OPT_FIELD ), self::OPT_FIELD );

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
							'type'  => (string) $type,
							'key'   => (string) $key,
							'until' => $until,
							'time'  => $entry['time'] ?? 0,
							'user'  => $entry['user'] ?? 0,
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
	 * Mutes that a newer version has ended are left out.
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
			$stored[ $mute->get_type() ][ $mute->get_key() ] = array(
				'until' => $mute->get_until(),
				'time'  => $mute->get_time(),
				'user'  => $mute->get_user_id(),
			);
		}

		/** This filter is documented in includes/class-settings.php */
		$stored = apply_filters( 'sc_wpun_put_options_filter', $stored, self::OPT_FIELD );

		if ( false === get_option( self::OPT_FIELD ) ) {
			add_option( self::OPT_FIELD, $stored, '', false );
		} else {
			update_option( self::OPT_FIELD, $stored );
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
	 * Whether a "skip this version" mute no longer covers the version WordPress offers.
	 *
	 * @param Mute $mute The mute.
	 *
	 * @return bool
	 */
	private function has_ended( Mute $mute ): bool {
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
	 * Every effective mute, without those a newer version has ended.
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
	 * The effective mute of an item, unless a newer version has ended it.
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

		return null !== $mute && $mute->applies_to( $item->get_new_version() );
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
	 *
	 * @return Mute|WP_Error The mute, or the reason it was refused.
	 */
	public function mute( string $type, string $key, ?string $until = null, string $source = 'api' ) {
		$result = $this->build( $type, $key, $until );

		/**
		 * Filters a mute before it is saved, to apply a policy.
		 *
		 * Receives the mute, or the `WP_Error` that says why it is refused. Return a `WP_Error` to refuse
		 * it; the message is shown on the screen or by WP-CLI. A returned mute must be for the same item,
		 * and a refusal cannot be turned into a mute. Return a changed mute to, for example, cap how long
		 * a mute lasts.
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

		$mute = new Mute(
			array(
				'type'  => $filtered->get_type(),
				'key'   => $filtered->get_key(),
				'until' => $filtered->get_until(),
				'time'  => $filtered->get_time(),
				'user'  => $filtered->get_user_id(),
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
	 *
	 * @return Mute|WP_Error
	 */
	private function build( string $type, string $key, ?string $until ) {
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

		$existing = $this->find( $type, $key );
		if ( null !== $existing && $existing->is_from_code() ) {
			return new WP_Error( 'sc_wpun_mute_from_code', __( 'That mute is set in code and cannot be changed here.', 'wp-updates-notifier' ) );
		}

		return new Mute(
			array(
				'type'  => $type,
				'key'   => $key,
				'until' => $until,
				'time'  => time(),
				'user'  => get_current_user_id(),
			)
		);
	}
}
