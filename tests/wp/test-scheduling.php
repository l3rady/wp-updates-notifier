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

	const BASENAME = 'wp-updates-notifier/wp-updates-notifier.php';

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
		sc_wpun()->network_context()->delete_option( 'sc_wpun_settings' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_settings_ver' );

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

		do_action( 'deactivate_' . self::BASENAME, is_multisite() ); // Network-active on a multisite, so a network deactivation.

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
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof \WPUN\Scheduler && 'maybe_schedule' === $callback['function'][1] ) {
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
		wp_clear_scheduled_hook( 'sc_wpun_update_check' ); // Saving a new frequency schedules the check itself.
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

	/**
	 * How many events the update check has.
	 *
	 * @return int
	 */
	private function event_count() {
		$count = 0;
		foreach ( _get_cron_array() as $events ) {
			$count += isset( $events['sc_wpun_update_check'] ) ? count( $events['sc_wpun_update_check'] ) : 0;
		}
		return $count;
	}

	/**
	 * The action without arguments brings an event that is gone back, at the saved frequency.
	 *
	 * Saving settings schedules the check itself, so the event is removed first.
	 */
	public function test_the_enable_cron_action_without_arguments_restores_a_missing_event() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );

		do_action( 'sc_wpun_enable_cron' );

		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * The self-healing repair puts a missing event back (#62, #64).
	 */
	public function test_the_repair_restores_an_event_that_was_lost() {
		$this->save_settings( array( 'frequency' => 'twicedaily' ) );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );

		$this->plugin()->maybe_schedule();

		$this->assertSame( 'twicedaily', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * Stopping the checks with the manual action schedules nothing, even when no event was running.
	 */
	public function test_the_manual_action_schedules_nothing_without_an_event() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );

		do_action( 'sc_wpun_enable_cron', 'manual' );

		$this->assertFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );
	}

	/**
	 * Asking for the schedule that is already running leaves the next run where it was.
	 */
	public function test_enabling_the_running_schedule_keeps_the_next_run() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		$next = time() + 5000;
		wp_schedule_event( $next, 'daily', 'sc_wpun_update_check' );

		$this->plugin()->enable_cron();

		$this->assertSame( $next, wp_next_scheduled( 'sc_wpun_update_check' ) );
		$this->assertSame( 1, $this->event_count() );
	}

	/**
	 * Moving to another frequency replaces the event; the old one does not keep running next to it.
	 */
	public function test_a_new_frequency_replaces_the_event() {
		$this->save_settings( array( 'frequency' => 'hourly' ) );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		wp_schedule_event( time() + 5000, 'hourly', 'sc_wpun_update_check' );

		$this->plugin()->enable_cron( 'weekly' );

		$this->assertSame( 'weekly', wp_get_schedule( 'sc_wpun_update_check' ) );
		$this->assertSame( 1, $this->event_count() );
	}

	/**
	 * The scheduler offers the same frequencies as the settings form, shortest first.
	 */
	public function test_the_scheduler_lists_its_frequencies() {
		$this->assertSame(
			array( 'hourly', 'twicedaily', 'daily', 'weekly' ),
			array_keys( sc_wpun()->scheduler()->get_frequencies() )
		);
	}

	/**
	 * Schedules are sorted shortest first.
	 */
	public function test_schedules_sort_by_interval() {
		$scheduler = sc_wpun()->scheduler();

		$this->assertSame( -1, $scheduler->sort_by_interval( array( 'interval' => 1 ), array( 'interval' => 2 ) ) );
		$this->assertSame( 1, $scheduler->sort_by_interval( array( 'interval' => 2 ), array( 'interval' => 1 ) ) );
	}

	/**
	 * The 1.x methods of the main class still schedule, unschedule and reschedule.
	 */
	public function test_the_old_methods_still_drive_the_schedule() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		$plugin = $this->plugin();

		$plugin->run_init();
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		$plugin->activate();
		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );

		$plugin->deactivate( is_multisite() );
		$this->assertFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );

		$plugin->enable_cron( 'weekly' );
		$this->assertSame( 'weekly', wp_get_schedule( 'sc_wpun_update_check' ) );

		$plugin->disable_cron();
		$this->assertFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );

		$plugin->maybe_schedule();
		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );

		$plugin->settings_updated( array( 'frequency' => 'daily' ), array( 'frequency' => 'hourly' ) );
		$this->assertSame( 'hourly', wp_get_schedule( 'sc_wpun_update_check' ) );

		$this->assertSame( array( 'x' ), $plugin->plugin_action_links( array( 'x' ), 'other/other.php' ) );
		ob_start();
		$plugin->sc_wpun_settings_main_text();
		foreach ( array( 'notify_plugins', 'notify_themes', 'hide_updates' ) as $name ) {
			$plugin->{'sc_wpun_settings_main_field_' . $name}();
		}
		$html = (string) ob_get_clean();
		foreach ( array( 'notify_plugins', 'notify_themes', 'hide_updates' ) as $name ) {
			$this->assertStringContainsString( 'sc_wpun_settings[' . $name . ']', $html, $name );
		}
	}

	/**
	 * The 1.x test email method sends a message.
	 */
	public function test_the_old_test_email_method_sends() {
		$this->save_settings();

		$this->plugin()->send_test_email();

		$this->assertSame( 1, $this->sent_mail_count() );
	}
}
