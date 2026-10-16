<?php
/**
 * Delivering a notification through channel instances.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Dispatcher;
use WPUN\Notification;
use WPUN\Notification_Item;

/**
 * The dispatcher.
 */
class Test_Dispatcher extends WPUN_Test_Case {

	/**
	 * Registry.
	 *
	 * @var Channel_Registry
	 */
	private $registry;

	/**
	 * Channel registered as "test".
	 *
	 * @var Test_Channel
	 */
	private $channel;

	/**
	 * Dispatcher.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Notices captured by dispatch_capturing_notices().
	 *
	 * @var string[]
	 */
	private $notices = array();

	/**
	 * Register a test channel on a fresh registry.
	 */
	public function set_up() {
		parent::set_up();

		$this->registry   = new Channel_Registry();
		$this->channel    = new Test_Channel( 'test' );
		$this->dispatcher = new Dispatcher( $this->registry );
		$this->registry->register( $this->channel );
	}

	/**
	 * A notification.
	 *
	 * @return Notification
	 */
	private function notification() {
		$item = new Notification_Item(
			array(
				'type'            => 'core',
				'key'             => 'core',
				'name'            => 'WordPress',
				'current_version' => '6.0',
				'new_version'     => '6.1',
			)
		);

		return new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $item ) );
	}

	/**
	 * An instance of the test channel.
	 *
	 * @param string $id        Id.
	 * @param string $behaviour What the channel does.
	 * @param bool   $enabled   Whether it is switched on.
	 * @param string $type      Channel type.
	 * @return Channel_Instance
	 */
	private function instance( $id, $behaviour = 'succeed', $enabled = true, $type = 'test' ) {
		return new Channel_Instance( $id, $type, $id, $enabled, array( 'behaviour' => $behaviour ) );
	}

	/**
	 * Run dispatch() and capture the notices WP_DEBUG makes the dispatcher raise for failing channels.
	 *
	 * @param array $instances Instances.
	 * @return array Results by instance id.
	 */
	private function dispatch_capturing_notices( array $instances ) {
		$this->notices = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing the notice the dispatcher raises under WP_DEBUG.
			function ( $number, $message ) {
				$this->notices[] = $message;
				return true;
			},
			E_USER_NOTICE
		);
		try {
			return $this->dispatcher->dispatch( $this->notification(), $instances );
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Enabled instances receive the notification.
	 */
	public function test_delivers_to_enabled_instances() {
		$notification = $this->notification();

		$results = $this->dispatcher->dispatch( $notification, array( $this->instance( 'a' ) ) );

		$this->assertSame( array( 'a' ), array_keys( $results ) );
		$this->assertTrue( $results['a']->is_success() );
		$this->assertEquals( array( $notification ), $this->channel->received['a'] );
	}

	/**
	 * Disabled instances are left out and not called.
	 */
	public function test_skips_disabled_instances() {
		$results = $this->dispatcher->dispatch( $this->notification(), array( $this->instance( 'off', 'succeed', false ), $this->instance( 'on' ) ) );

		$this->assertSame( array( 'on' ), array_keys( $results ) );
		$this->assertArrayNotHasKey( 'off', $this->channel->received );
	}

	/**
	 * An instance of a type nobody registered is unavailable, and left alone.
	 */
	public function test_unregistered_type_is_unavailable() {
		$results = $this->dispatcher->dispatch( $this->notification(), array( $this->instance( 'gone', 'succeed', true, 'missing' ), $this->instance( 'ok' ) ) );

		$this->assertFalse( $results['gone']->is_success() );
		$this->assertSame( 'unavailable', $results['gone']->get_code() );
		$this->assertTrue( $results['ok']->is_success() );
	}

	/**
	 * One channel failing, throwing or answering badly never stops the next.
	 */
	public function test_failures_do_not_stop_later_instances() {
		$results = $this->dispatch_capturing_notices(
			array(
				$this->instance( 'throws', 'throw' ),
				$this->instance( 'fails', 'fail' ),
				$this->instance( 'bad', 'invalid' ),
				$this->instance( 'healthy' ),
			)
		);

		$this->assertSame( array( 'throws', 'fails', 'bad', 'healthy' ), array_keys( $results ) );
		$this->assertSame( 'exception', $results['throws']->get_code() );
		$this->assertStringNotContainsString( 'It threw.', $results['throws']->get_message(), 'Exception messages can hold secrets and are kept out of results.' );
		$this->assertSame( 'The "test" channel failed with an error.', $results['throws']->get_message() );
		$this->assertArrayHasKey( 'exception', $results['throws']->get_context() );
		$this->assertSame( 'test_failure', $results['fails']->get_code() );
		$this->assertSame( 500, $results['fails']->get_status() );
		$this->assertSame( 'invalid_result', $results['bad']->get_code() );
		$this->assertTrue( $results['healthy']->is_success() );
		$this->assertCount( 1, $this->channel->received['healthy'] );
	}

	/**
	 * Two instances of one type each receive the notification.
	 */
	public function test_instances_of_the_same_type_each_receive_it() {
		$results = $this->dispatcher->dispatch( $this->notification(), array( $this->instance( 'first' ), $this->instance( 'second' ) ) );

		$this->assertCount( 2, $results );
		$this->assertCount( 1, $this->channel->received['first'] );
		$this->assertCount( 1, $this->channel->received['second'] );
	}

	/**
	 * Under WP_DEBUG a throwing channel is reported as a notice.
	 */
	public function test_exception_is_reported_when_debugging() {
		$this->assertTrue( WP_DEBUG, 'The WordPress test suite runs with WP_DEBUG.' );

		$this->dispatch_capturing_notices( array( $this->instance( 'throws', 'throw' ) ) );

		$this->assertCount( 1, $this->notices );
		$this->assertStringContainsString( 'It threw.', $this->notices[0] );
	}
}
