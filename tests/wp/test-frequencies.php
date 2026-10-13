<?php
/**
 * The check frequency: what is offered, and what happens when a schedule disappears (#64).
 *
 * @package wp-updates-notifier
 */

/**
 * Frequencies and their cron schedules.
 */
class Test_Frequencies extends WPUN_Test_Case {

	/**
	 * Start every test with no event.
	 */
	public function set_up() {
		parent::set_up();
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
	}

	/**
	 * Render the frequency field.
	 *
	 * @return string
	 */
	private function render_frequency_field() {
		ob_start();
		$this->plugin()->sc_wpun_settings_main_field_frequency();
		return ob_get_clean();
	}

	/**
	 * Option values of the frequency field, in order.
	 *
	 * @param string $html Field HTML.
	 * @return array
	 */
	private function option_values( $html ) {
		preg_match_all( '/<option value="([^"]*)"/', $html, $matches );
		return $matches[1];
	}

	/**
	 * Register a schedule the way another plugin would.
	 *
	 * @param string $name     Schedule name.
	 * @param int    $interval Interval in seconds.
	 */
	private function register_schedule( $name, $interval ) {
		// phpcs:disable WordPress.WP.CronInterval.ChangeDetected -- Test schedules of any interval, on purpose.
		add_filter(
			'cron_schedules',
			static function ( $schedules ) use ( $name, $interval ) {
				$schedules[ $name ] = array(
					'interval' => $interval,
					'display'  => $name,
				);
				return $schedules;
			}
		);
		// phpcs:enable WordPress.WP.CronInterval.ChangeDetected
	}

	/**
	 * The field offers the curated frequencies, shortest first, whatever other plugins register.
	 */
	public function test_the_curated_frequencies_are_offered() {
		$this->save_settings();
		$this->register_schedule( 'every_minute', MINUTE_IN_SECONDS );
		$this->register_schedule( 'fortnightly', 2 * WEEK_IN_SECONDS );

		$this->assertSame( array( 'hourly', 'twicedaily', 'daily', 'weekly' ), $this->option_values( $this->render_frequency_field() ) );
	}

	/**
	 * The list can be changed with a filter, but never below an hour.
	 */
	public function test_the_frequencies_filter() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		$this->register_schedule( 'every_minute', MINUTE_IN_SECONDS );
		$this->register_schedule( 'fortnightly', 2 * WEEK_IN_SECONDS );
		add_filter(
			'sc_wpun_frequencies',
			static function () {
				return array( 'fortnightly', 'every_minute', 'daily', 'not_registered' );
			}
		);

		$this->assertSame( array( 'daily', 'fortnightly' ), $this->option_values( $this->render_frequency_field() ) );
	}

	/**
	 * Every select the field renders is closed.
	 */
	public function test_the_field_markup_is_closed() {
		$this->save_settings( array( 'frequency' => 'gone' ) );
		$html = $this->render_frequency_field();

		$this->assertSame( substr_count( $html, '<select' ), substr_count( $html, '</select>' ) );
	}

	/**
	 * A saved schedule that no longer exists is shown as it is, with an explanation, not silently replaced.
	 */
	public function test_an_unavailable_saved_frequency_is_shown_not_replaced() {
		$this->save_settings( array( 'frequency' => 'schedule_from_a_removed_plugin' ) );

		$html = $this->render_frequency_field();

		$this->assertSame( 'schedule_from_a_removed_plugin', $this->option_values( $html )[0] );
		$this->assertMatchesRegularExpression( '/<option value="schedule_from_a_removed_plugin" selected="selected">/', $html );
		$this->assertStringContainsString( 'no longer available', $html );
	}

	/**
	 * With an unavailable frequency and no event, the check runs at the default frequency instead of never.
	 */
	public function test_an_unknown_saved_frequency_still_leaves_a_schedule() {
		$this->save_settings( array( 'frequency' => 'schedule_from_a_removed_plugin' ) );

		$this->plugin()->enable_cron();

		$this->assertSame( 'hourly', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * With an unavailable frequency, an event that is running keeps going at its stored interval.
	 */
	public function test_a_running_event_keeps_its_schedule() {
		$this->register_schedule( 'fortnightly', 2 * WEEK_IN_SECONDS );
		$next = time() + 5000;
		wp_schedule_event( $next, 'fortnightly', 'sc_wpun_update_check' );
		$this->save_settings( array( 'frequency' => 'fortnightly' ) );

		$this->plugin()->enable_cron();

		$this->assertSame( $next, wp_next_scheduled( 'sc_wpun_update_check' ) );
		$this->assertSame( 'fortnightly', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * The settings form refuses frequencies that are not offered, including "manual".
	 */
	public function test_the_form_refuses_frequencies_that_are_not_offered() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$this->save_settings( array( 'frequency' => 'daily' ) );
		$this->register_schedule( 'every_minute', MINUTE_IN_SECONDS );
		$plugin = $this->plugin();

		foreach ( array( 'manual', 'every_minute', 'nonsense' ) as $frequency ) {
			$result = $plugin->sc_wpun_settings_validate( $this->settings( array( 'frequency' => $frequency ) ) );
			$this->assertSame( 'daily', $result['frequency'], $frequency );
		}

		$this->assertSame( 'weekly', $plugin->sc_wpun_settings_validate( $this->settings( array( 'frequency' => 'weekly' ) ) )['frequency'] );
	}

	/**
	 * A stored "manual" frequency (never checks since 1.5.1) becomes daily on upgrade, and gets scheduled.
	 */
	public function test_manual_is_migrated_to_daily_on_upgrade() {
		update_option( 'sc_wpun_settings', $this->settings( array( 'frequency' => 'manual' ) ) );
		update_option( 'sc_wpun_settings_ver', '8.0' );

		$this->call_private( 'settings_up_to_date' );
		$this->plugin()->maybe_schedule();

		$this->assertSame( 'daily', $this->stored_settings()['frequency'] );
		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * Code that stops the checks with do_action( 'sc_wpun_enable_cron', 'manual' ) keeps working.
	 */
	public function test_the_manual_action_still_stops_the_checks() {
		$this->save_settings();
		wp_schedule_event( time(), 'hourly', 'sc_wpun_update_check' );

		do_action( 'sc_wpun_enable_cron', 'manual' );

		$this->assertFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );
	}
}
