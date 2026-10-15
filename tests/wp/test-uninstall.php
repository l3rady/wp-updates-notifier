<?php
/**
 * Uninstalling removes everything the plugin stored (#78).
 *
 * @package wp-updates-notifier
 */

/**
 * The uninstall routine (uninstall.php).
 */
class Test_Uninstall extends WPUN_Test_Case {

	/**
	 * Store everything the plugin can leave behind on the current site.
	 */
	private function leave_traces() {
		$this->save_settings();
		$this->save_state( array( 'core' => '99.0.0' ) );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		wp_schedule_event( time(), 'daily', 'sc_wpun_update_check' );
		set_transient( 'sc_wpun_send_test_email', 1 );
		set_transient( 'sc_wpun_send_test_slack', 1 );
	}

	/**
	 * Assert nothing of the plugin is left on the current site.
	 *
	 * @param string $where Label for failure messages.
	 */
	private function assert_no_traces( $where ) {
		foreach ( array( 'sc_wpun_settings', 'sc_wpun_settings_ver', 'sc_wpun_state', 'sc_wpun_channels' ) as $option ) {
			$this->assertFalse( get_option( $option ), "$where: $option" );
		}
		$this->assertFalse( wp_next_scheduled( 'sc_wpun_update_check' ), "$where: cron event" );
		$this->assertFalse( get_transient( 'sc_wpun_send_test_email' ), "$where: test email transient" );
		$this->assertFalse( get_transient( 'sc_wpun_send_test_slack' ), "$where: test Slack transient" );
	}

	/**
	 * Run uninstall.php the way WordPress does.
	 */
	private function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-updates-notifier/class-sc-wp-updates-notifier.php' );
		}

		include WP_PLUGIN_DIR . '/wp-updates-notifier/uninstall.php';
	}

	/**
	 * Single site: options, run state, transients and the cron event all go.
	 */
	public function test_uninstall_removes_everything() {
		$this->leave_traces();

		$this->uninstall();

		$this->assert_no_traces( 'site' );
	}

	/**
	 * Running it twice is harmless.
	 */
	public function test_uninstall_can_run_twice() {
		$this->leave_traces();

		$this->uninstall();
		$this->uninstall();

		$this->assert_no_traces( 'site' );
	}

	/**
	 * Multisite: every site of the network is cleaned, not just the current one.
	 */
	public function test_uninstall_cleans_every_site_of_a_network() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}

		$this->leave_traces();
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		$this->leave_traces();
		restore_current_blog();

		$this->uninstall();

		$this->assert_no_traces( 'main site' );
		switch_to_blog( $blog_id );
		$this->assert_no_traces( 'other site' );
		restore_current_blog();
	}
}
