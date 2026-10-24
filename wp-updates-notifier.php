<?php
/**
 * Entry point for the plugin.
 *
 * This file is read by WordPress to generate the plugin information in the
 * admin panel.
 *
 * @link    https://github.com/l3rady/wp-updates-notifier
 * @since   1.5.0
 * @package SC_WP_Updates_Notifier
 */

/*
 * Plugin Name: WP Updates Notifier
 * Plugin URI: https://wpun.cariss.dev/
 * Description: Sends an email to notify you if there are any updates for your WordPress site. Can notify about core, plugin and theme updates.
 * x-release-please-start-version
 * Version: 2.0.0
 * x-release-please-end
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: Scott Cariss
 * Author URI: https://wpun.cariss.dev/#about-the-developer
 * Text Domain: wp-updates-notifier
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

/*
	Copyright 2015-2026  Scott Cariss  (email : scott@cariss.dev)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

defined( 'SC_WPUN_FILE' ) || define( 'SC_WPUN_FILE', __FILE__ );

/*
 * Version of the plugin, the same as the Version header. Used to version the admin stylesheet.
 * Release-please keeps it in step with the header; bin/check-headers.sh fails if they differ.
 */
/* x-release-please-start-version */
defined( 'SC_WPUN_VERSION' ) || define( 'SC_WPUN_VERSION', '2.0.0' );
/* x-release-please-end */

/*
 * Version of the public API (channels, routing, hooks), separate from the plugin version.
 * Semver: a minor release adds API, a major release removes or changes some. Add-ons check
 * it with sc_wpun_api_satisfies().
 */
defined( 'SC_WPUN_API_VERSION' ) || define( 'SC_WPUN_API_VERSION', '1.0.0' );

require_once __DIR__ . '/includes/class-autoloader.php';
\WPUN\Autoloader::register();

// Built-in notification modules.
require_once __DIR__ . '/modules/email/email.php';

// Only load class if it hasn't already been loaded.
if ( ! class_exists( 'SC_WP_Updates_Notifier' ) ) {

	/**
	 * The plugin's original class, kept for backwards compatibility.
	 *
	 * Other code may call its public methods or use its constants, so it stays and
	 * hands every call to the component that now does the work. New code should not use it.
	 */
	class SC_WP_Updates_Notifier {
		const OPT_FIELD         = \WPUN\Settings::OPT_FIELD;
		const OPT_VERSION_FIELD = \WPUN\Settings::OPT_VERSION_FIELD;
		const OPT_STATE_FIELD   = \WPUN\State::OPT_FIELD;
		const OPT_VERSION       = \WPUN\Settings::OPT_VERSION;
		const DEFAULT_FREQUENCY = \WPUN\Scheduler::DEFAULT_FREQUENCY;
		const CRON_NAME         = \WPUN\Scheduler::CRON_NAME;

		/**
		 * Whether the plugin has been initialised in this request.
		 *
		 * @var bool
		 */
		public static bool $did_init = false;

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function run_init() {
			sc_wpun()->boot();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function activate() {
			sc_wpun()->activate();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function deactivate() {
			sc_wpun()->deactivate();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @param mixed $manual_interval Passed on unchanged.
		 * @return void
		 */
		public function enable_cron( $manual_interval = false ) {
			sc_wpun()->scheduler()->enable_cron( $manual_interval );
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function maybe_schedule() {
			sc_wpun()->scheduler()->maybe_schedule();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function disable_cron() {
			sc_wpun()->scheduler()->disable_cron();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @param mixed $links Passed on unchanged.
		 * @param mixed $file Passed on unchanged.
		 * @return mixed
		 */
		public function plugin_action_links( $links, $file ) {
			return sc_wpun()->settings_page()->plugin_action_links( $links, $file );
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function do_update_check() {
			sc_wpun()->update_checker()->do_update_check();
		}

		/**
		 * Kept for backwards compatibility. What was notified is tracked per channel instance since 2.0.0,
		 * so this only removes entries every stored instance has been told about at that version.
		 *
		 * @deprecated 2.0.0
		 *
		 * @param mixed $plugins_need_update Update list.
		 * @return mixed
		 */
		public function check_plugins_against_notified( $plugins_need_update ) {
			_deprecated_function( __METHOD__, '2.0.0' );

			return sc_wpun()->update_checker()->check_plugins_against_notified( $plugins_need_update );
		}

		/**
		 * Kept for backwards compatibility. What was notified is tracked per channel instance since 2.0.0,
		 * so this only removes entries every stored instance has been told about at that version.
		 *
		 * @deprecated 2.0.0
		 *
		 * @param mixed $themes_need_update Update list.
		 * @return mixed
		 */
		public function check_themes_against_notified( $themes_need_update ) {
			_deprecated_function( __METHOD__, '2.0.0' );

			return sc_wpun()->update_checker()->check_themes_against_notified( $themes_need_update );
		}

		/**
		 * Kept for backwards compatibility: email is sent by the email channel since 2.0.0.
		 *
		 * @deprecated 2.0.0 Use the email channel.
		 *
		 * @param mixed $message Body of the email.
		 *
		 * @return bool
		 */
		public function send_email_message( $message ) {
			_deprecated_function( __METHOD__, '2.0.0', 'the email channel' );

			return sc_wpun()->notifier()->send_legacy_message( (string) $message );
		}

		/**
		 * Kept for backwards compatibility: Slack notifications were removed in 2.0.0, so this does nothing.
		 *
		 * @deprecated 2.0.0
		 *
		 * @param mixed $message Ignored.
		 *
		 * @return false
		 */
		public function send_slack_message( $message ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Kept so existing callers keep working.
			_deprecated_function( __METHOD__, '2.0.0' );

			return false;
		}

		/**
		 * Kept for backwards compatibility: WordPress's automatic update emails are no longer changed since 2.0.0.
		 *
		 * @deprecated 2.0.0 Use the auto_core_update_email filter.
		 *
		 * @return string
		 */
		public function sc_wpun_wp_mail_from() {
			_deprecated_function( __METHOD__, '2.0.0', 'the auto_core_update_email filter' );

			return '';
		}

		/**
		 * Kept for backwards compatibility: WordPress's automatic update emails are no longer changed since 2.0.0.
		 *
		 * @deprecated 2.0.0 Use the auto_core_update_email filter.
		 *
		 * @return string
		 */
		public function sc_wpun_wp_mail_from_name() {
			_deprecated_function( __METHOD__, '2.0.0', 'the auto_core_update_email filter' );

			return '';
		}

		/**
		 * Kept for backwards compatibility: email is sent by the email channel since 2.0.0.
		 *
		 * @deprecated 2.0.0 Use the email channel.
		 *
		 * @return string
		 */
		public function sc_wpun_wp_mail_content_type() {
			_deprecated_function( __METHOD__, '2.0.0', 'the email channel' );

			return 'text/plain';
		}

		/**
		 * Kept for backwards compatibility: WordPress's automatic update emails are no longer changed since 2.0.0.
		 *
		 * @deprecated 2.0.0 Use the auto_core_update_email filter.
		 *
		 * @param mixed $email Returned unchanged.
		 * @return mixed
		 */
		public function filter_auto_core_update_email( $email ) {
			_deprecated_function( __METHOD__, '2.0.0', 'the auto_core_update_email filter' );

			return $email;
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function remove_update_nag_for_nonadmins() {
			sc_wpun()->update_nag()->remove_update_nag_for_nonadmins();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function admin_settings_menu() {
			sc_wpun()->settings_page()->admin_settings_menu();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function settings_page() {
			sc_wpun()->settings_page()->settings_page();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function admin_settings_init() {
			sc_wpun()->settings_page()->admin_settings_init();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @param mixed $input Passed on unchanged.
		 * @return mixed
		 */
		public function sc_wpun_settings_validate( $input ) {
			return sc_wpun()->settings_page()->sc_wpun_settings_validate( $input );
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @param mixed $old_value Passed on unchanged.
		 * @param mixed $value Passed on unchanged.
		 * @return void
		 */
		public function settings_updated( $old_value, $value ) {
			sc_wpun()->scheduler()->settings_updated( $old_value, $value );
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function send_test_email() {
			sc_wpun()->settings_page()->send_test_email();
		}

		/**
		 * Kept for backwards compatibility: Slack notifications were removed in 2.0.0, so this does nothing.
		 *
		 * @deprecated 2.0.0
		 *
		 * @return void
		 */
		public function send_test_slack() {
			_deprecated_function( __METHOD__, '2.0.0' );
		}

		/**
		 * Kept for backwards compatibility: the section has no intro text, so this does nothing.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_text() {
			// The section has no intro text since 2.0.0.
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_frequency() {
			sc_wpun()->settings_page()->sc_wpun_settings_main_field_frequency();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_plugins() {
			sc_wpun()->settings_page()->sc_wpun_settings_main_field_notify_plugins();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_themes() {
			sc_wpun()->settings_page()->sc_wpun_settings_main_field_notify_themes();
		}

		/**
		 * Kept for backwards compatibility: the Automatic updates setting was removed in 2.0.0, so this does nothing.
		 *
		 * @deprecated 2.0.0
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_automatic() {
			_deprecated_function( __METHOD__, '2.0.0' );
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_hide_updates() {
			sc_wpun()->settings_page()->sc_wpun_settings_main_field_hide_updates();
		}

		/**
		 * Kept for backwards compatibility: Slack notifications were removed in 2.0.0, so this does nothing.
		 *
		 * @deprecated 2.0.0
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_text() {
			_deprecated_function( __METHOD__, '2.0.0' );
		}

		/**
		 * Kept for backwards compatibility: Slack notifications were removed in 2.0.0, so this does nothing.
		 *
		 * @deprecated 2.0.0
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_notifications() {
			_deprecated_function( __METHOD__, '2.0.0' );
		}

		/**
		 * Kept for backwards compatibility: Slack notifications were removed in 2.0.0, so this does nothing.
		 *
		 * @deprecated 2.0.0
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_webhook_url() {
			_deprecated_function( __METHOD__, '2.0.0' );
		}

		/**
		 * Kept for backwards compatibility: Slack notifications were removed in 2.0.0, so this does nothing.
		 *
		 * @deprecated 2.0.0
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_channel_override() {
			_deprecated_function( __METHOD__, '2.0.0' );
		}
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- The accessor sits beside the compatibility class so the main file stays the one entry point.
if ( ! function_exists( 'sc_wpun' ) ) {
	/**
	 * The plugin.
	 *
	 * @internal
	 *
	 * @return \WPUN\Plugin
	 */
	function sc_wpun(): \WPUN\Plugin {
		static $plugin = null;

		$plugin ??= new \WPUN\Plugin();

		return $plugin;
	}
}

if ( ! function_exists( 'sc_wpun_register_channel' ) ) {
	/**
	 * Register a notification channel. Call it from the `sc_wpun_register_channels` action.
	 *
	 * @since 2.0.0
	 *
	 * @param \WPUN\Channel $channel The channel.
	 *
	 * @return bool False if its type is invalid or already registered.
	 */
	function sc_wpun_register_channel( \WPUN\Channel $channel ): bool {
		return sc_wpun()->channels()->register( $channel );
	}
}

if ( ! function_exists( 'sc_wpun_channels' ) ) {
	/**
	 * The store of the configured channel instances.
	 *
	 * @since 2.0.0
	 *
	 * @return \WPUN\Channel_Store
	 */
	function sc_wpun_channels(): \WPUN\Channel_Store {
		return sc_wpun()->channel_store();
	}
}
if ( ! function_exists( 'sc_wpun_api_version' ) ) {
	/**
	 * The version of the public API, for add-ons to check what they can use.
	 *
	 * Semver, separate from the plugin version: a minor version adds API, a major version
	 * removes or changes some. Most add-ons want sc_wpun_api_satisfies() instead.
	 *
	 * @since 2.0.0
	 *
	 * @return string For example "1.0.0".
	 */
	function sc_wpun_api_version(): string {
		return SC_WPUN_API_VERSION;
	}
}

if ( ! function_exists( 'sc_wpun_api_satisfies' ) ) {
	/**
	 * Whether the installed plugin's API version satisfies a version constraint.
	 *
	 * Constraints use Composer's syntax. Check one before using the API, and show an admin
	 * notice instead of failing when it is false. Declare `Requires Plugins: wp-updates-notifier`
	 * in the add-on's header too, so WordPress makes sure the plugin is active:
	 *
	 *     add_action( 'plugins_loaded', function () {
	 *         if ( ! function_exists( 'sc_wpun_api_satisfies' ) || ! sc_wpun_api_satisfies( '^1.0' ) ) {
	 *             add_action( 'admin_notices', 'my_addon_needs_newer_updates_notifier' );
	 *             return;
	 *         }
	 *         add_action( 'sc_wpun_register_channels', 'my_addon_register_channel' );
	 *     } );
	 *
	 * | Constraint                                  | Meaning                  |
	 * | ------------------------------------------- | ------------------------ |
	 * | `1.2.0`, `=1.2.0`                           | exactly                  |
	 * | `>=1.0`, `>1.0`, `<2.0`, `<=1.4`, `!=1.3.0` | comparison               |
	 * | `^1.2`                                      | `>=1.2.0 <2.0.0`         |
	 * | `~1.2`                                      | `>=1.2.0 <2.0.0`         |
	 * | `~1.2.3`                                    | `>=1.2.3 <1.3.0`         |
	 * | `1.2.*`                                     | `>=1.2.0 <1.3.0`         |
	 * | `>=1.0 <1.4`, `>=1.0, <1.4`                 | both (AND)               |
	 * | `^1.0 || ^2.0`                              | either (OR)              |
	 *
	 * Missing minor and patch parts count as 0, and pre-release suffixes are not supported.
	 * An invalid constraint returns false and triggers `_doing_it_wrong()`. See
	 * WPUN\Version_Constraint for the details.
	 *
	 * @since 2.0.0
	 *
	 * @param string $constraint The API versions the add-on works with, for example "^1.0".
	 *
	 * @return bool
	 */
	function sc_wpun_api_satisfies( string $constraint ): bool {
		return \WPUN\Version_Constraint::satisfies( sc_wpun_api_version(), $constraint );
	}
}

if ( ! function_exists( 'sc_wpun_api_supports' ) ) {
	/**
	 * Whether the installed plugin offers the API version an add-on was written for.
	 *
	 * The `^` shorthand of sc_wpun_api_satisfies(), which is the one to prefer: this is the same
	 * as `sc_wpun_api_satisfies( '^' . $required )`. True when the API has the same major
	 * version and is at least as new: an add-on written for 1.2.0 works with 1.2.0 and 1.5.0,
	 * but not with 1.1.0 or 2.0.0. Anything but a full "X.Y.Z" version returns false.
	 *
	 * @since 2.0.0
	 *
	 * @param string $required The API version the add-on needs, for example "1.0.0".
	 *
	 * @return bool
	 */
	function sc_wpun_api_supports( string $required ): bool {
		return 1 === preg_match( '/^\d+\.\d+\.\d+$/D', $required )
			&& \WPUN\Version_Constraint::satisfies( sc_wpun_api_version(), '^' . $required );
	}
}
// phpcs:enable Universal.Files.SeparateFunctionsFromOO.Mixed

// Runs on every include: the activation and deactivation hooks must exist when WordPress loads this file to activate the plugin.
sc_wpun()->register();
