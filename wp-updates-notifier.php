<?php
/*
Plugin Name: WP Updates Notifier
Plugin URI: http://l3rady.com/projects/wp-updates-notifier/
Description: Sends email to notify you if there are any updates for your WordPress site. Can notify about core, plugin and theme updates.
Author: Scott Cariss
Version: 1.4
Author URI: http://l3rady.com/
Text Domain: wp-updates-notifier
Domain Path: /languages
*/

/*  Copyright 2013  Scott Cariss  (email : scott@l3rady.com)

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
if ( !class_exists( 'sc_WPUpdatesNotifier' ) ) {

	// WP Updates Notifier - All the magic happens here!
	class sc_WPUpdatesNotifier {
		protected static $options_field = "sc_wpun_settings";
		protected static $options_field_ver = "sc_wpun_settings_ver";
		protected static $options_field_current_ver = "2.0";
		protected static $cron_name = "sc_wpun_update_check";
		protected static $frequency_intervals = array( "hourly", "twicedaily", "daily" );

		function __construct() {
			// Check settings are up to date
			self::settingsUpToDate();
			// Create Activation and Deactivation Hooks
			register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
			register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
			// Internationalization
			load_plugin_textdomain( 'wp-updates-notifier', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
			// Add Filters
			add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 2 ); // Add settings link to plugin in plugin list
			add_filter( 'sc_wpun_plugins_need_update', array( __CLASS__, 'check_plugins_against_notified' ) ); // Filter out plugins that need update if already been notified
			add_filter( 'sc_wpun_themes_need_update', array( __CLASS__, 'check_themes_against_notified' ) ); // Filter out themes that need update if already been notified
			// Add Actions
			add_action( 'admin_menu', array( __CLASS__, 'admin_settings_menu' ) ); // Add menu to options
			add_action( 'admin_init', array( __CLASS__, 'admin_settings_init' ) ); // Add admin init functions
			add_action( 'admin_init', array( __CLASS__, 'remove_update_nag_for_nonadmins' ) ); // See if we remove update nag for non admins
			add_action( 'admin_init', array( __CLASS__, 'admin_register_scripts_styles' ) );
			add_action( 'sc_wpun_enable_cron', array( __CLASS__, 'enable_cron' ) ); // action to enable cron
			add_action( 'sc_wpun_disable_cron', array( __CLASS__, 'disable_cron' ) ); // action to disable cron
			add_action( self::$cron_name, array( __CLASS__, 'do_update_check' ) ); // action to link cron task to actual task
		}


		/**
		 * Check if this plugin settings are up to date. Firstly check the version in
		 * the DB. If they don't match then load in defaults but don't override values
		 * already set. Also this will remove obsolete settings that are not needed.
		 *
		 * @return void
		 */
		protected function settingsUpToDate() {
			$current_ver = get_option( self::$options_field_ver ); // Get current plugin version
			if ( self::$options_field_current_ver != $current_ver ) { // is the version the same as this plugin?
				$options  = (array) get_option( self::$options_field ); // get current settings from DB
				$defaults = array( // Here are our default values for this plugin
					'cron_method'    => 'wordpress', // Cron method to be used for scheduling scans
					'frequency'      => self::$frequency_intervals[0],
					'notify_to'      => get_option( 'admin_email' ),
					'notify_from'    => get_option( 'admin_email' ),
					'notify_plugins' => 1,
					'notify_themes'  => 1,
					'hide_updates'   => 1,
					'notified'       => array(
						'core'   => "",
						'plugin' => array(),
						'theme'  => array(),
					),
					'security_key'   => sha1( microtime( true ) . mt_rand( 10000, 90000 ) ), // Generate a random key to be used for Other Cron Method
				);
				// Intersect current options with defaults. Basically removing settings that are obsolete
				$options = array_intersect_key( $options, $defaults );
				// Merge current settings with defaults. Basically adding any new settings with defaults that we dont have.
				$options = array_merge( $defaults, $options );
				update_option( self::$options_field, $options ); // update settings
				update_option( self::$options_field_ver, self::$options_field_current_ver ); // update settings version
			}
		}


		/**
		 * Function that deals with activation of this plugin
		 *
		 * @return void
		 */
		public function activate() {
			do_action( "sc_wpun_enable_cron" ); // Enable cron
		}


		/**
		 * Function that deals with de-activation of this plugin
		 *
		 * @return void
		 */
		public function deactivate() {
			do_action( "sc_wpun_disable_cron" ); // Disable cron
		}


		/**
		 * Enable cron for this plugin. Check if a cron should be scheduled.
		 *
		 * @param bool|string $manual_interval For setting a manual cron interval.
		 *
		 * @return void
		 */
		public function enable_cron( $manual_interval = false ) {
			$options         = get_option( self::$options_field ); // get current settings
			$currentSchedule = wp_get_schedule( self::$cron_name ); // find if a schedule already exists
			if ( !empty( $manual_interval ) ) {
				$options['frequency'] = $manual_interval;
			} // if a manual cron interval is set, use this
			if ( $currentSchedule != $options['frequency'] ) { // check if the current schedule matches the one set in settings
				if ( in_array( $options['frequency'], self::$frequency_intervals ) ) { // check the cron setting is valid
					do_action( "sc_wpun_disable_cron" ); // remove any crons for this plugin first so we don't end up with multiple crons doing the same thing.
					wp_schedule_event( ( time() + 3600 ), $options['frequency'], self::$cron_name ); // schedule cron for this plugin.
				}
			}
		}


		/**
		 * Removes cron for this plugin.
		 *
		 * @return void
		 */
		public function disable_cron() {
			wp_clear_scheduled_hook( self::$cron_name ); // clear cron
		}


		/**
		 * Adds the settings link under the plugin on the plugin screen.
		 *
		 * @param array  $links
		 * @param string $file
		 *
		 * @return array $links
		 */
		public function plugin_action_links( $links, $file ) {
			static $this_plugin;
			if ( !$this_plugin ) {
				$this_plugin = plugin_basename( __FILE__ );
			}
			if ( $file == $this_plugin ) {
				$settings_link = '<a href="' . site_url() . '/wp-admin/options-general.php?page=wp-updates-notifier">' . __( "Settings", "wp-updates-notifier" ) . '</a>';
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
			$options      = get_option( self::$options_field ); // get settings
			$message      = ""; // start with a blank message
			$core_updated = self::core_update_check( $message ); // check the WP core for updates
			if ( 0 != $options['notify_plugins'] ) { // are we to check for plugin updates?
				$plugins_updated = self::plugins_update_check( $message, $options['notify_plugins'] ); // check for plugin updates
			}
			else {
				$plugins_updated = false; // no plugin updates
			}
			if ( 0 != $options['notify_themes'] ) { // are we to check for theme updates?
				$themes_updated = self::themes_update_check( $message, $options['notify_themes'] ); // check for theme updates
			}
			else {
				$themes_updated = false; // no theme updates
			}
			if ( $core_updated || $plugins_updated || $themes_updated ) { // Did anything come back as need updating?
				$message = __( "There are updates available for your WordPress site:", "wp-updates-notifier" ) . "\n" . $message . "\n";
				$message .= sprintf( __( "Please visit %s to update.", "wp-updates-notifier" ), admin_url( 'update-core.php' ) );
				self::send_notification_email( $message ); // send our notification email.
			}
		}


		/**
		 * Checks to see if any WP core updates
		 *
		 * @param string $message holds message to be sent via notification
		 *
		 * @return bool
		 */
		protected function core_update_check( &$message ) {
			global $wp_version;
			$settings = get_option( self::$options_field ); // get settings
			do_action( "wp_version_check" ); // force WP to check its core for updates
			$update_core = get_site_transient( "update_core" ); // get information of updates
			if ( 'upgrade' == $update_core->updates[0]->response ) { // is WP core update available?
				if ( $update_core->updates[0]->current != $settings['notified']['core'] ) { // have we already notified about this version?
					require_once( ABSPATH . WPINC . '/version.php' ); // Including this because some plugins can mess with the real version stored in the DB.
					$new_core_ver = $update_core->updates[0]->current; // The new WP core version
					$old_core_ver = $wp_version; // the old WP core version
					$message .= "\n" . sprintf( __( "WP-Core: WordPress is out of date. Please update from version %s to %s", "wp-updates-notifier" ), $old_core_ver, $new_core_ver ) . "\n";
					$settings['notified']['core'] = $new_core_ver; // set core version we are notifying about
					update_option( self::$options_field, $settings ); // update settings
					return true; // we have updates so return true
				}
				else {
					return false; // There are updates but we have already notified in the past.
				}
			}
			$settings['notified']['core'] = ""; // no updates lets set this nothing
			update_option( self::$options_field, $settings ); // update settings
			return false; // no updates return false
		}


		/**
		 * Check to see if any plugin updates.
		 *
		 * @param string $message     holds message to be sent via notification
		 * @param int    $allOrActive should we look for all plugins or just active ones
		 *
		 * @return bool
		 */
		protected function plugins_update_check( &$message, $allOrActive ) {
			global $wp_version;
			$cur_wp_version = preg_replace( '/-.*$/', '', $wp_version );
			$settings       = get_option( self::$options_field ); // get settings
			do_action( "wp_update_plugins" ); // force WP to check plugins for updates
			$update_plugins = get_site_transient( 'update_plugins' ); // get information of updates
			if ( !empty( $update_plugins->response ) ) { // any plugin updates available?
				$plugins_need_update = $update_plugins->response; // plugins that need updating
				if ( 2 == $allOrActive ) { // are we to check just active plugins?
					$active_plugins      = array_flip( get_option( 'active_plugins' ) ); // find which plugins are active
					$plugins_need_update = array_intersect_key( $plugins_need_update, $active_plugins ); // only keep plugins that are active
				}
				$plugins_need_update = apply_filters( 'sc_wpun_plugins_need_update', $plugins_need_update ); // additional filtering of plugins need update
				if ( count( $plugins_need_update ) >= 1 ) { // any plugins need updating after all the filtering gone on above?
					require_once( ABSPATH . 'wp-admin/includes/plugin-install.php' ); // Required for plugin API
					require_once( ABSPATH . WPINC . '/version.php' ); // Required for WP core version
					foreach ( $plugins_need_update as $key => $data ) { // loop through the plugins that need updating
						$plugin_info = get_plugin_data( WP_PLUGIN_DIR . "/" . $key ); // get local plugin info
						$info        = plugins_api( 'plugin_information', array( 'slug' => $data->slug ) ); // get repository plugin info
						$message .= "\n" . sprintf( __( "Plugin: %s is out of date. Please update from version %s to %s", "wp-updates-notifier" ), $plugin_info['Name'], $plugin_info['Version'], $data->new_version ) . "\n";
						$message .= "\t" . sprintf( __( "Details: %s", "wp-updates-notifier" ), $data->url ) . "\n";
						$message .= "\t" . sprintf( __( "Changelog: %s%s", "wp-updates-notifier" ), $data->url, "changelog/" ) . "\n";
						if ( isset( $info->tested ) && version_compare( $info->tested, $wp_version, '>=' ) ) {
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: 100%% (according to its author)' ), $cur_wp_version );
						}
						elseif ( isset( $info->compatibility[$wp_version][$data->new_version] ) ) {
							$compat = $info->compatibility[$wp_version][$data->new_version];
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: %2$d%% (%3$d "works" votes out of %4$d total)' ), $wp_version, $compat[0], $compat[2], $compat[1] );
						}
						else {
							$compat = sprintf( __( 'Compatibility with WordPress %1$s: Unknown' ), $wp_version );
						}
						$message .= "\t" . sprintf( __( "Compatibility: %s", "wp-updates-notifier" ), $compat ) . "\n";
						$settings['notified']['plugin'][$key] = $data->new_version; // set plugin version we are notifying about
					}
					update_option( self::$options_field, $settings ); // save settings
					return true; // we have plugin updates return true
				}
			}
			else {
				if ( 0 != count( $settings['notified']['plugin'] ) ) { // is there any plugin notifications?
					$settings['notified']['plugin'] = array(); // set plugin notifications to empty as all plugins up-to-date
					update_option( self::$options_field, $settings ); // save settings
				}
			}
			return false; // No plugin updates so return false
		}


		/**
		 * Check to see if any theme updates.
		 *
		 * @param string $message     holds message to be sent via notification
		 * @param int    $allOrActive should we look for all themes or just active ones
		 *
		 * @return bool
		 */
		protected function themes_update_check( &$message, $allOrActive ) {
			$settings = get_option( self::$options_field ); // get settings
			do_action( "wp_update_themes" ); // force WP to check for theme updates
			$update_themes = get_site_transient( 'update_themes' ); // get information of updates
			if ( !empty( $update_themes->response ) ) { // any theme updates available?
				$themes_need_update = $update_themes->response; // themes that need updating
				if ( 2 == $allOrActive ) { // are we to check just active themes?
					$active_theme       = array( get_option( 'template' ) => array() ); // find current theme that is active
					$themes_need_update = array_intersect_key( $themes_need_update, $active_theme ); // only keep theme that is active
				}
				$themes_need_update = apply_filters( 'sc_wpun_themes_need_update', $themes_need_update ); // additional filtering of themes need update
				if ( count( $themes_need_update ) >= 1 ) { // any themes need updating after all the filtering gone on above?
					foreach ( $themes_need_update as $key => $data ) { // loop through the themes that need updating
						$theme_info = get_theme_data( WP_CONTENT_DIR . "/themes/" . $key . "/style.css" ); // get theme info
						$message .= "\n" . sprintf( __( "Theme: %s is out of date. Please update from version %s to %s", "wp-updates-notifier" ), $theme_info['Name'], $theme_info['Version'], $data['new_version'] ) . "\n";
						$settings['notified']['theme'][$key] = $data['new_version']; // set theme version we are notifying about
					}
					update_option( self::$options_field, $settings ); // save settings
					return true; // we have theme updates return true
				}
			}
			else {
				if ( 0 != count( $settings['notified']['theme'] ) ) { // is there any theme notifications?
					$settings['notified']['theme'] = array(); // set theme notifications to empty as all themes up-to-date
					update_option( self::$options_field, $settings ); // save settings
				}
			}
			return false; // No theme updates so return false
		}


		/**
		 * Filter for removing plugins from update list if already been notified about
		 *
		 * @param array $plugins_need_update
		 *
		 * @return array $plugins_need_update
		 */
		public function check_plugins_against_notified( $plugins_need_update ) {
			$settings = get_option( self::$options_field ); // get settings
			foreach ( $plugins_need_update as $key => $data ) { // loop through plugins that need update
				if ( isset( $settings['notified']['plugin'][$key] ) ) { // has this plugin been notified before?
					if ( $data->new_version == $settings['notified']['plugin'][$key] ) { // does this plugin version match that of the one that's been notified?
						unset( $plugins_need_update[$key] ); // don't notify this plugin as has already been notified
					}
				}
			}
			return $plugins_need_update;
		}


		/**
		 * Filter for removing themes from update list if already been notified about
		 *
		 * @param array $themes_need_update
		 *
		 * @return array $themes_need_update
		 */
		public function check_themes_against_notified( $themes_need_update ) {
			$settings = get_option( self::$options_field ); // get settings
			foreach ( $themes_need_update as $key => $data ) { // loop through themes that need update
				if ( isset( $settings['notified']['theme'][$key] ) ) { // has this theme been notified before?
					if ( $data['new_version'] == $settings['notified']['theme'][$key] ) { // does this theme version match that of the one that's been notified?
						unset( $themes_need_update[$key] ); // don't notify this theme as has already been notified
					}
				}
			}
			return $themes_need_update;
		}


		/**
		 * Sends email notification.
		 *
		 * @param string $message holds message to be sent in body of email
		 *
		 * @return void
		 */
		public function send_notification_email( $message ) {
			$settings = get_option( self::$options_field ); // get settings
			$subject  = sprintf( __( "WP Updates Notifier: Updates Available @ %s", "wp-updates-notifier" ), site_url() );
			add_filter( 'wp_mail_from', array( __CLASS__, 'sc_wpun_wp_mail_from' ) ); // add from filter
			add_filter( 'wp_mail_from_name', array( __CLASS__, 'sc_wpun_wp_mail_from_name' ) ); // add from name filter
			add_filter( 'wp_mail_content_type', array( __CLASS__, 'sc_wpun_wp_mail_content_type' ) ); // add content type filter
			wp_mail( $settings['notify_to'], $subject, $message ); // send email
			remove_filter( 'wp_mail_from', array( __CLASS__, 'sc_wpun_wp_mail_from' ) ); // remove from filter
			remove_filter( 'wp_mail_from_name', array( __CLASS__, 'sc_wpun_wp_mail_from_name' ) ); // remove from name filter
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'sc_wpun_wp_mail_content_type' ) ); // remove content type filter
		}

		public function sc_wpun_wp_mail_from() {
			$settings = get_option( self::$options_field );
			return $settings['notify_from'];
		}

		public function sc_wpun_wp_mail_from_name() {
			return __( "WP Updates Notifier", "wp-updates-notifier" );
		}

		public function sc_wpun_wp_mail_content_type() {
			return "text/plain";
		}


		/**
		 * Removes the update nag for non admin users.
		 *
		 * @return void
		 */
		public function remove_update_nag_for_nonadmins() {
			$settings = get_option( self::$options_field ); // get settings
			if ( 1 == $settings['hide_updates'] ) { // is this enabled?
				if ( !current_user_can( 'update_plugins' ) ) { // can the current user update plugins?
					remove_action( 'admin_notices', 'update_nag', 3 ); // no they cannot so remove the nag for them.
				}
			}
		}


		static public function admin_register_scripts_styles()
		{
			wp_register_script( 'wp_updates_monitor_js_function', plugins_url( 'js/function.js', __FILE__ ), array( 'jquery' ), '1.0', true );
		}


		/*
		 * EVERYTHING SETTINGS
		 *
		 * I'm not going to comment any of this as its all pretty
		 * much straight forward use of the WordPress Settings API.
		 */
		public function admin_settings_menu() {
			$page = add_options_page( 'WP Updates Notifier', 'WP Updates Notifier', 'manage_options', 'wp-updates-notifier', array( __CLASS__, 'settings_page' ) );
			add_action( "admin_print_scripts-{$page}" , array( __CLASS__, 'enqueue_plugin_script' ) );
		}

		static public function enqueue_plugin_script() {
			wp_enqueue_script( 'wp_updates_monitor_js_function' );
		}

		public function settings_page() {
			?>
			<div class="wrap">
				<?php screen_icon(); ?>
				<h2><?php _e( "WP Updates Notifier", "wp-updates-notifier" ); ?></h2>

				<form action="options.php" method="post">
					<?php
					settings_fields( "sc_wpun_settings" );
					do_settings_sections( "wp-updates-notifier" );
					?>
					<p>&nbsp;</p>
					<input class="button-primary" name="Submit" type="submit" value="<?php _e( "Save settings", "wp-updates-notifier" ); ?>" />
					<input class="button" name="submitwithemail" type="submit" value="<?php _e( "Save settings with test email", "wp-updates-notifier" ); ?>" />
				</form>
			</div>
		<?php
		}

		public function admin_settings_init() {
			register_setting( self::$options_field, self::$options_field, array( __CLASS__, "sc_wpun_settings_validate" ) ); // Register Main Settings
			add_settings_section( "sc_wpun_settings_main", __( "Settings", "wp-updates-notifier" ), array( __CLASS__, "sc_wpun_settings_main_text" ), "wp-updates-notifier" ); // Make settings main section
			add_settings_field( "sc_wpun_settings_main_cron_method", __( "Cron Method", "wp-updates-notifier" ), array( __CLASS__, "sc_wpun_settings_main_field_cron_method" ), "wp-updates-notifier", "sc_wpun_settings_main" );
			add_settings_field( "sc_wpun_settings_main_frequency", __( "Frequency to check", "wp-updates-notifier" ), array( __CLASS__, "sc_wpun_settings_main_field_frequency" ), "wp-updates-notifier", "sc_wpun_settings_main" );
			add_settings_field( "sc_wpun_settings_main_notify_to", __( "Notify email to", "wp-updates-notifier" ), array( __CLASS__, "sc_wpun_settings_main_field_notify_to" ), "wp-updates-notifier", "sc_wpun_settings_main" );
			add_settings_field( "sc_wpun_settings_main_notify_from", __( "Notify email from", "wp-updates-notifier" ), array( __CLASS__, "sc_wpun_settings_main_field_notify_from" ), "wp-updates-notifier", "sc_wpun_settings_main" );
			add_settings_field( "sc_wpun_settings_main_notify_plugins", __( "Notify about plugin updates?", "wp-updates-notifier" ), array( __CLASS__, "sc_wpun_settings_main_field_notify_plugins" ), "wp-updates-notifier", "sc_wpun_settings_main" );
			add_settings_field( "sc_wpun_settings_main_notify_themes", __( "Notify about theme updates?", "wp-updates-notifier" ), array( __CLASS__, "sc_wpun_settings_main_field_notify_themes" ), "wp-updates-notifier", "sc_wpun_settings_main" );
			add_settings_field( "sc_wpun_settings_main_hide_updates", __( "Hide core WP update nag from non-admin users?", "wp-updates-notifier" ), array( __CLASS__, "sc_wpun_settings_main_field_hide_updates" ), "wp-updates-notifier", "sc_wpun_settings_main" );
		}

		public function sc_wpun_settings_validate( $input ) {
			$valid = get_option( self::$options_field );

			if( in_array( $input['cron_method'], array( "wordpress", "other" ) ) ) {
				$valid['cron_method'] = $input['cron_method'];
			}
			else {
				add_settings_error( "sc_wpun_settings_main_cron_method", "sc_wpun_settings_main_cron_method_error", __( "Invalid cron method selected", "wp-updates-notifier" ), "error" );
			}

			if ( in_array( $input['frequency'], self::$frequency_intervals ) ) {
				$valid['frequency'] = $input['frequency'];
				do_action( "sc_wpun_enable_cron", $input['frequency'] );
			}
			else {
				add_settings_error( "sc_wpun_settings_main_frequency", "sc_wpun_settings_main_frequency_error", __( "Invalid frequency entered", "wp-updates-notifier" ), "error" );
			}

			$emails_to = explode( ",", $input['notify_to'] );
			if ( $emails_to ) {
				$sanitized_emails = array();
				$was_error        = false;
				foreach ( $emails_to as $email_to ) {
					$address = sanitize_email( trim( $email_to ) );
					if ( !is_email( $address ) ) {
						add_settings_error( "sc_wpun_settings_main_notify_to", "sc_wpun_settings_main_notify_to_error", __( "One or more email to addresses are invalid", "wp-updates-notifier" ), "error" );
						$was_error = true;
						break;
					}
					$sanitized_emails[] = $address;
				}
				if ( !$was_error ) {
					$valid['notify_to'] = implode( ',', $sanitized_emails );
				}
			}
			else {
				add_settings_error( "sc_wpun_settings_main_notify_to", "sc_wpun_settings_main_notify_to_error", __( "No email to address entered", "wp-updates-notifier" ), "error" );
			}

			$sanitized_email_from = sanitize_email( $input['notify_from'] );
			if ( is_email( $sanitized_email_from ) ) {
				$valid['notify_from'] = $sanitized_email_from;
			}
			else {
				add_settings_error( "sc_wpun_settings_main_notify_from", "sc_wpun_settings_main_notify_from_error", __( "Invalid email from entered", "wp-updates-notifier" ), "error" );
			}

			$sanitized_notify_plugins = absint( $input['notify_plugins'] );
			if ( $sanitized_notify_plugins >= 0 && $sanitized_notify_plugins <= 2 ) {
				$valid['notify_plugins'] = $sanitized_notify_plugins;
			}
			else {
				add_settings_error( "sc_wpun_settings_main_notify_plugins", "sc_wpun_settings_main_notify_plugins_error", __( "Invalid plugin updates value entered", "wp-updates-notifier" ), "error" );
			}

			$sanitized_notify_themes = absint( $input['notify_themes'] );
			if ( $sanitized_notify_themes >= 0 && $sanitized_notify_themes <= 2 ) {
				$valid['notify_themes'] = $sanitized_notify_themes;
			}
			else {
				add_settings_error( "sc_wpun_settings_main_notify_themes", "sc_wpun_settings_main_notify_themes_error", __( "Invalid theme updates value entered", "wp-updates-notifier" ), "error" );
			}

			$sanitized_hide_updates = absint( $input['hide_updates'] );
			if ( $sanitized_hide_updates <= 1 ) {
				$valid['hide_updates'] = $sanitized_hide_updates;
			}
			else {
				add_settings_error( "sc_wpun_settings_main_hide_updates", "sc_wpun_settings_main_hide_updates_error", __( "Invalid hide updates value entered", "wp-updates-notifier" ), "error" );
			}

			if ( isset( $_POST['submitwithemail'] ) ) {
				add_filter( 'pre_set_transient_settings_errors', array( __CLASS__, "send_test_email" ) );
			}

			return $valid;
		}

		public function send_test_email( $settings_errors ) {
			if ( isset( $settings_errors[0]['type'] ) && $settings_errors[0]['type'] == "updated" ) {
				self::send_notification_email( __( "This is a test message from WP Updates Notifier.", "wp-updates-notifier" ) );
			}
		}

		public function sc_wpun_settings_main_text() {
		}

		public function sc_wpun_settings_main_field_cron_method() {
			$options = get_option( self::$options_field );
			?>
			<select name="<?php echo self::$options_field; ?>[cron_method]">
				<option value="wordpress" <?php selected( $options['cron_method'], "wordpress" ); ?>><?php _e( "WordPress Cron", "wp-updates-notifier" ); ?></option>
				<option value="other" <?php selected( $options['cron_method'], "other" ); ?>><?php _e( "Other Cron", "wp-updates-notifier" ); ?></option>
			</select>
			<div>
				<br />
				<span class="description"><?php _e( "Cron Command: ", "wp-updates-notifier" ); ?></span>
				<pre>wget -q "<?php echo site_url(); ?>/index.php?sc_wpun_check=1&amp;sc_wpun_key=<?php echo $options['security_key']; ?>" -O /dev/null >/dev/null 2>&amp;1</pre>
			</div>
		<?php
		}

		public function sc_wpun_settings_main_field_frequency() {
			$options = get_option( self::$options_field );
			?>
			<select id="sc_wpun_settings_main_frequency" name="<?php echo self::$options_field; ?>[frequency]">
			<option value="<?php echo self::$frequency_intervals[0]; ?>" <?php selected( $options['frequency'], self::$frequency_intervals[0] ); ?>><?php _e( "Hourly", "wp-updates-notifier" ); ?></option>
			<option value="<?php echo self::$frequency_intervals[1]; ?>" <?php selected( $options['frequency'], self::$frequency_intervals[1] ); ?>><?php _e( "Twice Daily", "wp-updates-notifier" ); ?></option>
			<option value="<?php echo self::$frequency_intervals[2]; ?>" <?php selected( $options['frequency'], self::$frequency_intervals[2] ); ?>><?php _e( "Daily", "wp-updates-notifier" ); ?></option>
			<select>
		<?php
		}

		public function sc_wpun_settings_main_field_notify_to() {
			$options = get_option( self::$options_field );
			?>
			<input id="sc_wpun_settings_main_notify_to" class="regular-text" name="<?php echo self::$options_field; ?>[notify_to]" value="<?php echo $options['notify_to']; ?>" />
			<span class="description"><?php _e( "Separate multiple email address with a comma (,)", "wp-updates-notifier" ); ?></span><?php
		}

		public function sc_wpun_settings_main_field_notify_from() {
			$options = get_option( self::$options_field );
			?>
			<input id="sc_wpun_settings_main_notify_from" class="regular-text" name="<?php echo self::$options_field; ?>[notify_from]" value="<?php echo $options['notify_from']; ?>" /><?php
		}

		public function sc_wpun_settings_main_field_notify_plugins() {
			$options = get_option( self::$options_field );
			?>
			<label><input name="<?php echo self::$options_field; ?>[notify_plugins]" type="radio" value="0" <?php checked( $options['notify_plugins'], 0 ); ?> /> <?php _e( "No", "wp-updates-notifier" ); ?>
			</label><br />
			<label><input name="<?php echo self::$options_field; ?>[notify_plugins]" type="radio" value="1" <?php checked( $options['notify_plugins'], 1 ); ?> /> <?php _e( "Yes", "wp-updates-notifier" ); ?>
			</label><br />
			<label><input name="<?php echo self::$options_field; ?>[notify_plugins]" type="radio" value="2" <?php checked( $options['notify_plugins'], 2 ); ?> /> <?php _e( "Yes but only active plugins", "wp-updates-notifier" ); ?>
			</label>
		<?php
		}

		public function sc_wpun_settings_main_field_notify_themes() {
			$options = get_option( self::$options_field );
			?>
			<label><input name="<?php echo self::$options_field; ?>[notify_themes]" type="radio" value="0" <?php checked( $options['notify_themes'], 0 ); ?> /> <?php _e( "No", "wp-updates-notifier" ); ?>
			</label><br />
			<label><input name="<?php echo self::$options_field; ?>[notify_themes]" type="radio" value="1" <?php checked( $options['notify_themes'], 1 ); ?> /> <?php _e( "Yes", "wp-updates-notifier" ); ?>
			</label><br />
			<label><input name="<?php echo self::$options_field; ?>[notify_themes]" type="radio" value="2" <?php checked( $options['notify_themes'], 2 ); ?> /> <?php _e( "Yes but only active themes", "wp-updates-notifier" ); ?>
			</label>
		<?php
		}

		public function sc_wpun_settings_main_field_hide_updates() {
			$options = get_option( self::$options_field );
			?>
		<select id="sc_wpun_settings_main_hide_updates" name="<?php echo self::$options_field; ?>[hide_updates]">
			<option value="1" <?php selected( $options['hide_updates'], 1 ); ?>><?php _e( "Yes", "wp-updates-notifier" ); ?></option>
			<option value="0" <?php selected( $options['hide_updates'], 0 ); ?>><?php _e( "No", "wp-updates-notifier" ); ?></option>
			<select>
			<?php
		}
		/**** END EVERYTHING SETTINGS ****/
	}
}


// Create Instance of WPUN Class
if ( !isset( $sc_wpun ) && function_exists( 'add_action' ) ) {
	$sc_wpun = new sc_WPUpdatesNotifier();
}
?>