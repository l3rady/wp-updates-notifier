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
 * Contributors: l3rady, eherman24, alleyinteractive
 * Version: 1.6.1
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
		const OPT_VERSION       = '7.0';
		const CRON_NAME         = 'sc_wpun_update_check';

		const MARKUP_VARS_SLACK = array(
			'i_start'     => '_',
			'i_end'       => '_',
			'line_break'  => '
',
			'link_start'  => '<',
			'link_middle' => '|',
			'link_end'    => '>',
			'b_start'     => '*',
			'b_end'       => '*',
		);

		const MARKUP_VARS_EMAIL = array(
			'i_start'     => '<i>',
			'i_end'       => '</i>',
			'line_break'  => '<br>',
			'link_start'  => '<a href="',
			'link_middle' => '">',
			'link_end'    => '</a>',
			'b_start'     => '<b>',
			'b_end'       => '</b>',
		);

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
			add_action( 'sc_wpun_enable_cron', array( $this, 'enable_cron' ) ); // action to enable cron
			add_action( 'sc_wpun_disable_cron', array( $this, 'disable_cron' ) ); // action to disable cron
			add_action( self::CRON_NAME, array( $this, 'do_update_check' ) ); // action to link cron task to actual task
			add_action( 'manage_plugins_custom_column', array( $this, 'manage_plugins_custom_column' ), 10, 3 ); // Filter the column data on the plugins page.
			add_action( 'manage_plugins_columns', array( $this, 'manage_plugins_columns' ) ); // Filter the column headers on the plugins page.
			add_action( 'admin_head', array( $this, 'custom_admin_css' ) ); // Custom css for the admin plugins.php page.
			add_action( 'admin_footer', array( $this, 'custom_admin_js' ) ); // Custom js for the admin plugins.php page.
			add_action( 'wp_ajax_toggle_plugin_notification',  array( $this, 'toggle_plugin_notification' ) ); // Ajax function to toggle the notifications for a plugin on the plugin.php page.
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
					'frequency'              => 'hourly',
					'email_notifications'    => 0,
					'notify_to'              => get_option( 'admin_email' ),
					'notify_from'            => get_option( 'admin_email' ),
					'slack_notifications'    => 0,
					'slack_webhook_url'      => '',
					'slack_channel_override' => '',
					'disabled_plugins'       => array(),
					'notify_plugins'         => 1,
					'notify_themes'          => 1,
					'notify_automatic'       => 1,
					'hide_updates'           => 1,
					'notified'               => array(
						'core'   => '',
						'plugin' => array(),
						'theme'  => array(),
					),
					'last_check_time'        => false,
				);

				// If we are upgrading from settings before settings version 7, turn on email notifications by default.
				if ( intval( $current_ver ) < 7 ) {
					$defaults['email_notifications'] = 1;
				}

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
		 * Alter the columns on the plugins page to show enable and disable notifications.
		 *
		 * @param string $column_name Name of the column.
		 * @param string $plugin_file Path to the plugin file relative to the plugins directory.
		 * @param array  $plugin_data An array of plugin data.
		 */
		public function manage_plugins_custom_column( $column_name, $plugin_file, $plugin_data ) {
			$options = $this->get_set_options( self::OPT_FIELD ); // get settings
			if ( 1 === $options['notify_plugins'] ) {
				if ( 'update_notifications' === $column_name ) {
					if ( is_plugin_active( $plugin_file ) ) {
						$nonce = wp_create_nonce( 'toggle_plugin_notification_' . hash( 'md5', $plugin_file ) );
						if ( isset( $options['disabled_plugins'][ $plugin_file ] ) ) {
							echo '<button class="sc_wpun_btn sc_wpun_btn_disable" data-nonce="' . esc_html( $nonce ) . '" data-toggle="enable" data-file="' . esc_html( $plugin_file ) . '">' . esc_html( __( 'Notifications Disabled', 'wp-updates-notifier' ) ) . '</button>';
						} else {
							echo '<button class="sc_wpun_btn sc_wpun_btn_enable" data-nonce="' . esc_html( $nonce ) . '" data-toggle="disable" data-file="' . esc_html( $plugin_file ) . '">' . esc_html( __( 'Notifications Enabled', 'wp-updates-notifier' ) ) . '</button>';
						}
					}
				}
			}
		}


		/**
		 * Alter the columns on the plugins page to show enable and disable notifications.
		 *
		 * @param array $column_headers An array of column headers.
		 */
		public function manage_plugins_columns( $column_headers ) {
			$options = $this->get_set_options( self::OPT_FIELD ); // get settings
			if ( 1 === $options['notify_plugins'] ) {
				$column_headers['update_notifications'] = __( 'Update Notifications', 'wp-updates-notifier' );
			}
			return $column_headers;
		}


		/**
		 * Custom css for the plugins.php page.
		 */
		public function custom_admin_css() {
			$options = $this->get_set_options( self::OPT_FIELD ); // get settings
			if ( 1 === $options['notify_plugins'] ) {
				echo '<style type="text/css">

				.column-update_notifications{
					width: 15%;
				}

				.sc_wpun_btn:before {
					font-family: "dashicons";
					display: inline-block;
					-webkit-font-smoothing: antialiased;
					font: normal 20px/1;
					vertical-align: top;
					margin-right: 5px;
					margin-right: 0.5rem;
				}

				.sc_wpun_btn_enable:before {
					content: "\f12a";
					color: green;
				}

				.sc_wpun_btn_disable:before {
					content: "\f153";
					color: red;
				}

				.sc_wpun_btn_enable:hover:before {
					content: "\f153";
					color: red;
				}

				.sc_wpun_btn_disable:hover:before {
					content: "\f12a";
					color: green;
				}

				</style>';
			}
		}


		/**
		 * Custom js for the plugins.php page.
		 */
		public function custom_admin_js() {
			?>
			<script type="text/javascript" >
			jQuery(document).ready(function($) {
				$( '.sc_wpun_btn' ).click(function(e) {
					e.preventDefault();

					var data = {
						'action': 'toggle_plugin_notification',
						'toggle': $(e.target).data().toggle,
						'plugin_file': $(e.target).data().file,
						'_wpnonce': $(e.target).data().nonce,
					};

					jQuery.post(ajaxurl, data, function(response) {
						console.log(response);
						if ( 'success' == response ) {
							if ( 'disable' == $(e.target).data().toggle ) {
								$(e.target).data( 'toggle', 'enable' );
								$(e.target).removeClass( 'sc_wpun_btn_enable' );
								$(e.target).addClass( 'sc_wpun_btn_disable' );
								$(e.target).text( '<?php echo esc_html( __( 'Notifications Disabled', 'wp-updates-notifier' ) ); ?>' );
							} else {
								$(e.target).data( 'toggle', 'disable' );
								$(e.target).removeClass( 'sc_wpun_btn_disable' );
								$(e.target).addClass( 'sc_wpun_btn_enable' );
								$(e.target).text( '<?php echo esc_html( __( 'Notifications Enabled', 'wp-updates-notifier' ) ); ?>' );
							}
						}
					});
				});
			});
			</script>
			<?php
		}


		public function toggle_plugin_notification() {
			if ( isset( $_POST['plugin_file'] ) && isset( $_POST['toggle'] ) && current_user_can( 'update_plugins' ) && current_user_can( 'manage_options' ) ) {
				$plugin_file = sanitize_text_field( wp_unslash( $_POST['plugin_file'] ) );
				$toggle      = sanitize_text_field( wp_unslash( $_POST['toggle'] ) );

				// Verify the nonce
				check_ajax_referer( 'toggle_plugin_notification_' . hash( 'md5', $plugin_file ) );

				$options        = $this->get_set_options( self::OPT_FIELD ); // get settings
				$active_plugins = array_flip( get_option( 'active_plugins' ) );

				if ( 'disable' === $toggle ) {
					$options['disabled_plugins'][ $plugin_file ] = 1;
					echo 'success';
				} elseif ( 'enable' === $toggle ) {
					unset( $options['disabled_plugins'][ $plugin_file ] );
					echo 'success';
				} else {
					echo 'failure';
				}
				$output = $this->get_set_options( self::OPT_FIELD, $options ); // update settings
			}
			wp_die();
		}


		/**
		 * This is run by the cron. The update check checks the core always, the
		 * plugins and themes if asked. If updates found email notification sent.
		 *
		 * @return void
		 */
		public function do_update_check() {
			$options         = $this->get_set_options( self::OPT_FIELD ); // get settings
			$updates         = array(); // store all of the updates here.
			$updates['core'] = $this->core_update_check(); // check the WP core for updates
			if ( 0 !== $options['notify_plugins'] ) { // are we to check for plugin updates?
				$updates['plugin'] = $this->plugins_update_check(); // check for plugin updates
			} else {
				$updates['plugin'] = false; // no plugin updates
			}
			if ( 0 !== $options['notify_themes'] ) { // are we to check for theme updates?
				$updates['theme'] = $this->themes_update_check(); // check for theme updates
			} else {
				$updates['theme'] = false; // no theme updates
			}

			/**
			 * Filters the updates before they're parsed for sending.
			 *
			 * Change the updates array of core, plugins, and themes to be notified about.
			 *
			 * @since 1.6.1
			 *
			 * @param array  $updates Array of updates to notify about.
			 */
			$updates = apply_filters( 'sc_wpun_updates', $updates );

			if ( ! empty( $updates['core'] ) || ! empty( $updates['plugin'] ) || ! empty( $updates['theme'] ) ) { // Did anything come back as need updating?

				// Send email notification.
				if ( 1 === $options['email_notifications'] ) {
					$message = $this->prepare_message( $updates, self::MARKUP_VARS_EMAIL );
					$this->send_email_message( $message );
				}

				// Send slack notification.
				if ( 1 === $options['slack_notifications'] ) {
					$message = $this->prepare_message( $updates, self::MARKUP_VARS_SLACK );
					$this->send_slack_message( $message );
				}
			}

			$this->log_last_check_time();
		}


		/**
		 * Checks to see if any WP core updates
		 *
		 * @return array Array of core updates.
		 */
		private function core_update_check() {
			global $wp_version;
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings
			do_action( 'wp_version_check' ); // force WP to check its core for updates
			$update_core = get_site_transient( 'update_core' ); // get information of updates
			if ( 'upgrade' === $update_core->updates[0]->response ) { // is WP core update available?
				if ( $update_core->updates[0]->current !== $settings['notified']['core'] ) { // have we already notified about this version?
					require_once ABSPATH . WPINC . '/version.php'; // Including this because some plugins can mess with the real version stored in the DB.
					$new_core_ver                 = $update_core->updates[0]->current; // The new WP core version
					$old_core_ver                 = $wp_version; // the old WP core version
					$core_updates                 = array(
						'old_version' => $old_core_ver,
						'new_version' => $new_core_ver,
					);
					$settings['notified']['core'] = $new_core_ver; // set core version we are notifying about
					$this->get_set_options( self::OPT_FIELD, $settings ); // update settings
					return $core_updates; // we have updates so return the array of updates
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
		 * @return bool
		 */
		private function plugins_update_check() {
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings
			do_action( 'wp_update_plugins' ); // force WP to check plugins for updates
			$update_plugins = get_site_transient( 'update_plugins' ); // get information of updates
			$plugin_updates = array(); // array to store all of the plugin updates
			if ( ! empty( $update_plugins->response ) ) { // any plugin updates available?
				$plugins_need_update = $update_plugins->response; // plugins that need updating
				$active_plugins      = array_flip( get_option( 'active_plugins' ) ); // find which plugins are active
				$plugins_need_update = array_intersect_key( $plugins_need_update, $active_plugins ); // only keep plugins that are active
				$plugins_need_update = apply_filters( 'sc_wpun_plugins_need_update', $plugins_need_update ); // additional filtering of plugins need update
				if ( count( $plugins_need_update ) >= 1 ) { // any plugins need updating after all the filtering gone on above?
					require_once ABSPATH . 'wp-admin/includes/plugin-install.php'; // Required for plugin API
					require_once ABSPATH . WPINC . '/version.php'; // Required for WP core version
					foreach ( $plugins_need_update as $key => $data ) { // loop through the plugins that need updating
						$plugin_info      = get_plugin_data( WP_PLUGIN_DIR . '/' . $key ); // get local plugin info
						$plugin_updates[] = array(
							'name'          => $plugin_info['Name'],
							'old_version'   => $plugin_info['Version'],
							'new_version'   => $data->new_version,
							'changelog_url' => $data->url . 'changelog/',
						);

						$settings['notified']['plugin'][ $key ] = $data->new_version; // set plugin version we are notifying about
					}
					$this->get_set_options( self::OPT_FIELD, $settings ); // save settings
					return $plugin_updates; // we have plugin updates return the array
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
		 * @return bool
		 */
		private function themes_update_check() {
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings
			do_action( 'wp_update_themes' ); // force WP to check for theme updates
			$update_themes = get_site_transient( 'update_themes' ); // get information of updates
			$theme_updates = array(); // array to store all the theme updates
			if ( ! empty( $update_themes->response ) ) { // any theme updates available?
				$themes_need_update = $update_themes->response; // themes that need updating
				$active_theme       = array( get_option( 'template' ) => array() ); // find current theme that is active
				$themes_need_update = array_intersect_key( $themes_need_update, $active_theme ); // only keep theme that is active
				$themes_need_update = apply_filters( 'sc_wpun_themes_need_update', $themes_need_update ); // additional filtering of themes need update
				if ( count( $themes_need_update ) >= 1 ) { // any themes need updating after all the filtering gone on above?
					foreach ( $themes_need_update as $key => $data ) { // loop through the themes that need updating
						$theme_info      = wp_get_theme( $key ); // get theme info
						$theme_updates[] = array(
							'name'        => $theme_info['Name'],
							'old_version' => $theme_info['Name'],
							'new_version' => $data['new_version'],
						);

						$settings['notified']['theme'][ $key ] = $data['new_version']; // set theme version we are notifying about
					}
					$this->get_set_options( self::OPT_FIELD, $settings ); // save settings
					return $theme_updates; // we have theme updates return the array of updates
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
		 * Prepare the message.
		 *
		 * @param array $updates Array of all of the updates to notifiy about.
		 * @param array $markup_vars Array of the markup characters to use.
		 *
		 * @return string Message to be sent.
		 */
		public function prepare_message( $updates, $markup_vars ) {
			$message = $markup_vars['i_start'] . esc_html( __( 'Updates Available', 'wp-updates-notifier' ) )
			. $markup_vars['i_end'] . $markup_vars['line_break'] . $markup_vars['b_start']
			. esc_html( get_bloginfo() ) . $markup_vars['b_end'] . ' - '
			. $markup_vars['link_start'] . esc_url( home_url() ) . $markup_vars['link_middle']
			. esc_url( home_url() ) . $markup_vars['link_end'] . $markup_vars['line_break'];

			if ( ! empty( $updates['core'] ) ) {
				$message .= $markup_vars['line_break'] . $markup_vars['b_start'] . $markup_vars['link_start']
				. esc_url( admin_url( 'update-core.php' ) ) . $markup_vars['link_middle']
				. esc_html( __( 'WordPress Core', 'wp-updates-notifier' ) ) . $markup_vars['link_end']
				. $markup_vars['b_end'] . ' (' . $updates['core']['old_version'] . esc_html( __( ' to ', 'wp-updates-notifier' ) )
				. $updates['core']['new_version'] . ')' . $markup_vars['line_break'];
			}

			if ( ! empty( $updates['plugin'] ) ) {
				$message .= $markup_vars['line_break'] . $markup_vars['b_start'] . $markup_vars['link_start']
				. esc_url( admin_url( 'plugins.php?plugin_status=upgrade' ) ) . $markup_vars['link_middle']
				. esc_html( __( 'Plugin Updates', 'wp-updates-notifier' ) ) . $markup_vars['link_end']
				. $markup_vars['b_end'] . $markup_vars['line_break'];

				foreach ( $updates['plugin'] as $plugin ) {
					$message .= '	' . $plugin['name'];
					if ( ! empty( $plugin['old_version'] ) && ! empty( $plugin['new_version'] ) ) {
						$message .= ' (' . $plugin['old_version'] . esc_html( __( ' to ', 'wp-updates-notifier' ) )
						. $markup_vars['link_start'] . esc_url( $plugin['changelog_url'] ) . $markup_vars['link_middle']
						. $plugin['new_version'] . $markup_vars['link_end'] . ')' . $markup_vars['line_break'];
					}
				}
			}

			if ( ! empty( $updates['theme'] ) ) {
				$message .= $markup_vars['line_break'] . $markup_vars['b_start'] . $markup_vars['link_start']
				. esc_url( admin_url( 'themes.php' ) ) . $markup_vars['link_middle'] . esc_html( __( 'Theme Updates', 'wp-updates-notifier' ) )
				. $markup_vars['link_end'] . $markup_vars['b_end'] . $markup_vars['line_break'];

				foreach ( $updates['theme'] as $theme ) {
					$message .= '	' . $theme['name'];
					if ( ! empty( $theme['old_version'] ) && ! empty( $theme['new_version'] ) ) {
						$message .= ' (' . $theme['old_version'] . esc_html( __( ' to ', 'wp-updates-notifier' ) )
						. $theme['new_version'] . ')' . $markup_vars['line_break'];
					}
				}
			}

			return $message;
		}


		/**
		 * Sends email message.
		 *
		 * @param string $message Holds message to be sent in body of email.
		 *
		 * @return bool Whether the email contents were sent successfully.
		 */
		public function send_email_message( $message ) {
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings

			/**
			 * Filters the email subject.
			 *
			 * Change the subject line that gets sent in the email.
			 *
			 * @since 1.6.1
			 *
			 * @param string  $subject Email subject line.
			 */
			$subject = sprintf( __( 'WP Updates Notifier: Updates Available @ %s', 'wp-updates-notifier' ), home_url() );
			$subject = apply_filters( 'sc_wpun_email_subject', $subject );

			add_filter( 'wp_mail_from', array( $this, 'sc_wpun_wp_mail_from' ) ); // add from filter
			add_filter( 'wp_mail_from_name', array( $this, 'sc_wpun_wp_mail_from_name' ) ); // add from name filter
			add_filter( 'wp_mail_content_type', array( $this, 'sc_wpun_wp_mail_content_type' ) ); // add content type filter

			/**
			 * Filters the email content.
			 *
			 * Change the message that gets sent in the email.
			 *
			 * @since 1.6.1
			 *
			 * @param string  $message Email message.
			 */
			$message = apply_filters( 'sc_wpun_email_content', $message );

			// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			$response = wp_mail( $settings['notify_to'], apply_filters( 'sc_wpun_email_subject', $subject ), apply_filters( 'sc_wpun_email_content', $message ) ); // send email
			// phpcs:enable
			remove_filter( 'wp_mail_from', array( $this, 'sc_wpun_wp_mail_from' ) ); // remove from filter
			remove_filter( 'wp_mail_from_name', array( $this, 'sc_wpun_wp_mail_from_name' ) ); // remove from name filter
			remove_filter( 'wp_mail_content_type', array( $this, 'sc_wpun_wp_mail_content_type' ) ); // remove content type filter

			return $response;
		}


		/**
		 * Sends slack post.
		 *
		 * @param string $message Holds message to be posted to slack.
		 *
		 * @return bool Success or failure.
		 */
		public function send_slack_message( $message ) {
			$settings = $this->get_set_options( self::OPT_FIELD ); // get settings

			/**
			 * Filters the Slack username.
			 *
			 * Change the username that is used to post to Slack.
			 *
			 * @since 1.6.1
			 *
			 * @param string  $username Username string.
			 */
			$username = __( 'WP Updates Notifier', 'wp-updates-notifier' );
			$username = apply_filters( 'sc_wpun_slack_username', $username );

			/**
			 * Filters the Slack user icon.
			 *
			 * Change the user icon that is posted to Slack.
			 *
			 * @since 1.6.1
			 *
			 * @param string  $user_icon Emoji string.
			 */
			$user_icon = ':robot_face:';
			$user_icon = apply_filters( 'sc_wpun_slack_user_icon', $user_icon );

			/**
			 * Filters the slack message content.
			 *
			 * Change the message content that is posted to Slack.
			 *
			 * @since 1.6.1
			 *
			 * @param String  $message Message posted to Slack.
			 */
			$message = apply_filters( 'sc_wpun_slack_content', $message );

			$payload = array(
				'username'   => $username,
				'icon_emoji' => $user_icon,
				'text'       => $message,
			);

			if ( ! empty( $settings['slack_channel_override'] ) && '' !== $settings['slack_channel_override'] ) {
				$payload['channel'] = $settings['slack_channel_override'];
			}

			/**
			 * Filters the Slack channel.
			 *
			 * Change the Slack channel to post to.
			 *
			 * @since 1.6.1
			 *
			 * @param string  $payload['channel'] Slack channel.
			 */
			$payload['channel'] = apply_filters( 'sc_wpun_slack_channel', $payload['channel'] );

			/**
			 * Filters the Slack webhook url.
			 *
			 * Change the webhook url that is called by the plugin to post to Slack.
			 *
			 * @since 1.6.1
			 *
			 * @param string  $settings['slack_webhook_url'] Webhook url.
			 */
			$slack_webhook_url = apply_filters( 'sc_wpun_slack_webhook_url', $settings['slack_webhook_url'] );

			$response = wp_remote_post(
				$slack_webhook_url,
				array(
					'method' => 'POST',
					'body'   => array(
						'payload' => wp_json_encode( $payload ),
					),
				)
			);

			return is_wp_error( $response );
		}

		/**
		 * Get the from email address.
		 *
		 * @return String email address.
		 */
		public function sc_wpun_wp_mail_from() {
			$settings = $this->get_set_options( self::OPT_FIELD );
			return $settings['notify_from'];
		}

		/**
		 * Get the name to send email from.
		 *
		 * @return String From Name.
		 */
		public function sc_wpun_wp_mail_from_name() {
			return __( 'WP Updates Notifier', 'wp-updates-notifier' );
		}

		/**
		 * Email type.
		 *
		 * @return String email type.
		 */
		public function sc_wpun_wp_mail_content_type() {
			return 'text/html';
		}

		/**
		 * Change the last time checked.
		 *
		 * @return void
		 */
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
		 * Get cron schedules.
		 *
		 * @return Array cron schedules.
		 */
		private function get_schedules() {
			$schedules = wp_get_schedules();
			uasort( $schedules, array( $this, 'sort_by_interval' ) );
			return $schedules;
		}

		/**
		 * Get cron intervals.
		 *
		 * @return Array cron intervals.
		 */
		private function get_intervals() {
			$intervals   = array_keys( $this->get_schedules() );
			$intervals[] = 'manual';
			return $intervals;
		}

		/**
		 * Simple sort function.
		 *
		 * @param  int $a Integer for sorting.
		 * @param  int $b Integer for sorting.
		 *
		 * @return int Frequency internval.
		 */
		private function sort_by_interval( $a, $b ) {
			return $a['interval'] - $b['interval'];
		}

		/**
		 * Add admin menu.
		 *
		 * @return void
		 */
		public function admin_settings_menu() {
			add_options_page( __( 'Updates Notifier', 'wp-updates-notifier' ), __( 'Updates Notifier', 'wp-updates-notifier' ), 'manage_options', 'wp-updates-notifier', array( $this, 'settings_page' ) );
		}

		/**
		 * Output settings page and trigger sending tests.
		 *
		 * @return void
		 */
		public function settings_page() {
			// Trigger tests if they are ready to be sent.
			$sc_wpun_send_test_slack = get_transient( 'sc_wpun_send_test_slack' );
			if ( $sc_wpun_send_test_slack ) {
				delete_transient( 'sc_wpun_send_test_slack' );
				$this->send_test_slack();
			}
			$sc_wpun_send_test_email = get_transient( 'sc_wpun_send_test_email' );
			if ( $sc_wpun_send_test_email ) {
				delete_transient( 'sc_wpun_send_test_email' );
				$this->send_test_email();
			}

			$options     = $this->get_set_options( self::OPT_FIELD );
			$date_format = get_option( 'date_format' );
			$time_format = get_option( 'time_format' );
			?>
			<div class="wrap">
				<h2><?php esc_html_e( 'Updates Notifier', 'wp-updates-notifier' ); ?></h2>

				<p>
					<span class="description">
					<?php
					if ( empty( $options['last_check_time'] ) ) {
						$scan_date = __( 'Never', 'wp-updates-notifier' );
					} else {
						$scan_date = sprintf(
							__( '%1$1s @ %2$2s', 'wp-updates-notifier' ),
							gmdate( $date_format, $options['last_check_time'] ),
							gmdate( $time_format, $options['last_check_time'] )
						);
					}

					echo esc_html( __( 'Last scanned: ', 'wp-updates-notifier' ) . $scan_date );
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
					<input class="button" name="submitwithslack" type="submit" value="<?php esc_html_e( 'Save settings with test slack post', 'wp-updates-notifier' ); ?>" />
				</form>
			</div>
			<?php
		}

		/**
		 * Add all of the settings for the settings page.
		 *
		 * @return void
		 */
		public function admin_settings_init() {
			register_setting( self::OPT_FIELD, self::OPT_FIELD, array( $this, 'sc_wpun_settings_validate' ) ); // Register Settings

			add_settings_section( 'sc_wpun_settings_main', __( 'Settings', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_text' ), 'wp-updates-notifier' ); // Make settings main section
			add_settings_field( 'sc_wpun_settings_main_frequency', __( 'Frequency to check', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_frequency' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_plugins', __( 'Notify about plugin updates?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_plugins' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_themes', __( 'Notify about theme updates?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_themes' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_automatic', __( 'Notify automatic core updates to this address?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_automatic' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_hide_updates', __( 'Hide core WP update nag from non-admin users?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_hide_updates' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );

			// Email notification settings.
			add_settings_section( 'sc_wpun_settings_email_notifications', __( 'Email Notifications', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_text' ), 'wp-updates-notifier' );
			add_settings_field( 'sc_wpun_settings_email_notifications_email_notifications', __( 'Send email notifications?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_field_email_notifications' ), 'wp-updates-notifier', 'sc_wpun_settings_email_notifications' );
			add_settings_field( 'sc_wpun_settings_email_notifications_notify_to', __( 'Notify email to', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_field_notify_to' ), 'wp-updates-notifier', 'sc_wpun_settings_email_notifications' );
			add_settings_field( 'sc_wpun_settings_email_notifications_notify_from', __( 'Notify email from', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_field_notify_from' ), 'wp-updates-notifier', 'sc_wpun_settings_email_notifications' );

			// Slack notification settings.
			add_settings_section( 'sc_wpun_settings_slack_notifications', __( 'Slack Notifications', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_slack_notifications_text' ), 'wp-updates-notifier' );
			add_settings_field( 'sc_wpun_settings_slack_notifications_slack_notifications', __( 'Send slack notifications?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_slack_notifications_field_slack_notifications' ), 'wp-updates-notifier', 'sc_wpun_settings_slack_notifications' );
			add_settings_field( 'sc_wpun_settings_slack_notifications_slack_webhook_url', __( 'Webhook url', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_slack_notifications_field_slack_webhook_url' ), 'wp-updates-notifier', 'sc_wpun_settings_slack_notifications' );
			add_settings_field( 'sc_wpun_settings_slack_notifications_slack_channel_override', __( 'Channel to notify', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_slack_notifications_field_slack_channel_override' ), 'wp-updates-notifier', 'sc_wpun_settings_slack_notifications' );
		}

		/**
		 * Validate and sanitize all of the settings from the page form.
		 *
		 * @param array $input Array of unsanitized options from the page form.
		 *
		 * @return array Array of sanitized and validated settings.
		 */
		public function sc_wpun_settings_validate( $input ) {
			// disabled plugins will only be set through the plugins page, so we only check the admin referer for the options page if they aren't set
			if ( ! isset( $input['disabled_plugins'] ) ) {
				check_admin_referer( 'sc_wpun_settings-options' );
			}
			$valid = $this->get_set_options( self::OPT_FIELD );

			// Validate main settings.
			if ( in_array( $input['frequency'], $this->get_intervals(), true ) ) {
				$valid['frequency'] = $input['frequency'];
				do_action( 'sc_wpun_enable_cron', $input['frequency'] );
			} else {
				add_settings_error( 'sc_wpun_settings_main_frequency', 'sc_wpun_settings_main_frequency_error', __( 'Invalid frequency entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_notify_plugins = absint( isset( $input['notify_plugins'] ) ? $input['notify_plugins'] : 0 );
			if ( $sanitized_notify_plugins >= 0 && $sanitized_notify_plugins <= 1 ) {
				$valid['notify_plugins'] = $sanitized_notify_plugins;
			} else {
				add_settings_error( 'sc_wpun_settings_main_notify_plugins', 'sc_wpun_settings_main_notify_plugins_error', __( 'Invalid plugin updates value entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_notify_themes = absint( isset( $input['notify_themes'] ) ? $input['notify_themes'] : 0 );
			if ( $sanitized_notify_themes >= 0 && $sanitized_notify_themes <= 1 ) {
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

			// Validate email notification settings.
			if ( ! empty( $input['notify_to'] ) ) {
				$emails_to = explode( ',', $input['notify_to'] );
				if ( $emails_to ) {
					$sanitized_emails = array();
					$was_error        = false;
					foreach ( $emails_to as $email_to ) {
						$address = sanitize_email( trim( $email_to ) );
						if ( ! is_email( $address ) ) {
							add_settings_error( 'sc_wpun_settings_email_notifications_notify_to', 'sc_wpun_settings_email_notifications_notify_to_error', __( 'One or more email to addresses are invalid', 'wp-updates-notifier' ), 'error' );
							$was_error = true;
							break;
						}
						$sanitized_emails[] = $address;
					}
					if ( ! $was_error ) {
						$valid['notify_to'] = implode( ',', $sanitized_emails );
					}
				}
			} else {
				$valid['notify_to'] = '';
			}

			if ( ! empty( $input['notify_from'] ) ) {
				$sanitized_email_from = sanitize_email( $input['notify_from'] );
				if ( is_email( $sanitized_email_from ) ) {
					$valid['notify_from'] = $sanitized_email_from;
				} else {
					add_settings_error( 'sc_wpun_settings_email_notifications_notify_from', 'sc_wpun_settings_email_notifications_notify_from_error', __( 'Invalid email from entered', 'wp-updates-notifier' ), 'error' );
				}
			} else {
				$valid['notify_from'] = '';
			}

			$email_notifications = absint( isset( $input['email_notifications'] ) ? $input['email_notifications'] : 0 );
			if ( 1 < $email_notifications ) {
				add_settings_error( 'sc_wpun_settings_email_notifications_email_notifications', 'sc_wpun_settings_email_notifications_email_notifications_error', __( 'Invalid notification email value entered', 'wp-updates-notifier' ), 'error' );
			}

			if ( 1 === $email_notifications ) {
				if ( ! empty( $valid['notify_to'] ) && ! empty( $valid['notify_from'] ) ) {
					$email_notifications = 1;
				} else {
					add_settings_error( 'sc_wpun_settings_email_notifications_notify_from', 'sc_wpun_settings_email_notifications_notify_to_error', __( 'Can not enable email notifications, addresses are not valid', 'wp-updates-notifier' ), 'error' );
					$email_notifications = 0;
				}
			}
			$valid['email_notifications'] = $email_notifications;

			$active_plugins = array_flip( get_option( 'active_plugins' ) );
			$valid['disabled_plugins'] = array();
			if ( ! empty( $input['disabled_plugins'] ) ) {
				foreach ( $input['disabled_plugins'] as $new_disabled_plugin => $val ) {
					if ( isset( $active_plugins[ $new_disabled_plugin ] ) ) {
						$valid['disabled_plugins'][ $new_disabled_plugin ] = 1;
					}
				}
			}

			// Validate slack settings.
			if ( ! empty( $input['slack_webhook_url'] ) ) {
				if ( false === filter_var( $input['slack_webhook_url'], FILTER_VALIDATE_URL ) ) {
					add_settings_error( 'sc_wpun_settings_slack_notifications_slack_webhook_url', 'sc_wpun_settings_slack_notifications_slack_webhook_url_error', __( 'Invalid webhook url entered', 'wp-updates-notifier' ), 'error' );
				} else {
					$valid['slack_webhook_url'] = $input['slack_webhook_url'];
				}
			} else {
				$valid['slack_webhook_url'] = '';
			}

			if ( ! empty( $input['slack_channel_override'] ) ) {
				if ( '#' !== substr( $input['slack_channel_override'], 0, 1 ) && '@' !== substr( $input['slack_channel_override'], 0, 1 ) ) {
					add_settings_error( 'sc_wpun_settings_slack_notifications_slack_channel_override', 'sc_wpun_settings_slack_notifications_slack_channel_override_error', __( 'Channel name must start with a # or @', 'wp-updates-notifier' ), 'error' );
				} elseif ( strpos( $input['slack_channel_override'], ' ' ) ) {
					add_settings_error( 'sc_wpun_settings_slack_notifications_slack_channel_override', 'sc_wpun_settings_slack_notifications_slack_channel_override_error', __( 'Channel name must not contain a space', 'wp-updates-notifier' ), 'error' );
				} else {
					$valid['slack_channel_override'] = $input['slack_channel_override'];
				}
			} else {
				$valid['slack_channel_override'] = '';
			}

			$slack_notifications = absint( isset( $input['slack_notifications'] ) ? $input['slack_notifications'] : 0 );
			if ( $slack_notifications > 1 ) {
				add_settings_error( 'sc_wpun_settings_slack_notifications_slack_notifications', 'sc_wpun_settings_slack_notifications_slack_notifications_error', __( 'Invalid notification slack value entered', 'wp-updates-notifier' ), 'error' );
			}

			if ( 1 === $slack_notifications ) {
				if ( '' === $valid['slack_webhook_url'] ) {
					add_settings_error( 'sc_wpun_settings_slack_notifications_slack_webhook_url', 'sc_wpun_settings_slack_notifications_slack_webhook_url_error', __( 'No to slack webhoook url entered', 'wp-updates-notifier' ), 'error' );
					$slack_notifications = 0;
				} else {
					$slack_notifications = 1;
				}
			} else {
				$slack_notifications = 0;
			}
			$valid['slack_notifications'] = $slack_notifications;

			// Parse sending test notifiations.

			if ( isset( $_POST['submitwithemail'] ) ) {
				if ( '' !== $valid['notify_to'] && '' !== $valid['notify_from'] ) {
					set_transient( 'sc_wpun_send_test_email', 1 );
				} else {
					add_settings_error( 'sc_wpun_settings_email_notifications_email_notifications', 'sc_wpun_settings_email_notifications_email_notifications_error', __( 'Can not send test email. Email settings are invalid.', 'wp-updates-notifier' ), 'error' );
				}
			}

			if ( isset( $_POST['submitwithslack'] ) ) {
				if ( '' !== $valid['slack_webhook_url'] ) {
					set_transient( 'sc_wpun_send_test_slack', 1 );
				} else {
					add_settings_error( 'sc_wpun_settings_email_notifications_slack_notifications', 'sc_wpun_settings_email_notifications_slack_notifications_error', __( 'Can not post test slack message. Slack settings are invalid.', 'wp-updates-notifier' ), 'error' );
				}
			}

			return $valid;
		}

		/**
		 * Send a test email.
		 *
		 * @return void
		 */
		public function send_test_email() {
			$this->send_email_message( __( 'This is a test message from WP Updates Notifier.', 'wp-updates-notifier' ) );
		}

		/**
		 * Send a test slack message.
		 *
		 * @return void
		 */
		public function send_test_slack() {
			$this->send_slack_message( __( 'This is a test message from WP Updates Notifier.', 'wp-updates-notifier' ) );
		}

		/**
		 * Output the text at the top of the main settings section (function is required even if it outputs nothing).
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_text() {
		}

		/**
		 * Settings field for frequency.
		 *
		 * @return void
		 */
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

		/**
		 * Settings field for notify plugins.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_plugins() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="0" <?php checked( $options['notify_plugins'], 0 ); ?> /> <?php esc_html_e( 'No', 'wp-updates-notifier' ); ?>
			</label><br />
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="1" <?php checked( $options['notify_plugins'], 1 ); ?> /> <?php esc_html_e( 'Yes (only checks active plugins)', 'wp-updates-notifier' ); ?>
			</label>
			<?php
		}

		/**
		 * Settings field for notify themes.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_themes() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_themes]" type="radio" value="0" <?php checked( $options['notify_themes'], 0 ); ?> /> <?php esc_html_e( 'No', 'wp-updates-notifier' ); ?>
			</label><br />
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_themes]" type="radio" value="1" <?php checked( $options['notify_themes'], 1 ); ?> /> <?php esc_html_e( 'Yes (only checks active themes)', 'wp-updates-notifier' ); ?>
			</label>
			<?php
		}

		/**
		 * Settings field for notify automatic.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_automatic() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_automatic]" type="checkbox" value="1" <?php checked( $options['notify_automatic'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
			</label>
			<?php
		}

		/**
		 * Settings field for hiding updates.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_hide_updates() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<select id="sc_wpun_settings_main_hide_updates" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[hide_updates]">
				<option value="1" <?php selected( $options['hide_updates'], 1 ); ?>><?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?></option>
				<option value="0" <?php selected( $options['hide_updates'], 0 ); ?>><?php esc_html_e( 'No', 'wp-updates-notifier' ); ?></option>
			</select>
			<?php
		}

		/**
		 * Output the text at the top of the email settings section (function is required even if it outputs nothing).
		 *
		 * @return void
		 */
		public function sc_wpun_settings_email_notifications_text() {
		}

		/**
		 * Settings field for email notifications.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_email_notifications_field_email_notifications() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[email_notifications]" type="checkbox" value="1" <?php checked( $options['email_notifications'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
			</label>
			<?php
		}

		/**
		 * Settings field for email to field.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_email_notifications_field_notify_to() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<input id="sc_wpun_settings_email_notifications_notify_to" class="regular-text" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_to]" value="<?php echo esc_attr( $options['notify_to'] ); ?>" />
			<span class="description"><?php esc_html_e( 'Separate multiple email address with a comma (,)', 'wp-updates-notifier' ); ?></span>
			<?php
		}

		/**
		 * Settings field for email from field.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_email_notifications_field_notify_from() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<input id="sc_wpun_settings_email_notifications_notify_from" class="regular-text" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_from]" value="<?php echo esc_attr( $options['notify_from'] ); ?>" />
			<?php
		}

		/**
		 * Output the text at the top of the slack settings section (function is required even if it outputs nothing).
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_text() {
		}

		/**
		 * Settings field for slack notifications.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_notifications() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[slack_notifications]" type="checkbox" value="1" <?php checked( $options['slack_notifications'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
			</label>
			<?php
		}

		/**
		 * Settings field for slack webhook url.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_webhook_url() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<input id="sc_wpun_settings_slack_notifications_slack_webhook_url" class="regular-text" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[slack_webhook_url]" value="<?php echo esc_attr( $options['slack_webhook_url'] ); ?>" />
			<?php
		}

		/**
		 * Settings field for slack channel override.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_slack_notifications_field_slack_channel_override() {
			$options = $this->get_set_options( self::OPT_FIELD );
			?>
			<input id="sc_wpun_settings_slack_notifications_slack_channel_override" class="regular-text" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[slack_channel_override]" value="<?php echo esc_attr( $options['slack_channel_override'] ); ?>" />
			<span class="description"><?php esc_html_e( 'Not requred.', 'wp-updates-notifier' ); ?></span>
			<?php
		}
	}
}

new SC_WP_Updates_Notifier();
