<?php
/**
 * Activating for a network, moving a 2.x site's options to it, and the copies that are not network-activated (#85).
 *
 * @package wp-updates-notifier
 */

use WPUN\Plugin;
use WPUN\Scheduler;
use WPUN\Settings;

/**
 * Activation, migration and dormant copies on a multisite.
 */
class Test_Network_Activation extends WPUN_Test_Case {

	/**
	 * The options the plugin keeps, which the migration copies.
	 */
	const OPTIONS = array( 'sc_wpun_settings', 'sc_wpun_settings_ver', 'sc_wpun_state', 'sc_wpun_channels', 'sc_wpun_muted' );

	/**
	 * A second site.
	 *
	 * @var int
	 */
	private $sub_id = 0;

	/**
	 * Start with no options anywhere and no scheduled check.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			return;
		}

		$this->sub_id = self::factory()->blog->create();
		foreach ( self::OPTIONS as $name ) {
			delete_site_option( $name );
		}
		foreach ( array( get_main_site_id(), $this->sub_id ) as $blog_id ) {
			switch_to_blog( $blog_id );
			foreach ( self::OPTIONS as $name ) {
				delete_option( $name ); // Creating a site can leave defaults on it.
			}
			wp_clear_scheduled_hook( Scheduler::CRON_NAME );
			restore_current_blog();
		}
	}

	/**
	 * Skip on a single site.
	 */
	private function require_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite.' );
		}
	}

	/**
	 * The options a 2.x site had on the main site, with a channel instance of a chosen id.
	 *
	 * @return array The stored values by option name.
	 */
	private function seed_main_site_options() {
		$values = array(
			'sc_wpun_settings'     => $this->global_settings( array( 'frequency' => 'daily' ) ),
			'sc_wpun_settings_ver' => self::SETTINGS_VERSION,
			'sc_wpun_state'        => array(
				'channels'        => array( 'ops_mail' => array( 'notified' => array( 'core' => array( 'core' => '99.0.0' ) ) ) ),
				'last_check_time' => 1234,
			),
			'sc_wpun_channels'     => array(
				'ops_mail' => array(
					'type'     => 'email',
					'label'    => 'Ops',
					'enabled'  => true,
					'settings' => array( 'to' => 'ops@example.org' ),
					'routing'  => array(),
				),
			),
			'sc_wpun_muted'        => array( 'plugin' => array( 'hello.php' => array( 'until' => null ) ) ),
		);
		foreach ( $values as $name => $value ) {
			update_blog_option( get_main_site_id(), $name, $value );
		}

		return $values;
	}

	/**
	 * The next run of the check on a site, or false.
	 *
	 * @param int $blog_id Site id.
	 * @return int|false
	 */
	private function next_on( $blog_id ) {
		switch_to_blog( $blog_id );
		$next = wp_next_scheduled( Scheduler::CRON_NAME );
		restore_current_blog();

		return $next;
	}

	/**
	 * The first boot in network mode copies the main site's options, unchanged, channel ids included.
	 */
	public function test_the_first_network_boot_copies_the_main_site_options() {
		$this->require_multisite();
		$values = $this->seed_main_site_options();

		sc_wpun()->settings()->maybe_upgrade();

		foreach ( $values as $name => $value ) {
			$this->assertSame( $value, get_site_option( $name ), $name );
			$this->assertSame( $value, get_blog_option( get_main_site_id(), $name ), "$name stays on the main site." );
		}
		$this->assertSame( 'ops@example.org', sc_wpun_channels()->get( 'ops_mail' )->get_setting( 'to' ) );
		$this->assertSame( 1234, sc_wpun()->state()->get()['last_check_time'] );
	}

	/**
	 * Settings of an older version are copied with their version, so the usual upgrade brings them up to date.
	 */
	public function test_copied_older_settings_are_upgraded() {
		$this->require_multisite();
		$this->seed_main_site_options();
		update_blog_option( get_main_site_id(), 'sc_wpun_settings', array( 'frequency' => 'weekly' ) );
		update_blog_option( get_main_site_id(), 'sc_wpun_settings_ver', '12.0' );

		sc_wpun()->settings()->maybe_upgrade();

		$this->assertSame( Settings::OPT_VERSION, get_site_option( 'sc_wpun_settings_ver' ) );
		$this->assertSame( 'weekly', get_site_option( 'sc_wpun_settings' )['frequency'] );
		$this->assertArrayHasKey( 'notify_core', get_site_option( 'sc_wpun_settings' ) );
		$this->assertArrayHasKey( 'ops_mail', get_site_option( 'sc_wpun_channels' ), 'The channels are not made again.' );
	}

	/**
	 * Booting or activating again never overwrites what the network has.
	 */
	public function test_a_second_boot_or_activation_keeps_the_network_options() {
		$this->require_multisite();
		$this->seed_main_site_options();
		sc_wpun()->settings()->maybe_upgrade();

		update_site_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'weekly' ) ) );
		update_site_option( 'sc_wpun_muted', array() );
		update_blog_option( get_main_site_id(), 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'hourly' ) ) );

		sc_wpun()->settings()->maybe_upgrade();
		sc_wpun()->activate();
		sc_wpun()->activate( true );

		$this->assertSame( 'weekly', get_site_option( 'sc_wpun_settings' )['frequency'] );
		$this->assertSame( array(), get_site_option( 'sc_wpun_muted' ) );
	}

	/**
	 * A network with options already (a fresh 3.0 install) is not given the main site's.
	 */
	public function test_existing_network_options_stop_the_copy() {
		$this->require_multisite();
		$this->seed_main_site_options();
		update_site_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'weekly' ) ) );
		update_site_option( 'sc_wpun_settings_ver', self::SETTINGS_VERSION );

		sc_wpun()->settings()->maybe_upgrade();

		$this->assertFalse( get_site_option( 'sc_wpun_muted' ) );
		$this->assertSame( 'weekly', get_site_option( 'sc_wpun_settings' )['frequency'] );
	}

	/**
	 * With nothing on the main site, the network gets the defaults.
	 */
	public function test_nothing_to_copy_gives_the_defaults() {
		$this->require_multisite();

		sc_wpun()->settings()->maybe_upgrade();

		$this->assertSame( Settings::OPT_VERSION, get_site_option( 'sc_wpun_settings_ver' ) );
		$this->assertSame( 'hourly', get_site_option( 'sc_wpun_settings' )['frequency'] );
		$this->assertArrayHasKey( 'email', get_site_option( 'sc_wpun_channels' ) );
		$this->assertSame( 'none', get_option( 'sc_wpun_settings', 'none' ), 'Nothing is written to the site.' );
	}

	/**
	 * A single site never copies anything: there is no network.
	 */
	public function test_a_site_that_is_not_network_activated_copies_nothing() {
		$this->require_multisite();
		$this->seed_main_site_options();
		$this->set_network_activated( false );

		sc_wpun()->settings()->maybe_upgrade();

		$this->assertFalse( get_site_option( 'sc_wpun_settings' ) );
	}

	/**
	 * A network activation migrates, schedules the one check on the main site, and forgets it assumed the network.
	 */
	public function test_network_activation_migrates_and_schedules_on_the_main_site() {
		$this->require_multisite();
		$this->seed_main_site_options();
		$this->set_network_activated( false ); // WordPress records the activation after the hook runs.

		switch_to_blog( $this->sub_id ); // Network activation from a site of the network.
		sc_wpun()->activate( true );
		restore_current_blog();

		$this->assertSame( 'ops@example.org', sc_wpun_channels()->get( 'ops_mail' )->get_setting( 'to' ) );
		$this->assertSame( 'daily', get_site_option( 'sc_wpun_settings' )['frequency'] );
		$this->assertSame( 'daily', $this->event_schedule( get_main_site_id() ) );
		$this->assertFalse( $this->next_on( $this->sub_id ) );
		$this->assertFalse( sc_wpun()->network_context()->is_network(), 'The assumption ends with the activation.' );
	}

	/**
	 * The schedule name of the check on a site, or false.
	 *
	 * @param int $blog_id Site id.
	 * @return string|false
	 */
	private function event_schedule( $blog_id ) {
		switch_to_blog( $blog_id );
		$schedule = wp_get_schedule( Scheduler::CRON_NAME );
		restore_current_blog();

		return $schedule;
	}

	/**
	 * Activating a copy on a single site of a multisite does nothing: it creates no options and schedules no check.
	 */
	public function test_activating_on_a_single_site_is_dormant() {
		$this->require_multisite();
		$this->set_network_activated( false );

		switch_to_blog( $this->sub_id );
		sc_wpun()->activate( false );
		$this->assertSame( 'none', get_option( 'sc_wpun_settings', 'none' ) );
		restore_current_blog();

		$this->assertFalse( $this->next_on( $this->sub_id ) );
		$this->assertFalse( $this->next_on( get_main_site_id() ) );
		$this->assertFalse( get_site_option( 'sc_wpun_settings' ) );
	}

	/**
	 * Deactivating a copy on a single site removes that site's event, and a network deactivation the main site's.
	 */
	public function test_deactivation_clears_the_right_event() {
		$this->require_multisite();

		// A per-site copy, not network-activated: that site's own event goes.
		$this->set_network_activated( false );
		switch_to_blog( $this->sub_id );
		wp_schedule_event( time(), 'daily', Scheduler::CRON_NAME );
		restore_current_blog();
		wp_schedule_event( time(), 'daily', Scheduler::CRON_NAME );
		( new Plugin() )->boot(); // Dormant: registers the removal.

		switch_to_blog( $this->sub_id );
		sc_wpun()->deactivate( false );
		do_action( 'sc_wpun_disable_cron' );
		restore_current_blog();
		$this->assertFalse( $this->next_on( $this->sub_id ) );
		$this->assertNotFalse( $this->next_on( get_main_site_id() ), 'The main site\'s event is not touched.' );

		// Network-activated, a site's copy going leaves the network's check alone; the network's own deactivation stops it.
		$this->set_network_activated( true );
		sc_wpun()->deactivate( false );
		$this->assertNotFalse( $this->next_on( get_main_site_id() ) );
		switch_to_blog( $this->sub_id );
		sc_wpun()->deactivate( true );
		restore_current_blog();
		$this->assertFalse( $this->next_on( get_main_site_id() ) );
	}

	/**
	 * A copy that is not network-activated, booted in the admin, with the hooks of the network mode removed.
	 *
	 * @param string $screen Screen to boot on.
	 * @return Plugin
	 */
	private function boot_dormant_copy( $screen = 'dashboard' ) {
		$this->set_network_activated( false );
		foreach ( array( Scheduler::CRON_NAME, 'init', 'admin_menu', 'network_admin_menu', 'admin_init', 'admin_notices', 'network_admin_notices', 'site_status_tests', 'debug_information', 'sc_wpun_disable_cron', 'load-plugins.php' ) as $hook ) {
			remove_all_actions( $hook );
		}
		set_current_screen( $screen );
		$plugin = new Plugin();
		$plugin->boot();

		return $plugin;
	}

	/**
	 * Dormant: no check, no schedule, no screen, no mutes and no Site Health checks of the channels.
	 */
	public function test_a_dormant_copy_registers_none_of_the_normal_hooks() {
		$this->require_multisite();
		$plugin = $this->boot_dormant_copy();

		$this->assertFalse( has_action( Scheduler::CRON_NAME, array( $plugin->update_checker(), 'do_update_check' ) ) );
		$this->assertFalse( has_action( 'init', array( $plugin->scheduler(), 'maybe_schedule' ) ) );
		$this->assertFalse( has_action( 'admin_menu' ) );
		$this->assertFalse( has_action( 'network_admin_menu' ) );
		$this->assertFalse( has_action( 'admin_init' ) );
		$this->assertFalse( has_action( 'load-plugins.php' ) );
		$this->assertFalse( has_filter( 'debug_information' ) );
		$this->assertSame( 'none', get_option( 'sc_wpun_settings', 'none' ), 'No options are written.' );

		$tests = apply_filters( 'site_status_tests', array() );
		$this->assertSame( array( 'sc_wpun_network' ), array_keys( $tests['direct'] ) );
		$this->assertSame( 'critical', $plugin->network_activation_notice()->site_health_result()['status'] );
	}

	/**
	 * The add-ons' moment still comes.
	 */
	public function test_a_dormant_copy_still_fires_loaded() {
		$this->require_multisite();
		$fired = 0;
		add_action(
			'sc_wpun_loaded',
			static function () use ( &$fired ) {
				++$fired;
			}
		);

		$this->boot_dormant_copy();

		$this->assertSame( 1, $fired );
	}

	/**
	 * Only a user who can network-activate sees the notice, and it links to the network's plugins.
	 */
	public function test_the_notice_is_for_network_admins() {
		$this->require_multisite();
		$plugin = $this->boot_dormant_copy();
		$this->assertNotFalse( has_action( 'admin_notices' ) );
		$this->assertNotFalse( has_action( 'network_admin_notices' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		$plugin->network_activation_notice()->render();
		$this->assertSame( '', ob_get_clean(), 'A site administrator cannot act on it.' );

		$super = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $super );
		wp_set_current_user( $super );
		ob_start();
		$plugin->network_activation_notice()->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'network-activated', $html );
		$this->assertStringContainsString( esc_url( network_admin_url( 'plugins.php' ) ), $html );
		$this->assertStringContainsString( network_admin_url( 'plugins.php' ), $plugin->network_activation_notice()->site_health_result()['actions'] );
	}

	/**
	 * A check event a site kept removes itself when it fires, and sends nothing.
	 */
	public function test_a_dormant_copy_clears_a_leftover_event_without_checking() {
		$this->require_multisite();
		$this->save_settings();
		$this->set_updates( '99.0.0' );
		wp_schedule_event( time(), 'daily', Scheduler::CRON_NAME );
		$this->boot_dormant_copy();

		do_action( Scheduler::CRON_NAME );

		$this->assertFalse( wp_next_scheduled( Scheduler::CRON_NAME ) );
		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( array(), $this->http_requests );
	}

	/**
	 * The plugin can only be activated for the whole network, and says so in its header.
	 */
	public function test_the_header_asks_for_network_activation() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->assertTrue( get_plugin_data( SC_WPUN_FILE, false, false )['Network'] );
	}
}
