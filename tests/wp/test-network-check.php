<?php
/**
 * The schedule and the check of a network-activated plugin (#85).
 *
 * @package wp-updates-notifier
 */

use WPUN\Config_Context;
use WPUN\Network_Context;
use WPUN\Plugin;

/**
 * Cron on the main site only, network-wide "active only", and the network's name and links.
 */
class Test_Network_Check extends WPUN_Test_Case {

	/**
	 * A site that is not the main site.
	 *
	 * @var int
	 */
	private $sub_id = 0;

	/**
	 * Another site that is not the main site.
	 *
	 * @var int
	 */
	private $other_id = 0;

	/**
	 * Network-activate the plugin, as far as the plugin can tell, and make two more sites.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite.' );
		}

		$this->sub_id   = self::factory()->blog->create();
		$this->other_id = self::factory()->blog->create();

		// Creating a site runs `init` on it, which schedules the check there while the plugin is not yet a network's.
		foreach ( array( $this->sub_id, $this->other_id ) as $blog_id ) {
			switch_to_blog( $blog_id );
			wp_clear_scheduled_hook( 'sc_wpun_update_check' );
			restore_current_blog();
		}

		$active                                    = (array) get_site_option( 'active_sitewide_plugins', array() );
		$active[ plugin_basename( SC_WPUN_FILE ) ] = time();
		update_site_option( 'active_sitewide_plugins', $active );

		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		$this->fake_http( 'api.wordpress.org/plugins/info', $this->fake_response( 200, wp_json_encode( array( 'name' => 'Hello Dolly' ) ) ) );
	}

	/**
	 * Leave the network as found.
	 */
	public function tear_down() {
		if ( is_multisite() ) {
			$active = (array) get_site_option( 'active_sitewide_plugins', array() );
			unset( $active[ plugin_basename( SC_WPUN_FILE ) ] );
			update_site_option( 'active_sitewide_plugins', $active );
		}
		parent::tear_down();
	}

	/**
	 * Network settings and the email instance, as a network would store them.
	 *
	 * @param array $overrides Settings to override.
	 */
	private function save_network_settings( array $overrides = array() ) {
		update_site_option( 'sc_wpun_settings', $this->global_settings( $overrides ) );
		update_site_option( 'sc_wpun_settings_ver', self::SETTINGS_VERSION );
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'email', 'email', 'Email', true, array( 'to' => 'ops@example.org' ) ) );
	}

	/**
	 * The next run of the check on a site, or false.
	 *
	 * @param int $blog_id Site id.
	 * @return int|false
	 */
	private function next_on( $blog_id ) {
		switch_to_blog( $blog_id );
		$next = wp_next_scheduled( 'sc_wpun_update_check' );
		restore_current_blog();

		return $next;
	}

	/**
	 * Enabling the cron from a sub-site schedules it on the main site.
	 */
	public function test_enabling_cron_from_a_sub_site_schedules_on_the_main_site() {
		$this->save_network_settings();

		switch_to_blog( $this->sub_id );
		sc_wpun()->scheduler()->enable_cron();
		$this->assertSame( $this->sub_id, get_current_blog_id(), 'The site is restored.' );
		restore_current_blog();

		$this->assertNotFalse( $this->next_on( get_main_site_id() ) );
		$this->assertFalse( $this->next_on( $this->sub_id ) );
	}

	/**
	 * Disabling the cron from a sub-site clears the main site's event.
	 */
	public function test_disabling_cron_from_a_sub_site_clears_the_main_site_event() {
		$this->save_network_settings();
		sc_wpun()->scheduler()->enable_cron();
		$this->assertNotFalse( $this->next_on( get_main_site_id() ) );

		switch_to_blog( $this->sub_id );
		do_action( 'sc_wpun_disable_cron' );
		restore_current_blog();

		$this->assertFalse( $this->next_on( get_main_site_id() ) );
	}

	/**
	 * Scheduling on every request does nothing on a sub-site.
	 */
	public function test_maybe_schedule_does_nothing_on_a_sub_site() {
		$this->save_network_settings();

		switch_to_blog( $this->sub_id );
		sc_wpun()->scheduler()->maybe_schedule();
		restore_current_blog();

		$this->assertFalse( $this->next_on( get_main_site_id() ) );
		$this->assertFalse( $this->next_on( $this->sub_id ) );

		sc_wpun()->scheduler()->maybe_schedule();
		$this->assertNotFalse( $this->next_on( get_main_site_id() ) );
	}

	/**
	 * An event left on a sub-site removes itself and checks nothing.
	 */
	public function test_a_leftover_event_on_a_sub_site_clears_itself_and_sends_nothing() {
		$this->save_network_settings();
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '9.9.9' ) ) );
		sc_wpun()->scheduler()->enable_cron();

		switch_to_blog( $this->sub_id );
		wp_schedule_event( time(), 'hourly', 'sc_wpun_update_check' );

		remove_all_actions( 'sc_wpun_update_check' );
		( new Plugin() )->boot();
		do_action( 'sc_wpun_update_check' );
		restore_current_blog();

		$this->assertFalse( $this->next_on( $this->sub_id ) );
		$this->assertNotFalse( $this->next_on( get_main_site_id() ) );
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * One check on the main site sends one notification.
	 */
	public function test_a_check_on_the_main_site_sends_one_notification() {
		$this->save_network_settings( array( 'notify_plugins' => 'all' ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '9.9.9' ) ) );

		remove_all_actions( 'sc_wpun_update_check' );
		( new Plugin() )->boot();
		do_action( 'sc_wpun_update_check' );

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * A plugin active on one sub-site only counts as active.
	 */
	public function test_a_plugin_active_on_a_sub_site_is_active_for_the_network() {
		$this->save_network_settings( array( 'notify_plugins' => 'active' ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '9.9.9' ) ) );

		sc_wpun()->update_checker()->do_update_check();
		$this->assertSame( 0, $this->sent_mail_count(), 'Active nowhere.' );

		update_blog_option( $this->sub_id, 'active_plugins', array( 'hello.php' ) );
		sc_wpun()->update_checker()->do_update_check();
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * A theme active on another sub-site only counts as active, and so does its parent.
	 */
	public function test_a_theme_active_on_another_sub_site_is_active_for_the_network() {
		$slugs = array_keys( wp_get_themes() );
		$this->save_network_settings(
			array(
				'notify_plugins' => 'none',
				'notify_themes'  => 'active',
			)
		);
		$this->set_updates( null, array(), array( $slugs[1] => array( 'new_version' => '99.3' ) ) );
		update_blog_option( get_main_site_id(), 'stylesheet', $slugs[0] );
		update_blog_option( get_main_site_id(), 'template', $slugs[0] );

		sc_wpun()->update_checker()->do_update_check();
		$this->assertSame( 0, $this->sent_mail_count(), 'Active nowhere.' );

		update_blog_option( $this->other_id, 'stylesheet', $slugs[1] );
		update_blog_option( $this->other_id, 'template', $slugs[1] );
		sc_wpun()->update_checker()->do_update_check();
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * The notification carries the network's name and links.
	 */
	public function test_a_check_notification_has_the_networks_name_and_links() {
		$this->save_network_settings();
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '9.9.9' ) ) );
		get_network()->site_name = 'The Network';
		update_site_option( 'site_name', 'The Network' );

		$seen = null;
		add_filter(
			'sc_wpun_notification',
			static function ( $notification ) use ( &$seen ) {
				$seen = $notification;
				return $notification;
			}
		);
		switch_to_blog( $this->sub_id );
		sc_wpun()->update_checker()->do_update_check();
		restore_current_blog();

		$this->assertSame( 'The Network', $seen->get_site_name() );
		$this->assertSame( network_home_url(), $seen->get_site_url() );
		$this->assertSame( network_admin_url( 'update-core.php' ), $seen->get_updates_url() );
	}

	/**
	 * A test message carries the network's name and links too.
	 */
	public function test_a_test_message_has_the_networks_name_and_links() {
		$this->save_network_settings();
		update_site_option( 'site_name', 'The Network' );

		$context = new Network_Context();
		$this->assertSame( 'The Network', $context->site_name() );
		$this->assertSame( network_home_url(), $context->home_url() );
		$this->assertSame( network_admin_url( 'update-core.php' ), $context->update_core_url() );

		$result = sc_wpun()->dispatcher()->send_test( sc_wpun_channels()->get( 'email' ) );
		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( network_home_url(), $this->sent_mail()->body );
	}

	/**
	 * Without network activation the site's own name and links are used.
	 */
	public function test_the_site_name_and_links_are_the_sites_own_when_not_a_network() {
		$active = (array) get_site_option( 'active_sitewide_plugins', array() );
		unset( $active[ plugin_basename( SC_WPUN_FILE ) ] );
		update_site_option( 'active_sitewide_plugins', $active );

		$context = new Network_Context();

		$this->assertSame( get_bloginfo(), $context->site_name() );
		$this->assertSame( home_url(), $context->home_url() );
		$this->assertSame( admin_url( 'update-core.php' ), $context->update_core_url() );
		$this->assertSame( get_option( 'admin_email' ), $context->admin_email() );
	}

	/**
	 * The default recipient is the network admin's address.
	 */
	public function test_the_default_recipient_is_the_network_admin_email() {
		update_site_option( 'admin_email', 'network-admin@example.org' );
		update_option( 'admin_email', 'site-admin@example.org' );

		$this->assertSame( 'network-admin@example.org', ( new Network_Context() )->admin_email() );

		// A new install: no settings yet, so the upgrade creates the email instance.
		delete_site_option( 'sc_wpun_settings' );
		delete_site_option( 'sc_wpun_settings_ver' );
		delete_site_option( 'sc_wpun_channels' );
		sc_wpun()->settings()->maybe_upgrade();

		$this->assertSame( 'network-admin@example.org', sc_wpun_channels()->get( 'email' )->get_setting( 'to' ) );
	}

	/**
	 * The configuration context of a sub-site is the main site's.
	 */
	public function test_the_config_context_site_is_the_main_site() {
		switch_to_blog( $this->sub_id );
		$context = Config_Context::setting( 'frequency' );
		restore_current_blog();

		$this->assertSame( get_main_site_id(), $context->get_site_id() );
	}
}
