<?php
/**
 * The legacy SC_WP_Updates_Notifier class, kept for add-ons that still use it.
 *
 * @package wp-updates-notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

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
		 * @param bool $network_wide Passed on unchanged.
		 * @return void
		 */
		public function activate( $network_wide = false ) {
			sc_wpun()->activate( $network_wide );
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @param bool $network_wide Passed on unchanged.
		 * @return void
		 */
		public function deactivate( $network_wide = false ) {
			sc_wpun()->deactivate( $network_wide );
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

			return sc_wpun()->notifier()->check_plugins_against_notified( $plugins_need_update );
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

			return sc_wpun()->notifier()->check_themes_against_notified( $themes_need_update );
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
		 * Kept for backwards compatibility, and does nothing. Slack is a channel since 3.0.0; configure it on the Channels tab.
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
			sc_wpun()->notifier()->send_legacy_message( __( 'This is a test message from WP Updates Notifier.', 'wp-updates-notifier' ) );
		}

		/**
		 * Kept for backwards compatibility, and does nothing. Slack is a channel since 3.0.0; configure it on the Channels tab.
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
			echo sc_wpun()->settings_page()->main_control( 'frequency' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form_Parts.
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_plugins() {
			echo sc_wpun()->settings_page()->main_control( 'notify_plugins' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form_Parts.
		}

		/**
		 * Kept for backwards compatibility: see the matching method of the component that now owns it.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_themes() {
			echo sc_wpun()->settings_page()->main_control( 'notify_themes' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form_Parts.
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
			echo sc_wpun()->settings_page()->main_control( 'hide_updates' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form_Parts.
		}

		/**
		 * Kept for backwards compatibility, and does nothing. Slack is a channel since 3.0.0; configure it on the Channels tab.
		 *
		 * @deprecated 2.0.0
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_text() {
			_deprecated_function( __METHOD__, '2.0.0' );
		}

		/**
		 * Kept for backwards compatibility, and does nothing. Slack is a channel since 3.0.0; configure it on the Channels tab.
		 *
		 * @deprecated 2.0.0
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_notifications() {
			_deprecated_function( __METHOD__, '2.0.0' );
		}

		/**
		 * Kept for backwards compatibility, and does nothing. Slack is a channel since 3.0.0; configure it on the Channels tab.
		 *
		 * @deprecated 2.0.0
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_webhook_url() {
			_deprecated_function( __METHOD__, '2.0.0' );
		}

		/**
		 * Kept for backwards compatibility, and does nothing. Slack is a channel since 3.0.0; configure it on the Channels tab.
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
