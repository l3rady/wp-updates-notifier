<?php
/**
 * Delivering a notification through channel instances.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Channel_Store;
use WPUN\Delivery_Result;
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
		$this->assertStringContainsString( 'RuntimeException', $this->notices[0] );
		$this->assertStringNotContainsString( 'It threw.', $this->notices[0], 'The exception message can hold a secret.' );
		$this->assertStringContainsString( 'throws', $this->notices[0] );
	}

	/**
	 * A dispatcher whose store throws when asked about the instance `broken` (an add-on can throw from anywhere the store reaches).
	 *
	 * @return Dispatcher
	 */
	private function dispatcher_with_throwing_store() {
		$store = new class( sc_wpun()->config(), $this->registry ) extends Channel_Store {
			/**
			 * Throw for one instance.
			 *
			 * @param string $id Instance id.
			 * @return string[]
			 * @throws RuntimeException For `broken`.
			 */
			public function problems( string $id ): array {
				if ( 'broken' === $id ) {
					throw new RuntimeException( 'schema blew up with secret-xyz' );
				}
				return parent::problems( $id );
			}
		};

		return new Dispatcher( $this->registry, $store );
	}

	/**
	 * Run a callable and capture the notices the dispatcher raises for exceptions.
	 *
	 * @param callable $run What to run.
	 * @return mixed What it returned.
	 */
	private function capturing_notices( callable $run ) {
		$this->notices = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing the notice the dispatcher raises under WP_DEBUG.
			function ( $number, $message ) {
				$this->notices[] = $message;
				return true;
			},
			E_USER_NOTICE
		);
		try {
			return $run();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * What a failed instance may show: a result of the exception kind, with the class and no message.
	 *
	 * @param Delivery_Result $result The result.
	 * @param string          $exception_class The exception class.
	 */
	private function assert_exception_result( $result, $exception_class ) {
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'exception', $result->get_code() );
		$this->assertSame( array( 'exception' => $exception_class ), $result->get_context() );
		$this->assertStringNotContainsString( 'secret-xyz', $result->get_message() . wp_json_encode( $result->get_context() ) );
		$this->assertStringNotContainsString( 'secret-xyz', implode( ' ', $this->notices ) );
		$this->assertStringContainsString( $exception_class, implode( ' ', $this->notices ) );
	}

	/**
	 * A store that throws fails its own instance and no other.
	 */
	public function test_a_store_that_throws_does_not_stop_the_check() {
		$dispatcher = $this->dispatcher_with_throwing_store();
		$before     = $this->instance( 'before' );
		$after      = $this->instance( 'after' );

		$results = $this->capturing_notices(
			fn() => $dispatcher->dispatch( $this->notification(), array( $before, $this->instance( 'broken' ), $after ) )
		);

		$this->assertSame( array( 'before', 'broken', 'after' ), array_keys( $results ) );
		$this->assertTrue( $results['before']->is_success() );
		$this->assertTrue( $results['after']->is_success() );
		$this->assert_exception_result( $results['broken'], RuntimeException::class );
		$this->assertArrayHasKey( 'after', $this->channel->received );
	}

	/**
	 * A routing callback that throws fails its own instance and no other.
	 */
	public function test_a_routing_callback_that_throws_does_not_stop_the_check() {
		add_filter(
			'sc_wpun_channel_should_receive_item',
			static function ( $receive, $item, $instance ) {
				if ( 'bad_route' === $instance->get_id() ) {
					throw new LogicException( 'routing blew up with secret-xyz' );
				}
				return $receive;
			},
			10,
			3
		);

		$results = $this->capturing_notices(
			fn() => $this->dispatcher->dispatch( $this->notification(), array( $this->instance( 'bad_route' ), $this->instance( 'good' ) ) )
		);

		$this->assertSame( array( 'bad_route', 'good' ), array_keys( $results ) );
		$this->assert_exception_result( $results['bad_route'], LogicException::class );
		$this->assertTrue( $results['good']->is_success() );
		$this->assertArrayNotHasKey( 'bad_route', $this->channel->received, 'The channel was not called for the failed instance.' );
	}

	/**
	 * An instance that is routed nothing still returns no result, and a test message is never routed.
	 */
	public function test_an_instance_routed_nothing_has_no_result() {
		add_filter( 'sc_wpun_channel_should_receive_item', '__return_false' );

		$results = $this->dispatcher->dispatch( $this->notification(), array( $this->instance( 'quiet' ) ) );

		$this->assertSame( array(), $results );
	}

	/**
	 * A listener hears about the failed instance, as it does about any failure.
	 */
	public function test_a_failure_before_sending_is_announced() {
		$heard = array();
		add_action(
			'sc_wpun_notification_failed',
			static function ( $result, $notification, $instance ) use ( &$heard ) {
				$heard[] = array( $instance->get_id(), $result->get_code() );
			},
			10,
			3
		);
		$dispatcher = $this->dispatcher_with_throwing_store();

		$this->capturing_notices( fn() => $dispatcher->dispatch( $this->notification(), array( $this->instance( 'broken' ) ) ) );

		$this->assertSame( array( array( 'broken', 'exception' ) ), $heard );
	}

	/**
	 * Sending through one instance reports a store that throws as a failure too.
	 */
	public function test_send_one_reports_a_store_that_throws() {
		$dispatcher = $this->dispatcher_with_throwing_store();

		$result = $this->capturing_notices( fn() => $dispatcher->send_one( $this->notification(), $this->instance( 'broken' ) ) );

		$this->assert_exception_result( $result, RuntimeException::class );
	}

	/**
	 * A channel that throws is still reported once by send_one(), as before.
	 */
	public function test_send_one_reports_a_channel_that_throws() {
		$result = $this->capturing_notices( fn() => $this->dispatcher->send_one( $this->notification(), $this->instance( 'throws', 'throw' ) ) );

		$this->assertSame( 'exception', $result->get_code() );
		$this->assertSame( array( 'exception' => RuntimeException::class ), $result->get_context() );
		$this->assertCount( 1, $this->notices, 'Logged once.' );
	}
}
