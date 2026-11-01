<?php
/**
 * Optional reminders for updates that are still waiting (#81).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;

/**
 * The `remind` routing key, the per-instance reminder clock and the single reminder message.
 */
class Test_Reminders extends WPUN_Test_Case {

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

		$this->channel = new Test_Channel( 'remtest' );
		sc_wpun()->channels()->unregister( 'remtest' );
		sc_wpun()->channels()->register( $this->channel );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_channels' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'daily' ) ) );
	}

	/**
	 * Save an instance of the fake channel.
	 *
	 * @param string $id        Id.
	 * @param string $remind    The `remind` routing value; null leaves the key out.
	 * @param string $behaviour What it does: succeed or fail.
	 * @param bool   $enabled   Whether it is on.
	 */
	private function add( $id = 'a', $remind = 'daily', $behaviour = 'succeed', $enabled = true ) {
		$routing = null === $remind ? array() : array( 'remind' => $remind );
		sc_wpun_channels()->save( new Channel_Instance( $id, 'remtest', ucfirst( $id ), $enabled, array( 'behaviour' => $behaviour ), $routing ) );
	}

	/**
	 * Run a check with core 99.0.0 and a plugin at the given version waiting.
	 *
	 * @param string|null $plugin_version Plugin version waiting, or null for none.
	 * @param string|null $core_version   Core version waiting, or null for none.
	 */
	private function run_check( $plugin_version = '2.0.0', $core_version = '99.0.0' ) {
		$plugins = null === $plugin_version ? array() : array( 'hello.php' => array( 'new_version' => $plugin_version ) );
		$this->set_updates( $core_version, $plugins );
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * Set when the reminder clock of an instance started, as seconds ago.
	 *
	 * @param string $id          Id.
	 * @param int    $seconds_ago How long ago.
	 */
	private function backdate( $id, $seconds_ago ) {
		$state                                     = sc_wpun()->network_context()->get_option( 'sc_wpun_state' );
		$state['channels'][ $id ]['last_reminder'] = time() - $seconds_ago;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
	}

	/**
	 * The stored clock of an instance.
	 *
	 * @param string $id Id.
	 * @return int|null
	 */
	private function clock( $id = 'a' ) {
		return $this->stored_state()['channels'][ $id ]['last_reminder'] ?? null;
	}

	/**
	 * The messages an instance received.
	 *
	 * @param string $id Id.
	 * @return \WPUN\Notification[]
	 */
	private function received( $id = 'a' ) {
		return $this->channel->received[ $id ] ?? array();
	}

	/**
	 * The item keys of a message.
	 *
	 * @param \WPUN\Notification $notification Message.
	 * @return string[]
	 */
	private function keys( $notification ) {
		$keys = array();
		foreach ( $notification->get_items() as $item ) {
			$keys[] = $item->get_key();
		}
		sort( $keys );
		return $keys;
	}

	/**
	 * Reminders are off by default: nothing repeats and no clock is kept.
	 */
	public function test_never_is_the_default() {
		$this->add( 'a', null );

		$this->run_check();
		$this->assertCount( 1, $this->received() );

		$state                                   = sc_wpun()->network_context()->get_option( 'sc_wpun_state' );
		$state['channels']['a']['last_reminder'] = time() - 10 * WEEK_IN_SECONDS;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		$this->run_check();

		$this->assertCount( 1, $this->received(), 'Nothing is repeated.' );
		$this->assertNull( $this->clock(), 'A stale clock is cleared.' );
	}

	/**
	 * The first check with something waiting starts the clock and sends only the normal message.
	 */
	public function test_the_clock_starts_on_the_first_check() {
		$this->add( 'a', 'daily' );

		$this->run_check();

		$this->assertCount( 1, $this->received() );
		$this->assertFalse( $this->received()[0]->is_reminder() );
		$this->assertEqualsWithDelta( time(), $this->clock(), 5 );

		// Items were sent: a second check at once is quiet, and the clock stays.
		$clock = $this->clock();
		$this->run_check();
		$this->assertCount( 1, $this->received() );
		$this->assertSame( $clock, $this->clock() );
	}

	/**
	 * Before the interval nothing; after it one reminder with everything waiting.
	 */
	public function test_a_reminder_lists_everything_waiting_once_due() {
		$this->add( 'a', 'daily' );
		$this->run_check();

		$this->backdate( 'a', 6 * HOUR_IN_SECONDS );
		$this->run_check();
		$this->assertCount( 1, $this->received(), 'Not due yet.' );

		$this->backdate( 'a', DAY_IN_SECONDS + 60 );
		$this->run_check();

		$this->assertCount( 2, $this->received() );
		$reminder = $this->received()[1];
		$this->assertTrue( $reminder->is_reminder() );
		$this->assertSame( array( 'core', 'hello.php' ), $this->keys( $reminder ) );
		$this->assertEqualsWithDelta( time(), $this->clock(), 5, 'The clock advanced.' );
	}

	/**
	 * A weekly reminder waits a week; a daily one a day.
	 */
	public function test_weekly_and_daily_intervals() {
		$this->add( 'weekly', 'weekly' );
		$this->add( 'daily', 'daily' );
		$this->run_check();

		$this->backdate( 'weekly', 2 * DAY_IN_SECONDS );
		$this->backdate( 'daily', 2 * DAY_IN_SECONDS );
		$this->run_check();

		$this->assertCount( 1, $this->received( 'weekly' ) );
		$this->assertCount( 2, $this->received( 'daily' ) );

		$this->backdate( 'weekly', WEEK_IN_SECONDS + 60 );
		$this->run_check();
		$this->assertCount( 2, $this->received( 'weekly' ) );
		$this->assertTrue( $this->received( 'weekly' )[1]->is_reminder() );
	}

	/**
	 * A late cron run is forgiven by half the check interval, and not by more.
	 */
	public function test_the_tolerance_is_half_the_check_interval() {
		$this->add( 'a', 'daily' ); // The check frequency is daily: 12 hours of tolerance.
		$this->run_check();

		$this->backdate( 'a', DAY_IN_SECONDS - 12 * HOUR_IN_SECONDS - 60 );
		$this->run_check();
		$this->assertCount( 1, $this->received(), 'Just short of interval minus tolerance.' );

		$this->backdate( 'a', DAY_IN_SECONDS - 12 * HOUR_IN_SECONDS + 60 );
		$this->run_check();
		$this->assertCount( 2, $this->received() );
		$this->assertTrue( $this->received()[1]->is_reminder() );
	}

	/**
	 * An hourly check only forgives half an hour.
	 */
	public function test_the_tolerance_follows_the_check_frequency() {
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'hourly' ) ) );
		$this->add( 'a', 'daily' );
		$this->run_check();

		$this->backdate( 'a', DAY_IN_SECONDS - HOUR_IN_SECONDS );
		$this->run_check();
		$this->assertCount( 1, $this->received() );

		$this->backdate( 'a', DAY_IN_SECONDS - 20 * MINUTE_IN_SECONDS );
		$this->run_check();
		$this->assertCount( 2, $this->received() );
	}

	/**
	 * Checks less often than reminders send one on every check, but never one right after another.
	 */
	public function test_the_tolerance_is_at_most_half_the_reminder_interval() {
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'weekly' ) ) );
		$this->add( 'a', 'daily' );
		$this->run_check();

		$this->backdate( 'a', HOUR_IN_SECONDS ); // A "Check now" soon after: 3.5 days of tolerance would make it due.
		$this->run_check();
		$this->assertCount( 1, $this->received() );

		$this->backdate( 'a', WEEK_IN_SECONDS ); // The next weekly check.
		$this->run_check();
		$this->assertCount( 2, $this->received() );
	}

	/**
	 * A new version between reminders is sent at once, alone, and the clock stays.
	 */
	public function test_a_new_version_is_notified_at_once() {
		$this->add( 'a', 'daily' );
		$this->run_check( '2.0.0' );
		$this->backdate( 'a', 3 * HOUR_IN_SECONDS );
		$clock = $this->clock();

		$this->run_check( '3.0.0' );

		$this->assertCount( 2, $this->received() );
		$message = $this->received()[1];
		$this->assertFalse( $message->is_reminder() );
		$this->assertSame( array( 'hello.php' ), $this->keys( $message ) );
		$this->assertSame( $clock, $this->clock() );
	}

	/**
	 * A new item in the run of a due reminder is folded into the one reminder.
	 */
	public function test_a_new_item_in_a_due_run_is_folded_into_the_reminder() {
		$this->add( 'a', 'daily' );
		$this->run_check( null );
		$this->backdate( 'a', DAY_IN_SECONDS + 60 );

		$this->run_check( '2.0.0' );

		$this->assertCount( 2, $this->received(), 'The first check and the reminder, no second message.' );
		$reminder = $this->received()[1];
		$this->assertTrue( $reminder->is_reminder() );
		$this->assertSame( array( 'core', 'hello.php' ), $this->keys( $reminder ) );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );

		$this->run_check( '2.0.0' );
		$this->assertCount( 2, $this->received(), 'The new item is not sent again.' );
	}

	/**
	 * With nothing waiting the clock is cleared and starts again with the next item.
	 */
	public function test_nothing_pending_clears_the_clock() {
		$this->add( 'a', 'daily' );
		$this->run_check();
		$this->assertNotNull( $this->clock() );

		$this->run_check( null, null );
		$this->assertNull( $this->clock() );

		$this->run_check( null, '100.0.0' );
		$this->assertCount( 2, $this->received() );
		$this->assertNotNull( $this->clock() );
	}

	/**
	 * An instance told to wait gets nothing, and its clock is left alone.
	 */
	public function test_a_deferred_instance_gets_no_reminder() {
		$this->add( 'a', 'daily' );
		$this->run_check();
		$this->backdate( 'a', 2 * DAY_IN_SECONDS );
		$state                              = sc_wpun()->network_context()->get_option( 'sc_wpun_state' );
		$state['channels']['a']['retry_at'] = time() + HOUR_IN_SECONDS;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );
		$clock = $this->clock();

		$this->run_check();

		$this->assertCount( 1, $this->received() );
		$this->assertSame( $clock, $this->clock() );
	}

	/**
	 * A switched-off instance gets no reminder.
	 */
	public function test_a_disabled_instance_gets_no_reminder() {
		$this->add( 'a', 'daily', 'succeed', false );

		$this->run_check();

		$this->assertCount( 0, $this->received() );
		$this->assertNull( $this->clock() );
	}

	/**
	 * The run that skips what is pending sends nothing and starts the clock.
	 */
	public function test_a_skip_pending_run_starts_the_clock() {
		$this->add( 'a', 'daily' );
		sc_wpun()->state()->skip_pending( 'a' );

		$this->run_check();

		$this->assertCount( 0, $this->received() );
		$this->assertEqualsWithDelta( time(), $this->clock(), 5 );

		$this->backdate( 'a', DAY_IN_SECONDS + 60 );
		$this->run_check();
		$this->assertCount( 1, $this->received() );
		$this->assertTrue( $this->received()[0]->is_reminder() );
	}

	/**
	 * Re-enabling without sending what is waiting restarts an old interval, so the skipped updates are not reminded about at once.
	 */
	public function test_a_skip_restarts_an_old_clock() {
		$this->add( 'a', 'weekly' );
		$this->run_check();
		$this->assertCount( 1, $this->received() );

		$this->backdate( 'a', 2 * WEEK_IN_SECONDS ); // Switched off for a while, with the clock left behind.
		sc_wpun()->state()->skip_pending( 'a' );
		$this->run_check();
		$this->assertCount( 1, $this->received() );
		$this->assertEqualsWithDelta( time(), $this->clock(), 5 );

		$this->run_check();
		$this->assertCount( 1, $this->received() );
	}

	/**
	 * A due run that is also a skip run sends no reminder.
	 */
	public function test_a_skip_pending_run_never_sends_a_reminder() {
		$this->add( 'a', 'daily' );
		sc_wpun()->state()->skip_pending( 'a' );
		$state                                   = sc_wpun()->network_context()->get_option( 'sc_wpun_state' );
		$state['channels']['a']['last_reminder'] = time() - 3 * DAY_IN_SECONDS;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );

		$this->run_check();

		$this->assertCount( 0, $this->received() );
	}

	/**
	 * A failed reminder does not move the clock and is tried again on the next run.
	 */
	public function test_a_failed_reminder_is_retried() {
		$this->add( 'a', 'daily' );
		$this->run_check();
		$this->backdate( 'a', DAY_IN_SECONDS + 60 );
		$clock = $this->clock();
		$this->add( 'a', 'daily', 'fail' );

		$this->run_check();

		$this->assertCount( 2, $this->received() );
		$this->assertTrue( $this->received()[1]->is_reminder() );
		$this->assertSame( $clock, $this->clock() );
		$this->assertFalse( $this->stored_state()['channels']['a']['last_result']['success'] );

		$this->add( 'a', 'daily', 'succeed' );
		$this->run_check();

		$this->assertCount( 3, $this->received() );
		$this->assertTrue( $this->received()[2]->is_reminder() );
		$this->assertTrue( $this->stored_state()['channels']['a']['last_result']['success'] );
		$this->assertGreaterThan( $clock, $this->clock() );
	}

	/**
	 * Each instance has its own interval.
	 */
	public function test_the_setting_is_per_instance() {
		$this->add( 'weekly', 'weekly' );
		$this->add( 'never', 'never' );
		$this->run_check();

		$this->backdate( 'weekly', WEEK_IN_SECONDS + 60 );
		$this->run_check();

		$this->assertCount( 2, $this->received( 'weekly' ) );
		$this->assertCount( 1, $this->received( 'never' ) );
		$this->assertNull( $this->clock( 'never' ) );
	}

	/**
	 * A test send does not touch the clock.
	 */
	public function test_a_test_send_does_not_touch_the_clock() {
		$this->add( 'a', 'daily' );
		$this->run_check();
		$this->backdate( 'a', 3 * HOUR_IN_SECONDS );
		$clock = $this->clock();

		sc_wpun()->dispatcher()->send_one( new \WPUN\Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array(), array( 'is_test' => true ) ), sc_wpun_channels()->get( 'a' ) );

		$this->assertSame( $clock, $this->clock() );
	}

	/**
	 * Every check: the first check is a normal message, the very next one a reminder with everything waiting.
	 */
	public function test_every_check_reminds_on_the_next_check() {
		$this->add( 'a', 'every_check' );

		$this->run_check();
		$this->assertCount( 1, $this->received() );
		$this->assertFalse( $this->received()[0]->is_reminder() );
		$this->assertEqualsWithDelta( time(), $this->clock(), 5 );

		$this->run_check();
		$this->assertCount( 2, $this->received(), 'No interval: due at once.' );
		$this->assertTrue( $this->received()[1]->is_reminder() );
		$this->assertSame( array( 'core', 'hello.php' ), $this->keys( $this->received()[1] ) );

		$this->run_check();
		$this->assertCount( 3, $this->received() );
		$this->assertTrue( $this->received()[2]->is_reminder() );
	}

	/**
	 * Every check: a new item on a later check is folded into that check's reminder.
	 */
	public function test_every_check_folds_new_items_into_the_reminder() {
		$this->add( 'a', 'every_check' );
		$this->run_check( null );

		$this->run_check( '2.0.0' );

		$this->assertCount( 2, $this->received(), 'The first check and one reminder.' );
		$this->assertTrue( $this->received()[1]->is_reminder() );
		$this->assertSame( array( 'core', 'hello.php' ), $this->keys( $this->received()[1] ) );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php', 'a' ) );
	}

	/**
	 * Every check: a forced run is a normal message and leaves the clock.
	 */
	public function test_every_check_force_is_a_normal_message() {
		$this->add( 'a', 'every_check' );
		$this->run_check();
		$clock = $this->clock();

		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		sc_wpun()->update_checker()->run_check( 'cli', false, true );

		$this->assertCount( 2, $this->received() );
		$this->assertFalse( $this->received()[1]->is_reminder() );
		$this->assertSame( $clock, $this->clock() );
	}

	/**
	 * Every check: a skip run sends nothing, starts the clock, and the next check reminds.
	 */
	public function test_every_check_skip_run_starts_the_clock() {
		$this->add( 'a', 'every_check' );
		sc_wpun()->state()->skip_pending( 'a' );

		$this->run_check();
		$this->assertCount( 0, $this->received() );
		$this->assertEqualsWithDelta( time(), $this->clock(), 5 );

		$this->run_check();
		$this->assertCount( 1, $this->received() );
		$this->assertTrue( $this->received()[0]->is_reminder() );
	}

	/**
	 * Every check: a failed reminder does not move the clock and is tried again.
	 */
	public function test_every_check_failed_reminder_is_retried() {
		$this->add( 'a', 'every_check' );
		$this->run_check();
		$this->backdate( 'a', HOUR_IN_SECONDS );
		$clock = $this->clock();
		$this->add( 'a', 'every_check', 'fail' );

		$this->run_check();
		$this->assertCount( 2, $this->received() );
		$this->assertTrue( $this->received()[1]->is_reminder() );
		$this->assertSame( $clock, $this->clock() );

		$this->add( 'a', 'every_check', 'succeed' );
		$this->run_check();
		$this->assertCount( 3, $this->received() );
		$this->assertTrue( $this->received()[2]->is_reminder() );
		$this->assertGreaterThan( $clock, $this->clock() );
	}

	/**
	 * Every check: with nothing waiting the clock is cleared and the next item is a normal message.
	 */
	public function test_every_check_nothing_pending_clears_the_clock() {
		$this->add( 'a', 'every_check' );
		$this->run_check();
		$this->assertNotNull( $this->clock() );

		$this->run_check( null, null );
		$this->assertNull( $this->clock() );
		$this->assertCount( 1, $this->received() );

		$this->run_check( null, '100.0.0' );
		$this->assertCount( 2, $this->received() );
		$this->assertFalse( $this->received()[1]->is_reminder() );
	}

	/**
	 * The reminder key is a normalised routing choice.
	 */
	public function test_routing_knows_remind() {
		$fields = \WPUN\Routing::fields();
		$this->assertSame( array( 'never', 'daily', 'weekly', 'every_check' ), array_keys( $fields['remind']['options'] ) );
		$this->assertSame( 'never', $fields['remind']['default'] );
		$this->assertSame( 'radio', $fields['remind']['display'] );

		$this->assertSame( 'never', \WPUN\Routing::normalise( array() )['remind'] );
		$this->assertSame( 'never', \WPUN\Routing::normalise( array( 'remind' => 'hourly' ) )['remind'] );
		$this->assertSame( 'weekly', \WPUN\Routing::normalise( array( 'remind' => 'weekly' ) )['remind'] );
		$this->assertSame( 'every_check', \WPUN\Routing::normalise( array( 'remind' => 'every_check' ) )['remind'] );
	}

	/**
	 * The clock survives a read of the state, and junk does not.
	 */
	public function test_the_state_keeps_the_clock() {
		$channels = sc_wpun()->state()->normalise(
			array(
				'channels' => array(
					'ok'   => array( 'last_reminder' => 1900000000 ),
					'str'  => array( 'last_reminder' => '1900000001' ),
					'text' => array( 'last_reminder' => 'soon' ),
					'zero' => array( 'last_reminder' => 0 ),
					'neg'  => array( 'last_reminder' => -5 ),
				),
			)
		)['channels'];

		$this->assertSame( 1900000000, $channels['ok']['last_reminder'] );
		$this->assertSame( 1900000001, $channels['str']['last_reminder'] );
		foreach ( array( 'text', 'zero', 'neg' ) as $id ) {
			$this->assertArrayNotHasKey( 'last_reminder', $channels[ $id ], $id );
		}
	}

	/**
	 * The reminder wording in the three formats.
	 */
	public function test_the_renderer_marks_a_reminder() {
		$item = new \WPUN\Notification_Item(
			array(
				'type'            => 'plugin',
				'key'             => 'hello.php',
				'name'            => 'Hello',
				'current_version' => '1.0',
				'new_version'     => '2.0',
			)
		);
		$make = static fn( $reminder ) => new \WPUN\Notification( 'My *Site*', 'https://example.org', 'https://example.org/wp-admin/update-core.php', array( $item ), array( 'is_reminder' => $reminder ) );

		$this->assertStringStartsWith( 'Reminder: these updates are still waiting for My *Site* (https://example.org)', \WPUN\Notification_Renderer::plain_text( $make( true ) ) );
		$this->assertStringStartsWith( '**Reminder: these updates are still waiting for My \*Site\*', \WPUN\Notification_Renderer::markdown( $make( true ) ) );
		$this->assertStringStartsWith( '<p>Reminder: these updates are still waiting for My *Site* (https://example.org)</p>', \WPUN\Notification_Renderer::html( $make( true ) ) );

		$this->assertStringStartsWith( 'There are updates available for My *Site*', \WPUN\Notification_Renderer::plain_text( $make( false ) ) );
		$this->assertStringNotContainsString( 'Reminder', \WPUN\Notification_Renderer::html( $make( false ) ) );
	}

	/**
	 * The email subject says reminder, with the prefix.
	 */
	public function test_the_email_subject_for_a_reminder() {
		update_option( 'blogname', 'Shop' );
		$item = new \WPUN\Notification_Item(
			array(
				'type'            => 'plugin',
				'key'             => 'hello.php',
				'name'            => 'Hello',
				'current_version' => '1.0',
				'new_version'     => '2.0',
			)
		);
		$note = new \WPUN\Notification( 'Shop', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $item ), array( 'is_reminder' => true ) );

		( new \WPUN\Modules\Email\Email_Channel() )->send( $note, new Channel_Instance( 'email', 'email', 'Email', true, array( 'to' => 'ops@example.org', 'subject_prefix' => '[Client]' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( '[Client] WP Updates Notifier: reminder of updates waiting for Shop', $this->sent_mail()->subject );
		$this->assertStringStartsWith( 'Reminder: these updates are still waiting for Shop', $this->sent_mail()->body );
	}

	/**
	 * The Channels tab mentions the reminders.
	 */
	public function test_the_routing_summary_mentions_reminders() {
		$this->assertSame( 'All updates', \WPUN\Admin\Channels_Tab::routing_summary( array( 'remind' => 'never' ) ) );
		$this->assertSame( 'All updates · Reminders: Weekly', \WPUN\Admin\Channels_Tab::routing_summary( array( 'remind' => 'weekly' ) ) );
		$this->assertSame( 'All updates · Reminders: Every check', \WPUN\Admin\Channels_Tab::routing_summary( array( 'remind' => 'every_check' ) ) );
		$this->assertSame( 'WordPress updates: Only major releases · Reminders: Daily', \WPUN\Admin\Channels_Tab::routing_summary( array( 'core' => 'major', 'remind' => 'daily' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Wait for the first half of a second, so a test that sets a time and checks it right away stays within one second.
	 */
	private function start_of_a_second() {
		$fraction = microtime( true ) - floor( microtime( true ) );
		if ( $fraction > 0.4 ) {
			usleep( (int) ( ( 1 - $fraction ) * 1000000 ) + 20000 );
		}
	}

	/**
	 * A reminder is due when the time since the last one is exactly the interval less the tolerance.
	 */
	public function test_a_reminder_is_due_exactly_at_the_interval_less_the_tolerance() {
		$this->add( 'a', 'daily' ); // The check frequency is daily: 12 hours of tolerance.
		$this->run_check();

		$this->start_of_a_second();
		$this->backdate( 'a', DAY_IN_SECONDS - 12 * HOUR_IN_SECONDS );
		$this->run_check();

		$this->assertCount( 2, $this->received() );
		$this->assertTrue( $this->received()[1]->is_reminder() );
	}

	/**
	 * A check frequency the site does not offer anymore forgives nothing.
	 */
	public function test_an_unknown_check_frequency_has_no_tolerance() {
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'schedule_from_a_removed_plugin' ) ) );
		$this->add( 'a', 'daily' );
		$this->run_check();

		$this->backdate( 'a', DAY_IN_SECONDS - 60 );
		$this->run_check();

		$this->assertCount( 1, $this->received() );
	}

	/**
	 * The reminder says what started the check, like the message it repeats.
	 */
	public function test_a_reminder_keeps_the_source_of_the_check() {
		$this->add( 'a', 'daily' );
		$this->run_check();
		$this->backdate( 'a', DAY_IN_SECONDS + 60 );

		sc_wpun()->update_checker()->run_manual_check();

		$this->assertTrue( $this->received()[1]->is_reminder() );
		$this->assertSame( 'manual', $this->received()[1]->get_source() );
	}

	/**
	 * A reminder lists the items the instance is routed as a list, not with the gaps of the ones left out.
	 */
	public function test_a_reminder_of_routed_items_is_a_list() {
		sc_wpun_channels()->save( new Channel_Instance( 'a', 'remtest', 'A', true, array(), array( 'types' => array( 'plugin' ), 'remind' => 'daily' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->run_check();
		$this->backdate( 'a', DAY_IN_SECONDS + 60 );

		$this->run_check();

		$this->assertTrue( $this->received()[1]->is_reminder() );
		$this->assertSame( array( 0 ), array_keys( $this->received()[1]->get_items() ) );
		$this->assertSame( array( 'hello.php' ), $this->keys( $this->received()[1] ) );
	}

	/**
	 * An instance waiting for its channel to come back does not hold up the instances after it.
	 */
	public function test_a_pending_skip_without_its_channel_does_not_stop_the_next_instance() {
		sc_wpun_channels()->save( new Channel_Instance( 'gone', 'not_registered', 'Gone', true ) );
		sc_wpun()->state()->skip_pending( 'gone' );
		$this->add( 'b', 'never' );

		$this->run_check();

		$this->assertCount( 1, $this->received( 'b' ) );
		$this->assertTrue( $this->stored_state()['channels']['gone']['skip_pending'], 'The skip waits for the channel.' );
	}

	/**
	 * The items a veto leaves are a list.
	 */
	public function test_items_left_after_a_veto_are_a_list() {
		add_filter(
			'sc_wpun_should_notify_item',
			static function ( $notify, $item ) {
				return 'core' === $item->get_key() ? false : $notify;
			},
			10,
			2
		);
		$kept = null;
		add_filter(
			'sc_wpun_notification',
			static function ( $notification ) use ( &$kept ) {
				$kept = array_keys( $notification->get_items() );
				return $notification;
			}
		);

		$this->run_check();

		$this->assertSame( array( 0 ), $kept );
	}

	/**
	 * A veto that answers with something other than true or false still counts as a yes or a no.
	 */
	public function test_a_veto_filter_may_return_any_value() {
		add_filter(
			'sc_wpun_should_notify_item',
			static function () {
				return array( 'yes' );
			}
		);
		$this->add( 'a', 'never' );

		$this->run_check();

		$this->assertCount( 1, $this->received() );
	}
}
