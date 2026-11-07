<?php
/**
 * A result can say which items went out before it failed or was deferred (#444).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Update_Checker;

/**
 * The result, and how the update check records and resumes a partial delivery.
 */
class Test_Partial_Delivery extends WPUN_Test_Case {

	/**
	 * A second installed plugin to have waiting next to Hello Dolly.
	 */
	const OTHER = 'wp-updates-notifier/wp-updates-notifier.php';

	/**
	 * The fake channel.
	 *
	 * @var Test_Channel
	 */
	private $channel;

	/**
	 * What the next sends do, in order; a send with nothing left succeeds. Each is a callable taking the notification.
	 *
	 * @var callable[]
	 */
	private $script = array();

	/**
	 * Register the fake channel and start without instances or state.
	 */
	public function set_up() {
		parent::set_up();

		$this->script           = array();
		$this->channel          = new Test_Channel( 'part' );
		$script                 = &$this->script;
		$this->channel->handler = static function ( Notification $notification ) use ( &$script ) {
			$step = array_shift( $script );
			return null === $step ? Delivery_Result::success() : call_user_func( $step, $notification );
		};
		sc_wpun()->channels()->unregister( 'part' );
		sc_wpun()->channels()->register( $this->channel );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_channels' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_muted' );
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'daily' ) ) );
	}

	/**
	 * Save an instance of the fake channel.
	 *
	 * @param string      $id     Id.
	 * @param string|null $remind The `remind` routing value; null leaves the key out.
	 */
	private function add( $id = 'a', $remind = null ) {
		$routing = null === $remind ? array() : array( 'remind' => $remind );
		sc_wpun_channels()->save( new Channel_Instance( $id, 'part', ucfirst( $id ), true, array( 'behaviour' => 'script' ), $routing ) );
	}

	/**
	 * Queue what the next send does.
	 *
	 * @param callable $step Takes the notification, returns a result.
	 */
	private function then( $step ) {
		$this->script[] = $step;
	}

	/**
	 * Run a check with core and these plugins waiting.
	 *
	 * @param string[] $plugins Plugin files waiting, at version 2.0.
	 * @param bool     $force   Whether to force.
	 * @return array The plan.
	 */
	private function run_check( array $plugins = array( 'hello.php', self::OTHER ), $force = false ) {
		$updates = array();
		foreach ( $plugins as $file ) {
			$updates[ $file ] = array( 'new_version' => '2.0' );
		}
		$this->set_updates( '99.0.0', $updates );
		return sc_wpun()->update_checker()->run_check( 'cli', false, $force );
	}

	/**
	 * Copies of items of a message, the way a module would have its own: same type, key and version, other name.
	 *
	 * @param Notification $notification Message.
	 * @param string[]     $keys         Keys to copy; empty for the first two.
	 * @return Notification_Item[]
	 */
	private function copies( Notification $notification, array $keys = array() ) {
		$copies = array();
		foreach ( $notification->get_items() as $item ) {
			if ( ( array() === $keys && count( $copies ) < 2 ) || in_array( $item->get_key(), $keys, true ) ) {
				$copies[] = new Notification_Item(
					array(
						'type'            => $item->get_type(),
						'key'             => $item->get_key(),
						'name'            => 'Module copy',
						'current_version' => '0.0',
						'new_version'     => $item->get_new_version(),
					)
				);
			}
		}
		return $copies;
	}

	/**
	 * A step that fails after delivering some items.
	 *
	 * @param string[] $keys Keys delivered; empty for the first two.
	 * @param int|null $wait Seconds to wait, if any.
	 * @return callable
	 */
	private function fail_after( array $keys = array(), $wait = null ) {
		return function ( Notification $notification ) use ( $keys, $wait ) {
			return Delivery_Result::failure( 'Rate limited.', 'rate_limited', 429, $wait )->with_delivered( $this->copies( $notification, $keys ) );
		};
	}

	/**
	 * A step that is deferred after delivering some items.
	 *
	 * @param string[] $keys Keys delivered; empty for the first two.
	 * @return callable
	 */
	private function defer_after( array $keys = array() ) {
		return function ( Notification $notification ) use ( $keys ) {
			return Delivery_Result::deferred( time() + 3600, 'Later.' )->with_delivered( $this->copies( $notification, $keys ) );
		};
	}

	/**
	 * The item keys of a message or a list of items, sorted.
	 *
	 * @param Notification|Notification_Item[] $source Message or items.
	 * @return string[]
	 */
	private function keys( $source ) {
		$items = $source instanceof Notification ? $source->get_items() : $source;
		$keys  = array();
		foreach ( $items as $item ) {
			$keys[] = $item->get_key();
		}
		sort( $keys );
		return $keys;
	}

	/**
	 * The messages an instance received.
	 *
	 * @param string $id Id.
	 * @return Notification[]
	 */
	private function received( $id = 'a' ) {
		return $this->channel->received[ $id ] ?? array();
	}

	/**
	 * The last message an instance received.
	 *
	 * @param string $id Id.
	 * @return Notification
	 */
	private function last( $id = 'a' ) {
		return end( $this->channel->received[ $id ] );
	}

	/**
	 * A stored field of an instance's state.
	 *
	 * @param string $field Field.
	 * @param string $id    Id.
	 * @return mixed
	 */
	private function entry( $field, $id = 'a' ) {
		return $this->stored_state()['channels'][ $id ][ $field ] ?? null;
	}

	/**
	 * Set when the reminder clock started, as seconds ago.
	 *
	 * @param int    $seconds_ago How long ago.
	 * @param string $id          Id.
	 */
	private function backdate( $seconds_ago, $id = 'a' ) {
		$state                                     = sc_wpun()->network_context()->get_option( 'sc_wpun_state' );
		$state['channels'][ $id ]['last_reminder'] = time() - $seconds_ago;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
	}

	/**
	 * Make the stored wait and hold end.
	 *
	 * @param string $id Id.
	 */
	private function end_wait( $id = 'a' ) {
		$state = sc_wpun()->network_context()->get_option( 'sc_wpun_state' );
		unset( $state['channels'][ $id ]['retry_at'], $state['channels'][ $id ]['held_until'] );
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
	}

	/**
	 * Snoozes for the two plugins, straight into the stored mutes to control the time.
	 *
	 * @param int $expires Expiry.
	 */
	private function snooze( $expires ) {
		$snooze = array(
			'until'   => null,
			'time'    => 1,
			'user'    => 0,
			'expires' => $expires,
		);
		sc_wpun()->network_context()->update_option(
			'sc_wpun_muted',
			array(
				'plugin' => array(
					'hello.php' => $snooze,
					self::OTHER => $snooze,
				),
			)
		);
	}

	// The result.

	/**
	 * Nothing is delivered unless the channel says so, and with_delivered() returns a changed copy.
	 */
	public function test_the_result() {
		$item     = new Notification_Item(
			array(
				'type'        => 'plugin',
				'key'         => 'a.php',
				'name'        => 'A',
				'new_version' => '2',
			)
		);
		$original = Delivery_Result::failure( 'Slow down.', 'rate_limited', 429, 30, array( 'x' => 1 ) );
		$this->assertSame( array(), $original->get_delivered() );

		$copy = $original->with_delivered( array( 'junk', $item, 5, null, array() ) );

		$this->assertNotSame( $original, $copy );
		$this->assertSame( array(), $original->get_delivered(), 'The original is unchanged.' );
		$this->assertSame( array( $item ), $copy->get_delivered(), 'Only items are kept.' );
		$this->assertFalse( $copy->is_success() );
		$this->assertSame( 'Slow down.', $copy->get_message() );
		$this->assertSame( 'rate_limited', $copy->get_code() );
		$this->assertSame( 429, $copy->get_status() );
		$this->assertSame( 30, $copy->get_retry_after() );
		$this->assertSame( array( 'x' => 1 ), $copy->get_context() );

		$deferred = Delivery_Result::deferred( 1900000000, 'Later.' )->with_delivered( array( $item ) );
		$this->assertTrue( $deferred->is_deferred() );
		$this->assertSame( 1900000000, $deferred->get_deferred_until() );
		$this->assertSame( 'Later.', $deferred->get_message() );
		$this->assertSame( array( $item ), $deferred->get_delivered() );
	}

	// A normal message.

	/**
	 * A failure with the delivered items records them, and the retry sends the rest.
	 */
	public function test_a_failure_records_the_delivered_items_and_the_retry_sends_the_rest() {
		$this->add();
		$this->then( $this->fail_after( array( 'core', 'hello.php' ), 60 ) );

		$plan = $this->run_check();

		$this->assertSame( Update_Checker::STATUS_FAILED, $plan['a']['status'] );
		$this->assertCount( 1, $this->received() );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'a' ) );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );
		$this->assertSame( '', $this->notified_version( 'plugin', self::OTHER, 'a' ) );

		$meta = $this->entry( 'notified_meta' )['plugin']['hello.php'];
		$this->assertNotSame( 'Module copy', $meta['name'], 'The plan\'s details are recorded, not the module\'s.' );
		$this->assertNotSame( '0.0', $meta['current_version'] );

		$this->assertNotNull( $this->entry( 'retry_at' ) );
		$this->end_wait();
		$this->run_check();

		$this->assertCount( 2, $this->received() );
		$this->assertSame( array( self::OTHER ), $this->keys( $this->last() ), 'Only the rest is sent.' );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', self::OTHER, 'a' ) );
	}

	/**
	 * Some of four items delivered before the wait: the retry sends the other two.
	 */
	public function test_part_of_several_items_delivered() {
		$this->add();
		$themes = array_slice( array_keys( wp_get_themes() ), 0, 1 );
		$this->assertNotEmpty( $themes, 'The test site has a theme.' );
		$plugins = array(
			'hello.php' => array( 'new_version' => '2.0' ),
			self::OTHER => array( 'new_version' => '2.0' ),
		);
		$theme   = array( $themes[0] => array( 'new_version' => '99.1' ) );
		$this->set_updates( '99.0.0', $plugins, $theme );
		$this->then( $this->fail_after( array( 'core', 'hello.php' ), 60 ) );

		sc_wpun()->update_checker()->run_check( 'cli' );
		$this->assertCount( 4, $this->received()[0]->get_items() );

		$this->end_wait();
		sc_wpun()->update_checker()->run_check( 'cli' );

		$expected = array( self::OTHER, $themes[0] );
		sort( $expected );
		$this->assertSame( $expected, $this->keys( $this->last() ) );
		$this->assertCount( 2, $this->received() );
	}

	/**
	 * A deferral with the delivered items records them and keeps the hold.
	 */
	public function test_a_deferral_records_the_delivered_items_and_keeps_the_hold() {
		$this->add();
		$this->then( $this->defer_after( array( 'hello.php' ) ) );

		$plan = $this->run_check();

		$this->assertSame( Update_Checker::STATUS_WAITING, $plan['a']['status'] );
		$this->assertGreaterThan( time(), $this->entry( 'held_until' ) );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );
		$this->assertSame( '', $this->notified_version( 'core', 'core', 'a' ) );

		$this->run_check();
		$this->assertCount( 1, $this->received(), 'Held: nothing is sent.' );
		$this->assertGreaterThan( time(), $this->entry( 'held_until' ) );

		$this->end_wait();
		$this->run_check();
		$this->assertSame( array( 'core', self::OTHER ), $this->keys( $this->last() ) );
	}

	/**
	 * Items that are not in the message are ignored: another key, another new version, another type.
	 */
	public function test_delivered_items_that_are_not_in_the_message_are_ignored() {
		$this->add();
		$other = static function ( $type, $key, $version ) {
			return new Notification_Item(
				array(
					'type'        => $type,
					'key'         => $key,
					'name'        => 'Other',
					'new_version' => $version,
				)
			);
		};
		$this->then(
			static function () use ( $other ) {
				return Delivery_Result::failure( 'Stopped.' )->with_delivered(
					array(
						$other( 'plugin', 'unknown.php', '2.0' ),
						$other( 'plugin', 'hello.php', '3.0' ),
						$other( 'theme', 'hello.php', '2.0' ),
					)
				);
			}
		);

		$this->run_check();

		$this->assertSame( array(), $this->entry( 'notified' ) );
		$this->assertNull( $this->entry( 'notified_meta' ) );
	}

	/**
	 * On a success the delivered list changes nothing: everything is recorded.
	 */
	public function test_a_success_with_delivered_items_is_a_plain_success() {
		$this->add();
		$this->then(
			function ( Notification $notification ) {
				return Delivery_Result::success( 'Done.' )->with_delivered( $this->copies( $notification, array( 'core' ) ) );
			}
		);

		$plan = $this->run_check();

		$this->assertSame( Update_Checker::STATUS_SEND, $plan['a']['status'] );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'a' ) );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', self::OTHER, 'a' ) );
	}

	/**
	 * A pre_send callback may return a partial failure too.
	 */
	public function test_a_pre_send_result_with_delivered_items_is_recorded() {
		$this->add();
		add_filter(
			'sc_wpun_pre_send',
			function ( $pre, Notification $notification ) {
				return Delivery_Result::failure( 'No.' )->with_delivered( $this->copies( $notification, array( 'core' ) ) );
			},
			10,
			2
		);

		$this->run_check();

		$this->assertCount( 0, $this->received(), 'The channel was not called.' );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'a' ) );
		$this->assertSame( '', $this->notified_version( 'plugin', 'hello.php', 'a' ) );
	}

	/**
	 * A test send ignores the delivered items.
	 */
	public function test_a_test_send_ignores_delivered_items() {
		$this->add( 'a', 'daily' );
		$this->run_check();
		$before = $this->stored_state()['channels']['a'];
		$this->then( $this->fail_after( array( 'core' ) ) );

		$test   = new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array(), array( 'is_test' => true ) );
		$result = sc_wpun()->dispatcher()->send_one( $test, sc_wpun_channels()->get( 'a' ) );

		$this->assertFalse( $result->is_success() );
		$after = $this->stored_state()['channels']['a'];
		$this->assertSame( $before['notified'], $after['notified'] );
		$this->assertSame( $before['reminder_sent'] ?? null, $after['reminder_sent'] ?? null );
	}

	/**
	 * A skip flag survives a partial result of a forced send, which records what was delivered.
	 */
	public function test_a_skip_flag_survives_a_partial_forced_send() {
		foreach ( array( $this->fail_after( array( 'core' ) ), $this->defer_after( array( 'core' ) ) ) as $step ) {
			$this->add();
			sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
			sc_wpun()->state()->skip_pending( 'a' );
			$this->then( $step );

			$this->run_check( array( 'hello.php' ), true );

			$this->assertTrue( $this->entry( 'skip_pending' ) );
			$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'a' ) );
			$this->assertSame( '', $this->notified_version( 'plugin', 'hello.php', 'a' ) );
		}
	}

	/**
	 * A snoozed item that was delivered loses its flag; the others keep theirs.
	 */
	public function test_snooze_flags_clear_only_for_delivered_items() {
		$flag = function ( $key ) {
			return ! empty( $this->entry( 'notified_meta' )['plugin'][ $key ]['snoozed'] );
		};

		$this->add();
		$this->run_check();

		$this->snooze( time() + HOUR_IN_SECONDS );
		$this->run_check();
		$this->assertTrue( $flag( 'hello.php' ) );
		$this->assertTrue( $flag( self::OTHER ) );

		$this->snooze( time() - 1 );
		$this->then( $this->fail_after( array( 'hello.php' ) ) );
		$this->run_check();

		$this->assertFalse( $flag( 'hello.php' ), 'Delivered: cleared as a success would.' );
		$this->assertTrue( $flag( self::OTHER ), 'Not delivered: still flagged.' );
	}

	// Reminders.

	/**
	 * A reminder that stopped halfway resumes with the rest, and a later success starts a clean round.
	 */
	public function test_a_reminder_resumes_without_the_delivered_items() {
		$this->add( 'a', 'every_check' );
		$this->run_check();
		$this->assertCount( 1, $this->received() );
		$this->assertFalse( $this->last()->is_reminder() );

		$this->then( $this->fail_after( array( 'core', 'hello.php' ) ) );
		$this->run_check();
		$this->assertCount( 2, $this->received() );
		$this->assertTrue( $this->last()->is_reminder() );
		$this->assertSame( array( 'core', 'hello.php', self::OTHER ), $this->keys( $this->last() ) );
		$this->assertSame(
			array(
				'core'   => array( 'core' => '99.0.0' ),
				'plugin' => array( 'hello.php' => '2.0' ),
			),
			$this->entry( 'reminder_sent' )
		);

		$this->set_updates( '99.0.0', array_fill_keys( array( 'hello.php', self::OTHER ), array( 'new_version' => '2.0' ) ) );
		$plan = sc_wpun()->update_checker()->run_check( 'cli', true );
		$this->assertSame( array( self::OTHER ), $this->keys( $plan['a']['items'] ), 'A dry run shows what would really be sent.' );
		$this->assertCount( 2, $this->received(), 'A dry run sends nothing.' );

		$plan = $this->run_check();
		$this->assertCount( 3, $this->received() );
		$this->assertTrue( $this->last()->is_reminder() );
		$this->assertSame( array( self::OTHER ), $this->keys( $this->last() ) );
		$this->assertSame( array( self::OTHER ), $this->keys( $plan['a']['items'] ), 'The plan lists the remaining items.' );
		$this->assertNull( $this->entry( 'reminder_sent' ), 'A completed reminder clears the progress.' );

		$this->run_check();
		$this->assertCount( 4, $this->received() );
		$this->assertSame( array( 'core', 'hello.php', self::OTHER ), $this->keys( $this->last() ), 'A new round sends everything.' );
	}

	/**
	 * A deferred reminder also resumes, and keeps the hold.
	 */
	public function test_a_deferred_reminder_resumes() {
		$this->add( 'a', 'every_check' );
		$this->run_check();

		$this->then( $this->defer_after( array( 'core' ) ) );
		$this->run_check();
		$this->assertSame( array( 'core' => array( 'core' => '99.0.0' ) ), $this->entry( 'reminder_sent' ) );
		$this->assertNotNull( $this->entry( 'held_until' ) );

		$this->end_wait();
		$this->run_check();
		$this->assertSame( array( 'hello.php', self::OTHER ), $this->keys( $this->last() ) );
	}

	/**
	 * A reminder whose remaining items resolved counts as complete: nothing is sent and the clock restarts.
	 */
	public function test_a_reminder_with_nothing_left_is_complete() {
		$this->add( 'a', 'every_check' );
		$this->run_check();

		$this->then( $this->fail_after( array( 'core', 'hello.php' ) ) );
		$this->run_check();
		$this->assertCount( 2, $this->received() );
		$this->assertNotNull( $this->entry( 'reminder_sent' ) );

		$this->backdate( 100 );
		$plan = $this->run_check( array( 'hello.php' ) ); // Akismet was updated.

		$this->assertCount( 2, $this->received(), 'Nothing is sent.' );
		$this->assertSame( Update_Checker::STATUS_NOTHING, $plan['a']['status'] );
		$this->assertEqualsWithDelta( time(), $this->entry( 'last_reminder' ), 5 );
		$this->assertNull( $this->entry( 'reminder_sent' ) );
	}

	/**
	 * Progress for an item that changed version is dropped.
	 */
	public function test_progress_is_pruned_when_an_item_changes_version() {
		$this->add( 'a', 'every_check' );
		$this->run_check();
		$this->then( $this->fail_after( array( 'core', 'hello.php' ) ) );
		$this->run_check();

		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '3.0' ) ) );
		$plan = sc_wpun()->update_checker()->run_check( 'cli', true );

		$this->assertSame( array( 'hello.php' ), $this->keys( $plan['a']['items'] ), 'The new version was not delivered; core was.' );
	}

	/**
	 * A new item folded into a reminder and delivered by it counts as notified.
	 */
	public function test_a_new_item_delivered_in_a_reminder_is_notified() {
		$this->add( 'a', 'every_check' );
		$this->run_check( array( 'hello.php' ) );

		$this->then( $this->fail_after( array( self::OTHER ) ) );
		$this->run_check( array( 'hello.php', self::OTHER ) );

		$this->assertTrue( $this->last()->is_reminder() );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', self::OTHER, 'a' ), 'Recorded, so it is not sent as new after the round.' );
		$this->assertSame( array( 'plugin' => array( self::OTHER => '2.0' ) ), $this->entry( 'reminder_sent' ) );
	}

	/**
	 * A partial result does not restart the reminder clock.
	 */
	public function test_the_clock_is_not_restarted_by_a_partial_result() {
		foreach ( array( $this->fail_after( array( 'core' ) ), $this->defer_after( array( 'core' ) ) ) as $step ) {
			$this->add( 'a', 'daily' );
			sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
			$this->run_check();
			$this->backdate( DAY_IN_SECONDS + 60 );
			$clock = $this->entry( 'last_reminder' );

			$this->then( $step );
			$this->run_check();

			$this->assertTrue( $this->last()->is_reminder() );
			$this->assertSame( $clock, $this->entry( 'last_reminder' ) );
			$this->assertNotNull( $this->entry( 'reminder_sent' ) );
		}
	}

	/**
	 * Skipping what is pending and stopping the clock clear the progress.
	 */
	public function test_progress_is_cleared_by_a_skip_and_a_clock_stop() {
		$this->add( 'a', 'every_check' );
		$this->run_check();
		$this->then( $this->fail_after( array( 'core' ) ) );
		$this->run_check();
		$this->assertNotNull( $this->entry( 'reminder_sent' ) );

		sc_wpun()->state()->skip_pending( 'a' );
		$this->assertNull( $this->entry( 'reminder_sent' ) );

		// A clock stop: reminders switched off.
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		$this->run_check();
		$this->then( $this->fail_after( array( 'core' ) ) );
		$this->run_check();
		$this->assertNotNull( $this->entry( 'reminder_sent' ) );
		$this->add( 'a', 'never' );
		$this->run_check();
		$this->assertNull( $this->entry( 'reminder_sent' ) );
		$this->assertNull( $this->entry( 'last_reminder' ) );

		// A clock stop: nothing pending.
		$this->add( 'a', 'every_check' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		$this->run_check();
		$this->then( $this->fail_after( array( 'core' ) ) );
		$this->run_check();
		$this->assertNotNull( $this->entry( 'reminder_sent' ) );
		$this->set_updates( null );
		sc_wpun()->update_checker()->run_check( 'cli' );
		$this->assertNull( $this->entry( 'reminder_sent' ) );
	}

	/**
	 * The stored progress is kept when valid and cleaned when it is not.
	 */
	public function test_normalise() {
		$clean = sc_wpun()->state()->normalise(
			array(
				'channels' => array(
					'a' => array(
						'reminder_sent' => array(
							'plugin' => array(
								'x.php' => '2.0',
								'bad'   => array( 1 ),
							),
							'junk'   => 'text',
						),
					),
					'b' => array( 'reminder_sent' => 'junk' ),
					'c' => array( 'reminder_sent' => array() ),
				),
			)
		);

		$this->assertSame( array( 'plugin' => array( 'x.php' => '2.0' ) ), $clean['channels']['a']['reminder_sent'] );
		$this->assertArrayNotHasKey( 'reminder_sent', $clean['channels']['b'] );
		$this->assertArrayNotHasKey( 'reminder_sent', $clean['channels']['c'] );
	}
}
