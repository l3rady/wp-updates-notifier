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
 * Plugin URI: https://github.com/l3rady/wp-updates-notifier
 * Description: Sends email or Slack message to notify you if there are any updates for your WordPress site. Can notify about core, plugin and theme updates.
 * x-release-please-start-version
 * Version: 1.6.0
 * x-release-please-end
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: Scott Cariss
 * Author URI: https://scott.cariss.dev/
 * Text Domain: wp-updates-notifier
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

/*
	Copyright 2015  Scott Cariss  (email : scott@cariss.dev)

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

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-state.php';
require_once __DIR__ . '/includes/class-scheduler.php';
require_once __DIR__ . '/includes/class-notifier.php';
require_once __DIR__ . '/includes/class-notification-item.php';
require_once __DIR__ . '/includes/class-notification.php';
require_once __DIR__ . '/includes/class-notification-renderer.php';
require_once __DIR__ . '/includes/class-legacy-message.php';
require_once __DIR__ . '/includes/class-delivery-result.php';
require_once __DIR__ . '/includes/class-channel-instance.php';
require_once __DIR__ . '/includes/abstract-class-channel.php';
require_once __DIR__ . '/includes/class-channel-registry.php';
require_once __DIR__ . '/includes/class-channel-store.php';
require_once __DIR__ . '/includes/class-dispatcher.php';
require_once __DIR__ . '/includes/class-update-checker.php';
require_once __DIR__ . '/includes/admin/class-settings-page.php';
require_once __DIR__ . '/includes/admin/class-update-nag.php';
require_once __DIR__ . '/includes/class-plugin.php';

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
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @param mixed $plugins_need_update Passed on unchanged.
		 * @return mixed
		 */
		public function check_plugins_against_notified( $plugins_need_update ) {
			return sc_wpun()->update_checker()->check_plugins_against_notified( $plugins_need_update );
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @param mixed $themes_need_update Passed on unchanged.
		 * @return mixed
		 */
		public function check_themes_against_notified( $themes_need_update ) {
			return sc_wpun()->update_checker()->check_themes_against_notified( $themes_need_update );
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @param mixed $message Passed on unchanged.
		 * @return mixed
		 */
		public function send_email_message( $message ) {
			return sc_wpun()->notifier()->send_email_message( $message );
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @param mixed $message Passed on unchanged.
		 * @return mixed
		 */
		public function send_slack_message( $message ) {
			return sc_wpun()->notifier()->send_slack_message( $message );
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return mixed
		 */
		public function sc_wpun_wp_mail_from() {
			return sc_wpun()->notifier()->sc_wpun_wp_mail_from();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return mixed
		 */
		public function sc_wpun_wp_mail_from_name() {
			return sc_wpun()->notifier()->sc_wpun_wp_mail_from_name();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return mixed
		 */
		public function sc_wpun_wp_mail_content_type() {
			return sc_wpun()->notifier()->sc_wpun_wp_mail_content_type();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @param mixed $email Passed on unchanged.
		 * @return mixed
		 */
		public function filter_auto_core_update_email( $email ) {
			return sc_wpun()->notifier()->filter_auto_core_update_email( $email );
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
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function send_test_slack() {
			sc_wpun()->settings_page()->send_test_slack();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_text() {
			sc_wpun()->settings_page()->sc_wpun_settings_main_text();
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
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_automatic() {
			sc_wpun()->settings_page()->sc_wpun_settings_main_field_notify_automatic();
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
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_email_notifications_text() {
			sc_wpun()->settings_page()->sc_wpun_settings_email_notifications_text();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_email_notifications_field_email_notifications() {
			sc_wpun()->settings_page()->sc_wpun_settings_email_notifications_field_email_notifications();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_email_notifications_field_notify_to() {
			sc_wpun()->settings_page()->sc_wpun_settings_email_notifications_field_notify_to();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_email_notifications_field_notify_from() {
			sc_wpun()->settings_page()->sc_wpun_settings_email_notifications_field_notify_from();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_text() {
			sc_wpun()->settings_page()->sc_wpun_settings_slack_notifications_text();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_notifications() {
			sc_wpun()->settings_page()->sc_wpun_settings_slack_notifications_field_slack_notifications();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_webhook_url() {
			sc_wpun()->settings_page()->sc_wpun_settings_slack_notifications_field_slack_webhook_url();
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_channel_override() {
			sc_wpun()->settings_page()->sc_wpun_settings_slack_notifications_field_slack_channel_override();
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
// phpcs:enable Universal.Files.SeparateFunctionsFromOO.Mixed

// Runs on every include: the activation and deactivation hooks must exist when WordPress loads this file to activate the plugin.
sc_wpun()->register();
