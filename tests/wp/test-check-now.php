<?php
/**
 * The Check now button on the General tab (#188).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Check_Now;
use WPUN\Channel_Instance;
use WPUN\Scheduler;

/**
 * The form and its handler.
 */
class Test_Check_Now extends WPUN_Test_Case {

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

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
		self::$admin_id  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * A valid request of an administrator, with an update waiting.
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
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		reset_phpmailer_instance();
		wp_set_current_user( self::$admin_id );
		$_POST['_wpnonce'] = wp_create_nonce( Check_Now::ACTION );
	}

	/**
	 * Forget the request.
	 */
	public function tear_down() {
		unset( $_POST['_wpnonce'], $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * The notice of the current user, read and deleted.
	 *
	 * @return array|null
	 */
	private function notice() {
		return sc_wpun()->notices()->take();
	}

	/**
	 * Register the fixture channel as type `test`, unless an earlier test already has.
	 *
	 * @return Test_Channel
	 */
	private function register_test_channel() {
		$channel = sc_wpun()->channels()->get( 'test' );
		if ( null === $channel ) {
			$channel = new Test_Channel( 'test' );
			sc_wpun()->channels()->register( $channel );
		}
		$channel->received = array();

		return $channel;
	}

	/**
	 * Count how often the check starts.
	 *
	 * @return ArrayObject Holds the count under `count`.
	 */
	private function count_checks() {
		$counter = new ArrayObject( array( 'count' => 0 ) );
		add_action(
			'sc_wpun_before_update_check',
			static function () use ( $counter ) {
				++$counter['count'];
			}
		);

		return $counter;
	}

	/**
	 * The General tab shows the form once, with a nonce that does not clash with the settings form.
	 */
	public function test_the_general_tab_has_the_form() {
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$html = ob_get_clean();

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		$xpath = new DOMXPath( $dom );

		$forms = $xpath->query( '//form[@class="sc-wpun-check-now"]' );
		$this->assertSame( 1, $forms->length );
		$this->assertStringContainsString( 'admin-post.php', $forms->item( 0 )->getAttribute( 'action' ) );
		$this->assertSame( 'post', $forms->item( 0 )->getAttribute( 'method' ) );
		$this->assertSame( 1, $xpath->query( './/button[@type="submit"]', $forms->item( 0 ) )->length );
		$this->assertSame( 1, $xpath->query( './/button[@type="submit"][@aria-describedby="sc-wpun-check-now-description"]', $forms->item( 0 ) )->length );
		$this->assertSame( 1, $xpath->query( './/p[@id="sc-wpun-check-now-description"]', $forms->item( 0 ) )->length );
		$this->assertSame( 1, $xpath->query( './/input[@type="hidden"][@name="action"][@value="sc_wpun_check_now"]', $forms->item( 0 ) )->length );
		$nonce = $xpath->query( './/input[@name="_wpnonce"]', $forms->item( 0 ) );
		$this->assertSame( 1, $nonce->length );
		$this->assertFalse( $nonce->item( 0 )->hasAttribute( 'id' ) );
		$this->assertSame( 1, $xpath->query( '//*[@id="_wpnonce"]' )->length );
		$this->assertSame( 1, $xpath->query( './/p[@class="description"]/a[contains(@href,"tab=channels")]', $forms->item( 0 ) )->length );
		$this->assertStringContainsString( 'Check for updates and notify now', $html );
	}

	/**
	 * The form is not on the Channels tab.
	 */
	public function test_the_channels_tab_has_no_form() {
		$_GET['tab'] = 'channels';
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'sc_wpun_check_now', $html );
	}

	/**
	 * A pending update is sent and recorded, and a second click has nothing new.
	 */
	public function test_it_sends_pending_updates_once() {
		$next = wp_next_scheduled( Scheduler::CRON_NAME );

		$this->assertTrue( sc_wpun()->check_now()->process() );
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php' ) );
		$this->assertNotEmpty( $this->stored_state()['last_check_time'] );
		$notice = $this->notice();
		$this->assertTrue( $notice['success'] );
		$this->assertSame( 'Checked just now. Sent to 1 channel.', $notice['message'] );

		reset_phpmailer_instance();
		$this->assertTrue( sc_wpun()->check_now()->process() );
		$this->assertSame( 0, $this->sent_mail_count() );
		$notice = $this->notice();
		$this->assertTrue( $notice['success'] );
		$this->assertSame( 'Checked just now. Nothing new to send.', $notice['message'] );
		$this->assertSame( $next, wp_next_scheduled( Scheduler::CRON_NAME ) );
	}

	/**
	 * One failing channel does not stop the other, and the notice names it and says why.
	 */
	public function test_a_failing_channel_is_named() {
		$channel = $this->register_test_channel();
		sc_wpun_channels()->save( new Channel_Instance( 'bad', 'test', 'Bad one', true, array( 'behaviour' => 'fail' ) ) );

		$this->assertTrue( sc_wpun()->check_now()->process() );
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertArrayHasKey( 'bad', $channel->received );
		$notice = $this->notice();
		$this->assertFalse( $notice['success'] );
		$this->assertSame( 'Checked just now. Sent to 1 channel; 1 failed: Bad one (It failed.)', $notice['message'] );
	}

	/**
	 * When every channel fails, the notice does not claim anything was sent.
	 */
	public function test_every_channel_failing() {
		$this->register_test_channel();
		sc_wpun_channels()->delete( 'email' );
		sc_wpun_channels()->save( new Channel_Instance( 'bad', 'test', 'Bad one', true, array( 'behaviour' => 'fail' ) ) );

		$this->assertTrue( sc_wpun()->check_now()->process() );
		$notice = $this->notice();
		$this->assertFalse( $notice['success'] );
		$this->assertSame( 'Checked just now. 1 channel failed: Bad one (It failed.)', $notice['message'] );
	}

	/**
	 * With no enabled channel, nothing is sent and the notice says why.
	 */
	public function test_no_enabled_channel() {
		$this->save_settings( array( 'email_notifications' => 0 ) );

		$this->assertTrue( sc_wpun()->check_now()->process() );
		$this->assertSame( 0, $this->sent_mail_count() );
		$notice = $this->notice();
		$this->assertTrue( $notice['success'] );
		$this->assertSame( 'Checked just now. Nothing was sent because no channel is enabled.', $notice['message'] );
	}

	/**
	 * A missing or bad nonce, or a user who may not manage the plugin, runs nothing.
	 */
	public function test_a_refused_request_runs_nothing() {
		$checks = $this->count_checks();

		unset( $_POST['_wpnonce'] );
		$this->assertFalse( sc_wpun()->check_now()->process() );

		$_POST['_wpnonce'] = 'bad';
		$this->assertFalse( sc_wpun()->check_now()->process() );

		$_POST['_wpnonce'] = wp_create_nonce( 'sc_wpun_send_test_email' );
		$this->assertFalse( sc_wpun()->check_now()->process() );

		wp_set_current_user( self::$editor_id );
		$_POST['_wpnonce'] = wp_create_nonce( Check_Now::ACTION );
		$this->assertFalse( sc_wpun()->check_now()->process() );

		$this->assertSame( 0, $checks['count'] );
		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertNull( $this->notice() );
	}

	/**
	 * The handler answers 403 to a refused request.
	 */
	public function test_the_handler_answers_403() {
		unset( $_POST['_wpnonce'] );
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message, $title, $args ) {
					throw new WPDieException( 'status ' . absint( $args['response'] ) );
				};
			}
		);

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'status 403' );
		sc_wpun()->check_now()->handle();
	}

	/**
	 * The admin-post hook is registered with the other admin hooks.
	 */
	public function test_the_hook_is_registered() {
		sc_wpun()->register_admin_hooks();

		$this->assertNotFalse( has_action( 'admin_post_sc_wpun_check_now' ) );
	}
}
