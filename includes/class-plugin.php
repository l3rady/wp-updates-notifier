<?php
/**
 * The plugin.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use WPUN\Admin\About_Tab;
use WPUN\Admin\Channel_Form;
use WPUN\Admin\Channels_Tab;
use WPUN\Admin\Check_Now;
use WPUN\Admin\Notices;
use WPUN\Admin\Settings_Page;
use WPUN\Admin\Plugin_Requirements;
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
	 * The plugin header an add-on declares the API version it needs in, as a version constraint.
	 *
	 * @var string
	 */
	public const API_HEADER = 'Requires WPUN API';

	/**
	 * The plugin's website, with the documentation. The root opens the newest release's docs.
	 *
	 * @var string
	 */
	public const SITE_URL = 'https://wpun.cariss.dev/';

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
	 * @var Settings_Page|null
	 */
	private $settings_page = null;

	/**
	 * Channels tab.
	 *
	 * @var Channels_Tab|null
	 */
	private $channels_tab = null;

	/**
	 * The About tab.
	 *
	 * @var About_Tab|null
	 */
	private $about_tab = null;

	/**
	 * The Check now button.
	 *
	 * @var Check_Now|null
	 */
	private $check_now = null;

	/**
	 * The add and edit forms of a channel.
	 *
	 * @var Channel_Form|null
	 */
	private $channel_form = null;

	/**
	 * Notices about what an admin action did.
	 *
	 * @var Notices|null
	 */
	private $notices = null;

	/**
	 * Update nag.
	 *
	 * @var Update_Nag|null
	 */
	private $update_nag = null;

	/**
	 * The notices about the API requirement of add-ons on the Plugins screen.
	 *
	 * @var Plugin_Requirements|null
	 */
	private $plugin_requirements = null;

	/**
	 * Channels tab, built on first use: front-end requests never need it.
	 *
	 * @return Channels_Tab
	 */
	public function channels_tab(): Channels_Tab {
		$this->channels_tab ??= new Channels_Tab( $this->channel_store, $this->channels, $this->dispatcher, $this->state, $this->notices(), $this->channel_form() );

		return $this->channels_tab;
	}

	/**
	 * The About tab, built on first use: front-end requests never need it.
	 *
	 * @return About_Tab
	 */
	public function about_tab(): About_Tab {
		$this->about_tab ??= new About_Tab();

		return $this->about_tab;
	}

	/**
	 * The Check now button, built on first use: front-end requests never need it.
	 *
	 * @return Check_Now
	 */
	public function check_now(): Check_Now {
		$this->check_now ??= new Check_Now( $this->channel_store, $this->dispatcher, $this->notices(), $this->update_checker );

		return $this->check_now;
	}

	/**
	 * The add and edit forms of a channel, built on first use: front-end requests never need them.
	 *
	 * @return Channel_Form
	 */
	public function channel_form(): Channel_Form {
		$this->channel_form ??= new Channel_Form( $this->channel_store, $this->channels, $this->dispatcher, $this->state, $this->notices(), $this->settings() );

		return $this->channel_form;
	}

	/**
	 * Notices about what an admin action did, built on first use.
	 *
	 * @return Notices
	 */
	public function notices(): Notices {
		$this->notices ??= new Notices();

		return $this->notices;
	}

	/**
	 * Notice about Slack no longer being supported.
	 *
	 * @var Slack_Notice
	 */
	private $slack_notice;

	/**
	 * Resolver of configuration supplied from outside the database.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Report of configuration problems.
	 *
	 * @var Config_Report
	 */
	private $config_report;

	/**
	 * Report of modules refused for needing a newer API.
	 *
	 * @var Rejected_Channels
	 */
	private $rejected_channels;

	/**
	 * Bundled translations.
	 *
	 * @var Translations
	 */
	private $translations;

	/**
	 * Build the components. Nothing is hooked until register() runs.
	 */
	public function __construct() {
		$this->translations      = new Translations();
		$this->state             = new State();
		$this->config            = new Config();
		$this->channels          = new Channel_Registry();
		$this->channel_store     = new Channel_Store( $this->config, $this->channels );
		$this->settings          = new Settings( $this->state, $this->channel_store, $this->config );
		$this->config_report     = new Config_Report( $this->config, $this->settings, $this->channel_store );
		$this->rejected_channels = new Rejected_Channels( $this->channels );
		$this->scheduler         = new Scheduler( $this->settings );
		$this->notifier          = new Notifier( $this->channel_store );
		$this->dispatcher        = new Dispatcher( $this->channels, $this->channel_store );
		$this->update_checker    = new Update_Checker( $this->settings, $this->state, $this->dispatcher, $this->channel_store );
		$this->slack_notice      = new Slack_Notice( $this->channel_store, $this->channels, $this->state );
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
	 * Resolver of configuration supplied from outside the database.
	 *
	 * @return Config
	 */
	public function config(): Config {
		return $this->config;
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
	 * Settings page, built on first use: front-end requests never need it.
	 *
	 * @return Settings_Page
	 */
	public function settings_page(): Settings_Page {
		$this->settings_page ??= new Settings_Page( $this->settings, $this->state, $this->notifier, $this->slack_notice, $this->config_report, $this->rejected_channels, $this->channels_tab(), $this->notices(), $this->check_now(), $this->about_tab() );

		return $this->settings_page;
	}

	/**
	 * Report of modules refused for needing a newer API.
	 *
	 * @return Rejected_Channels
	 */
	public function rejected_channels(): Rejected_Channels {
		return $this->rejected_channels;
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
	 * Update nag, built on first use: front-end requests never need it.
	 *
	 * @return Update_Nag
	 */
	public function update_nag(): Update_Nag {
		$this->update_nag ??= new Update_Nag( $this->settings );

		return $this->update_nag;
	}

	/**
	 * Plugins screen notices, built on first use: front-end requests never need them.
	 *
	 * @return Plugin_Requirements
	 */
	public function plugin_requirements(): Plugin_Requirements {
		$this->plugin_requirements ??= new Plugin_Requirements();

		return $this->plugin_requirements;
	}

	/**
	 * Add the plugin header an add-on declares the API version it needs in.
	 *
	 * It is registered on every request so `get_plugins()` and `get_plugin_data()` return it anywhere.
	 *
	 * @param mixed $headers The extra plugin headers.
	 * @return mixed
	 */
	public function add_plugin_header( $headers ) {
		if ( ! is_array( $headers ) ) {
			return $headers;
		}
		$headers[] = self::API_HEADER;

		return $headers;
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
		add_filter( 'extra_plugin_headers', array( $this, 'add_plugin_header' ) ); // Let add-ons declare the API version they need.
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
		// Internationalization: the bundled translations load just in time, with a language pack's strings first.
		$this->translations->register();
		// Add Actions.
		add_action( 'switch_blog', array( $this->config, 'flush' ) ); // Values are resolved per site.
		add_filter( 'site_status_tests', array( $this->config_report, 'add_site_health_test' ) ); // Site Health test while a supplied value cannot be used.
		add_filter( 'site_status_tests', array( $this->rejected_channels, 'add_site_health_test' ) ); // Site Health test while a module is refused for needing a newer API.
		add_action( 'sc_wpun_channel_deleted', array( $this->state, 'forget_channel' ) ); // Deleting an instance deletes its delivery state.
		add_action( 'sc_wpun_notification_sent', array( $this->state, 'record_delivery' ), 10, 3 ); // Remember each instance's latest delivery result.
		add_action( 'sc_wpun_notification_failed', array( $this->state, 'record_delivery' ), 10, 3 );
		/**
		 * Schedules the update check. Handled by the `Scheduler` class.
		 *
		 * Kept from 1.x. Schedules the check at the saved frequency, unless it is already scheduled at it.
		 * The plugin does this itself on every request (on `init`), so there is rarely a reason to call it.
		 * Pass a frequency to schedule at it instead of the saved one; `manual` stops the checks, as
		 * `sc_wpun_disable_cron` does.
		 *
		 *     do_action( 'sc_wpun_enable_cron' );
		 *     do_action( 'sc_wpun_enable_cron', 'daily' );
		 *
		 * @hook-listened
		 *
		 * @since 1.0.0
		 *
		 * @param string $frequency Optional. A frequency key from `sc_wpun_frequencies`, such as `daily`, or `manual`. Default is the saved frequency.
		 */
		add_action( 'sc_wpun_enable_cron', array( $this->scheduler, 'enable_cron' ) ); // action to enable cron.
		add_action( 'sc_wpun_disable_cron', array( $this->scheduler, 'disable_cron' ) ); // action to disable cron.
		/**
		 * Runs the update check now.
		 *
		 * It works as a scheduled run does: same routing, per-instance dedupe, delivery state and
		 * hooks, so items an instance was already told about are not sent again. The notification
		 * source is `manual` and the schedule is not changed. Use it from a deploy script, or from
		 * a system cron when `DISABLE_WP_CRON` is set, for example
		 * `wp eval "do_action( 'sc_wpun_run_check' );"`.
		 *
		 * There is no lock: a run that overlaps another check, scheduled or manual, can send an
		 * update the other is also sending, so it may arrive twice. A channel that must never
		 * deliver twice can keep its own record of what it sent.
		 *
		 *     do_action( 'sc_wpun_run_check' );
		 *
		 * There is no capability check. Before firing it from anything a user can reach, check
		 * `current_user_can()` with the capability from the `sc_wpun_capability` filter.
		 *
		 * @hook-listened
		 *
		 * @since 2.0.0
		 */
		add_action( 'sc_wpun_run_check', array( $this->update_checker, 'run_manual_check' ) );
		add_action( Scheduler::CRON_NAME, array( $this->update_checker, 'do_update_check' ) ); // action to link cron task to actual task.
		add_action( Scheduler::CRON_NAME, array( $this->slack_notice, 'maybe_send_email' ), 5 ); // Tell the admin once that Slack is no longer supported, before the check.
		add_action( 'admin_post_' . Slack_Notice::DISMISS_ACTION, array( $this->slack_notice, 'handle_dismiss' ) ); // Dismiss the notice on the settings page.
		add_filter( 'site_status_tests', array( $this->slack_notice, 'add_site_health_test' ) ); // Site Health test while Slack settings are stored but unused.
		add_action( 'init', array( $this->scheduler, 'maybe_schedule' ) ); // Put the check back on the schedule if it is missing.
		add_action( 'update_option_' . Settings::OPT_FIELD, array( $this->scheduler, 'settings_updated' ), 10, 2 ); // Reschedule when the frequency changes.

		if ( is_admin() ) {
			$this->register_admin_hooks();
		}

		/**
		 * Fires when the plugin has loaded and its hooks are registered.
		 *
		 * Add-ons can use it as the moment to start talking to the plugin's API.
		 *
		 * @since 2.0.0
		 */
		do_action( 'sc_wpun_loaded' );
	}

	/**
	 * Register the hooks of the admin components.
	 *
	 * Only admin requests (which include `admin-post.php` and `admin-ajax.php`) need the settings page
	 * and the update nag, so front-end requests never load their classes. Read `Channels_Tab::TEST_ACTION`
	 * only here: reading a class constant loads the class.
	 *
	 * @return void
	 */
	public function register_admin_hooks(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( SC_WPUN_FILE ), array( $this->settings_page(), 'plugin_action_links' ), 10, 2 ); // Add settings link to plugin in plugin list.
		add_filter( 'plugin_row_meta', array( $this->settings_page(), 'plugin_row_meta' ), 10, 2 ); // Link the documentation from the plugin's row.
		add_action( 'after_plugin_row', array( $this->plugin_requirements(), 'render_row' ), 10, 2 ); // Notice below an add-on that needs another API version.
		add_action( 'admin_menu', array( $this->settings_page(), 'admin_settings_menu' ) ); // Add menu to options.
		add_action( 'admin_init', array( $this->settings_page(), 'admin_settings_init' ) ); // Add admin init functions.
		add_action( 'admin_init', array( $this->update_nag(), 'remove_update_nag_for_nonadmins' ) ); // See if we remove update nag for non admins.
		add_filter( 'option_page_capability_' . Settings::OPT_FIELD, array( Settings_Page::class, 'capability' ) ); // options.php accepts saves with the same capability.
		add_action( 'admin_post_' . Channels_Tab::TEST_ACTION, array( $this->channels_tab(), 'handle_test_send' ) ); // Send a test message through a channel.
		add_action( 'admin_post_' . Channels_Tab::TOGGLE_ACTION, array( $this->channels_tab(), 'handle_toggle' ) ); // Switch a channel on or off.
		add_action( 'admin_post_' . Channels_Tab::DUPLICATE_ACTION, array( $this->channels_tab(), 'handle_duplicate' ) ); // Duplicate a channel.
		add_action( 'admin_post_' . Channels_Tab::DELETE_ACTION, array( $this->channels_tab(), 'handle_delete' ) ); // Delete a channel.
		add_action( 'admin_post_' . Check_Now::ACTION, array( $this->check_now(), 'handle' ) ); // Run the update check on demand.
		add_action( 'admin_post_' . Channel_Form::SAVE_ACTION, array( $this->channel_form(), 'handle_save' ) ); // Add or edit a channel.
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
		/**
		 * Fires when the plugin is deactivated, to turn the scheduled check off.
		 *
		 * @since 1.0.0
		 */
		do_action( 'sc_wpun_disable_cron' ); // Disable cron.
	}
}
