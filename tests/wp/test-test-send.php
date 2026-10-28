<?php
/**
 * Sending a test message from the Channels tab (#67, #165).
 *
 * @package wp-updates-notifier
 */

/**
 * The per-instance send test form and its handler.
 */
class Test_Test_Send extends WPUN_Test_Case {

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Another administrator.
	 *
	 * @var int
	 */
	private static $other_admin_id;

	/**
	 * A user who may not manage options.
	 *
	 * @var int
	 */
	private static $editor_id;

	/**
	 * Create the users.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id       = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$other_admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id      = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Start with a valid request of an administrator.
	 */
	public function set_up() {
		parent::set_up();

		$this->save_settings();
		wp_set_current_user( self::$admin_id );
		$_POST['_wpnonce'] = wp_create_nonce( 'sc_wpun_send_test_email' );
		$_POST['id']       = 'email';
	}

	/**
	 * Forget the request.
	 */
	public function tear_down() {
		unset( $_POST['_wpnonce'], $_POST['id'], $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * The settings page output.
	 *
	 * @return string
	 */
	private function page() {
		ob_start();
		sc_wpun()->settings_page()->settings_page();

		return ob_get_clean();
	}

	/**
	 * The Channels tab has a test form for the instance, and the General tab has none.
	 */
	public function test_the_channels_tab_has_the_test_form() {
		$html = $this->page();
		$this->assertStringNotContainsString( 'value="sc_wpun_send_test"', $html );
		$this->assertStringNotContainsString( 'Send test email', $html );
		$this->assertStringNotContainsString( 'notify_reply_to', $html );

		$_GET['tab'] = 'channels';
		$html        = $this->page();
		$this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringContainsString( 'value="sc_wpun_send_test"', $html );
		$this->assertStringContainsString( 'aria-label="Send test to Email"', $html );
	}

	/**
	 * The test goes to the saved recipients as a test message and no global transient is used.
	 */
	public function test_a_test_is_sent_and_reported_once() {
		$this->assertTrue( sc_wpun()->channels_tab()->process_test_send() );

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertStringContainsString( 'test message', $this->sent_mail()->subject );
		$this->assertFalse( get_transient( 'sc_wpun_send_test_email' ) );

		$html = $this->page();
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'Test message sent through Email.', $html );
		$this->assertStringNotContainsString( 'sc-wpun-notice', $this->page(), 'Shown once.' );
	}

	/**
	 * A failed send shows the reason.
	 */
	public function test_a_failure_is_reported() {
		add_filter( 'pre_wp_mail', '__return_false' );

		$this->assertTrue( sc_wpun()->channels_tab()->process_test_send() );

		$html = $this->page();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'WordPress could not send the email.', $html );
	}

	/**
	 * A switched-off instance is a failure, and an unknown one a notice.
	 */
	public function test_a_disabled_or_unknown_instance_fails() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		sc_wpun()->channels_tab()->process_test_send();
		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertStringContainsString( 'notice-error', $this->page() );

		$_POST['id']       = 'nothing';
		$_POST['_wpnonce'] = wp_create_nonce( 'sc_wpun_send_test_nothing' );
		sc_wpun()->channels_tab()->process_test_send();
		$this->assertStringContainsString( 'notice-error', $this->page() );
	}

	/**
	 * The result is kept per user.
	 */
	public function test_the_result_is_for_the_current_user_only() {
		sc_wpun()->channels_tab()->process_test_send();

		wp_set_current_user( self::$other_admin_id );
		$this->assertStringNotContainsString( 'sc-wpun-notice', $this->page() );

		wp_set_current_user( self::$admin_id );
		$this->assertStringContainsString( 'sc-wpun-notice', $this->page() );
	}

	/**
	 * The result is kept in user meta, not a transient, and a stale one is not shown.
	 */
	public function test_a_stale_result_is_not_shown() {
		sc_wpun()->channels_tab()->process_test_send();

		$stored = get_user_meta( self::$admin_id, 'sc_wpun_notice', true );
		$this->assertIsArray( $stored );
		$stored['time'] = time() - HOUR_IN_SECONDS;
		update_user_meta( self::$admin_id, 'sc_wpun_notice', $stored );

		$this->assertStringNotContainsString( 'sc-wpun-notice', $this->page() );
		$this->assertSame( '', get_user_meta( self::$admin_id, 'sc_wpun_notice', true ), 'Removed once read.' );
	}

	/**
	 * On a network, the result is shown on the site the test was sent from and not on another.
	 */
	public function test_the_result_stays_on_its_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}

		sc_wpun()->channels_tab()->process_test_send();

		$blog_id = self::factory()->blog->create();
		add_user_to_blog( $blog_id, self::$admin_id, 'administrator' ); // The page checks the capability on that site too.
		switch_to_blog( $blog_id );
		$this->assertStringNotContainsString( 'sc-wpun-notice', $this->page() );
		restore_current_blog();

		$this->assertStringContainsString( 'sc-wpun-notice', $this->page() );
	}

	/**
	 * A bad nonce or a user without manage_options sends nothing.
	 */
	public function test_nonce_and_capability_are_enforced() {
		$page = sc_wpun()->channels_tab();

		$_POST['_wpnonce'] = 'bad';
		$this->assertFalse( $page->process_test_send() );

		unset( $_POST['_wpnonce'] );
		$this->assertFalse( $page->process_test_send() );

		wp_set_current_user( self::$editor_id );
		$_POST['_wpnonce'] = wp_create_nonce( 'sc_wpun_send_test_email' );
		$this->assertFalse( $page->process_test_send() );

		$this->assertSame( 0, $this->sent_mail_count() );

		$this->expectException( WPDieException::class );
		$page->handle_test_send();
	}

	/**
	 * The dispatcher sends a test message with no items, and refuses a channel that cannot send one.
	 */
	public function test_the_dispatcher_sends_a_test_message() {
		$result = sc_wpun()->dispatcher()->send_test( sc_wpun_channels()->get( 'email' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $this->sent_mail_count() );

		$channel = new Test_Channel( 'notest' );
		sc_wpun()->channels()->register( $channel );
		$instance = new WPUN\Channel_Instance( 'notest', 'notest', 'No test', true );
		$refused  = sc_wpun()->dispatcher()->send_test( $instance );
		sc_wpun()->channels()->unregister( 'notest' );

		$this->assertFalse( $refused->is_success() );
		$this->assertSame( 'unsupported', $refused->get_code() );
		$this->assertSame( array(), $channel->received );

		$missing = sc_wpun()->dispatcher()->send_test( $instance );
		$this->assertSame( 'unsupported', $missing->get_code() );
	}
}
