<?php
/**
 * A deferred delivery result holds an instance without an error (#349).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channels_Tab;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\State;
use WPUN\Update_Checker;

/**
 * The result, how it is stored and honoured by the check, and how it is shown.
 */
class Test_Deferred_Result extends WPUN_Test_Case {

	/**
	 * The fake channel.
	 *
	 * @var Test_Channel
	 */
	private $channel;

	/**
	 * Register the fake channel and start without instances or state.
	 */
	public function set_up() {
		parent::set_up();

		$this->channel = new Test_Channel( 'held' );
		sc_wpun()->channels()->unregister( 'held' );
		sc_wpun()->channels()->register( $this->channel );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_channels' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_muted' );
	}

	/**
	 * Save an instance of the fake channel.
	 *
	 * @param string $id        Id.
	 * @param string $behaviour What it does.
	 * @param array  $routing   Routing.
	 */
	private function add( $id = 'a', $behaviour = 'defer', array $routing = array() ) {
		sc_wpun_channels()->save( new Channel_Instance( $id, 'held', ucfirst( $id ), true, array( 'behaviour' => $behaviour ), $routing ) );
	}

	/**
	 * Run an update check with an update pending.
	 *
	 * @return array The plan.
	 */
	private function run_check() {
		$this->set_updates( '99.0.0' );
		return sc_wpun()->update_checker()->run_check( 'test' );
	}

	/**
	 * How many times an instance was asked to send.
	 *
	 * @param string $id Id.
	 * @return int
	 */
	private function calls( $id = 'a' ) {
		return count( $this->channel->received[ $id ] ?? array() );
	}

	/**
	 * The stored hold of an instance.
	 *
	 * @param string $id Id.
	 * @return int|null
	 */
	private function held_until( $id = 'a' ) {
		return $this->stored_state()['channels'][ $id ]['held_until'] ?? null;
	}

	/**
	 * Make the stored hold end.
	 *
	 * @param string $id Id.
	 */
	private function end_hold( $id = 'a' ) {
		$state                                  = sc_wpun()->network_context()->get_option( 'sc_wpun_state' );
		$state['channels'][ $id ]['held_until'] = time() - 1;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
	}

	/**
	 * A real notification with one item.
	 *
	 * @param bool $test Whether it is a test.
	 * @return Notification
	 */
	private function notification( $test = false ) {
		$items = $test ? array() : array(
			new Notification_Item(
				array(
					'type'            => 'plugin',
					'key'             => 'hello.php',
					'name'            => 'Hello Dolly',
					'current_version' => '1.0',
					'new_version'     => '2.0',
				)
			),
		);

		return new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', $items, array( 'is_test' => $test ) );
	}

	/**
	 * Count the calls of an action.
	 *
	 * @param string $hook Hook.
	 * @return ArrayObject Holds the count under `count`.
	 */
	private function count_action( $hook ) {
		$counter = new ArrayObject( array( 'count' => 0 ) );
		add_action(
			$hook,
			static function () use ( $counter ) {
				++$counter['count'];
			}
		);

		return $counter;
	}

	// The result.

	/**
	 * The value object.
	 */
	public function test_the_result() {
		$result = Delivery_Result::deferred( 1900000000, 'Later.' );

		$this->assertTrue( $result->is_deferred() );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 1900000000, $result->get_deferred_until() );
		$this->assertSame( 'Later.', $result->get_message() );
		$this->assertSame( 'deferred', $result->get_code() );
		$this->assertNull( $result->get_retry_after() );

		foreach ( array( Delivery_Result::success(), Delivery_Result::failure( 'No.' ) ) as $other ) {
			$this->assertFalse( $other->is_deferred() );
			$this->assertNull( $other->get_deferred_until() );
		}
	}

	// The check.

	/**
	 * A hold skips the instance until the time, then delivers everything pending, and nothing is lost.
	 */
	public function test_a_hold_skips_the_instance_until_the_time_and_then_delivers() {
		$this->add();

		$plan = $this->run_check();

		$this->assertSame( 1, $this->calls() );
		$this->assertEqualsWithDelta( time() + 3600, $this->held_until(), 5 );
		$this->assertSame( '', $this->notified_version( 'core', 'core', 'a' ), 'Not recorded as notified.' );
		$this->assertSame( Update_Checker::STATUS_WAITING, $plan['a']['status'] );
		$this->assertSame( $this->held_until(), $plan['a']['retry_at'] );

		$plan = $this->run_check();

		$this->assertSame( 1, $this->calls(), 'Not asked again while held.' );
		$this->assertSame( Update_Checker::STATUS_WAITING, $plan['a']['status'] );
		$this->assertSame( $this->held_until(), $plan['a']['retry_at'] );

		$this->channel->defer_offset = 0;
		$this->add( 'a', 'succeed' );
		$this->end_hold();
		$this->run_check();

		$this->assertSame( 2, $this->calls() );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'a' ) );
		$this->assertNull( $this->held_until(), 'A later result lifts the hold.' );
	}

	/**
	 * A hold set during a real check survives the save of the check.
	 */
	public function test_a_hold_set_during_a_check_is_stored() {
		$this->add();
		$this->add( 'b', 'succeed' );

		$this->run_check();

		$this->assertNotNull( $this->held_until() );
		$this->assertNull( $this->held_until( 'b' ) );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'b' ) );
	}

	/**
	 * Holding does not touch the last result, the failure, the retry time, the reminder clock or a snooze flag.
	 */
	public function test_a_hold_is_not_a_failure() {
		$this->add( 'a', 'succeed', array( 'remind' => 'every_check' ) );
		$this->run_check();
		$before = $this->stored_state()['channels']['a'];
		$this->assertTrue( $before['last_result']['success'] );

		$this->add( 'a', 'defer', array( 'remind' => 'every_check' ) );
		$this->set_updates( '100.0.0' );
		$failed = $this->count_action( 'sc_wpun_notification_failed' );
		$sent   = $this->count_action( 'sc_wpun_notification_sent' );
		$held   = $this->count_action( 'sc_wpun_notification_deferred' );
		sc_wpun()->update_checker()->run_check( 'test' );

		$after = $this->stored_state()['channels']['a'];
		$this->assertSame( 0, $failed['count'] );
		$this->assertSame( 0, $sent['count'] );
		$this->assertSame( 1, $held['count'] );
		$this->assertSame( $before['last_result'], $after['last_result'] );
		$this->assertSame( $before['notified'], $after['notified'], 'Nothing is marked notified.' );
		$this->assertArrayNotHasKey( 'retry_at', $after );
		$this->assertSame( $before['last_reminder'] ?? null, $after['last_reminder'] ?? null, 'The reminder interval does not restart.' );

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertSame( 'good', call_user_func( $tests['direct']['sc_wpun_channel_delivery']['test'] )['status'], 'No Site Health warning.' );
	}

	/**
	 * Put a snooze of the plugin straight into the stored mutes, to control the time.
	 *
	 * @param int $expires Expiry.
	 */
	private function snooze( $expires ) {
		sc_wpun()->network_context()->update_option(
			'sc_wpun_muted',
			array(
				'plugin' => array(
					'hello.php' => array(
						'until'   => null,
						'time'    => 1,
						'user'    => 0,
						'expires' => $expires,
					),
				),
			)
		);
	}

	/**
	 * A forced message that is deferred does not replace the skip flag, and records nothing.
	 */
	public function test_a_skip_flag_survives_a_deferred_forced_send() {
		$this->add();
		$this->set_updates( '99.0.0' );
		sc_wpun()->state()->skip_pending( 'a' );

		$plan = sc_wpun()->update_checker()->run_check( 'cli', false, true );

		$this->assertSame( 1, $this->calls(), 'The forced message was sent, and deferred.' );
		$this->assertSame( Update_Checker::STATUS_WAITING, $plan['a']['status'] );
		$this->assertTrue( $this->stored_state()['channels']['a']['skip_pending'] );
		$this->assertSame( '', $this->notified_version( 'core', 'core', 'a' ) );
		$this->assertNotNull( $this->held_until() );
	}

	/**
	 * A snoozed flag stays while a message is deferred, and goes when the item is delivered.
	 */
	public function test_a_snooze_flag_survives_a_deferred_send() {
		$plugin = array( 'hello.php' => array( 'new_version' => '2.0' ) );
		$meta   = function () {
			return $this->stored_state()['channels']['a']['notified_meta']['plugin']['hello.php'] ?? array();
		};

		$this->add( 'a', 'succeed' );
		$this->set_updates( null, $plugin );
		sc_wpun()->update_checker()->run_check( 'test' );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );

		$this->snooze( time() + HOUR_IN_SECONDS );
		sc_wpun()->update_checker()->run_check( 'test' );
		$this->assertTrue( $meta()['snoozed'], 'Flagged while snoozed.' );

		$this->snooze( time() - 1 );
		$this->add();
		sc_wpun()->update_checker()->run_check( 'test' );
		$this->assertSame( 2, $this->calls(), 'The ended snooze makes it new, and the channel defers.' );
		$this->assertNotNull( $this->held_until() );
		$this->assertTrue( $meta()['snoozed'], 'Still flagged: not delivered.' );

		$this->end_hold();
		$this->add( 'a', 'succeed' );
		sc_wpun()->update_checker()->run_check( 'test' );
		$this->assertSame( 3, $this->calls() );
		$this->assertArrayNotHasKey( 'snoozed', $meta(), 'Delivered: the flag is cleared.' );
		$this->assertNull( $this->held_until() );
	}

	/**
	 * A forced run and a reminder run respect the hold.
	 */
	public function test_force_and_reminders_respect_the_hold() {
		$this->add( 'a', 'defer', array( 'remind' => 'every_check' ) );
		$this->run_check();

		$plan = sc_wpun()->update_checker()->run_check( 'cli', false, true );
		$this->assertSame( Update_Checker::STATUS_WAITING, $plan['a']['status'] );
		$plan = $this->run_check();
		$this->assertSame( Update_Checker::STATUS_WAITING, $plan['a']['status'] );

		$this->assertSame( 1, $this->calls() );
	}

	/**
	 * The hold is capped at a week, and a time in the past holds nothing.
	 */
	public function test_cap_and_past_time() {
		$this->add();
		$this->channel->defer_offset = 10 * YEAR_IN_SECONDS;
		$this->run_check();
		$this->assertEqualsWithDelta( time() + State::MAX_RETRY_AFTER, $this->held_until(), 5 );

		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		$this->channel->received     = array();
		$this->channel->defer_offset = -5;
		$this->run_check();
		$this->assertNull( $this->held_until() );
		$this->run_check();
		$this->assertSame( 2, $this->calls(), 'Nothing held it.' );
		$this->assertSame( '', $this->notified_version( 'core', 'core', 'a' ) );
	}

	/**
	 * Another instance is delivered as usual.
	 */
	public function test_other_instances_are_not_held() {
		$this->add();
		$this->add( 'b', 'succeed' );

		$this->run_check();
		$this->run_check();

		$this->assertSame( 1, $this->calls( 'a' ) );
		$this->assertSame( 1, $this->calls( 'b' ) );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'b' ) );
	}

	/**
	 * A hold and a Retry-After: the later one counts. A failure lifts a hold.
	 */
	public function test_the_waiting_time() {
		$this->add();
		$this->assertNull( sc_wpun_instance_waiting_until( 'a' ), 'Nothing yet.' );
		$this->assertNull( sc_wpun_instance_waiting_until( 'unknown' ) );

		$this->run_check();
		$held = $this->held_until();
		$this->assertSame( $held, sc_wpun_instance_waiting_until( 'a' ), 'A hold.' );

		$state                              = $this->stored_state();
		$state['channels']['a']['retry_at'] = $held + 600;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		$this->assertSame( $held + 600, sc_wpun_instance_waiting_until( 'a' ), 'Both: the later one.' );

		$state['channels']['a']['retry_at'] = time() + 60;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		$this->assertSame( $held, sc_wpun_instance_waiting_until( 'a' ), 'Both: the hold is later.' );

		unset( $state['channels']['a']['held_until'] );
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		$this->assertSame( $state['channels']['a']['retry_at'], sc_wpun_instance_waiting_until( 'a' ), 'A Retry-After only.' );

		$this->end_hold();
		$state = $this->stored_state();
		unset( $state['channels']['a']['retry_at'] );
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		$this->assertNull( sc_wpun_instance_waiting_until( 'a' ), 'Over.' );
	}

	/**
	 * A sent or failed result lifts the hold; a test send does neither.
	 */
	public function test_sent_and_failed_results_lift_the_hold() {
		foreach ( array( 'succeed', 'fail' ) as $behaviour ) {
			sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
			$this->add();
			$this->run_check();
			$this->assertNotNull( $this->held_until() );

			$this->add( 'a', $behaviour );
			sc_wpun()->dispatcher()->send_one( $this->notification( true ), sc_wpun_channels()->get( 'a' ) );
			$this->assertNotNull( $this->held_until(), 'A test send leaves it.' );

			sc_wpun()->dispatcher()->send_one( $this->notification(), sc_wpun_channels()->get( 'a' ) );
			$this->assertNull( $this->held_until(), $behaviour );
		}
	}

	/**
	 * A new hold replaces an older one.
	 */
	public function test_a_new_hold_replaces_the_old() {
		$this->add();
		$this->run_check();
		$this->channel->defer_offset = 7200;
		sc_wpun()->dispatcher()->send_one( $this->notification(), sc_wpun_channels()->get( 'a' ) );

		$this->assertEqualsWithDelta( time() + 7200, $this->held_until(), 5 );
	}

	/**
	 * A resolution still fires for a held instance.
	 */
	public function test_resolutions_still_fire_for_a_held_instance() {
		$this->add();
		$this->save_channel_state( 'a', array( 'core' => array( 'core' => wp_get_wp_version() ) ) );
		$state                                = $this->stored_state();
		$state['channels']['a']['held_until'] = time() + 3600;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		$fired = array();
		add_action(
			'sc_wpun_item_resolved',
			static function ( $item, $how, $instance ) use ( &$fired ) {
				$fired[] = array( $item->get_key(), $how, $instance->get_id() );
			},
			10,
			3
		);

		$this->set_updates( null );
		sc_wpun()->update_checker()->run_check( 'test' );

		$this->assertSame( array( array( 'core', 'core', 'a' ) ), $fired );
		$this->assertNotNull( sc_wpun_instance_waiting_until( 'a' ) );
	}

	/**
	 * Normalising keeps a valid time only.
	 */
	public function test_normalise() {
		$channels = sc_wpun()->state()->normalise(
			array(
				'channels' => array(
					'ok'   => array( 'held_until' => 1900000000 ),
					'text' => array( 'held_until' => 'soon' ),
					'zero' => array( 'held_until' => 0 ),
					'none' => array(),
				),
			)
		)['channels'];

		$this->assertSame( 1900000000, $channels['ok']['held_until'] );
		foreach ( array( 'text', 'zero', 'none' ) as $id ) {
			$this->assertArrayNotHasKey( 'held_until', $channels[ $id ] );
		}
	}

	// The dispatcher.

	/**
	 * A pre_send callback can hold an instance.
	 */
	public function test_pre_send_can_defer() {
		$this->add( 'a', 'succeed' );
		$until = time() + 1800;
		add_filter(
			'sc_wpun_pre_send',
			static function () use ( $until ) {
				return Delivery_Result::deferred( $until, 'Closed.' );
			}
		);

		$this->run_check();

		$this->assertSame( 0, $this->calls() );
		$this->assertSame( $until, $this->held_until() );
		$this->assertSame( '', $this->notified_version( 'core', 'core', 'a' ) );
	}

	/**
	 * A test send ignores a pre_send hold, says so, and does not store one.
	 */
	public function test_a_test_send_ignores_a_pre_send_hold() {
		$this->add( 'a', 'succeed' );
		add_filter(
			'sc_wpun_pre_send',
			static function () {
				return Delivery_Result::deferred( time() + 1800 );
			}
		);

		$result = sc_wpun()->dispatcher()->send_one( $this->notification( true ), sc_wpun_channels()->get( 'a' ) );

		$this->assertSame( 1, $this->calls() );
		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'Delivered.', $result->get_message() );
		$this->assertStringContainsString( 'Not held back, because this is a test message.', $result->get_message() );
		$this->assertNull( $this->held_until() );

		$this->add( 'a', 'fail' );
		$result = sc_wpun()->dispatcher()->send_one( $this->notification( true ), sc_wpun_channels()->get( 'a' ) );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 500, $result->get_status() );
		$this->assertStringContainsString( 'Not held back', $result->get_message() );
	}

	/**
	 * A deferred result from the channel itself, on a test, is returned unchanged and not stored.
	 */
	public function test_a_deferred_result_on_a_test_is_not_stored() {
		$this->add();

		$result = sc_wpun()->dispatcher()->send_one( $this->notification( true ), sc_wpun_channels()->get( 'a' ) );

		$this->assertTrue( $result->is_deferred() );
		$this->assertSame( 'Held back.', $result->get_message() );
		$this->assertNull( $this->held_until() );
	}

	// Display.

	/**
	 * The Channels tab shows the hold, not an error, and only while it lasts.
	 */
	public function test_the_channels_tab_text() {
		$last = array(
			'success' => true,
			'message' => 'Fine.',
			'time'    => time(),
		);
		$at   = time() + 300;
		$when = WPUN\Admin\Settings_Page::format_site_time( $at );

		$text = Channels_Tab::last_delivery_text( $last, null, $at );

		$this->assertStringStartsWith( 'Sent ', $text );
		$this->assertStringEndsWith( 'Held until ' . $when, $text );
		$this->assertStringNotContainsString( 'Failed', $text );
		$this->assertSame( 'Never; Held until ' . $when, Channels_Tab::last_delivery_text( null, null, $at ) );
		$this->assertStringNotContainsString( 'Held', Channels_Tab::last_delivery_text( $last, null, time() - 5 ) );
		$this->assertStringNotContainsString( 'Held', Channels_Tab::last_delivery_text( $last ) );
	}

	/**
	 * The report, the CLI tables and Site Health debug information show the hold.
	 */
	public function test_status_report_cli_and_debug_info_show_the_hold() {
		$this->add();
		$this->run_check();

		$channel = sc_wpun()->status_report()->channels()[0];
		$this->assertSame( WPUN\Status_Report::format_time( $this->held_until() ), $channel['held_until'] );
		$this->assertNull( $channel['last_result'] );
		$this->assertNull( $channel['last_failure'] );

		$section = apply_filters( 'debug_information', array() )[ WPUN\Site_Health::SECTION ];
		$this->assertStringContainsString( 'held until: ' . $channel['held_until'], $section['fields']['channel_a']['debug'] );
		$this->assertStringContainsString( 'Held until: ' . $channel['held_until'], $section['fields']['channel_a']['value'] );

		$this->end_hold();
		$this->assertNull( sc_wpun()->status_report()->channels()[0]['held_until'], 'Only while it lasts.' );
	}
}
