<?php
/**
 * The last and next check times on the settings page (#73).
 *
 * @package wp-updates-notifier
 */

/**
 * Times shown on the settings page.
 */
class Test_Settings_Page_Times extends WPUN_Test_Case {

	/**
	 * Render the settings page as an administrator.
	 *
	 * @return string
	 */
	private function render() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin = $this->plugin();
		$plugin->admin_settings_init();

		ob_start();
		$plugin->settings_page();
		return ob_get_clean();
	}

	/**
	 * The expected date-and-time text for a timestamp, in the site's timezone.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	private function site_time( $timestamp ) {
		return wp_date( get_option( 'date_format' ), $timestamp ) . ' at ' . wp_date( get_option( 'time_format' ), $timestamp );
	}

	/**
	 * The last check is shown in the site's timezone, not UTC.
	 */
	public function test_last_check_uses_the_site_timezone() {
		update_option( 'timezone_string', 'Pacific/Auckland' );
		$timestamp = gmmktime( 12, 0, 0, 1, 15, 2024 ); // 01:00 on the 16th in Auckland.
		$this->save_settings();
		$this->save_state( array(), $timestamp );

		$html = $this->render();

		$this->assertStringContainsString( 'Last checked: ' . esc_html( $this->site_time( $timestamp ) ), $html );
		$this->assertStringNotContainsString( gmdate( get_option( 'time_format' ), $timestamp ), $html, 'The UTC time must not be shown.' );
	}

	/**
	 * A site that has never checked says so.
	 */
	public function test_never_checked() {
		$this->save_settings();
		$this->save_state();

		$this->assertStringContainsString( 'Last checked: never', $this->render() );
	}

	/**
	 * The next scheduled check is shown, so cron problems are visible.
	 */
	public function test_next_check_is_shown() {
		$this->save_settings();
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		$next = time() + 3 * HOUR_IN_SECONDS;
		wp_schedule_event( $next, 'hourly', 'sc_wpun_update_check' );

		$this->assertStringContainsString( 'Next check: ' . esc_html( $this->site_time( $next ) ), $this->render() );
	}

	/**
	 * A missing event is called out.
	 */
	public function test_a_missing_event_is_called_out() {
		$this->save_settings();
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );

		$this->assertStringContainsString( 'Next check: not scheduled', $this->render() );
	}
}
