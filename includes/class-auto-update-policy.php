<?php
/**
 * Whether WordPress is set to install an update by itself.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Answers whether WordPress's automatic updates are on for an update, from the same facts core uses.
 *
 * It reads settings and filters only. It never calls `WP_Automatic_Updater::should_update()` for core or
 * `find_core_auto_update()`, because both can send core's "update available" email (ADR 0020). It does not
 * check filesystem credentials, or the PHP and MySQL requirements of a core update: the answer says that
 * automatic updates are on, not that they will succeed.
 *
 * @internal
 *
 * @since 3.0.0
 */
final class Auto_Update_Policy {
	/**
	 * Why automatic updates cannot run on this site: `file_mods`, `disabled` or `vcs`, or an empty string if they can.
	 *
	 * @return string
	 */
	public function blocked_reason(): string {
		$updater = $this->updater();

		if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			return 'file_mods';
		}
		if ( $updater->is_disabled() ) {
			return 'disabled';
		}
		if ( $updater->is_vcs_checkout( ABSPATH ) ) {
			return 'vcs';
		}

		return '';
	}

	/**
	 * Whether automatic updates are on for an update that is waiting.
	 *
	 * @param string $type        `core`, `plugin` or `theme`.
	 * @param string $key         `core`, the plugin file or the theme stylesheet.
	 * @param object $entry       The update data of WordPress: the upgrade offer for core, the transient entry otherwise.
	 * @param string $new_version The version that is waiting.
	 *
	 * @return bool
	 */
	public function is_on( string $type, string $key, object $entry, string $new_version ): bool {
		$enabled = $this->default_for( $type, $key, $entry, $new_version );

		/**
		 * Filters whether an update counts as installed automatically, which adds the `auto_update` flag and its note to the item.
		 *
		 * The default follows WordPress: automatic updates are on for the item and can run on this site (the
		 * `AUTOMATIC_UPDATER_DISABLED` and `DISALLOW_FILE_MODS` constants, version control checkouts, the
		 * auto-update settings and the `auto_update_*` filters). Use it for an updater outside WordPress that
		 * installs the update later. Returning false removes the flag and the note.
		 *
		 * Example, a host that updates plugins itself:
		 *
		 *     add_filter(
		 *         'sc_wpun_auto_update_enabled',
		 *         function ( $enabled, $type ) {
		 *             return $enabled || 'plugin' === $type;
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param bool   $enabled     Whether automatic updates are on for the item.
		 * @param string $type        `core`, `plugin` or `theme`.
		 * @param string $key         `core`, the plugin file or the theme stylesheet.
		 * @param string $new_version The version that is waiting.
		 */
		return (bool) apply_filters( 'sc_wpun_auto_update_enabled', $enabled, $type, $key, $new_version );
	}

	/**
	 * What WordPress's own rules say.
	 *
	 * @param string $type        `core`, `plugin` or `theme`.
	 * @param string $key         Item key.
	 * @param object $entry       Update data.
	 * @param string $new_version Waiting version.
	 *
	 * @return bool
	 */
	private function default_for( string $type, string $key, object $entry, string $new_version ): bool {
		$updater = $this->updater();

		if ( $updater->is_disabled() ) {
			return false;
		}

		if ( 'core' === $type ) {
			if ( $updater->is_vcs_checkout( ABSPATH ) ) {
				return false;
			}
			$value = \Core_Upgrader::should_update_to_version( $new_version );

			return (bool) wp_is_auto_update_forced_for_item( 'core', $value, $entry ); // @phpstan-ignore argument.type (The stubs list plugin and theme only; the hook name is built from the type.)
		}

		if ( 'plugin' !== $type && 'theme' !== $type ) {
			return false;
		}

		$context = 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root( $key );
		if ( $updater->is_vcs_checkout( $context ) ) {
			return false;
		}

		// Core's filters expect the entry to name the item, as in the update transient.
		$item = (object) array_merge( get_object_vars( $entry ), array( $type => $key ) );

		$value = ! empty( $item->autoupdate );
		if ( ! $value && wp_is_auto_update_enabled_for_type( $type ) ) {
			$value = in_array( $key, (array) get_site_option( "auto_update_{$type}s", array() ), true );
		}
		if ( ! empty( $item->disable_autoupdate ) ) {
			$value = false;
		}

		return (bool) wp_is_auto_update_forced_for_item( $type, $value, $item );
	}

	/**
	 * WordPress's automatic updater, with the files it needs loaded (cron requests do not load them).
	 *
	 * @return \WP_Automatic_Updater
	 */
	private function updater(): \WP_Automatic_Updater {
		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! class_exists( 'WP_Upgrader', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( 'WP_Automatic_Updater', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}
		if ( ! class_exists( 'Core_Upgrader', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
		}

		return new \WP_Automatic_Updater();
	}
}
