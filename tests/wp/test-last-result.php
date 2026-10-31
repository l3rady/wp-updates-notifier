<?php
/**
 * The last delivery result of each channel instance is kept in the run state (#165).
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification;

/**
 * Stored on sent and failed deliveries, never for tests, and kept through update checks.
 */
class Test_Last_Result extends WPUN_Test_Case {

	/**
	 * A notification.
	 *
	 * @param bool $is_test Whether it is a test.
	 * @return Notification
	 */
	private function notification( $is_test = false ) {
		return new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array(), array( 'is_test' => $is_test ) );
	}

	/**
	 * A notification with one item, so the dispatcher routes it.
	 *
	 * @return Notification
	 */
	private function notification_with_item() {
		$item = new WPUN\Notification_Item(
			array(
				'type'            => 'plugin',
				'key'             => 'hello.php',
				'name'            => 'Hello Dolly',
				'current_version' => '1.0',
				'new_version'     => '2.0',
			)
		);

		return $this->notification()->with_items( array( $item ) );
	}

	/**
	 * The stored last result of the email instance.
	 *
	 * @return array|null
	 */
	private function last_result() {
		return $this->stored_state()['channels']['email']['last_result'] ?? null;
	}

	/**
	 * Run an update check with an update pending.
	 */
	private function run_check() {
		$this->set_updates( '99.0.0' );
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * A successful dispatch is stored.
	 */
	public function test_a_dispatch_success_is_stored() {
		$this->save_settings();

		sc_wpun()->dispatcher()->dispatch( $this->notification_with_item(), array( sc_wpun_channels()->get( 'email' ) ) );

		$last = $this->last_result();
		$this->assertTrue( $last['success'] );
		$this->assertIsString( $last['message'] );
		$this->assertEqualsWithDelta( time(), $last['time'], 5 );
		$this->assertSame( array( 'success', 'message', 'time' ), array_keys( $last ) );
	}

	/**
	 * A failed dispatch is stored with its message.
	 */
	public function test_a_dispatch_failure_is_stored() {
		$this->save_settings();
		add_filter( 'pre_wp_mail', '__return_false' );

		sc_wpun()->dispatcher()->dispatch( $this->notification_with_item(), array( sc_wpun_channels()->get( 'email' ) ) );

		$last = $this->last_result();
		$this->assertFalse( $last['success'] );
		$this->assertNotSame( '', $last['message'] );
		$this->assertEqualsWithDelta( time(), $last['time'], 5 );
	}

	/**
	 * The update check does not wipe the result with its older copy of the state, and still records what was notified.
	 */
	public function test_an_update_check_success_keeps_the_result() {
		$this->save_settings();

		$this->run_check();

		$this->assertTrue( $this->last_result()['success'] );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ) );
	}

	/**
	 * A failed delivery in an update check is stored and nothing is recorded as notified.
	 */
	public function test_an_update_check_failure_keeps_the_result() {
		$this->save_settings();
		add_filter( 'pre_wp_mail', '__return_false' );

		$this->run_check();

		$this->assertFalse( $this->last_result()['success'] );
		$this->assertSame( '', $this->notified_version( 'core', 'core' ) );
	}

	/**
	 * A later check that sends nothing keeps the result, and a deleted instance is pruned with it.
	 */
	public function test_a_quiet_check_keeps_the_result() {
		$this->save_settings();
		$this->run_check();
		$before = $this->last_result();

		sc_wpun()->update_checker()->do_update_check();

		$this->assertSame( $before, $this->last_result() );
	}

	/**
	 * A test send neither stores nor changes the result.
	 */
	public function test_a_test_send_is_not_stored() {
		$this->save_settings();
		$email = sc_wpun_channels()->get( 'email' );

		sc_wpun()->dispatcher()->send_one( $this->notification( true ), $email );
		$this->assertNull( $this->last_result() );

		sc_wpun()->dispatcher()->send_one( $this->notification(), $email );
		$before = $this->last_result();
		$this->assertTrue( $before['success'] );

		add_filter( 'pre_wp_mail', '__return_false' );
		sc_wpun()->dispatcher()->send_one( $this->notification( true ), $email );
		$this->assertSame( $before, $this->last_result() );
	}

	/**
	 * Malformed results are dropped and valid ones kept.
	 */
	public function test_normalise() {
		$state = sc_wpun()->state()->normalise(
			array(
				'channels' => array(
					'ok'        => array(
						'last_result' => array(
							'success' => 1,
							'message' => 'Hi',
							'time'    => '1700000000',
							'extra'   => 'x',
						),
					),
					'nomessage' => array(
						'last_result' => array(
							'success' => false,
							'message' => array( 'x' ),
							'time'    => 5,
						),
					),
					'notime'    => array(
						'last_result' => array(
							'success' => true,
							'message' => 'a',
						),
					),
					'zero'      => array(
						'last_result' => array(
							'success' => true,
							'message' => 'a',
							'time'    => 0,
						),
					),
					'junk'      => array( 'last_result' => 'text' ),
					'none'      => array(),
				),
			)
		)['channels'];

		$this->assertSame(
			array(
				'success' => true,
				'message' => 'Hi',
				'time'    => 1700000000,
			),
			$state['ok']['last_result']
		);
		$this->assertSame(
			array(
				'success' => false,
				'message' => '',
				'time'    => 5,
			),
			$state['nomessage']['last_result']
		);
		foreach ( array( 'notime', 'zero', 'junk', 'none' ) as $id ) {
			$this->assertArrayNotHasKey( 'last_result', $state[ $id ] );
		}
	}

	/**
	 * Skipping what is pending keeps the result.
	 */
	public function test_skip_pending_keeps_the_result() {
		$this->save_settings();
		sc_wpun()->dispatcher()->send_one( $this->notification(), sc_wpun_channels()->get( 'email' ) );
		$before = $this->last_result();
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '1' ) ) );
		$state                                     = sc_wpun()->state()->get();
		$state['channels']['email']['last_result'] = $before;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );

		sc_wpun()->state()->skip_pending( 'email' );

		$entry = $this->stored_state()['channels']['email'];
		$this->assertSame( $before, $entry['last_result'] );
		$this->assertSame( array(), $entry['notified'] );
		$this->assertTrue( $entry['skip_pending'] );
	}

	/**
	 * Forgetting or deleting an instance drops the result.
	 */
	public function test_forget_and_delete_drop_the_result() {
		$this->save_settings();
		sc_wpun()->dispatcher()->send_one( $this->notification(), sc_wpun_channels()->get( 'email' ) );
		$this->assertNotNull( $this->last_result() );

		sc_wpun()->state()->forget_channel( 'email' );
		$this->assertNull( $this->last_result() );

		sc_wpun()->dispatcher()->send_one( $this->notification(), sc_wpun_channels()->get( 'email' ) );
		$this->assertNotNull( $this->last_result() );

		sc_wpun_channels()->delete( 'email' );
		$this->assertArrayNotHasKey( 'email', $this->stored_state()['channels'] );
	}
}
