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
 * Description: Sends email to notify you if there are any updates for your WordPress site. Can notify about core, plugin and theme updates.
 * Contributors: l3rady, eherman24, alleyinteractive
 * Version: 1.5.0
 * Author: Scott Cariss
 * Author URI: http://l3rady.com/
 * Text Domain: wp-updates-notifier
 * Domain Path: /languages
 * License: GPL3+
*/

/*
	Copyright 2015  Scott Cariss  (email : scott@l3rady.com)

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

// Only load class if it hasn't already been loaded
if ( ! class_exists( 'SC_WP_Updates_Notifier' ) ) {

	/**
	 * WP Updates Notifier - All the magic happens here!
	 */
	class SC_WP_Updates_Notifier {
		const OPT_FIELD         = 'sc_wpun_settings';
		const OPT_VERSION_FIELD = 'sc_wpun_settings_ver';
		const OPT_VERSION       = '5.0';
		const CRON_NAME         = 'sc_wpun_update_check';

		public static $did_init = false;

		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'run_init' ) );
		}

		public function run_init() {
			if ( ! self::$did_init ) {
				$this->init();
				self::$did_init = true;
			}
		}

		private function init() {
			// Check settings are up to date
			$this->settings_up_to_date();
			// Create Activation and Deactivation Hooks
			register_activation_hook( __FILE__, array( $this, 'activate' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
			// Internationalization
			load_plugin_textdomain( 'wp-updates-notifier', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
			// Add Filters
			add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 ); // Add settings link to plugin in plugin list
			add_filter( 'sc_wpun_plugins_need_update', array( $this, 'check_plugins_against_notified' ) ); // Filter out plugins that need update if already been notified
			add_filter( 'sc_wpun_themes_need_update', array( $this, 'check_themes_against_notified' ) ); // Filter out themes that need update if already been notified
			add_filter( 'auto_core_update_email', array( $this, 'filter_auto_core_update_email' ), 1 ); // Filter the background update notification email.
			// Add Actions
			add_action( 'admin_menu', array( $this, 'admin_settings_menu' ) ); // Add menu to options
			add_action( 'admin_init', array( $this, 'admin_settings_init' ) ); // Add admin init functions
			add_action( 'admin_init', array( $this, 'remove_update_nag_for_nonadmins' ) ); // See if we remove update nag for non admins
			add_action( 'admin_init', array( $this, 'admin_register_scripts_styles' ) );
			add_action( 'sc_wpun_enable_cron', array( $this, 'enable_cron' ) ); // action to enable cron
			add_action( 'sc_wpun_disable_cron', array( $this, 'disable_cron' ) ); // action to disable cron
			add_action( self::CRON_NAME, array( $this, 'do_update_check' ) ); // action to link cron task to actual task
			add_action( 'wp_ajax_sc_wpun_check', array( $this, 'sc_wpun_check' ) ); // Admin ajax hook for remote cron method.
			add_action( 'wp_ajax_nopriv_sc_wpun_check', array( $this, 'sc_wpun_check' ) ); // Admin ajax hook for remote cron method.
		}

		/**
		 * Check if this plugin settings are up to date. Firstly check the version in
		 * the DB. If they don't match then load in defaults but don't override values
		 * already set. Also this will remove obsolete settings that are not needed.
		 *
		 * @return void
		 */
		private function settings_up_to_date() {
			$current_ver = $this->get_set_options( self::OPT_VERSION_FIELD ); // Get current plugin version
			if ( self::OPT_VERSION !== $current_ver ) { // is the version the same as this plugin?
				$options  = (array) get_option( self::OPT_FIELD ); // get current settings from DB
				$defaults = array( // Here are our default values for this plugin
					'cron_method'      => 'wordpress', // Cron method to be used for scheduling scans
					'frequency'        => 'hourly',
					'notify_to'        => get_option( 'admin_email' ),
					'notify_from'      => get_option( 'admin_email' ),
					'notify_plugins'   => 1,
					'notify_themes'    => 1,
					'notify_automatic' => 1,
					'hide_updates'     => 1,
					'notified'         => array(
						'core'   => '',
						'plugin' => array(),
						'theme'  => array(),
					),
					'security_key'     => sha1( microtime( true ) . wp_rand( 10000, 90000 ) ), // Generate a random key to be used for Other Cron Method,
					'last_check_time'  => false,
				);
				// Intersect current options with defaults. Basically removing settings that are obsolete
				$options = array_intersect_key( $options, $defaults );
				// Merge current settings with defaults. Basically adding any new settings with defaults that we dont have.
				$options = array_merge( $defaults, $options );
				$this->get_set_options( self::OPT_FIELD, $options ); // update settings
				$this->get_set_options( self::OPT_VERSION_FIELD, self::OPT_VERSION ); // update settings version
			}
		}


		/**
		 * Filter for when getting or settings this plugins settings
		 *
		 * @param string     $field    Option field name of where we are getting or setting plugin settings.
		 * @param bool|mixed $settings False if getting settings else an array with settings you are saving.
		 *
		 * @return bool|mixed True or false if setting or an array of settings if getting
		 */
		private function get_set_options( $field, $settings = false ) {
			if ( false === $settings ) {
				return apply_filters( 'sc_wpun_get_options_filter', get_option( $field ), $field );
			}

			return update_option( $field, apply_filters( 'sc_wpun_put_options_filter', $settings, $field ) );
		}


		/**
		 * Function that deals with activation of this plugin
		 *
		 * @return void
		 */
		public function activate() {
			do_action( 'sc_wpun_enable_cron' ); // Enable cron
		}


		/**
		 * Function that deals with de-activation of this plugin
		 *
		 * @return void
		 */
		public function deactivate() {
			do_action( 'sc_wpun_disable_cron' ); // Disable cron
		}


		/**
		 * Enable cron for this plugin. Check if a cron should be scheduled.
		 *
		 * @param bool|string $manual_interval For setting a manual cron interval.
		 *
		 * @return void
		 */
		public function enable_cron( $manual_interval = false ) {
			$options          = $this->get_set_options( self::OPT_FIELD ); // Get settings
			$current_schedule = wp_get_schedule( self::CRON_NAME ); // find if a schedule already exists

			// if a manual cron interval is set, use this
			if ( false !== $manual_interval ) {
				$options['frequency'] = $manual_interval;
			}

			if ( 'manual' === $options['frequency'] ) {
				do_action( 'sc_wpun_disable_cron' ); // Make sure no cron is setup as we are manual
			} else {
				// check if the current schedule matches the one set in settings
				if ( $current_schedule === $options['frequency'] ) {
					return;
				}

				// check the cron setting is valid
				if ( ! in_array( $options['frequency'], $this->get_intervals(), true ) ) {
					return;
				}

				// Remove any cron's for this plugin first so we don't end up with multiple cron's doing the same thing.
				do_action( 'sc_wpun_disable_cron' );

				// Schedule cron for this plugin.
				wp_schedule_event( time(), $options['frequency'], self::CRON_NAME );
			}
		}


		/**
		 * Removes cron for this plugin.
		 *
		 * @return void
		 */
		public function disable_cron() {
			wp_clear_scheduled_hook( self::CRON_NAME ); // clear cron
		}


		/**
		 * Adds the settings link under the plugin on the plugin screen.
		 *
		 * @param array  $links Links to list on the plugin screen.
		 * @param string $file Filename.
		 *
		 * @return array $links
		 */
		public function plugin_action_links( $links, $file ) {
			if ( plugin_basename( __FILE__ ) === $file ) {
				$settings_link = '<a href="' . admin_url( 'options-general.php?page=wp-updates-notifier' ) . '">' . __( 'Settings', 'wp-updates-notifier' ) . '</a>';
				array_unshift( $links, $settings_link );
			}
			return $links;
		}


		/**
		 * This is run by the cron. The update check checks the core always, the
		 * plugins and themes if asked. If updates found email notification sent.
		 *
		 * @return void
		 */
		public function do_update_check() {
			$options      = $this->get_set_options( self::OPT_FIELD ); // get settings
			$message      = ''; // start with a blank message
			$core_updated = $this->core_update_check( $message ); // check the WP core for updates
			if ( 0 !== $options['notify_plugins'] ) { // are we to check for plugin updates?
				$plugins_updated = $this->plugins_update_check( $message, $options['notify_plugins'] ); // check for plugin updates
			} else {
				$plugins_updated = false; // no plugin updates
			}
			if ( 0 !== $options['notify_themes'] ) { // are we to check for theme updates?
				$themes_updated = $this->themes_update_check( $message, $options['notify_themes'] ); // check for theme updates
			} else {
				$themes_updated = false; // no theme updates
			}
			if ( $core_updated || $plugins_updated || $themes_updated ) { // Did anything come back as need updating?
				$message  = __( 'There are updates available for your WordPress site:', 'wp-updates-notifier' ) . "\n" . $message . "\n";
				$message .= sprintf( __( 'Please visit %s to update.', 'wp-updates-notifier' ), admin_url( 'update-core.php' ) );
				$this->send_notification_email( $message ); // send our notification email.
			}

			$this->log_last_check_time();
		}


		/**
		 * Checks to see if any WP core updates
		 *
		 * @param string $message holds message to be sent via notification.
		 *
		 * @return bool
		 */
		private function core_update_check( &$message ) {
			global $wp_version;
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings
			do_action( 'wp_version_check' ); // force WP to check its core for updates
			$update_core = get_site_transient( 'update_core' ); // get information of updates
			if ( 'upgrade' === $update_core->updates[0]->response ) { // is WP core update available?
				if ( $update_core->updates[0]->current !== $settings['notified']['core'] ) { // have we already notified about this version?
					require_once ABSPATH . WPINC . '/version.php'; // Including this because some plugins can mess with the real version stored in the DB.
					$new_core_ver                 = $update_core->updates[0]->current; // The new WP core version
					$old_core_ver                 = $wp_version; // the old WP core version
					$message                     .= "\n" . sprintf( __( 'WP-Core: WordPress is out of date. Please update from version %1$s to %2$s', 'wp-updates-notifier' ), $old_core_ver, $new_core_ver ) . "\n";
					$settings['notified']['core'] = $new_core_ver; // set core version we are notifying about
					$this->get_set_options( self::OPT_FIELD, $settings ); // update settings
					return true; // we have updates so return true
				} else {
					return false; // There are updates but we have already notified in the past.
				}
			}
			$settings['notified']['core'] = ''; // no updates lets set this nothing
			$this->get_set_options( self::OPT_FIELD, $settings ); // update settings
			return false; // no updates return false
		}


		/**
		 * Check to see if any plugin updates.
		 *
		 * @param string $message     Holds message to be sent via notification.
		 * @param int    $all_or_active Should we look for all plugins or just active ones.
		 *
		 * @return bool
		 */
		private function plugins_update_check( &$message, $all_or_active ) {
			global $wp_version;
			$cur_wp_version = preg_replace( '/-.*$/', '', $wp_version );
			$settings       = $this->get_set_options( self::OPT_FIELD ); // get settings
			do_action( 'wp_update_plugins' ); // force WP to check plugins for updates
			$update_plugins = get_site_transient( 'update_plugins' ); // get information of updates
			if ( ! empty( $update_plugins->response ) ) { // any plugin updates available?
				$plugins_need_update = $update_plugins->response; // plugins that need updating
				if ( 2 === $all_or_active ) { // are we to check just active plugins?
					$active_plugins      = array_flip( get_option( 'active_plugins' ) ); // find which plugins are active
					$plugins_need_update = array_intersect_key( $plugins_need_update, $active_plugins ); // only keep plugins that are active
				}
				$plugins_need_update = apply_filters( 'sc_wpun_plugins_need_update', $plugins_need_update ); // additional filtering of plugins need update
				if ( count( $plugins_need_update ) >= 1 ) { // any plugins need updating after all the filtering gone on above?
					require_once ABSPATH . 'wp-admin/includes/plugin-install.php'; // Required for plugin API
					require_once ABSPATH . WPINC . '/version.php'; // Required for WP core version
					foreach ( $plugins_need_update as $key => $data ) { // loop through the plugins that need updating
						$plugin_info = get_plugin_data( WP_PLUGIN_DIR . '/' . $key ); // get local plugin info
						$info        = plugins_api( 'plugin_information', array( 'slug' => $data->slug ) ); // get repository plugin info
						$message    .= "\n" . sprintf( __( 'Plugin: %1$s is out of date. Please update from version %2$s to %3$s', 'wp-updates-notifier' ), $plugin_info['Name'], $plugin_info['Version'], $data->new_version ) . "\n";
						$message    .= "\t" . sprintf( __( 'Details: %s', 'wp-updates-notifier' ), $data->url ) . "\n";
						$message    .= "\t" . sprintf( __( 'Changelog: %1$s%2$s', 'wp-updates-notifier' ), $data->url, 'changelog/' ) . "\n";
						if ( isset( $info->tested ) && version_compare( $info->tested, $wp_version, '>=' ) ) {
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: 100%% (according to its author)', 'wp-updates-notifier' ), $cur_wp_version );
						} elseif ( isset( $info->compatibility[ $wp_version ][ $data->new_version ] ) ) {
							$compat = $info->compatibility[ $wp_version ][ $data->new_version ];
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: %2$d%% (%3$d "works" votes out of %4$d total)', 'wp-updates-notifier' ), $wp_version, $compat[0], $compat[2], $compat[1] );
						} else {
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: Unknown', 'wp-updates-notifier' ), $wp_version );
						}
						$message                               .= "\t" . sprintf( __( 'Compatibility: %s', 'wp-updates-notifier' ), $compat ) . "\n";
						$settings['notified']['plugin'][ $key ] = $data->new_version; // set plugin version we are notifying about
					}
					$this->get_set_options( self::OPT_FIELD, $settings ); // save settings
					return true; // we have plugin updates return true
				}
			} else {
				if ( 0 !== count( $settings['notified']['plugin'] ) ) { // is there any plugin notifications?
					$settings['notified']['plugin'] = array(); // set plugin notifications to empty as all plugins up-to-date
					$this->get_set_options( self::OPT_FIELD, $settings ); // save settings
				}
			}
			return false; // No plugin updates so return false
		}


		/**
		 * Check to see if any theme updates.
		 *
		 * @param string $message     Holds message to be sent via notification.
		 * @param int    $all_or_active Should we look for all themes or just active ones.
		 *
		 * @return bool
		 */
		private function themes_update_check( &$message, $all_or_active ) {
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings
			do_action( 'wp_update_themes' ); // force WP to check for theme updates
			$update_themes = get_site_transient( 'update_themes' ); // get information of updates
			if ( ! empty( $update_themes->response ) ) { // any theme updates available?
				$themes_need_update = $update_themes->response; // themes that need updating
				if ( 2 === $all_or_active ) { // are we to check just active themes?
					$active_theme       = array( get_option( 'template' ) => array() ); // find current theme that is active
					$themes_need_update = array_intersect_key( $themes_need_update, $active_theme ); // only keep theme that is active
				}
				$themes_need_update = apply_filters( 'sc_wpun_themes_need_update', $themes_need_update ); // additional filtering of themes need update
				if ( count( $themes_need_update ) >= 1 ) { // any themes need updating after all the filtering gone on above?
					foreach ( $themes_need_update as $key => $data ) { // loop through the themes that need updating
						$theme_info                            = wp_get_theme( $key ); // get theme info
						$message                              .= "\n" . sprintf( __( 'Theme: %1$s is out of date. Please update from version %2$s to %3$s', 'wp-updates-notifier' ), $theme_info['Name'], $theme_info['Version'], $data['new_version'] ) . "\n";
						$settings['notified']['theme'][ $key ] = $data['new_version']; // set theme version we are notifying about
					}
					$this->get_set_options( self::OPT_FIELD, $settings ); // save settings
					return true; // we have theme updates return true
				}
			} else {
				if ( 0 !== count( $settings['notified']['theme'] ) ) { // is there any theme notifications?
					$settings['notified']['theme'] = array(); // set theme notifications to empty as all themes up-to-date
					$this->get_set_options( self::OPT_FIELD, $settings ); // save settings
				}
			}
			return false; // No theme updates so return false
		}


		/**
		 * Filter for removing plugins from update list if already been notified about
		 *
		 * @param array $plugins_need_update Array of plugins that need an update.
		 *
		 * @return array $plugins_need_update
		 */
		public function check_plugins_against_notified( $plugins_need_update ) {
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings
			foreach ( $plugins_need_update as $key => $data ) { // loop through plugins that need update
				if ( isset( $settings['notified']['plugin'][ $key ] ) ) { // has this plugin been notified before?
					if ( $data->new_version === $settings['notified']['plugin'][ $key ] ) { // does this plugin version match that of the one that's been notified?
						unset( $plugins_need_update[ $key ] ); // don't notify this plugin as has already been notified
					}
				}
			}
			return $plugins_need_update;
		}


		/**
		 * Filter for removing themes from update list if already been notified about
		 *
		 * @param array $themes_need_update Array of themes that need an update.
		 *
		 * @return array $themes_need_update
		 */
		public function check_themes_against_notified( $themes_need_update ) {
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings
			foreach ( $themes_need_update as $key => $data ) { // loop through themes that need update
				if ( isset( $settings['notified']['theme'][ $key ] ) ) { // has this theme been notified before?
					if ( $data['new_version'] === $settings['notified']['theme'][ $key ] ) { // does this theme version match that of the one that's been notified?
						unset( $themes_need_update[ $key ] ); // don't notify this theme as has already been notified
					}
				}
			}
			return $themes_need_update;
		}


		/**
		 * Sends email notification.
		 *
		 * @param string $message Holds message to be sent in body of email.
		 *
		 * @return void
		 */
		public function send_notification_email( $message ) {
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings
			$subject  = sprintf( __( 'WP Updates Notifier: Updates Available @ %s', 'wp-updates-notifier' ), home_url() );
			add_filter( 'wp_mail_from', array( $this, 'sc_wpun_wp_mail_from' ) ); // add from filter
			add_filter( 'wp_mail_from_name', array( $this, 'sc_wpun_wp_mail_from_name' ) ); // add from name filter
			add_filter( 'wp_mail_content_type', array( $this, 'sc_wpun_wp_mail_content_type' ) ); // add content type filter
			// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			wp_mail( $settings['notify_to'], apply_filters( 'sc_wpun_email_subject', $subject ), apply_filters( 'sc_wpun_email_content', $message ) ); // send email
			// phpcs:enable
			remove_filter( 'wp_mail_from', array( $this, 'sc_wpun_wp_mail_from' ) ); // remove from filter
			remove_filter( 'wp_mail_from_name', array( $this, 'sc_wpun_wp_mail_from_name' ) ); // remove from name filter
			remove_filter( 'wp_mail_content_type', array( $this, 'sc_wpun_wp_mail_content_type' ) ); // remove content type filter
		}

		public function sc_wpun_wp_mail_from() {
			$settings = $this->get_set_options( self::OPT_FIELD );
			return $settings['notify_from'];
		}

		public function sc_wpun_wp_mail_from_name() {
			return __( 'WP Updates Notifier', 'wp-updates-notifier' );
		}

		public function sc_wpun_wp_mail_content_type() {
			return 'text/plain';
		}


		private function log_last_check_time() {
			$options                    = $this->get_set_options( self::OPT_FIELD );
			$options['last_check_time'] = time();
			$this->get_set_options( self::OPT_FIELD, $options );
		}

		/**
		 * Filter the background update notification email
		 *
		 * @param array $email Array of email arguments that will be passed to wp_mail().
		 *
		 * @return array Modified array containing the new email address.
		 */
		public function filter_auto_core_update_email( $email ) {
			$options = $this->get_set_options( self::OPT_FIELD ); // Get settings

			if ( 0 !== $options['notify_automatic'] ) {
				if ( ! empty( $options['notify_to'] ) ) { // If an email address has been set, override the WordPress default.
					$email['to'] = $options['notify_to'];
				}

				if ( ! empty( $options['notify_from'] ) ) { // If an email address has been set, override the WordPress default.
					$email['headers'][] = 'From: ' . $this->sc_wpun_wp_mail_from_name() . ' <' . $options['notify_from'] . '>';
				}
			}

			return $email;
		}


		/**
		 * Removes the update nag for non admin users.
		 *
		 * @return void
		 */
		public function remove_update_nag_for_nonadmins() {
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings
			if ( 1 === $settings['hide_updates'] ) { // is this enabled?
				if ( ! current_user_can( 'update_plugins' ) ) { // can the current user update plugins?
					remove_action( 'admin_notices', 'update_nag', 3 ); // no they cannot so remove the nag for them.
				}
			}
		}


		/**
		 * Adds JS to admin settings screen for this plugin
		 */
		public function admin_register_scripts_styles() {
			wp_register_script( 'wp_updates_monitor_js_function', plugins_url( 'js/function.js', __FILE__ ), array( 'jquery' ), '1.0', true );
		}


		public function sc_wpun_check() {
			$options = $this->get_set_options( self::OPT_FIELD ); // get settings

			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_GET['sc_wpun_key'] ) || $options['security_key'] !== $_GET['sc_wpun_key'] || 'other' !== $options['cron_method'] ) {
				return;
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$this->do_update_check();

			die( esc_html__( 'Successfully checked for updates.', 'wp-updates-notifier' ) );
		}


		private function get_schedules() {
			$schedules = wp_get_schedules();
			uasort( $schedules, array( $this, 'sort_by_interval' ) );
			return $schedules;
		}


		private function get_intervals() {
			$intervals   = array_keys( $this->get_schedules() );
			$intervals[] = 'manual';
			return $intervals;
		}


		private function sort_by_interval( $a, $b ) {
			return $a['interval'] - $b['interval'];
		}


		/**
		 * EVERYTHING SETTINGS
		 *
		 * I'm not going to comment any of this as its all pretty
		 * much straight forward use of the WordPress Settings API.
		 */
		public function admin_settings_menu() {
			$page = add_options_page( 'Updates Notifier', 'Updates Notifier', 'manage_options', 'wp-updates-notifier', array( $this, 'settings_page' ) );
			add_action( "admin_print_scripts-{$page}", array( $this, 'enqueue_plugin_script' ) );
		}

		public function enqueue_plugin_script() {
			wp_enqueue_script( 'wp_updates_monitor_js_function' );
		}

		public function settings_page() {
			$options     = $this->get_set_options( self::OPT_FIELD );
			$date_format = get_option( 'date_format' );
			$time_format = get_option( 'time_format' );
			?>
			<div class="wrap">
				<h2><?php esc_html_e( 'Updates Notifier', 'wp-updates-notifier' ); ?></h2>

				<p>
					<span class="description">
					<?php
					if ( false === $options['last_check_time'] ) {
						$scan_date = __( 'Never', 'wp-updates-notifier' );
					} else {
						$scan_date = sprintf(
							__( '%1$1s @ %2$2s', 'wp-updates-notifier' ),
							gmdate( $date_format, $options['last_check_time'] ),
							gmdate( $time_format, $options['last_check_time'] )
						);
					}

					esc_html( __( 'Last scanned: ', 'wp-updates-notifier' ) . $scan_date );
					?>
					</span>
				</p>

				<form action="<?php echo esc_attr( admin_url( 'options.php' ) ); ?>" method="post">
					<?php
					settings_fields( 'sc_wpun_settings' );
					do_settings_sections( 'wp-updates-notifier' );
					?>
					<p>&nbsp;</p>
					<input class="button-primary" name="Submit" type="submit" value="<?php esc_html_e( 'Save settings', 'wp-updates-notifier' ); ?>" />
					<input class="button" name="submitwithemail" type="submit" value="<?php esc_html_e( 'Save settings with test email', 'wp-updates-notifier' ); ?>" />
				</form>
			</div>
			<?php
		}

		public function admin_settings_init() {
			register_setting( self::OPT_FIELD, self::OPT_FIELD, array( $this, 'sc_wpun_settings_validate' ) ); // Register Main Settings
			add_settings_section( 'sc_wpun_settings_main', __( 'Settings', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_text' ), 'wp-updates-notifier' ); // Make settings main section
			add_settings_field( 'sc_wpun_settings_main_cron_method', __( 'Cron Method', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_cron_method' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_frequency', __( 'Frequency to check', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_frequency' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_to', __( 'Notify email to', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_to' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_from', __( 'Notify email from', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_from' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_plugins', __( 'Notify about plugin updates?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_plugins' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_themes', __( 'Notify about theme updates?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_themes' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_automatic', __( 'Notify automatic core updates to this address?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_automatic' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_hide_updates', __( 'Hide core WP update nag from non-admin users?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_hide_updates' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
		}

		public function sc_wpun_settings_validate( $input ) {
			check_admin_referer( 'sc_wpun_settings-options' );
			$valid = $this->get_set_options( self::OPT_FIELD );

			if ( isset( $input['cron_method'] ) && in_array( $input['cron_method'], array( 'wordpress', 'other' ), true ) ) {
				$valid['cron_method'] = $input['cron_method'];
			} else {
				add_settings_error( 'sc_wpun_settings_main_cron_method', 'sc_wpun_settings_main_cron_method_error', __( 'Invalid cron method selected', 'wp-updates-notifier' ), 'error' );
			}

			if ( 'other' === $valid['cron_method'] ) {
				$input['frequency'] = 'manual';
			}

			if ( in_array( $input['frequency'], $this->get_intervals(), true ) ) {
				$valid['frequency'] = $input['frequency'];
				do_action( 'sc_wpun_enable_cron', $input['frequency'] );
			} else {
				add_settings_error( 'sc_wpun_settings_main_frequency', 'sc_wpun_settings_main_frequency_error', __( 'Invalid frequency entered', 'wp-updates-notifier' ), 'error' );
			}

			$emails_to = explode( ',', $input['notify_to'] );
			if ( $emails_to ) {
				$sanitized_emails = array();
				$was_error        = false;
				foreach ( $emails_to as $email_to ) {
					$address = sanitize_email( trim( $email_to ) );
					if ( ! is_email( $address ) ) {
						add_settings_error( 'sc_wpun_settings_main_notify_to', 'sc_wpun_settings_main_notify_to_error', __( 'One or more email to addresses are invalid', 'wp-updates-notifier' ), 'error' );
						$was_error = true;
						break;
					}
					$sanitized_emails[] = $address;
				}
				if ( ! $was_error ) {
					$valid['notify_to'] = implode( ',', $sanitized_emails );
				}
			} else {
				add_settings_error( 'sc_wpun_settings_main_notify_to', 'sc_wpun_settings_main_notify_to_error', __( 'No email to address entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_email_from = sanitize_email( $input['notify_from'] );
			if ( is_email( $sanitized_email_from ) ) {
				$valid['notify_from'] = $sanitized_email_from;
			} else {
				add_settings_error( 'sc_wpun_settings_main_notify_from', 'sc_wpun_settings_main_notify_from_error', __( 'Invalid email from entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_notify_plugins = absint( isset( $input['notify_plugins'] ) ? $input['notify_plugins'] : 0 );
			if ( $sanitized_notify_plugins >= 0 && $sanitized_notify_plugins <= 2 ) {
				$valid['notify_plugins'] = $sanitized_notify_plugins;
			} else {
				add_settings_error( 'sc_wpun_settings_main_notify_plugins', 'sc_wpun_settings_main_notify_plugins_error', __( 'Invalid plugin updates value entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_notify_themes = absint( isset( $input['notify_themes'] ) ? $input['notify_themes'] : 0 );
			if ( $sanitized_notify_themes >= 0 && $sanitized_notify_themes <= 2 ) {
				$valid['notify_themes'] = $sanitized_notify_themes;
			} else {
				add_settings_error( 'sc_wpun_settings_main_notify_themes', 'sc_wpun_settings_main_notify_themes_error', __( 'Invalid theme updates value entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_notify_automatic = absint( isset( $input['notify_automatic'] ) ? $input['notify_automatic'] : 0 );
			if ( $sanitized_notify_automatic >= 0 && $sanitized_notify_automatic <= 1 ) {
				$valid['notify_automatic'] = $sanitized_notify_automatic;
			} else {
				add_settings_error( 'sc_wpun_settings_main_notify_automatic', 'sc_wpun_settings_main_notify_automatic_error', __( 'Invalid automatic updates value entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_hide_updates = absint( isset( $input['hide_updates'] ) ? $input['hide_updates'] : 0 );
			if ( $sanitized_hide_updates <= 1 ) {
				$valid['hide_updates'] = $sanitized_hide_updates;
			} else {
				add_settings_error( 'sc_wpun_settings_main_hide_updates', 'sc_wpun_settings_main_hide_updates_error', __( 'Invalid hide updates value entered', 'wp-updates-notifier' ), 'error' );
			}

			if ( isset( $_POST['submitwithemail'] ) ) {
				add_filter( 'pre_set_transient_settings_errors', array( $this, 'send_test_email' ) );
			}

			if ( isset( $input['cron_method'] ) && in_array( $input['cron_method'], array( 'wordpress', 'other' ), true ) ) {
				$valid['cron_method'] = $input['cron_method'];
			} else {
				add_settings_error( 'sc_wpun_settings_main_cron_method', 'sc_wpun_settings_main_cron_method_error', __( 'Invalid cron method selected', 'wp-updates-notifier' ), 'error' );
			}

			return $valid;
		}

		public function send_test_email( $settings_errors ) {
			if ( isset( $settings_errors[0]['type'] ) && 'updated' === $settings_errors[0]['type'] ) {
				$this->send_notification_email( __( 'This is a test message from WP Updates Notifier.', 'wp-updates-notifier' ) );
			}
			return $settings_errors;
		}

		public function sc_wpun_settings_main_text() {
		}

		public function sc_wpun_settings_main_field_cron_method() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<select name="<?php echo esc_attr( self::OPT_FIELD ); ?>[cron_method]">
				<option value="wordpress" <?php selected( $options['cron_method'], 'WordPress' ); ?>><?php esc_html_e( 'WordPress Cron', 'wp-updates-notifier' ); ?></option>
				<option value="other" <?php selected( $options['cron_method'], 'other' ); ?>><?php esc_html_e( 'Other Cron', 'wp-updates-notifier' ); ?></option>
			</select>
			<div>
				<br />
				<span class="description"><?php esc_html_e( 'Cron Command: ', 'wp-updates-notifier' ); ?></span>
				<pre>wget -q "<?php echo esc_attr( admin_url( '/admin-ajax.php?action=sc_wpun_check&sc_wpun_key=' . $options['security_key'] ) ); ?>" -O /dev/null >/dev/null 2>&amp;1</pre>
			</div>
			<?php
		}

		public function sc_wpun_settings_main_field_frequency() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<select id="sc_wpun_settings_main_frequency" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[frequency]">
			<?php foreach ( $this->get_schedules() as $k => $v ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $options['frequency'], $k ); ?>><?php echo esc_html( $v['display'] ); ?></option>
			<?php endforeach; ?>
			<select>
			<?php
		}

		public function sc_wpun_settings_main_field_notify_to() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<input id="sc_wpun_settings_main_notify_to" class="regular-text" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_to]" value="<?php echo esc_attr( $options['notify_to'] ); ?>" />
			<span class="description"><?php esc_html_e( 'Separate multiple email address with a comma (,)', 'wp-updates-notifier' ); ?></span>
												<?php
		}

		public function sc_wpun_settings_main_field_notify_from() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<input id="sc_wpun_settings_main_notify_from" class="regular-text" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_from]" value="<?php echo esc_attr( $options['notify_from'] ); ?>" />
																								<?php
		}

		public function sc_wpun_settings_main_field_notify_plugins() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="0" <?php checked( $options['notify_plugins'], 0 ); ?> /> <?php esc_html_e( 'No', 'wp-updates-notifier' ); ?>
			</label><br />
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="1" <?php checked( $options['notify_plugins'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
			</label><br />
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="2" <?php checked( $options['notify_plugins'], 2 ); ?> /> <?php esc_html_e( 'Yes but only active plugins', 'wp-updates-notifier' ); ?>
			</label>
			<?php
		}

		public function sc_wpun_settings_main_field_notify_themes() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_themes]" type="radio" value="0" <?php checked( $options['notify_themes'], 0 ); ?> /> <?php esc_html_e( 'No', 'wp-updates-notifier' ); ?>
			</label><br />
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_themes]" type="radio" value="1" <?php checked( $options['notify_themes'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
			</label><br />
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_themes]" type="radio" value="2" <?php checked( $options['notify_themes'], 2 ); ?> /> <?php esc_html_e( 'Yes but only active themes', 'wp-updates-notifier' ); ?>
			</label>
			<?php
		}

		public function sc_wpun_settings_main_field_notify_automatic() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_automatic]" type="checkbox" value="1" <?php checked( $options['notify_automatic'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
			</label>
			<?php
		}

		public function sc_wpun_settings_main_field_hide_updates() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<select id="sc_wpun_settings_main_hide_updates" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[hide_updates]">
				<option value="1" <?php selected( $options['hide_updates'], 1 ); ?>><?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?></option>
				<option value="0" <?php selected( $options['hide_updates'], 0 ); ?>><?php esc_html_e( 'No', 'wp-updates-notifier' ); ?></option>
			</select>
			<?php
		}
		/**** END EVERYTHING SETTINGS */
	}
}

new SC_WP_Updates_Notifier();