<?php
/**
 * Plugin Name:       WP Updates Notifier Example Channel
 * Description:       A small example of a notification channel for WP Updates Notifier that posts to a chat webhook. Copy it to write your own.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Requires Plugins:  wp-updates-notifier
 * Requires WPUN API: ^1.0
 * License:           GPL-2.0-or-later
 * Text Domain:       wpun-example-channel
 *
 * This is an add-on: a separate plugin that extends WP Updates Notifier through its public API only. Everything
 * you need to know to write one is in this file and in class-example-channel.php.
 *
 * `Requires WPUN API` is the same constraint as REQUIRED_API below. WP Updates Notifier reads it to show a notice on the
 * Plugins screen, before and after activation, when the installed plugin's API does not satisfy it.
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
 * The versions of the WP Updates Notifier extension API this add-on works with, as a constraint.
 *
 * It matches the API version (sc_wpun_api_version()), not the plugin version: it only changes when the API does, and a
 * new minor version only ever adds to the API. `^1.0` means any 1.x from 1.0 on. Ask for the oldest version that has
 * everything you use. Composer's syntax applies, so `>=1.0 <3.0` or `^1.0 || ^2.0` work too.
 */
const REQUIRED_API = '^1.0';

/**
 * Whether the installed WP Updates Notifier offers the API this add-on was written for.
 *
 * `Requires Plugins` in the header makes WordPress 6.5 and later refuse to activate this plugin without
 * WP Updates Notifier. It does not check the version, so this does: sc_wpun_api_satisfies() is true when the
 * installed API version matches REQUIRED_API.
 *
 * The channel declares the same constraint (Example_Channel::get_api_constraint()), and the registry refuses it on a
 * site that is too old. This check is still needed for code that runs outside the channel, such as hooks the add-on adds
 * itself, and to show the add-on's own notice.
 *
 * @return bool
 */
function api_is_supported(): bool {
	return function_exists( 'sc_wpun_api_satisfies' ) && \sc_wpun_api_satisfies( REQUIRED_API );
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
