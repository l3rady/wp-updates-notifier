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
 * x-release-please-start-version
 * Version: 1.6.0
 * x-release-please-end
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: Scott Cariss
 * Author URI: http://l3rady.com/
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

// Only load class if it hasn't already been loaded.
if ( ! class_exists( 'SC_WP_Updates_Notifier' ) ) {

	/**
	 * WP Updates Notifier - All the magic happens here!
	 *
	 * @phpstan-type Settings array{frequency: string, email_notifications: int, notify_to: string, notify_from: string, slack_notifications: int, slack_webhook_url: string, slack_channel_override: string, notify_plugins: int, notify_themes: int, notify_automatic: int, hide_updates: int}
	 * @phpstan-type Notified array{core: string, plugin: array<string, string>, theme: array<string, string>}
	 * @phpstan-type State array{notified: Notified, last_check_time: int|false}
	 */
	class SC_WP_Updates_Notifier {
		const OPT_FIELD         = 'sc_wpun_settings';
		const OPT_VERSION_FIELD = 'sc_wpun_settings_ver';
		const OPT_STATE_FIELD   = 'sc_wpun_state';
		const OPT_VERSION       = '8.1';
		const DEFAULT_FREQUENCY = 'hourly';
		const CRON_NAME         = 'sc_wpun_update_check';

		/**
		 * Whether the plugin has been initialised in this request.
		 *
		 * @var bool
		 */
		public static bool $did_init = false;

		/**
		 * Initialise the plugin when all plugins are loaded.
		 *
		 * The activation and deactivation hooks are registered here, when the file
		 * loads: on the activation request WordPress includes this file after
		 * `plugins_loaded` has already fired, so hooks registered from `init()` would
		 * never exist in that request.
		 */
		public function __construct() {
			add_action( 'plugins_loaded', array( $this, 'run_init' ) );
			register_activation_hook( __FILE__, array( $this, 'activate' ) );
			register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		}

		/**
		 * Initialise the plugin once per request.
		 *
		 * @return void
		 */
		public function run_init() {
			if ( ! self::$did_init ) {
				$this->init();
				self::$did_init = true;
			}
		}

		/**
		 * Register the plugin's hooks and load its translations.
		 *
		 * @return void
		 */
		private function init(): void {
			// Check settings are up to date.
			$this->settings_up_to_date();
			// Internationalization.
			load_plugin_textdomain( 'wp-updates-notifier', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
			// Add Filters.
			add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2 ); // Add settings link to plugin in plugin list.
			add_filter( 'sc_wpun_plugins_need_update', array( $this, 'check_plugins_against_notified' ) ); // Filter out plugins that need update if already been notified.
			add_filter( 'sc_wpun_themes_need_update', array( $this, 'check_themes_against_notified' ) ); // Filter out themes that need update if already been notified.
			add_filter( 'auto_core_update_email', array( $this, 'filter_auto_core_update_email' ), 1 ); // Filter the background update notification email.
			// Add Actions.
			add_action( 'admin_menu', array( $this, 'admin_settings_menu' ) ); // Add menu to options.
			add_action( 'admin_init', array( $this, 'admin_settings_init' ) ); // Add admin init functions.
			add_action( 'admin_init', array( $this, 'remove_update_nag_for_nonadmins' ) ); // See if we remove update nag for non admins.
			add_action( 'sc_wpun_enable_cron', array( $this, 'enable_cron' ) ); // action to enable cron.
			add_action( 'sc_wpun_disable_cron', array( $this, 'disable_cron' ) ); // action to disable cron.
			add_action( self::CRON_NAME, array( $this, 'do_update_check' ) ); // action to link cron task to actual task.
			add_action( 'init', array( $this, 'maybe_schedule' ) ); // Put the check back on the schedule if it is missing.
			add_action( 'update_option_' . self::OPT_FIELD, array( $this, 'settings_updated' ), 10, 2 ); // Reschedule when the frequency changes.
		}

		/**
		 * Check if this plugin settings are up to date. Firstly check the version in
		 * the DB. If they don't match then load in defaults but don't override values
		 * already set. Also this will remove obsolete settings that are not needed.
		 *
		 * @return void
		 */
		private function settings_up_to_date(): void {
			$current_ver = $this->get_set_options( self::OPT_VERSION_FIELD ); // Get current plugin version.
			if ( self::OPT_VERSION !== $current_ver ) { // is the version the same as this plugin?
				$options  = (array) get_option( self::OPT_FIELD ); // get current settings from DB.
				$defaults = $this->default_settings();

				// If we are upgrading from settings before settings version 7, turn on email notifications by default.
				if ( intval( $current_ver ) < 7 ) {
					$defaults['email_notifications'] = 1;
				}

				// "manual" has meant "never check" since the external cron was removed in 1.5.1. Check daily instead.
				if ( isset( $options['frequency'] ) && 'manual' === $options['frequency'] ) {
					$options['frequency'] = 'daily';
				}

				// Settings version 8 moved the run state (what was notified, when the last check ran) to its own option.
				if ( false === get_option( self::OPT_STATE_FIELD ) ) {
					$this->save_state(
						$this->normalise_state(
							array(
								'notified'        => $options['notified'] ?? array(),
								'last_check_time' => $options['last_check_time'] ?? false,
							)
						)
					);
				}

				// Intersect current options with defaults. Basically removing settings that are obsolete.
				$options = array_intersect_key( $options, $defaults );
				// Merge current settings with defaults. Basically adding any new settings with defaults that we dont have.
				$options = array_merge( $defaults, $options );
				$this->get_set_options( self::OPT_FIELD, $options ); // update settings.
				$this->get_set_options( self::OPT_VERSION_FIELD, self::OPT_VERSION ); // update settings version.
			}
		}

		/**
		 * The settings a new install starts with.
		 *
		 * @return Settings
		 */
		private function default_settings(): array {
			return array(
				'frequency'              => 'hourly',
				'email_notifications'    => 0,
				'notify_to'              => (string) get_option( 'admin_email' ),
				'notify_from'            => (string) get_option( 'admin_email' ),
				'slack_notifications'    => 0,
				'slack_webhook_url'      => '',
				'slack_channel_override' => '',
				'notify_plugins'         => 1,
				'notify_themes'          => 1,
				'notify_automatic'       => 1,
				'hide_updates'           => 1,
			);
		}

		/**
		 * The plugin's settings, filtered and with every value of the type the code expects.
		 *
		 * Values can arrive as strings (imports, `wp option update`, filters), and missing
		 * keys get their defaults.
		 *
		 * @return Settings
		 */
		private function get_settings(): array {
			$stored   = $this->get_set_options( self::OPT_FIELD );
			$stored   = is_array( $stored ) ? $stored : array();
			$defaults = $this->default_settings();

			return array(
				'frequency'              => $this->string_setting( $stored, 'frequency', $defaults['frequency'] ),
				'email_notifications'    => $this->int_setting( $stored, 'email_notifications', $defaults['email_notifications'] ),
				'notify_to'              => $this->string_setting( $stored, 'notify_to', $defaults['notify_to'] ),
				'notify_from'            => $this->string_setting( $stored, 'notify_from', $defaults['notify_from'] ),
				'slack_notifications'    => $this->int_setting( $stored, 'slack_notifications', $defaults['slack_notifications'] ),
				'slack_webhook_url'      => $this->string_setting( $stored, 'slack_webhook_url', $defaults['slack_webhook_url'] ),
				'slack_channel_override' => $this->string_setting( $stored, 'slack_channel_override', $defaults['slack_channel_override'] ),
				'notify_plugins'         => $this->int_setting( $stored, 'notify_plugins', $defaults['notify_plugins'] ),
				'notify_themes'          => $this->int_setting( $stored, 'notify_themes', $defaults['notify_themes'] ),
				'notify_automatic'       => $this->int_setting( $stored, 'notify_automatic', $defaults['notify_automatic'] ),
				'hide_updates'           => $this->int_setting( $stored, 'hide_updates', $defaults['hide_updates'] ),
			);
		}

		/**
		 * A stored setting as a non-negative integer, or its default.
		 *
		 * @param array<mixed> $stored   Stored settings.
		 * @param string       $key      Setting name.
		 * @param int          $fallback Value when the setting is missing or unusable.
		 *
		 * @return int
		 */
		private function int_setting( array $stored, string $key, int $fallback ): int {
			return isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ? absint( $stored[ $key ] ) : $fallback;
		}

		/**
		 * A stored setting as a string, or its default.
		 *
		 * @param array<mixed> $stored   Stored settings.
		 * @param string       $key      Setting name.
		 * @param string       $fallback Value when the setting is missing or unusable.
		 *
		 * @return string
		 */
		private function string_setting( array $stored, string $key, string $fallback ): string {
			return isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ? (string) $stored[ $key ] : $fallback;
		}

		/**
		 * What the plugin has already notified about and when it last checked.
		 *
		 * Kept apart from the settings, in an option that is not autoloaded, so a run
		 * never overwrites settings saved meanwhile and the settings form never sees it.
		 *
		 * @return State
		 */
		private function get_state(): array {
			return $this->normalise_state( $this->get_set_options( self::OPT_STATE_FIELD ) );
		}

		/**
		 * Store the run state, creating the option without autoload if it is missing.
		 *
		 * @param array<string, mixed> $state Run state.
		 *
		 * @return void
		 */
		private function save_state( array $state ): void {
			$state = apply_filters( 'sc_wpun_put_options_filter', $state, self::OPT_STATE_FIELD );

			if ( false === get_option( self::OPT_STATE_FIELD ) ) {
				add_option( self::OPT_STATE_FIELD, $state, '', false );
			} else {
				update_option( self::OPT_STATE_FIELD, $state );
			}
		}

		/**
		 * Give the run state its expected shape and types.
		 *
		 * @param mixed $state Stored state.
		 *
		 * @return State
		 */
		private function normalise_state( $state ): array {
			$state    = is_array( $state ) ? $state : array();
			$notified = isset( $state['notified'] ) && is_array( $state['notified'] ) ? $state['notified'] : array();

			return array(
				'notified'        => array(
					'core'   => isset( $notified['core'] ) && is_scalar( $notified['core'] ) ? (string) $notified['core'] : '',
					'plugin' => $this->normalise_versions( $notified['plugin'] ?? array() ),
					'theme'  => $this->normalise_versions( $notified['theme'] ?? array() ),
				),
				'last_check_time' => ! empty( $state['last_check_time'] ) && is_numeric( $state['last_check_time'] ) ? (int) $state['last_check_time'] : false,
			);
		}


		/**
		 * Keep only scalar versions, as strings, keyed by plugin file or theme.
		 *
		 * @param mixed $versions Stored versions.
		 *
		 * @return array<string, string>
		 */
		private function normalise_versions( $versions ): array {
			if ( ! is_array( $versions ) ) {
				return array();
			}

			$clean = array();
			foreach ( $versions as $key => $version ) {
				if ( is_scalar( $version ) ) {
					$clean[ (string) $key ] = (string) $version;
				}
			}

			return $clean;
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
			// `plugins_loaded` has not run on the activation request, so a new install has no settings yet.
			$this->settings_up_to_date();
			$this->enable_cron();
		}


		/**
		 * Function that deals with de-activation of this plugin
		 *
		 * @return void
		 */
		public function deactivate() {
			do_action( 'sc_wpun_disable_cron' ); // Disable cron.
		}


		/**
		 * Enable cron for this plugin. Check if a cron should be scheduled.
		 *
		 * @param bool|string $manual_interval For setting a manual cron interval.
		 *
		 * @return void
		 */
		public function enable_cron( $manual_interval = false ) {
			$options          = $this->get_settings(); // Get settings.
			$current_schedule = wp_get_schedule( self::CRON_NAME ); // find if a schedule already exists.

			// `do_action()` without arguments passes an empty string: use the saved frequency.
			if ( ! is_string( $manual_interval ) || '' === $manual_interval ) {
				$manual_interval = false;
			}

			// if a manual cron interval is set, use this.
			if ( false !== $manual_interval ) {
				$options['frequency'] = $manual_interval;
			}

			$frequency = $options['frequency'];

			// Kept for code that calls do_action( 'sc_wpun_enable_cron', 'manual' ) to stop the checks.
			if ( 'manual' === $frequency ) {
				do_action( 'sc_wpun_disable_cron' ); // Make sure no cron is setup as we are manual.
				return;
			}

			if ( ! array_key_exists( $frequency, $this->get_frequencies() ) ) {
				// A schedule that no longer exists (for example one a deactivated plugin
				// registered): an event that is already running keeps its interval, which
				// WordPress stored with it. Without one, check at the default frequency
				// rather than not at all.
				if ( false !== $current_schedule ) {
					return;
				}
				$frequency = self::DEFAULT_FREQUENCY;
			}

			// check if the current schedule matches the one set in settings.
			if ( $current_schedule === $frequency ) {
				return;
			}

			// Remove any cron's for this plugin first so we don't end up with multiple cron's doing the same thing.
			do_action( 'sc_wpun_disable_cron' );

			// Schedule cron for this plugin.
			wp_schedule_event( time(), $frequency, self::CRON_NAME );
		}


		/**
		 * Puts the update check back on the schedule when it is missing.
		 *
		 * Repairs sites where activation never scheduled it, and events lost for any
		 * other reason. Cheap: WordPress keeps the cron array in an autoloaded option.
		 *
		 * @return void
		 */
		public function maybe_schedule() {
			if ( false === wp_next_scheduled( self::CRON_NAME ) ) {
				$this->enable_cron();
			}
		}


		/**
		 * Removes cron for this plugin.
		 *
		 * @return void
		 */
		public function disable_cron() {
			wp_clear_scheduled_hook( self::CRON_NAME ); // clear cron.
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
			$options  = $this->get_settings(); // get settings.
			$state    = $this->get_state();
			$notified = $state['notified']; // What this run will record, if a notification goes out.
			$message  = ''; // start with a blank message.

			$core_updated = $this->core_update_check( $message, $notified ); // check the WP core for updates.
			if ( 0 !== $options['notify_plugins'] ) { // are we to check for plugin updates?
				$plugins_updated = $this->plugins_update_check( $message, $options['notify_plugins'], $notified ); // check for plugin updates.
			} else {
				$plugins_updated = false; // no plugin updates.
			}
			if ( 0 !== $options['notify_themes'] ) { // are we to check for theme updates?
				$themes_updated = $this->themes_update_check( $message, $options['notify_themes'], $notified ); // check for theme updates.
			} else {
				$themes_updated = false; // no theme updates.
			}

			if ( $core_updated || $plugins_updated || $themes_updated ) { // Did anything come back as need updating?
				$message = __( 'There are updates available for your WordPress site:', 'wp-updates-notifier' ) . ' ' . esc_html( get_bloginfo() ) . ' @ ' . esc_url( home_url() ) . "\n" . $message . "\n";
				// translators: %s: URL of the WordPress updates screen.
				$message .= sprintf( __( 'Please visit %s to update.', 'wp-updates-notifier' ), admin_url( 'update-core.php' ) );

				$delivered = false;

				// Send email notification.
				if ( 1 === $options['email_notifications'] && $this->send_email_message( $message ) ) {
					$delivered = true;
				}

				// Send slack notification. send_slack_message() returns true on failure (#66).
				if ( 1 === $options['slack_notifications'] && ! $this->send_slack_message( $message ) ) {
					$delivered = true;
				}

				// Only remember what was announced if it reached someone, so a failed send is retried.
				if ( $delivered ) {
					$state['notified'] = $notified;
				}
			} else {
				$state['notified'] = $notified; // Nothing new: keep the forgetting of updated items.
			}

			$state['last_check_time'] = time();
			$this->save_state( $state );
		}


		/**
		 * Checks to see if any WP core updates
		 *
		 * @param string $message  holds message to be sent via notification.
		 * @param array  $notified What has been notified about; updated with what this check reports.
		 * @phpstan-param Notified $notified
		 *
		 * @return bool
		 */
		private function core_update_check( string &$message, array &$notified ): bool {
			do_action( 'wp_version_check' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
			$update_core = get_site_transient( 'update_core' ); // get information of updates.

			// No usable data (new site, api.wordpress.org unreachable): we know nothing,
			// so keep what was notified instead of forgetting it.
			if ( ! is_object( $update_core ) || empty( $update_core->updates ) || ! is_array( $update_core->updates ) ) {
				return false;
			}

			$new_core_ver = $this->core_upgrade_offer( $update_core->updates );
			if ( '' === $new_core_ver ) {
				$notified['core'] = ''; // no updates lets set this nothing.
				return false; // no updates return false.
			}

			if ( $new_core_ver === $notified['core'] ) { // have we already notified about this version?
				return false; // There are updates but we have already notified in the past.
			}

			$old_core_ver = wp_get_wp_version(); // Read from version.php, so plugins that change the $wp_version global do not affect it.
			// translators: 1: Installed WordPress version. 2: New WordPress version.
			$message         .= "\n" . sprintf( __( 'WP-Core: WordPress is out of date. Please update from version %1$s to %2$s', 'wp-updates-notifier' ), $old_core_ver, $new_core_ver ) . "\n";
			$notified['core'] = $new_core_ver; // set core version we are notifying about.
			return true; // we have updates so return true.
		}

		/**
		 * The version of the first core update offer that is an upgrade.
		 *
		 * WordPress lists several offers (the latest release, other locales,
		 * automatic update packages); only one with the "upgrade" response is
		 * something to tell the site owner about.
		 *
		 * @param array<mixed> $offers The `updates` list of the update_core transient.
		 *
		 * @return string The offered version, or an empty string if there is no upgrade.
		 */
		private function core_upgrade_offer( array $offers ): string {
			foreach ( $offers as $offer ) {
				if ( is_object( $offer ) && isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response && is_scalar( $offer->current ) && '' !== (string) $offer->current ) {
					return (string) $offer->current;
				}
			}

			return '';
		}


		/**
		 * Check to see if any plugin updates.
		 *
		 * @param string $message     Holds message to be sent via notification.
		 * @param int    $all_or_active Should we look for all plugins or just active ones.
		 * @param array  $notified      What has been notified about; updated with what this check reports.
		 * @phpstan-param Notified $notified
		 *
		 * @return bool
		 */
		private function plugins_update_check( string &$message, $all_or_active, array &$notified ): bool {
			do_action( 'wp_update_plugins' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
			$update_plugins = get_site_transient( 'update_plugins' ); // get information of updates.
			$pending        = $this->normalise_plugin_updates( is_object( $update_plugins ) && isset( $update_plugins->response ) ? $update_plugins->response : array() );

			// Forget plugins that are no longer waiting for an update (updated or deleted).
			$notified['plugin'] = array_intersect_key( $notified['plugin'], $pending );

			if ( ! empty( $pending ) ) { // any plugin updates available?
				$plugins_need_update = $pending; // plugins that need updating.
				if ( 2 === $all_or_active ) { // are we to check just active plugins?
					$plugins_need_update = array_intersect_key( $plugins_need_update, array_flip( $this->active_plugins() ) ); // only keep plugins that are active.
				}
				$plugins_need_update = $this->normalise_plugin_updates( apply_filters( 'sc_wpun_plugins_need_update', $plugins_need_update ) ); // additional filtering of plugins need update; filters may return anything.
				if ( count( $plugins_need_update ) >= 1 ) { // any plugins need updating after all the filtering gone on above?
					if ( ! function_exists( 'get_plugin_data' ) ) {
						require_once ABSPATH . 'wp-admin/includes/plugin.php'; // Not loaded in cron requests.
					}
					foreach ( $plugins_need_update as $key => $data ) { // loop through the plugins that need updating.
						$plugin_info = get_plugin_data( WP_PLUGIN_DIR . '/' . $key, false, false ); // get local plugin info.
						$wporg_slug  = $this->wporg_plugin_slug( $data );
						// translators: 1: Plugin name. 2: Installed plugin version. 3: New plugin version.
						$message .= "\n" . sprintf( __( 'Plugin: %1$s is out of date. Please update from version %2$s to %3$s', 'wp-updates-notifier' ), $plugin_info['Name'], $plugin_info['Version'], $data->new_version ) . "\n";
						if ( '' !== $wporg_slug ) {
							// translators: %s: URL of the plugin page.
							$message .= "\t" . sprintf( __( 'Details: %s', 'wp-updates-notifier' ), 'https://wordpress.org/plugins/' . $wporg_slug . '/' ) . "\n";
							// translators: %s: URL of the changelog of the plugin.
							$message .= "\t" . sprintf( __( 'Changelog: %s', 'wp-updates-notifier' ), 'https://wordpress.org/plugins/' . $wporg_slug . '/#developers' ) . "\n";
						} elseif ( '' !== $data->url ) {
							// translators: %s: URL of the plugin page.
							$message .= "\t" . sprintf( __( 'Details: %s', 'wp-updates-notifier' ), $data->url ) . "\n";
						}
						foreach ( $this->plugin_compatibility_notes( $data ) as $note ) {
							$message .= "\t" . $note . "\n";
						}
						$notified['plugin'][ (string) $key ] = (string) $data->new_version; // set plugin version we are notifying about.
					}
					return true; // we have plugin updates return true.
				}
			}
			return false; // No plugin updates so return false.
		}


		/**
		 * Check to see if any theme updates.
		 *
		 * @param string $message     Holds message to be sent via notification.
		 * @param int    $all_or_active Should we look for all themes or just active ones.
		 * @param array  $notified      What has been notified about; updated with what this check reports.
		 * @phpstan-param Notified $notified
		 *
		 * @return bool
		 */
		private function themes_update_check( string &$message, $all_or_active, array &$notified ): bool {
			do_action( 'wp_update_themes' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
			$update_themes = get_site_transient( 'update_themes' ); // get information of updates.
			$pending       = $this->normalise_theme_updates( is_object( $update_themes ) && isset( $update_themes->response ) ? $update_themes->response : array() );

			// Forget themes that are no longer waiting for an update (updated or deleted).
			$notified['theme'] = array_intersect_key( $notified['theme'], $pending );

			if ( ! empty( $pending ) ) { // any theme updates available?
				$themes_need_update = $pending; // themes that need updating.
				if ( 2 === $all_or_active ) { // are we to check just active themes?
					$active_themes      = array_flip( array_unique( array( get_stylesheet(), get_template() ) ) ); // The active theme and, for a child theme, its parent.
					$themes_need_update = array_intersect_key( $themes_need_update, $active_themes ); // only keep theme that is active.
				}
				$themes_need_update = $this->normalise_theme_updates( apply_filters( 'sc_wpun_themes_need_update', $themes_need_update ) ); // additional filtering of themes need update; filters may return anything.
				if ( count( $themes_need_update ) >= 1 ) { // any themes need updating after all the filtering gone on above?
					foreach ( $themes_need_update as $key => $data ) { // loop through the themes that need updating.
						$theme_info = wp_get_theme( $key ); // get theme info.
						// translators: 1: Theme name. 2: Installed theme version. 3: New theme version.
						$message .= "\n" . sprintf( __( 'Theme: %1$s is out of date. Please update from version %2$s to %3$s', 'wp-updates-notifier' ), $theme_info['Name'], $theme_info['Version'], $data['new_version'] ) . "\n";
						if ( '' !== $data['url'] ) {
							// translators: %s: URL of the theme page.
							$message .= "\t" . sprintf( __( 'Details: %s', 'wp-updates-notifier' ), $data['url'] ) . "\n";
						}
						$notified['theme'][ (string) $key ] = $data['new_version']; // set theme version we are notifying about.
					}
					return true; // we have theme updates return true.
				}
			}
			return false; // No theme updates so return false.
		}


		/**
		 * Plugin update entries as objects with the properties the plugin reads.
		 *
		 * Updaters for plugins outside WordPress.org add their own entries to the
		 * update_plugins transient, sometimes as arrays, often without `url` or
		 * `slug`. Entries without a new version are dropped: there is nothing to
		 * report about them.
		 *
		 * @param mixed $response The transient's `response`.
		 *
		 * @return array<string, \stdClass> Entries keyed by plugin file, each with string `new_version`, `url`, `slug` and `id`.
		 */
		private function normalise_plugin_updates( $response ): array {
			$updates = array();

			foreach ( is_array( $response ) ? $response : array() as $file => $entry ) {
				if ( is_array( $entry ) ) {
					$entry = (object) $entry;
				} elseif ( is_object( $entry ) && ! $entry instanceof stdClass ) {
					$entry = (object) get_object_vars( $entry );
				}
				if ( ! $entry instanceof stdClass || ! isset( $entry->new_version ) || ! is_scalar( $entry->new_version ) || '' === (string) $entry->new_version ) {
					continue;
				}

				$entry->new_version = (string) $entry->new_version;
				foreach ( array( 'url', 'slug', 'id' ) as $property ) {
					$entry->$property = isset( $entry->$property ) && is_scalar( $entry->$property ) ? (string) $entry->$property : '';
				}

				$updates[ (string) $file ] = $entry;
			}

			return $updates;
		}

		/**
		 * Theme update entries as arrays with the keys the plugin reads.
		 *
		 * @param mixed $response The update_themes transient's `response`.
		 *
		 * @return array<string, array{new_version: string, url: string}> Entries keyed by stylesheet.
		 */
		private function normalise_theme_updates( $response ): array {
			$updates = array();

			foreach ( is_array( $response ) ? $response : array() as $stylesheet => $entry ) {
				$entry = is_object( $entry ) ? get_object_vars( $entry ) : $entry;
				if ( ! is_array( $entry ) || ! isset( $entry['new_version'] ) || ! is_scalar( $entry['new_version'] ) || '' === (string) $entry['new_version'] ) {
					continue;
				}

				$entry['new_version'] = (string) $entry['new_version'];
				$entry['url']         = isset( $entry['url'] ) && is_scalar( $entry['url'] ) ? (string) $entry['url'] : '';

				$updates[ (string) $stylesheet ] = $entry;
			}

			return $updates;
		}

		/**
		 * Lines about whether a plugin update suits this site, from the update data.
		 *
		 * WordPress.org puts `tested`, `requires` and `requires_php` in each entry of
		 * the update_plugins transient, so no request per plugin is needed. "Tested
		 * up to 6.5" covers every 6.5.x release, so only major.minor is compared.
		 *
		 * @param \stdClass $entry A normalised plugin update entry.
		 *
		 * @return string[]
		 */
		private function plugin_compatibility_notes( stdClass $entry ): array {
			$site_wp = $this->major_minor( wp_get_wp_version() );
			$tested  = isset( $entry->tested ) && is_scalar( $entry->tested ) ? (string) $entry->tested : '';
			$notes   = array();

			if ( '' === $tested ) {
				// translators: %s: WordPress version of the site, for example 6.5.
				$notes[] = sprintf( __( 'Compatibility with WordPress %s: unknown', 'wp-updates-notifier' ), $site_wp );
			} elseif ( version_compare( $this->major_minor( $tested ), $site_wp, '>=' ) ) {
				// translators: %s: WordPress version of the site, for example 6.5.
				$notes[] = sprintf( __( 'Compatibility with WordPress %s: tested by its author', 'wp-updates-notifier' ), $site_wp );
			} else {
				// translators: 1: WordPress version of the site. 2: Latest WordPress version the plugin was tested with.
				$notes[] = sprintf( __( 'Compatibility with WordPress %1$s: not tested yet (tested up to %2$s)', 'wp-updates-notifier' ), $site_wp, $tested );
			}

			$requires_php = isset( $entry->requires_php ) && is_scalar( $entry->requires_php ) ? (string) $entry->requires_php : '';
			if ( '' !== $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) {
				// translators: 1: PHP version the update needs. 2: PHP version of the site.
				$notes[] = sprintf( __( 'Warning: this version needs PHP %1$s; the site runs PHP %2$s.', 'wp-updates-notifier' ), $requires_php, PHP_VERSION );
			}

			$requires_wp = isset( $entry->requires ) && is_scalar( $entry->requires ) ? (string) $entry->requires : '';
			if ( '' !== $requires_wp && version_compare( wp_get_wp_version(), $requires_wp, '<' ) ) {
				// translators: 1: WordPress version the update needs. 2: WordPress version of the site.
				$notes[] = sprintf( __( 'Warning: this version needs WordPress %1$s; the site runs WordPress %2$s.', 'wp-updates-notifier' ), $requires_wp, wp_get_wp_version() );
			}

			return $notes;
		}

		/**
		 * The major.minor part of a version, for example 6.5 from 6.5.3 or 6.6-RC1.
		 *
		 * @param string $version Version.
		 *
		 * @return string
		 */
		private function major_minor( string $version ): string {
			$parts = explode( '.', (string) preg_replace( '/-.*$/', '', $version ) );

			return implode( '.', array_slice( $parts, 0, 2 ) );
		}

		/**
		 * The WordPress.org slug of a plugin update, or an empty string if it does not come from WordPress.org.
		 *
		 * WordPress.org entries carry an `id` like "w.org/plugins/akismet" and a URL
		 * under https://wordpress.org/plugins/; updaters for other plugins do not.
		 *
		 * @param \stdClass $entry A normalised plugin update entry.
		 *
		 * @return string
		 */
		private function wporg_plugin_slug( stdClass $entry ): string {
			$id         = (string) $entry->id;
			$slug       = (string) $entry->slug;
			$from_wporg = 0 === strpos( $id, 'w.org/plugins/' )
				|| 1 === preg_match( '#^https?://(www\.)?WordPress\.org/plugins/#', (string) $entry->url );

			if ( ! $from_wporg ) {
				return '';
			}

			$slug = '' !== $slug ? $slug : basename( $id );

			return 1 === preg_match( '/^[a-z0-9-]+$/', $slug ) ? $slug : '';
		}

		/**
		 * Plugins active on this site, including those network-activated on multisite.
		 *
		 * @return string[] Plugin files relative to the plugins directory.
		 */
		private function active_plugins(): array {
			$active = array_filter( (array) get_option( 'active_plugins', array() ), 'is_string' );

			if ( is_multisite() ) {
				$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
				$active  = array_merge( $active, array_filter( $network, 'is_string' ) );
			}

			return array_values( array_unique( $active ) );
		}

		/**
		 * Filter for removing plugins from update list if already been notified about
		 *
		 * @param array $plugins_need_update Array of plugins that need an update.
		 *
		 * @return array $plugins_need_update
		 */
		public function check_plugins_against_notified( $plugins_need_update ) {
			if ( ! is_array( $plugins_need_update ) ) {
				return $plugins_need_update;
			}
			$notified = $this->get_state()['notified'];
			foreach ( $plugins_need_update as $key => $data ) { // loop through plugins that need update.
				if ( isset( $notified['plugin'][ $key ], $data->new_version ) ) { // has this plugin been notified before?
					if ( (string) $data->new_version === $notified['plugin'][ $key ] ) { // does this plugin version match that of the one that's been notified?
						unset( $plugins_need_update[ $key ] ); // don't notify this plugin as has already been notified.
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
			if ( ! is_array( $themes_need_update ) ) {
				return $themes_need_update;
			}
			$notified = $this->get_state()['notified'];
			foreach ( $themes_need_update as $key => $data ) { // loop through themes that need update.
				if ( isset( $notified['theme'][ $key ], $data['new_version'] ) ) { // has this theme been notified before?
					if ( (string) $data['new_version'] === $notified['theme'][ $key ] ) { // does this theme version match that of the one that's been notified?
						unset( $themes_need_update[ $key ] ); // don't notify this theme as has already been notified.
					}
				}
			}
			return $themes_need_update;
		}


		/**
		 * Sends email message.
		 *
		 * @param string $message Holds message to be sent in body of email.
		 *
		 * @return bool Whether the email contents were sent successfully.
		 */
		public function send_email_message( $message ) {
			$settings = $this->get_settings(); // get settings.

			// translators: %s: Site URL.
			$subject = sprintf( __( 'WP Updates Notifier: Updates Available @ %s', 'wp-updates-notifier' ), home_url() );
			add_filter( 'wp_mail_from', array( $this, 'sc_wpun_wp_mail_from' ) ); // add from filter.
			add_filter( 'wp_mail_from_name', array( $this, 'sc_wpun_wp_mail_from_name' ) ); // add from name filter.
			add_filter( 'wp_mail_content_type', array( $this, 'sc_wpun_wp_mail_content_type' ) ); // add content type filter.
			// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			$response = wp_mail( $settings['notify_to'], apply_filters( 'sc_wpun_email_subject', $subject ), apply_filters( 'sc_wpun_email_content', esc_html( $message ) ) ); // send email.
			// phpcs:enable
			remove_filter( 'wp_mail_from', array( $this, 'sc_wpun_wp_mail_from' ) ); // remove from filter.
			remove_filter( 'wp_mail_from_name', array( $this, 'sc_wpun_wp_mail_from_name' ) ); // remove from name filter.
			remove_filter( 'wp_mail_content_type', array( $this, 'sc_wpun_wp_mail_content_type' ) ); // remove content type filter.

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
			$settings = $this->get_settings(); // get settings.

			$payload = array(
				'username'   => __( 'WP Updates Notifier', 'wp-updates-notifier' ),
				'icon_emoji' => ':robot_face:',
				'text'       => esc_html( $message ),
			);

			if ( ! empty( $settings['slack_channel_override'] ) && '' !== $settings['slack_channel_override'] ) {
				$payload['channel'] = $settings['slack_channel_override'];
			}

			$response = wp_remote_post(
				$settings['slack_webhook_url'],
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
			$settings = $this->get_settings();
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
			return 'text/plain';
		}

		/**
		 * Filter the background update notification email
		 *
		 * @param array $email Array of email arguments that will be passed to wp_mail().
		 *
		 * @return array Modified array containing the new email address.
		 */
		public function filter_auto_core_update_email( $email ) {
			$options = $this->get_settings(); // Get settings.

			if ( 0 !== $options['notify_automatic'] ) {
				if ( ! empty( $options['notify_to'] ) ) { // If an email address has been set, override the WordPress default.
					$email['to'] = $options['notify_to'];
				}

				if ( ! empty( $options['notify_from'] ) ) { // If an email address has been set, override the WordPress default.
					$email['headers'] = $this->normalise_mail_headers( $email['headers'] ?? array() );

					if ( ! $this->has_mail_header( $email['headers'], 'From' ) ) {
						$email['headers'][] = 'From: ' . $this->sc_wpun_wp_mail_from_name() . ' <' . $options['notify_from'] . '>';
					}
				}
			}

			return $email;
		}

		/**
		 * Turn mail headers into a list of header lines, the way wp_mail() reads them.
		 *
		 * Core builds the automatic update emails with `headers` as an empty string, and
		 * other filters may pass a newline-separated string or an array.
		 *
		 * @param mixed $headers Headers as a string, an array or anything else.
		 *
		 * @return array<mixed> List of header lines (an array passed in is returned as it is).
		 */
		private function normalise_mail_headers( $headers ): array {
			if ( is_array( $headers ) ) {
				return $headers;
			}

			if ( ! is_string( $headers ) || '' === trim( $headers ) ) {
				return array();
			}

			return array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r\n", "\n", $headers ) ) ) ) );
		}

		/**
		 * Whether a list of header lines already contains the given header.
		 *
		 * @param array<mixed> $headers List of header lines.
		 * @param string       $name    Header name, for example "From".
		 *
		 * @return bool
		 */
		private function has_mail_header( array $headers, string $name ): bool {
			foreach ( $headers as $header ) {
				if ( is_string( $header ) && 0 === stripos( ltrim( $header ), $name . ':' ) ) {
					return true;
				}
			}

			return false;
		}


		/**
		 * Hides WordPress's "update available" notice from users who cannot update WordPress.
		 *
		 * The notice is about core updates, and core itself words it by the
		 * `update_core` capability, so that is what decides who still sees it.
		 *
		 * @return void
		 */
		public function remove_update_nag_for_nonadmins() {
			$settings = $this->get_settings(); // get settings.
			if ( 1 === $settings['hide_updates'] && ! current_user_can( 'update_core' ) ) {
				remove_action( 'admin_notices', 'update_nag', 3 );
				remove_action( 'network_admin_notices', 'update_nag', 3 );
			}
		}

		/**
		 * The frequencies the plugin offers, shortest first.
		 *
		 * A curated list rather than every schedule other plugins register, so the
		 * choice does not change from site to site and cannot disappear with another
		 * plugin. Schedules shorter than an hour are never offered: each check also
		 * asks api.wordpress.org for update data.
		 *
		 * @return array<string, array{interval: int, display: string}> Schedules keyed by name.
		 */
		private function get_frequencies(): array {
			/**
			 * Filters the names of the cron schedules offered as check frequencies.
			 *
			 * Each must be a registered schedule (see the `cron_schedules` filter) of at
			 * least an hour.
			 *
			 * @since 2.0.0
			 *
			 * @param string[] $names Schedule names. Default hourly, twicedaily, daily and weekly.
			 */
			$names     = apply_filters( 'sc_wpun_frequencies', array( 'hourly', 'twicedaily', 'daily', 'weekly' ) );
			$schedules = wp_get_schedules();
			$offered   = array();

			foreach ( (array) $names as $name ) {
				if ( ! is_string( $name ) || ! isset( $schedules[ $name ]['interval'], $schedules[ $name ]['display'] ) ) {
					continue;
				}

				$interval = (int) $schedules[ $name ]['interval'];
				if ( $interval >= HOUR_IN_SECONDS ) {
					$offered[ $name ] = array(
						'interval' => $interval,
						'display'  => (string) $schedules[ $name ]['display'],
					);
				}
			}

			uasort( $offered, array( $this, 'sort_by_interval' ) );

			return $offered;
		}

		/**
		 * Sort schedules by interval, shortest first.
		 *
		 * @param array{interval: int} $a A schedule.
		 * @param array{interval: int} $b A schedule.
		 *
		 * @return int
		 */
		private function sort_by_interval( $a, $b ): int {
			return $a['interval'] <=> $b['interval'];
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

			$options    = $this->get_state();
			$next_check = wp_next_scheduled( self::CRON_NAME );
			?>
			<div class="wrap">
				<h2><?php esc_html_e( 'Updates Notifier', 'wp-updates-notifier' ); ?></h2>

				<p>
					<span class="description sc-wpun-last-check">
					<?php
					if ( empty( $options['last_check_time'] ) ) {
						esc_html_e( 'Last checked: never', 'wp-updates-notifier' );
					} else {
						// translators: %s: Date and time of the last check.
						echo esc_html( sprintf( __( 'Last checked: %s', 'wp-updates-notifier' ), $this->format_site_time( $options['last_check_time'] ) ) );
					}
					?>
					</span>
					<br />
					<span class="description sc-wpun-next-check">
					<?php
					if ( false === $next_check ) {
						esc_html_e( 'Next check: not scheduled. Save the settings to schedule it.', 'wp-updates-notifier' );
					} else {
						// translators: %s: Date and time of the next check.
						echo esc_html( sprintf( __( 'Next check: %s', 'wp-updates-notifier' ), $this->format_site_time( $next_check ) ) );
					}
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
		 * A timestamp as a date and time in the site's timezone and formats.
		 *
		 * @param int $timestamp Unix timestamp.
		 *
		 * @return string
		 */
		private function format_site_time( int $timestamp ): string {
			return sprintf(
				// translators: 1: Date. 2: Time.
				__( '%1$s at %2$s', 'wp-updates-notifier' ),
				wp_date( (string) get_option( 'date_format' ), $timestamp ),
				wp_date( (string) get_option( 'time_format' ), $timestamp )
			);
		}

		/**
		 * Add all of the settings for the settings page.
		 *
		 * @return void
		 */
		public function admin_settings_init() {
			register_setting( self::OPT_FIELD, self::OPT_FIELD, array( $this, 'sc_wpun_settings_validate' ) ); // Register Settings.

			add_settings_section( 'sc_wpun_settings_main', __( 'Settings', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_text' ), 'wp-updates-notifier' ); // Make settings main section.
			add_settings_field( 'sc_wpun_settings_main_frequency', __( 'Frequency to check', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_frequency' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_plugins', __( 'Notify about plugin updates?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_plugins' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_themes', __( 'Notify about theme updates?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_themes' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_notify_automatic', __( 'Notify automatic core updates to this address?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_automatic' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
			add_settings_field( 'sc_wpun_settings_main_hide_updates', __( 'Hide the WordPress update notice from users who cannot update WordPress?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_hide_updates' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );

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
			// Runs for every update_option() of the settings once admin_init has
			// registered them, not only for the settings form (options.php checks
			// the form's nonce itself). Start from the stored settings.
			$valid = $this->get_settings();

			if ( ! is_array( $input ) ) {
				return $valid;
			}

			// Validate main settings.
			$frequency = $input['frequency'] ?? ( $valid['frequency'] ?? '' );
			if ( array_key_exists( $frequency, $this->get_frequencies() ) ) {
				$valid['frequency'] = $frequency;
			} else {
				add_settings_error( 'sc_wpun_settings_main_frequency', 'sc_wpun_settings_main_frequency_error', __( 'Invalid frequency entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_notify_plugins = absint( $input['notify_plugins'] ?? 0 );
			if ( $sanitized_notify_plugins <= 2 ) {
				$valid['notify_plugins'] = $sanitized_notify_plugins;
			} else {
				add_settings_error( 'sc_wpun_settings_main_notify_plugins', 'sc_wpun_settings_main_notify_plugins_error', __( 'Invalid plugin updates value entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_notify_themes = absint( $input['notify_themes'] ?? 0 );
			if ( $sanitized_notify_themes <= 2 ) {
				$valid['notify_themes'] = $sanitized_notify_themes;
			} else {
				add_settings_error( 'sc_wpun_settings_main_notify_themes', 'sc_wpun_settings_main_notify_themes_error', __( 'Invalid theme updates value entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_notify_automatic = absint( $input['notify_automatic'] ?? 0 );
			if ( $sanitized_notify_automatic <= 1 ) {
				$valid['notify_automatic'] = $sanitized_notify_automatic;
			} else {
				add_settings_error( 'sc_wpun_settings_main_notify_automatic', 'sc_wpun_settings_main_notify_automatic_error', __( 'Invalid automatic updates value entered', 'wp-updates-notifier' ), 'error' );
			}

			$sanitized_hide_updates = absint( $input['hide_updates'] ?? 0 );
			if ( $sanitized_hide_updates <= 1 ) {
				$valid['hide_updates'] = $sanitized_hide_updates;
			} else {
				add_settings_error( 'sc_wpun_settings_main_hide_updates', 'sc_wpun_settings_main_hide_updates_error', __( 'Invalid hide updates value entered', 'wp-updates-notifier' ), 'error' );
			}

			// Validate email notification settings.
			if ( ! empty( $input['notify_to'] ) && is_string( $input['notify_to'] ) ) {
				$sanitized_emails = array();
				$was_error        = false;
				foreach ( explode( ',', $input['notify_to'] ) as $email_to ) {
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
			} else {
				$valid['notify_to'] = '';
			}

			if ( ! empty( $input['notify_from'] ) && is_string( $input['notify_from'] ) ) {
				$sanitized_email_from = sanitize_email( $input['notify_from'] );
				if ( is_email( $sanitized_email_from ) ) {
					$valid['notify_from'] = $sanitized_email_from;
				} else {
					add_settings_error( 'sc_wpun_settings_email_notifications_notify_from', 'sc_wpun_settings_email_notifications_notify_from_error', __( 'Invalid email from entered', 'wp-updates-notifier' ), 'error' );
				}
			} else {
				$valid['notify_from'] = '';
			}

			$email_notifications = absint( $input['email_notifications'] ?? 0 );
			if ( 1 < $email_notifications ) {
				add_settings_error( 'sc_wpun_settings_email_notifications_email_notifications', 'sc_wpun_settings_email_notifications_email_notifications_error', __( 'Invalid notification email value entered', 'wp-updates-notifier' ), 'error' );
			}

			if ( 1 === $email_notifications ) {
				if ( ! empty( $valid['notify_to'] ) && ! empty( $valid['notify_from'] ) ) {
					$email_notifications = 1;
				} else {
					add_settings_error( 'sc_wpun_settings_email_notifications_email_notifications', 'sc_wpun_settings_email_notifications_email_notifications_error', __( 'Can not enable email notifications, addresses are not valid', 'wp-updates-notifier' ), 'error' );
					$email_notifications = 0;
				}
			}
			$valid['email_notifications'] = $email_notifications;

			// Validate slack settings.
			if ( ! empty( $input['slack_webhook_url'] ) && is_string( $input['slack_webhook_url'] ) ) {
				$webhook_url = $this->sanitize_webhook_url( $input['slack_webhook_url'] );
				if ( '' === $webhook_url ) {
					add_settings_error( 'sc_wpun_settings_slack_notifications_slack_webhook_url', 'sc_wpun_settings_slack_notifications_slack_webhook_url_error', __( 'Invalid webhook url entered', 'wp-updates-notifier' ), 'error' );
				} else {
					$valid['slack_webhook_url'] = $webhook_url;
				}
			} else {
				$valid['slack_webhook_url'] = '';
			}

			if ( ! empty( $input['slack_channel_override'] ) && is_string( $input['slack_channel_override'] ) ) {
				if ( ! preg_match( '/^[#@]/', $input['slack_channel_override'] ) ) {
					add_settings_error( 'sc_wpun_settings_slack_notifications_slack_channel_override', 'sc_wpun_settings_slack_notifications_slack_channel_override_error', __( 'Channel name must start with a # or @', 'wp-updates-notifier' ), 'error' );
				} elseif ( preg_match( '/\s/', $input['slack_channel_override'] ) ) {
					add_settings_error( 'sc_wpun_settings_slack_notifications_slack_channel_override', 'sc_wpun_settings_slack_notifications_slack_channel_override_error', __( 'Channel name must not contain a space', 'wp-updates-notifier' ), 'error' );
				} else {
					$valid['slack_channel_override'] = $input['slack_channel_override'];
				}
			} else {
				$valid['slack_channel_override'] = '';
			}

			$slack_notifications = absint( $input['slack_notifications'] ?? 0 );
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

			// Test sends are only queued from the settings form itself (see #67).
			if ( ! $this->is_settings_form_submission() ) {
				return $valid;
			}

			if ( isset( $_POST['submitwithemail'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in is_settings_form_submission().
				if ( '' !== $valid['notify_to'] && '' !== $valid['notify_from'] ) {
					set_transient( 'sc_wpun_send_test_email', 1 );
				} else {
					add_settings_error( 'sc_wpun_settings_email_notifications_email_notifications', 'sc_wpun_settings_email_notifications_email_notifications_error', __( 'Can not send test email. Email settings are invalid.', 'wp-updates-notifier' ), 'error' );
				}
			}

			if ( isset( $_POST['submitwithslack'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in is_settings_form_submission().
				if ( '' !== $valid['slack_webhook_url'] ) {
					set_transient( 'sc_wpun_send_test_slack', 1 );
				} else {
					add_settings_error( 'sc_wpun_settings_slack_notifications_slack_notifications', 'sc_wpun_settings_slack_notifications_slack_notifications_error', __( 'Can not post test slack message. Slack settings are invalid.', 'wp-updates-notifier' ), 'error' );
				}
			}

			return $valid;
		}

		/**
		 * Whether this request is the plugin's settings form being saved through options.php.
		 *
		 * @return bool
		 */
		private function is_settings_form_submission(): bool {
			if ( ! isset( $_POST['option_page'], $_POST['_wpnonce'] ) || self::OPT_FIELD !== $_POST['option_page'] ) {
				return false;
			}

			return false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), self::OPT_FIELD . '-options' );
		}

		/**
		 * Accept only an absolute https URL with a host.
		 *
		 * @param string $url URL as entered.
		 *
		 * @return string The sanitized URL, or an empty string if it is not acceptable.
		 */
		private function sanitize_webhook_url( string $url ): string {
			$url = esc_url_raw( trim( $url ), array( 'https' ) );

			return '' !== $url && '' !== (string) wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
		}

		/**
		 * Reschedule the update check when a saved frequency changes.
		 *
		 * Runs on update_option_{option}, so only when the settings really change,
		 * whoever saves them.
		 *
		 * @param mixed $old_value Previous settings.
		 * @param mixed $value     New settings.
		 *
		 * @return void
		 */
		public function settings_updated( $old_value, $value ) {
			$old_frequency = is_array( $old_value ) ? ( $old_value['frequency'] ?? null ) : null;
			$new_frequency = is_array( $value ) ? ( $value['frequency'] ?? null ) : null;

			if ( is_string( $new_frequency ) && $old_frequency !== $new_frequency ) {
				$this->enable_cron( $new_frequency );
			}
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
			$options     = $this->get_settings();
			$frequencies = $this->get_frequencies();
			$unavailable = ! array_key_exists( $options['frequency'], $frequencies );
			?>
			<select id="sc_wpun_settings_main_frequency" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[frequency]">
			<?php if ( $unavailable ) : ?>
				<option value="<?php echo esc_attr( $options['frequency'] ); ?>" selected="selected">
					<?php
					// translators: %s: Name of the saved schedule.
					echo esc_html( sprintf( __( '%s (no longer available)', 'wp-updates-notifier' ), $options['frequency'] ) );
					?>
				</option>
			<?php endif; ?>
			<?php foreach ( $frequencies as $k => $v ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $options['frequency'], $k ); ?>><?php echo esc_html( $v['display'] ); ?></option>
			<?php endforeach; ?>
			</select>
			<?php if ( $unavailable ) : ?>
				<p class="description sc-wpun-frequency-unavailable">
					<?php esc_html_e( 'The saved frequency is no longer available on this site, so checks run at the interval they were last scheduled with. Choose a frequency and save to change it.', 'wp-updates-notifier' ); ?>
				</p>
			<?php endif; ?>
			<?php
		}

		/**
		 * Settings field for notify plugins.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_plugins() {
			$options = $this->get_settings();
			?>
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="0" <?php checked( $options['notify_plugins'], 0 ); ?> /> <?php esc_html_e( 'No', 'wp-updates-notifier' ); ?>
			</label><br />
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="1" <?php checked( $options['notify_plugins'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
			</label><br />
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="2" <?php checked( $options['notify_plugins'], 2 ); ?> /> <?php esc_html_e( 'Yes but only active plugins', 'wp-updates-notifier' ); ?>
			</label>
			<?php
		}

		/**
		 * Settings field for notify themes.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_themes() {
			$options = $this->get_settings();
			?>
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_themes]" type="radio" value="0" <?php checked( $options['notify_themes'], 0 ); ?> /> <?php esc_html_e( 'No', 'wp-updates-notifier' ); ?>
			</label><br />
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_themes]" type="radio" value="1" <?php checked( $options['notify_themes'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
			</label><br />
			<label><input name="<?php echo esc_attr( self::OPT_FIELD ); ?>[notify_themes]" type="radio" value="2" <?php checked( $options['notify_themes'], 2 ); ?> /> <?php esc_html_e( 'Yes but only active themes', 'wp-updates-notifier' ); ?>
			</label>
			<?php
		}

		/**
		 * Settings field for notify automatic.
		 *
		 * @return void
		 */
		public function sc_wpun_settings_main_field_notify_automatic() {
			$options = $this->get_settings();
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
			$options = $this->get_settings();
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
			$options = $this->get_settings();
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
			$options = $this->get_settings();
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
			$options = $this->get_settings();
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
			$options = $this->get_settings();
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
			$options = $this->get_settings();
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
			$options = $this->get_settings();
			?>
			<input id="sc_wpun_settings_slack_notifications_slack_channel_override" class="regular-text" name="<?php echo esc_attr( self::OPT_FIELD ); ?>[slack_channel_override]" value="<?php echo esc_attr( $options['slack_channel_override'] ); ?>" />
			<span class="description"><?php esc_html_e( 'Not requred.', 'wp-updates-notifier' ); ?></span>
			<?php
		}
	}
}

new SC_WP_Updates_Notifier();
