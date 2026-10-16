<?php
/**
 * The plugin.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use WPUN\Admin\Settings_Page;
use WPUN\Admin\Update_Nag;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Owns the components of the plugin and registers their hooks.
 *
 * @internal
 */
class Plugin {
	/**
	 * Whether the plugin has been initialised in this request.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Channel instance storage.
	 *
	 * @var Channel_Store
	 */
	private $channel_store;

	/**
	 * Run state.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Notifier.
	 *
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * Update checker.
	 *
	 * @var Update_Checker
	 */
	private $update_checker;

	/**
	 * Notification channels.
	 *
	 * @var Channel_Registry
	 */
	private $channels;

	/**
	 * Dispatcher.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Settings page.
	 *
	 * @var Settings_Page
	 */
	private $settings_page;

	/**
	 * Update nag.
	 *
	 * @var Update_Nag
	 */
	private $update_nag;

	/**
	 * Notice about Slack no longer being supported.
	 *
	 * @var Slack_Notice
	 */
	private $slack_notice;

	/**
	 * Build the components. Nothing is hooked until register() runs.
	 */
	public function __construct() {
		$this->state          = new State();
		$this->channel_store  = new Channel_Store();
		$this->settings       = new Settings( $this->state, $this->channel_store );
		$this->scheduler      = new Scheduler( $this->settings );
		$this->notifier       = new Notifier( $this->settings, $this->channel_store );
		$this->channels       = new Channel_Registry();
		$this->dispatcher     = new Dispatcher( $this->channels );
		$this->update_checker = new Update_Checker( $this->settings, $this->state, $this->dispatcher, $this->channel_store );
		$this->slack_notice   = new Slack_Notice( $this->channel_store, $this->channels, $this->state );
		$this->settings_page  = new Settings_Page( $this->settings, $this->state, $this->scheduler, $this->notifier, $this->channel_store, $this->slack_notice, $this->dispatcher );
		$this->update_nag     = new Update_Nag( $this->settings );
	}

	/**
	 * Settings.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Channel instance storage.
	 *
	 * @return Channel_Store
	 */
	public function channel_store(): Channel_Store {
		return $this->channel_store;
	}

	/**
	 * Run state.
	 *
	 * @return State
	 */
	public function state(): State {
		return $this->state;
	}

	/**
	 * Scheduler.
	 *
	 * @return Scheduler
	 */
	public function scheduler(): Scheduler {
		return $this->scheduler;
	}

	/**
	 * Update checker.
	 *
	 * @return Update_Checker
	 */
	public function update_checker(): Update_Checker {
		return $this->update_checker;
	}

	/**
	 * Notifier.
	 *
	 * @return Notifier
	 */
	public function notifier(): Notifier {
		return $this->notifier;
	}

	/**
	 * Notification channels.
	 *
	 * @return Channel_Registry
	 */
	public function channels(): Channel_Registry {
		return $this->channels;
	}

	/**
	 * Dispatcher.
	 *
	 * @return Dispatcher
	 */
	public function dispatcher(): Dispatcher {
		return $this->dispatcher;
	}

	/**
	 * Settings page.
	 *
	 * @return Settings_Page
	 */
	public function settings_page(): Settings_Page {
		return $this->settings_page;
	}

	/**
	 * Notice about Slack no longer being supported.
	 *
	 * @return Slack_Notice
	 */
	public function slack_notice(): Slack_Notice {
		return $this->slack_notice;
	}

	/**
	 * Update nag.
	 *
	 * @return Update_Nag
	 */
	public function update_nag(): Update_Nag {
		return $this->update_nag;
	}

	/**
	 * Initialise the plugin when all plugins are loaded.
	 *
	 * The activation and deactivation hooks are registered here, when the file
	 * loads: on the activation request WordPress includes the file after
	 * `plugins_loaded` has already fired, so hooks registered from `boot()` would
	 * never exist in that request.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
		register_activation_hook( SC_WPUN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( SC_WPUN_FILE, array( $this, 'deactivate' ) );
	}

	/**
	 * Register the plugin's hooks and load its translations, once per request.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Kept in step for code that read the original class's flag.
		\SC_WP_Updates_Notifier::$did_init = true;

		// Check settings are up to date.
		$this->settings->maybe_upgrade();
		// Internationalization.
		load_plugin_textdomain( 'wp-updates-notifier', false, dirname( plugin_basename( SC_WPUN_FILE ) ) . '/languages/' );
		// Add Filters.
		add_filter( 'plugin_action_links', array( $this->settings_page, 'plugin_action_links' ), 10, 2 ); // Add settings link to plugin in plugin list.
		add_filter( 'auto_core_update_email', array( $this->notifier, 'filter_auto_core_update_email' ), 1 ); // Filter the background update notification email.
		// Add Actions.
		add_action( 'admin_menu', array( $this->settings_page, 'admin_settings_menu' ) ); // Add menu to options.
		add_action( 'admin_init', array( $this->settings_page, 'admin_settings_init' ) ); // Add admin init functions.
		add_action( 'admin_init', array( $this->update_nag, 'remove_update_nag_for_nonadmins' ) ); // See if we remove update nag for non admins.
		add_action( 'sc_wpun_channel_deleted', array( $this->state, 'forget_channel' ) ); // Deleting an instance deletes its delivery state.
		add_action( 'sc_wpun_enable_cron', array( $this->scheduler, 'enable_cron' ) ); // action to enable cron.
		add_action( 'sc_wpun_disable_cron', array( $this->scheduler, 'disable_cron' ) ); // action to disable cron.
		add_action( Scheduler::CRON_NAME, array( $this->update_checker, 'do_update_check' ) ); // action to link cron task to actual task.
		add_action( Scheduler::CRON_NAME, array( $this->slack_notice, 'maybe_send_email' ), 5 ); // Tell the admin once that Slack is no longer supported, before the check.
		add_action( 'admin_post_' . Settings_Page::TEST_ACTION, array( $this->settings_page, 'handle_test_send' ) ); // Send a test message from the settings page.
		add_action( 'admin_post_' . Slack_Notice::DISMISS_ACTION, array( $this->slack_notice, 'handle_dismiss' ) ); // Dismiss the notice on the settings page.
		add_filter( 'site_status_tests', array( $this->slack_notice, 'add_site_health_test' ) ); // Site Health test while Slack settings are stored but unused.
		add_action( 'init', array( $this->scheduler, 'maybe_schedule' ) ); // Put the check back on the schedule if it is missing.
		add_action( 'update_option_' . Settings::OPT_FIELD, array( $this->scheduler, 'settings_updated' ), 10, 2 ); // Reschedule when the frequency changes.
	}

	/**
	 * Function that deals with activation of this plugin
	 *
	 * @return void
	 */
	public function activate(): void {
		// `plugins_loaded` has not run on the activation request, so a new install has no settings yet.
		$this->settings->maybe_upgrade();
		$this->scheduler->enable_cron();
	}

	/**
	 * Function that deals with de-activation of this plugin
	 *
	 * @return void
	 */
	public function deactivate(): void {
		do_action( 'sc_wpun_disable_cron' ); // Disable cron.
	}
}
