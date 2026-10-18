<?php
/**
 * A channel instance told to wait (Retry-After) is skipped until then (#180).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channels_Tab;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;
use WPUN\State;

/**
 * Stored on a failure with a retry_after, honoured by the update check, cleared by any other result.
 */
class Test_Retry_After extends WPUN_Test_Case {

	/**
	 * The fake channel.
	 *
	 * @var Test_Channel
	 */
	private $channel;

	/**
	 * Register a channel that can answer HTTP 429, and start without instances.
	 */
	public function set_up() {
		parent::set_up();

		$this->channel = new Test_Channel( 'limited' );
		sc_wpun()->channels()->unregister( 'limited' );
		sc_wpun()->channels()->register( $this->channel );
		delete_option( 'sc_wpun_channels' );
		delete_option( 'sc_wpun_state' );
	}

	/**
	 * Save an instance of the fake channel.
	 *
	 * @param string $id          Id.
	 * @param string $behaviour   What it does: succeed, fail or limit.
	 * @param mixed  $retry_after Seconds a limit asks for.
	 */
	private function add( $id, $behaviour = 'limit', $retry_after = 120 ) {
		$settings = array( 'behaviour' => $behaviour );
		if ( null !== $retry_after ) {
			$settings['retry_after'] = $retry_after;
		}
		sc_wpun_channels()->save( new Channel_Instance( $id, 'limited', ucfirst( $id ), true, $settings ) );
	}

	/**
	 * Change what an instance does from now on.
	 *
	 * @param string $id        Id.
	 * @param string $behaviour What it does.
	 */
	private function behave( $id, $behaviour ) {
		sc_wpun_channels()->save( new Channel_Instance( $id, 'limited', ucfirst( $id ), true, array( 'behaviour' => $behaviour ) ) );
	}

	/**
	 * Run an update check with an update pending.
	 */
	private function run_check() {
		$this->set_updates( '99.0.0' );
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * How many times an instance was asked to send.
	 *
	 * @param string $id Id.
	 * @return int
	 */
	private function calls( $id ) {
		return count( $this->channel->received[ $id ] ?? array() );
	}

	/**
	 * The stored time an instance is deferred to.
	 *
	 * @param string $id Id.
	 * @return int|null
	 */
	private function retry_at( $id = 'a' ) {
		return $this->stored_state()['channels'][ $id ]['retry_at'] ?? null;
	}

	/**
	 * Make the stored deferral end.
	 *
	 * @param string $id Id.
	 */
	private function end_deferral( $id = 'a' ) {
		$state                                = get_option( 'sc_wpun_state' );
		$state['channels'][ $id ]['retry_at'] = time() - 1;
		update_option( 'sc_wpun_state', $state, false );
	}

	/**
	 * A test notification, as the Channels tab sends.
	 *
	 * @return Notification
	 */
	private function test_notification() {
		return new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array(), array( 'is_test' => true ) );
	}

	/**
	 * The deferral survives the check, holds the instance back, and ends on time.
	 */
	public function test_a_check_defers_the_instance_until_the_time_and_then_sends() {
		$this->add( 'a' );

		$this->run_check();

		$this->assertSame( 1, $this->calls( 'a' ) );
		$this->assertEqualsWithDelta( time() + 120, $this->retry_at(), 5 );
		$this->assertSame( '', $this->notified_version( 'core', 'core', 'a' ) );

		$this->run_check();

		$this->assertSame( 1, $this->calls( 'a' ), 'Not asked again while deferred.' );
		$this->assertSame( '', $this->notified_version( 'core', 'core', 'a' ) );
		$this->assertNotNull( $this->retry_at(), 'Still deferred after a quiet check.' );

		$this->behave( 'a', 'succeed' );
		$this->end_deferral();
		$this->run_check();

		$this->assertSame( 2, $this->calls( 'a' ) );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'a' ) );
		$this->assertNull( $this->retry_at() );
		$this->assertTrue( $this->stored_state()['channels']['a']['last_result']['success'] );
	}

	/**
	 * Another instance is delivered as usual.
	 */
	public function test_other_instances_are_not_deferred() {
		$this->add( 'a' );
		$this->add( 'b', 'succeed', null );

		$this->run_check();
		$this->assertSame( 1, $this->calls( 'b' ) );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'b' ) );

		$this->set_updates( '100.0.0' );
		sc_wpun()->update_checker()->do_update_check();

		$this->assertSame( 1, $this->calls( 'a' ) );
		$this->assertSame( 2, $this->calls( 'b' ) );
		$this->assertSame( '100.0.0', $this->notified_version( 'core', 'core', 'b' ) );
		$this->assertNull( $this->retry_at( 'b' ) );
	}

	/**
	 * A success lifts the deferral.
	 */
	public function test_a_success_clears_it() {
		$this->add( 'a' );
		$this->run_check();
		$this->assertNotNull( $this->retry_at() );

		$this->behave( 'a', 'succeed' );
		sc_wpun()->dispatcher()->send_one( $this->test_notification()->with_items( $this->items() ), sc_wpun_channels()->get( 'a' ) );
		$this->assertNotNull( $this->retry_at(), 'A test send leaves it.' );

		$notification = new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', $this->items() );
		sc_wpun()->dispatcher()->send_one( $notification, sc_wpun_channels()->get( 'a' ) );

		$this->assertNull( $this->retry_at() );
	}

	/**
	 * A failure without a retry_after lifts it too.
	 */
	public function test_a_failure_without_retry_after_clears_it() {
		$this->add( 'a' );
		$this->run_check();
		$this->assertNotNull( $this->retry_at() );

		$this->behave( 'a', 'fail' );
		$notification = new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', $this->items() );
		sc_wpun()->dispatcher()->send_one( $notification, sc_wpun_channels()->get( 'a' ) );

		$this->assertNull( $this->retry_at() );
		$this->assertFalse( $this->stored_state()['channels']['a']['last_result']['success'] );
	}

	/**
	 * Items for a real notification.
	 *
	 * @return WPUN\Notification_Item[]
	 */
	private function items() {
		return array(
			new WPUN\Notification_Item(
				array(
					'type'            => 'plugin',
					'key'             => 'hello.php',
					'name'            => 'Hello Dolly',
					'current_version' => '1.0',
					'new_version'     => '2.0',
				)
			),
		);
	}

	/**
	 * A very long wait is cut to a week.
	 */
	public function test_the_wait_is_capped_at_a_week() {
		$this->add( 'a', 'limit', 10 * YEAR_IN_SECONDS );

		$this->run_check();

		$this->assertSame( 604800, State::MAX_RETRY_AFTER );
		$this->assertEqualsWithDelta( time() + State::MAX_RETRY_AFTER, $this->retry_at(), 5 );
	}

	/**
	 * No wait, or none given, sets nothing and the next check tries again.
	 */
	public function test_no_usable_retry_after_sets_nothing() {
		foreach ( array( 0, null ) as $wait ) {
			delete_option( 'sc_wpun_state' );
			$this->channel->received = array();
			$this->add( 'a', 'limit', $wait );

			$this->run_check();
			$this->assertNull( $this->retry_at() );

			$this->run_check();
			$this->assertSame( 2, $this->calls( 'a' ) );
		}
	}

	/**
	 * A test send works while deferred and does not set a deferral.
	 */
	public function test_a_test_send_ignores_the_deferral() {
		$this->add( 'a' );
		$this->run_check();
		$retry_at = $this->retry_at();

		$result = sc_wpun()->dispatcher()->send_one( $this->test_notification(), sc_wpun_channels()->get( 'a' ) );

		$this->assertSame( 2, $this->calls( 'a' ) );
		$this->assertSame( 429, $result->get_status() );
		$this->assertSame( $retry_at, $this->retry_at() );

		delete_option( 'sc_wpun_state' );
		sc_wpun()->dispatcher()->send_one( $this->test_notification(), sc_wpun_channels()->get( 'a' ) );
		$this->assertNull( $this->retry_at() );
	}

	/**
	 * A pending skip waits for the deferral to end.
	 */
	public function test_a_pending_skip_waits_while_deferred() {
		$this->add( 'a' );
		$this->run_check();
		sc_wpun()->state()->skip_pending( 'a' );
		$state                              = get_option( 'sc_wpun_state' );
		$state['channels']['a']['retry_at'] = time() + 600;
		update_option( 'sc_wpun_state', $state, false );

		$this->run_check();

		$this->assertTrue( $this->stored_state()['channels']['a']['skip_pending'] );
		$this->assertSame( 1, $this->calls( 'a' ) );

		$this->end_deferral();
		$this->run_check();

		$this->assertFalse( $this->stored_state()['channels']['a']['skip_pending'] );
		$this->assertSame( 1, $this->calls( 'a' ), 'The skip records without sending.' );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'a' ) );
	}

	/**
	 * Forgetting an instance forgets its deferral.
	 */
	public function test_forgetting_the_instance_removes_the_deferral() {
		$this->add( 'a' );
		$this->run_check();
		$this->assertNotNull( $this->retry_at() );

		sc_wpun()->state()->forget_channel( 'a' );

		$this->assertNull( $this->retry_at() );
	}

	/**
	 * A valid time is kept; junk is dropped.
	 */
	public function test_normalise() {
		$channels = sc_wpun()->state()->normalise(
			array(
				'channels' => array(
					'ok'       => array( 'retry_at' => 1900000000 ),
					'numeric'  => array( 'retry_at' => '1900000001' ),
					'text'     => array( 'retry_at' => 'soon' ),
					'zero'     => array( 'retry_at' => 0 ),
					'negative' => array( 'retry_at' => -5 ),
					'array'    => array( 'retry_at' => array( 1 ) ),
					'none'     => array(),
				),
			)
		)['channels'];

		$this->assertSame( 1900000000, $channels['ok']['retry_at'] );
		$this->assertSame( 1900000001, $channels['numeric']['retry_at'] );
		foreach ( array( 'text', 'zero', 'negative', 'array', 'none' ) as $id ) {
			$this->assertArrayNotHasKey( 'retry_at', $channels[ $id ] );
		}
	}

	/**
	 * A response with these headers.
	 *
	 * @param array $headers Headers.
	 * @return array
	 */
	private function response( array $headers ) {
		$response            = $this->fake_response( 429 );
		$response['headers'] = $headers;

		return $response;
	}

	/**
	 * Seconds from a Retry-After value.
	 *
	 * @param mixed $value Header value.
	 * @return int|null
	 */
	private function wait( $value ) {
		return Delivery_Result::retry_after_from_response( $this->response( array( 'retry-after' => $value ) ) );
	}

	/**
	 * Delta seconds.
	 */
	public function test_the_helper_reads_seconds() {
		$this->assertSame( 120, $this->wait( '120' ) );
		$this->assertSame( 30, $this->wait( ' 30 ' ) );
		$this->assertNull( $this->wait( '0' ) );
		$this->assertGreaterThan( 604800, $this->wait( '99999999999999999999999' ), 'No overflow.' );
		$this->assertIsInt( $this->wait( '99999999999999999999999' ) );
	}

	/**
	 * Values that are not a wait.
	 */
	public function test_the_helper_rejects_garbage() {
		foreach ( array( 'soon', 'tomorrow', '-5', '1.5', '', '   ', '12 seconds', 'Sun, 99 Foo 2999 99:99:99 GMT' ) as $value ) {
			$this->assertNull( $this->wait( $value ), var_export( $value, true ) );
		}
		$this->assertNull( Delivery_Result::retry_after_from_response( $this->response( array() ) ) );
		$this->assertNull( Delivery_Result::retry_after_from_response( new WP_Error( 'x', 'y' ) ) );
		$this->assertNull( Delivery_Result::retry_after_from_response( null ) );
	}

	/**
	 * The first of a repeated header counts.
	 */
	public function test_the_helper_uses_the_first_of_a_repeated_header() {
		$this->assertSame( 45, $this->wait( array( '45', '90' ) ) );
	}

	/**
	 * A case-insensitive header dictionary, as real responses have.
	 */
	public function test_the_helper_reads_a_header_of_any_case() {
		$response = $this->response( array() );

		$response['headers'] = new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array( 'Retry-After' => '60' ) );

		$this->assertSame( 60, Delivery_Result::retry_after_from_response( $response ) );
	}

	/**
	 * The three HTTP date formats, in the future.
	 */
	public function test_the_helper_reads_http_dates() {
		$at = time() + 120;

		$this->assertEqualsWithDelta( 120, $this->wait( gmdate( 'D, d M Y H:i:s \G\M\T', $at ) ), 2, 'IMF-fixdate' );
		$this->assertEqualsWithDelta( 120, $this->wait( gmdate( 'l, d-M-y H:i:s \G\M\T', $at ) ), 2, 'RFC 850' );
		$this->assertEqualsWithDelta( 120, $this->wait( gmdate( 'D M j H:i:s Y', $at ) ), 2, 'asctime' );
		$this->assertEqualsWithDelta( 120, $this->wait( gmdate( 'D M  j H:i:s Y', $at ) ), 2, 'asctime with padded day' );
	}

	/**
	 * A wrong day name does not move the date, and an impossible date is no wait.
	 */
	public function test_the_helper_ignores_the_day_name_and_rejects_impossible_dates() {
		$at  = time() + 3 * DAY_IN_SECONDS;
		$day = 'Mon' === gmdate( 'D', $at ) ? 'Tue' : 'Mon';

		$this->assertEqualsWithDelta( 3 * DAY_IN_SECONDS, $this->wait( $day . gmdate( ', d M Y H:i:s \G\M\T', $at ) ), 2 );
		$this->assertNull( $this->wait( 'Mon, 31 Feb ' . ( (int) gmdate( 'Y' ) + 1 ) . ' 08:49:37 GMT' ) );
		$this->assertNull( $this->wait( 'Mon, 06 Nov 2099 08:49:37 UTC' ) );
	}

	/**
	 * A date that has passed is no wait.
	 */
	public function test_the_helper_ignores_a_past_date() {
		$this->assertNull( $this->wait( gmdate( 'D, d M Y H:i:s \G\M\T', time() - 3600 ) ) );
		$this->assertNull( $this->wait( 'Sun, 06 Nov 1994 08:49:37 GMT' ) );
	}

	/**
	 * The Channels tab shows the deferral only while it lasts.
	 */
	public function test_the_channels_tab_text() {
		$last = array(
			'success' => false,
			'message' => 'Limited.',
			'time'    => time(),
		);
		$at   = time() + 300;

		$text = Channels_Tab::last_delivery_text( $last, $at );

		$this->assertStringStartsWith( 'Failed ', $text );
		$this->assertStringContainsString( 'Limited. Rate limited, next try after ' . WPUN\Admin\Settings_Page::format_site_time( $at ), $text );
		$this->assertStringNotContainsString( 'next try', Channels_Tab::last_delivery_text( $last, time() - 5 ) );
		$this->assertStringNotContainsString( 'next try', Channels_Tab::last_delivery_text( $last ) );
		$this->assertStringNotContainsString( 'next try', Channels_Tab::last_delivery_text( array_merge( $last, array( 'success' => true ) ), $at ) );
	}
}
