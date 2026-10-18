<?php
/**
 * Plugin Name:       WP Updates Notifier Test Add-on
 * Description:       A fixture add-on that uses only the public API of WP Updates Notifier, to prove it can be extended from outside.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Requires Plugins:  wp-updates-notifier
 * Requires WPUN API: ^1.0
 * License:           GPL-2.0-or-later
 *
 * Loading this file only defines things and adds one hook. Everything else is wired from boot() on `plugins_loaded`.
 *
 * @package wpun-test-addon
 */

namespace WPUN_Test_Addon;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Wire the add-on, if the installed plugin offers the API it was written for.
 *
 * @return void
 */
function boot(): void {
	if ( ! function_exists( 'sc_wpun_api_satisfies' ) || ! \sc_wpun_api_satisfies( '^1.0' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>' . esc_html( 'The test add-on needs a newer WP Updates Notifier.' ) . '</p></div>';
			}
		);
		return;
	}

	require_once __DIR__ . '/class-addon-channel.php';
	require_once __DIR__ . '/class-addon-config-source.php';
	require_once __DIR__ . '/class-addon.php';

	Addon::hook();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );
