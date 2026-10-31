<?php
/**
 * The check as the WP-CLI commands run it: a dry run that changes nothing, and a forced run (#79).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Update_Checker;

/**
 * Update_Checker::run_check().
 */
class Test_Cli_Check extends WPUN_Test_Case {

	/**
	 * The fake channel.
	 *
	 * @var Test_Channel
	 */
	private $channel;

	/**
	 * Hooks fired, by name.
	 *
	 * @var int[]
	 */
	private $fired = array();

	/**
	 * Register the fake channel, start without instances or state and count the hooks of sending.
	 */
	public function set_up() {
		parent::set_up();

		$this->channel = new Test_Channel( 'clitest' );
		sc_wpun()->channels()->unregister( 'clitest' );
		sc_wpun()->channels()->register( $this->channel );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_channels' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'daily' ) ) );

		$this->fired = array();
		foreach ( array( 'sc_wpun_after_dispatch', 'sc_wpun_after_update_check', 'sc_wpun_notification_sent', 'sc_wpun_notification_failed' ) as $hook ) {
			add_action(
				$hook,
				function () use ( $hook ) {
					$this->fired[ $hook ] = ( $this->fired[ $hook ] ?? 0 ) + 1;
				},
				10,
				0
			);
		}
		add_filter(
			'sc_wpun_pre_send',
			function ( $pre ) {
				$this->fired['sc_wpun_pre_send'] = ( $this->fired['sc_wpun_pre_send'] ?? 0 ) + 1;
				return $pre;
			}
		);
	}

	/**
	 * Save an instance of the fake channel.
	 *
	 * @param string $id      Id.
	 * @param bool   $enabled Whether it is on.
	 * @param string $remind  The `remind` routing value.
	 * @param string $mode    What it does: succeed, fail or limit.
	 */
	private function add( $id = 'a', $enabled = true, $remind = 'never', $mode = 'succeed' ) {
		sc_wpun_channels()->save( new Channel_Instance( $id, 'clitest', ucfirst( $id ), $enabled, array( 'behaviour' => $mode ), array( 'remind' => $remind ) ) );
	}

	/**
	 * Put a plugin update at 2.0.0 and a core update at 99.0.0 in WordPress's update data.
	 */
	private function waiting() {
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
	}

	/**
	 * Run the check for the CLI.
	 *
	 * @param bool $dry_run Dry run.
	 * @param bool $force   Force.
	 * @return array
	 */
	private function check( $dry_run = false, $force = false ) {
		return sc_wpun()->update_checker()->run_check( 'cli', $dry_run, $force );
	}

	/**
	 * Messages the fake channel received.
	 *
	 * @param string $id Id.
	 * @return int
	 */
	private function received( $id = 'a' ) {
		return count( $this->channel->received[ $id ] ?? array() );
	}

	/**
	 * The raw stored state.
	 *
	 * @return mixed
	 */
	private function raw_state() {
		return (array) sc_wpun()->network_context()->get_option( 'sc_wpun_state' );
	}

	/**
	 * Store a state with an instance that has a stale notified entry, a pending skip, a clock and the legacy list.
	 *
	 * @param string $id Id.
	 */
	private function messy_state( $id = 'a' ) {
		$this->save_channel_state( $id, array( 'plugin' => array( 'gone.php' => '1.0.0' ) ), true );
		$state                                     = $this->raw_state();
		$state['channels'][ $id ]['last_reminder'] = time() - 10 * DAY_IN_SECONDS;
		$state['last_check_time']                  = 12345;
		$state['legacy_notified']                  = array( 'core' => '1.0' );
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
	}

	/**
	 * A dry run sends nothing and fires none of the hooks of sending or ending a check.
	 */
	public function test_dry_run_sends_nothing_and_fires_no_hooks() {
		$this->add( 'a' );
		$this->add( 'b', true, 'daily' );
		$this->waiting();
		$checker = sc_wpun()->update_checker();
		$last    = $checker->last_notification();

		$plan = $this->check( true );

		$this->assertSame( 0, $this->received( 'a' ) + $this->received( 'b' ) );
		$this->assertSame( array(), $this->fired );
		$this->assertSame( array(), $checker->last_results() );
		$this->assertSame( $last, $checker->last_notification() );
		$this->assertSame( Update_Checker::STATUS_SEND, $plan['a']['status'] );
		$this->assertNull( $plan['a']['result'] );
	}

	/**
	 * A dry run changes nothing in the stored state, byte for byte.
	 */
	public function test_dry_run_leaves_the_state_identical() {
		$this->add( 'a', true, 'daily' );
		$this->add( 'b', true, 'daily' );
		$this->waiting();
		$this->messy_state( 'a' );
		$before = wp_json_encode( $this->raw_state() );

		$this->check( true );
		$this->check( true, true );

		$this->assertSame( $before, wp_json_encode( $this->raw_state() ) );
		$this->assertArrayHasKey( 'legacy_notified', $this->raw_state() );
	}

	/**
	 * A dry run still runs the check's own hook and filters.
	 */
	public function test_dry_run_runs_the_start_action_and_the_filters() {
		$this->add( 'a' );
		$this->waiting();
		$started = 0;
		add_action(
			'sc_wpun_before_update_check',
			function () use ( &$started ) {
				++$started;
			}
		);
		add_filter( 'sc_wpun_should_notify_item', static fn( $notify, $item ) => 'core' === $item->get_type() ? false : $notify, 10, 2 );

		$plan = $this->check( true );

		$this->assertSame( 1, $started );
		$this->assertCount( 1, $plan['a']['items'] ); // Only the plugin: the veto applied.
		$this->assertSame( 'cli', $plan['a']['notification']->get_source() );
	}

	/**
	 * Every instance is in the plan with the status that tells why.
	 */
	public function test_dry_run_reports_every_status() {
		$this->add( 'off', false );
		$this->add( 'wait' );
		$this->add( 'skipped' );
		$this->add( 'remind', true, 'daily' );
		$this->add( 'new' );
		$this->add( 'done' );
		$this->waiting();
		$this->save_channel_state( 'skipped', array(), true );
		$this->save_channel_state(
			'done',
			array(
				'core'   => array( 'core' => '99.0.0' ),
				'plugin' => array( 'hello.php' => '2.0.0' ),
			)
		);
		$this->save_channel_state(
			'remind',
			array(
				'core'   => array( 'core' => '99.0.0' ),
				'plugin' => array( 'hello.php' => '2.0.0' ),
			)
		);
		$state                                        = $this->raw_state();
		$state['channels']['remind']['last_reminder'] = time() - 3 * DAY_IN_SECONDS;
		$state['channels']['wait']                    = array(
			'notified'     => array(),
			'skip_pending' => false,
			'retry_at'     => time() + 600,
		);
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );

		$plan = $this->check( true );

		$this->assertSame( array( 'off', 'wait', 'skipped', 'remind', 'new', 'done' ), array_keys( $plan ) );
		$this->assertSame( Update_Checker::STATUS_DISABLED, $plan['off']['status'] );
		$this->assertSame( Update_Checker::STATUS_WAITING, $plan['wait']['status'] );
		$this->assertSame( $state['channels']['wait']['retry_at'], $plan['wait']['retry_at'] );
		$this->assertSame( Update_Checker::STATUS_SKIP, $plan['skipped']['status'] );
		$this->assertCount( 2, $plan['skipped']['items'] );
		$this->assertNull( $plan['skipped']['notification'] );
		$this->assertSame( Update_Checker::STATUS_REMINDER, $plan['remind']['status'] );
		$this->assertTrue( $plan['remind']['notification']->is_reminder() );
		$this->assertCount( 2, $plan['remind']['items'] );
		$this->assertSame( Update_Checker::STATUS_SEND, $plan['new']['status'] );
		$this->assertFalse( $plan['new']['notification']->is_reminder() );
		$this->assertSame( Update_Checker::STATUS_NOTHING, $plan['done']['status'] );
		$this->assertNull( $plan['done']['notification'] );
		$this->assertSame( array(), $plan['done']['items'] );
		$this->assertSame( 0, $this->received( 'new' ) );
	}

	/**
	 * A reminder with no clock yet is not due: the clock would start now.
	 */
	public function test_dry_run_reminder_without_a_clock_is_not_due() {
		$this->add( 'a', true, 'daily' );
		$this->waiting();
		$this->save_channel_state(
			'a',
			array(
				'core'   => array( 'core' => '99.0.0' ),
				'plugin' => array( 'hello.php' => '2.0.0' ),
			)
		);

		$plan = $this->check( true );

		$this->assertSame( Update_Checker::STATUS_NOTHING, $plan['a']['status'] );
		$this->assertArrayNotHasKey( 'last_reminder', $this->raw_state()['channels']['a'] );
	}

	/**
	 * A skipped instance whose module is gone keeps its flag and records nothing.
	 */
	public function test_skip_with_the_module_gone_reports_skip_and_nothing_recorded() {
		sc_wpun_channels()->save( new Channel_Instance( 'a', 'clitest', 'A', true, array() ) );
		$this->waiting();
		$this->save_channel_state( 'a', array(), true );
		sc_wpun()->channels()->unregister( 'clitest' );

		$plan = $this->check( false, true );

		$this->assertSame( Update_Checker::STATUS_SKIP, $plan['a']['status'] );
		$this->assertSame( array(), $plan['a']['items'] );
		$this->assertTrue( $this->raw_state()['channels']['a']['skip_pending'] );
	}

	/**
	 * A real run reports what it sent and still behaves as before.
	 */
	public function test_real_run_sends_records_and_reports() {
		$this->add( 'a' );
		$this->add( 'bad', true, 'never', 'fail' );
		$this->waiting();

		$plan = $this->check();

		$this->assertSame( Update_Checker::STATUS_SEND, $plan['a']['status'] );
		$this->assertTrue( $plan['a']['result']->is_success() );
		$this->assertSame( Update_Checker::STATUS_FAILED, $plan['bad']['status'] );
		$this->assertFalse( $plan['bad']['result']->is_success() );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );
		$this->assertSame( '', $this->notified_version( 'plugin', 'hello.php', 'bad' ) );
		$this->assertSame( 1, $this->fired['sc_wpun_after_update_check'] );
		$this->assertSame( array( 'a', 'bad' ), array_keys( sc_wpun()->update_checker()->last_results() ) );
		$this->assertNotFalse( $this->raw_state()['last_check_time'] );
	}

	/**
	 * A failing routing callback is a failed instance, and a dry run does not run the result's hooks.
	 */
	public function test_routing_exception_is_failed() {
		$this->add( 'a' );
		$this->waiting();
		add_filter(
			'sc_wpun_channel_should_receive_item',
			static function () {
				throw new RuntimeException( 'No.' );
			}
		);

		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Swallowing the notice the dispatcher raises under WP_DEBUG.
			static fn() => true,
			E_USER_NOTICE
		);
		try {
			$dry  = $this->check( true );
			$real = $this->check();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( Update_Checker::STATUS_FAILED, $dry['a']['status'] );
		$this->assertNull( $dry['a']['result'] );
		$this->assertSame( Update_Checker::STATUS_FAILED, $real['a']['status'] );
		$this->assertFalse( $real['a']['result']->is_success() );
	}

	/**
	 * Force sends again what was notified and records it.
	 */
	public function test_force_resends_notified_items() {
		$this->add( 'a' );
		$this->waiting();
		$this->save_channel_state(
			'a',
			array(
				'core'   => array( 'core' => '99.0.0' ),
				'plugin' => array( 'hello.php' => '2.0.0' ),
			)
		);

		$this->assertSame( Update_Checker::STATUS_NOTHING, $this->check()['a']['status'] );
		$this->assertSame( 0, $this->received() );

		$plan = $this->check( false, true );

		$this->assertSame( Update_Checker::STATUS_SEND, $plan['a']['status'] );
		$this->assertCount( 2, $plan['a']['items'] );
		$this->assertSame( 1, $this->received() );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );
	}

	/**
	 * Force with nothing pending is nothing.
	 */
	public function test_force_with_nothing_pending_is_nothing() {
		$this->add( 'a' );
		$this->set_updates();

		$this->assertSame( Update_Checker::STATUS_NOTHING, $this->check( false, true )['a']['status'] );
		$this->assertSame( 0, $this->received() );
	}

	/**
	 * Force sends to an instance with a pending skip, and the skip is cleared.
	 */
	public function test_force_overrides_skip_pending() {
		$this->add( 'a' );
		$this->waiting();
		$this->save_channel_state( 'a', array(), true );

		$plan = $this->check( false, true );

		$this->assertSame( Update_Checker::STATUS_SEND, $plan['a']['status'] );
		$this->assertSame( 1, $this->received() );
		$this->assertFalse( $this->raw_state()['channels']['a']['skip_pending'] );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );
	}

	/**
	 * Without force the skip still wins, and records without sending.
	 */
	public function test_skip_pending_records_without_sending() {
		$this->add( 'a' );
		$this->waiting();
		$this->save_channel_state( 'a', array(), true );

		$plan = $this->check();

		$this->assertSame( Update_Checker::STATUS_SKIP, $plan['a']['status'] );
		$this->assertSame( 0, $this->received() );
		$this->assertFalse( $this->raw_state()['channels']['a']['skip_pending'] );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );
	}

	/**
	 * Force sends a normal message, not a reminder, even if one is due, and leaves the clock.
	 */
	public function test_force_sends_a_normal_message_when_a_reminder_is_due() {
		$this->add( 'a', true, 'daily' );
		$this->waiting();
		$this->save_channel_state(
			'a',
			array(
				'core'   => array( 'core' => '99.0.0' ),
				'plugin' => array( 'hello.php' => '2.0.0' ),
			)
		);
		$state                                   = $this->raw_state();
		$state['channels']['a']['last_reminder'] = time() - 3 * DAY_IN_SECONDS;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );

		$this->assertSame( Update_Checker::STATUS_REMINDER, $this->check( true )['a']['status'] );
		$plan = $this->check( false, true );

		$this->assertSame( Update_Checker::STATUS_SEND, $plan['a']['status'] );
		$this->assertFalse( $plan['a']['notification']->is_reminder() );
		$this->assertSame( $state['channels']['a']['last_reminder'], $this->raw_state()['channels']['a']['last_reminder'] );
	}

	/**
	 * Force still respects a switched-off instance, a Retry-After wait, the veto and routing.
	 */
	public function test_force_respects_off_waiting_veto_and_routing() {
		$this->add( 'off', false );
		$this->add( 'wait' );
		sc_wpun_channels()->save( new Channel_Instance( 'plugins_only', 'clitest', 'P', true, array(), array( 'types' => array( 'plugin' ) ) ) );
		$this->waiting();
		$state                     = $this->raw_state();
		$state['channels']['wait'] = array(
			'notified'     => array(),
			'skip_pending' => false,
			'retry_at'     => time() + 600,
		);
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		add_filter( 'sc_wpun_should_notify_item', static fn( $notify, $item ) => 'theme' === $item->get_type() ? false : $notify, 10, 2 );

		$plan = $this->check( false, true );

		$this->assertSame( Update_Checker::STATUS_DISABLED, $plan['off']['status'] );
		$this->assertSame( Update_Checker::STATUS_WAITING, $plan['wait']['status'] );
		$this->assertSame( 0, $this->received( 'off' ) + $this->received( 'wait' ) );
		$this->assertSame( Update_Checker::STATUS_SEND, $plan['plugins_only']['status'] );
		$this->assertCount( 1, $plan['plugins_only']['items'] );
		$this->assertSame( 'plugin', $plan['plugins_only']['items'][0]->get_type() );
	}

	/**
	 * Force together with a dry run reports what would be sent and changes nothing.
	 */
	public function test_force_with_dry_run_changes_nothing() {
		$this->add( 'a' );
		$this->waiting();
		$this->save_channel_state(
			'a',
			array(
				'core'   => array( 'core' => '99.0.0' ),
				'plugin' => array( 'hello.php' => '2.0.0' ),
			),
			true
		);
		$before = wp_json_encode( $this->raw_state() );

		$plan = $this->check( true, true );

		$this->assertSame( Update_Checker::STATUS_SEND, $plan['a']['status'] );
		$this->assertCount( 2, $plan['a']['items'] );
		$this->assertSame( 0, $this->received() );
		$this->assertSame( $before, wp_json_encode( $this->raw_state() ) );
		$this->assertSame( array(), $this->fired );
	}

	/**
	 * The cron and manual checks are the same pipeline with no flags.
	 */
	public function test_cron_and_manual_checks_still_send_and_record() {
		$this->add( 'a' );
		$this->waiting();

		sc_wpun()->update_checker()->do_update_check();
		$this->assertSame( 1, $this->received() );
		$this->assertSame( 'cron', sc_wpun()->update_checker()->last_notification()->get_source() );

		sc_wpun()->update_checker()->run_manual_check();
		$this->assertSame( 1, $this->received() ); // Already told.
		$this->assertSame( 'manual', sc_wpun()->update_checker()->last_notification()->get_source() );
	}

	/**
	 * The plan lists everything the routing gives an instance as pending, notified or not.
	 */
	public function test_plan_lists_the_pending_items() {
		$this->add( 'a' );
		$this->waiting();
		$this->save_channel_state( 'a', array( 'plugin' => array( 'hello.php' => '2.0.0' ) ) );

		$plan = $this->check( true );

		$this->assertCount( 2, $plan['a']['pending'] );
		$this->assertCount( 1, $plan['a']['items'], 'Only the core update is new.' );
	}
}
