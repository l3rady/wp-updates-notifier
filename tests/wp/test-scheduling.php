<?php
/**
 * The update check is scheduled on activation and stays scheduled.
 *
 * @package wp-updates-notifier
 */

/**
 * Scheduling of the update check (#62).
 */
class Test_Scheduling extends WPUN_Test_Case {

	const BASENAME = 'wp-updates-notifier/class-sc-wp-updates-notifier.php';

	/**
	 * Start every test with no event.
	 */
	public function set_up() {
		parent::set_up();
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
	}

	/**
	 * On the activation request WordPress includes the file after `plugins_loaded` has fired,
	 * so the activation hook must be registered when the file loads.
	 */
	public function test_the_activation_hook_is_registered_when_the_file_is_included() {
		remove_all_actions( 'activate_' . self::BASENAME );
		remove_all_actions( 'plugins_loaded' );

		require WP_PLUGIN_DIR . '/' . self::BASENAME;

		$this->assertNotFalse( has_action( 'activate_' . self::BASENAME ) );
		$this->assertNotFalse( has_action( 'deactivate_' . self::BASENAME ) );
	}

	/**
	 * Activating schedules the check at the saved frequency.
	 */
	public function test_activation_schedules_the_check_at_the_saved_frequency() {
		$this->save_settings( array( 'frequency' => 'daily' ) );

		do_action( 'activate_' . self::BASENAME );

		$this->assertNotFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );
		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * A brand new install has no settings when it is activated; it gets the defaults and the default schedule.
	 */
	public function test_activating_a_new_install_creates_settings_and_schedules_hourly() {
		delete_option( 'sc_wpun_settings' );
		delete_option( 'sc_wpun_settings_ver' );

		do_action( 'activate_' . self::BASENAME );

		$this->assertSame( 'hourly', $this->stored_settings()['frequency'] );
		$this->assertSame( 'hourly', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * Deactivating removes the event.
	 */
	public function test_deactivation_removes_the_event() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		do_action( 'activate_' . self::BASENAME );

		do_action( 'deactivate_' . self::BASENAME );

		$this->assertFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );
	}

	/**
	 * `do_action()` without arguments passes an empty string, which must mean "the saved frequency".
	 */
	public function test_the_enable_cron_action_without_arguments_uses_the_saved_frequency() {
		$this->save_settings( array( 'frequency' => 'daily' ) );

		do_action( 'sc_wpun_enable_cron' );

		$this->assertNotFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );
		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * Sites that were activated before this fix, and never saved their settings, get the event back.
	 */
	public function test_a_missing_event_is_restored() {
		$this->save_settings( array( 'frequency' => 'twicedaily' ) );

		$this->plugin()->maybe_schedule();

		$this->assertSame( 'twicedaily', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * The repair runs on every request, so sites whose owners never log in are repaired too.
	 */
	public function test_the_repair_runs_on_init() {
		$this->assertNotFalse( has_action( 'init' ) );

		$hooked = false;
		foreach ( $GLOBALS['wp_filter']['init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof SC_WP_Updates_Notifier && 'maybe_schedule' === $callback['function'][1] ) {
					$hooked = true;
				}
			}
		}
		$this->assertTrue( $hooked, 'maybe_schedule() should be hooked to init.' );
	}

	/**
	 * An existing event is left alone: the repair never moves the next run.
	 */
	public function test_an_existing_event_is_left_alone() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		$next = time() + 5000;
		wp_schedule_event( $next, 'daily', 'sc_wpun_update_check' );

		$this->plugin()->maybe_schedule();

		$this->assertSame( $next, wp_next_scheduled( 'sc_wpun_update_check' ) );
	}

	/**
	 * The repair does not schedule anything when checks are set to manual.
	 */
	public function test_manual_frequency_is_respected() {
		$this->save_settings( array( 'frequency' => 'manual' ) );

		$this->plugin()->maybe_schedule();

		$this->assertFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );
	}
}
