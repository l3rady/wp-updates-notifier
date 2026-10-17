<?php
/**
 * Plugin Name:       WP Updates Notifier Example Channel
 * Description:       A small example of a notification channel for WP Updates Notifier that posts to a chat webhook. Copy it to write your own.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Requires Plugins:  wp-updates-notifier
 * License:           GPL-2.0-or-later
 * Text Domain:       wpun-example-channel
 *
 * This is an add-on: a separate plugin that extends WP Updates Notifier through its public API only. Everything
 * you need to know to write one is in this file and in class-example-channel.php.
 *
 * Loading this file only defines things and adds one hook. Everything else is wired from boot() on `plugins_loaded`,
 * by which time every active plugin, WP Updates Notifier included, has been loaded.
 *
 * @package wpun-example-channel
 */

namespace WPUN_Example_Channel;

use WPUN\Channel_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * The version of the WP Updates Notifier extension API this add-on was written for.
 *
 * It is the API version (sc_wpun_api_version()), not the plugin version: it only changes when the API does, and a
 * new minor version only ever adds to the API. Ask for the oldest version that has everything you use.
 */
const REQUIRED_API = '1.0.0';

/**
 * Whether the installed WP Updates Notifier offers the API this add-on was written for.
 *
 * `Requires Plugins` in the header makes WordPress 6.5 and later refuse to activate this plugin without
 * WP Updates Notifier. It does not check the version, so this does: sc_wpun_api_supports() is true when the
 * installed API has the same major version as REQUIRED_API and is at least as new.
 *
 * @return bool
 */
function api_is_supported(): bool {
	return function_exists( 'sc_wpun_api_supports' ) && \sc_wpun_api_supports( REQUIRED_API );
}

/**
 * Wire the add-on, if the installed plugin offers the API it was written for.
 *
 * It takes no parameters on purpose: WordPress passes an empty string to callbacks of `plugins_loaded`.
 *
 * @return void
 */
function boot(): void {
	if ( ! api_is_supported() ) {
		// Say so, and do nothing else: do not call into a plugin that may not be there or may be too old.
		add_action( 'admin_notices', __NAMESPACE__ . '\\api_notice' );
		return;
	}

	require_once __DIR__ . '/class-example-channel.php';

	// A module registers its channel type on this action. The registry is passed in, so no global is needed.
	// The action fires the first time the plugin needs the list of channels, which may be after this runs.
	add_action(
		'sc_wpun_register_channels',
		static function ( Channel_Registry $registry ) {
			$registry->register( new Example_Channel() );
		}
	);
}

/**
 * Tell the administrator that WP Updates Notifier is missing or too old.
 *
 * @return void
 */
function api_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'WP Updates Notifier Example Channel needs a newer version of WP Updates Notifier. Please update it.', 'wpun-example-channel' )
		. '</p></div>';
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );
