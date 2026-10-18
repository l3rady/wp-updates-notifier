<?php
/**
 * Running the update check on demand (#187).
 *
 * @package wp-updates-notifier
 */

use WPUN\Scheduler;

/**
 * The sc_wpun_run_check action.
 */
class Test_Run_Check extends WPUN_Test_Case {

	/**
	 * Fake what core asks api.wordpress.org about plugins, and set an update.
	 */
	public function set_up() {
		parent::set_up();

		$this->fake_http(
			'api.wordpress.org/plugins/info',
			$this->fake_response(
				200,
				wp_json_encode(
					array(
						'name'    => 'X',
						'slug'    => 'x',
						'version' => '9',
						'tested'  => '99',
					)
				)
			)
		);
		$this->save_settings();
		delete_option( 'sc_wpun_state' );
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		reset_phpmailer_instance();
	}

	/**
	 * Count how often an action fires.
	 *
	 * @param string $action Action name.
	 *
	 * @return ArrayObject Holds the count under `count`.
	 */
	private function count_action( $action ) {
		$counter = new ArrayObject( array( 'count' => 0 ) );
		add_action(
			$action,
			static function () use ( $counter ) {
				++$counter['count'];
			}
		);

		return $counter;
	}

	/**
	 * A manual run sends what is pending and records it; a second one has nothing new.
	 */
	public function test_a_manual_run_sends_pending_updates_once() {
		do_action( 'sc_wpun_run_check' );

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ) );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php' ) );

		reset_phpmailer_instance();
		do_action( 'sc_wpun_run_check' );

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * The notification says where the run came from, and the check hooks fire.
	 */
	public function test_the_source_is_manual_and_the_hooks_fire() {
		$before = $this->count_action( 'sc_wpun_before_update_check' );
		$after  = $this->count_action( 'sc_wpun_after_update_check' );

		do_action( 'sc_wpun_run_check' );

		$this->assertSame( 'manual', sc_wpun()->update_checker()->last_notification()->get_source() );
		$this->assertSame( 1, $before['count'] );
		$this->assertSame( 1, $after['count'] );

		do_action( Scheduler::CRON_NAME );
		$this->assertSame( 'cron', sc_wpun()->update_checker()->last_notification()->get_source() );

		sc_wpun()->update_checker()->do_update_check();
		$this->assertSame( 'cron', sc_wpun()->update_checker()->last_notification()->get_source() );
	}

	/**
	 * Only the update checker listens, so the Slack notice is not sent on a manual run.
	 */
	public function test_only_the_update_checker_listens() {
		$this->assertCount( 1, $GLOBALS['wp_filter']['sc_wpun_run_check']->callbacks[10] );
		$this->assertSame( sc_wpun()->update_checker(), array_values( $GLOBALS['wp_filter']['sc_wpun_run_check']->callbacks[10] )[0]['function'][0] );
		$this->assertCount( 1, $GLOBALS['wp_filter']['sc_wpun_run_check']->callbacks );
	}

	/**
	 * A manual run does not touch the schedule.
	 */
	public function test_the_schedule_is_unchanged() {
		wp_clear_scheduled_hook( Scheduler::CRON_NAME );
		wp_schedule_event( time() + 1234, 'daily', Scheduler::CRON_NAME );
		$next = wp_next_scheduled( Scheduler::CRON_NAME );

		do_action( 'sc_wpun_run_check' );

		$this->assertSame( $next, wp_next_scheduled( Scheduler::CRON_NAME ) );
		$this->assertSame( 'daily', wp_get_schedule( Scheduler::CRON_NAME ) );
	}
}
