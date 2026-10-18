<?php
/**
 * Slack is no longer supported (#143): the stored settings are kept and admins are told.
 *
 * @package wp-updates-notifier
 */

/**
 * The one-time email, the settings page notice and the Site Health test.
 */
class Test_Slack_Notice extends WPUN_Test_Case {

	const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/XXXX';

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
	 * Start without a nonce.
	 */
	public function set_up() {
		parent::set_up();

		unset( $_GET['_wpnonce'] );
	}

	/**
	 * Drop a stand-in Slack channel registered by a test.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( 'slack' );
		unset( $_GET['_wpnonce'] );

		parent::tear_down();
	}

	/**
	 * Store the settings of a site that used Slack with the 1.x release and upgrade them.
	 */
	private function upgrade_from_slack_site() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';
		update_option( 'sc_wpun_settings', $fixtures['1.6.0']['options'] );
		update_option( 'sc_wpun_settings_ver', $fixtures['1.6.0']['version'] );
		delete_option( 'sc_wpun_channels' );
		delete_option( 'sc_wpun_state' );

		sc_wpun()->settings()->maybe_upgrade();
	}

	/**
	 * The settings page output.
	 *
	 * @return string
	 */
	private function settings_page_html() {
		ob_start();
		sc_wpun()->settings_page()->settings_page();

		return ob_get_clean();
	}

	/**
	 * Register a stand-in for a Slack module.
	 */
	private function register_slack_channel() {
		sc_wpun()->channels()->register( new Test_Channel( 'slack' ) );
	}

	/**
	 * The address a captured mail went to.
	 *
	 * @param object $mail Captured mail.
	 * @return string
	 */
	private function recipient( $mail ) {
		$to = (array) $mail->to;
		$to = (array) reset( $to );

		return (string) reset( $to );
	}

	/**
	 * A site upgraded from 1.6.0 with Slack on: no request to Slack, one email about it across runs, nothing lost.
	 */
	public function test_an_upgraded_slack_site_is_told_once_and_nothing_is_posted() {
		$this->upgrade_from_slack_site();
		$channels_before = get_option( 'sc_wpun_channels' );
		$settings_before = get_option( 'sc_wpun_settings' );
		$this->set_updates( '99.0.0' );

		$this->assertTrue( sc_wpun_channels()->has( 'slack' ) );
		do_action( 'sc_wpun_update_check' );
		do_action( 'sc_wpun_update_check' );

		$this->assertCount( 0, $this->requests_to( 'hooks.slack.com' ) );
		$this->assertTrue( $this->stored_state()['slack_notice_sent'] );
		// One notice, plus the update email of the first run (the second run has nothing new).
		$this->assertSame( 2, $this->sent_mail_count() );
		$subjects = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			$subjects[] = $mail['subject'];
		}
		$this->assertCount( 1, preg_grep( '/Slack notifications are no longer supported/', $subjects ) );
		$this->assertSame( $channels_before, get_option( 'sc_wpun_channels' ) );
		$this->assertSame( $settings_before, get_option( 'sc_wpun_settings' ) );
		$this->assertSame( self::SLACK_URL, $this->instance_setting( 'slack', 'webhook_url' ) );
		$this->assertSame( '#ops', $this->instance_setting( 'slack', 'channel' ) );
		$this->assertTrue( $this->stored_instance( 'slack' )->is_enabled() );
	}

	/**
	 * With email on, the one-time email goes to the email recipients and says email continues.
	 */
	public function test_the_email_goes_to_the_email_recipients() {
		$this->save_settings(
			array(
				'notify_to'           => 'ops@example.org',
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);

		sc_wpun()->slack_notice()->maybe_send_email();

		$this->assertSame( 1, $this->sent_mail_count() );
		$mail = $this->sent_mail();
		$this->assertSame( 'ops@example.org', $this->recipient( $mail ) );
		$this->assertSame( 'WP Updates Notifier: Slack notifications are no longer supported', $mail->subject );
		$this->assertStringContainsString( 'Email notifications continue', $mail->body );
	}

	/**
	 * With email off, it goes to the admin email and points to the settings page.
	 */
	public function test_the_email_goes_to_the_admin_email_when_email_is_off() {
		update_option( 'admin_email', 'admin@example.org' );
		$this->save_settings(
			array(
				'email_notifications' => 0,
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);

		sc_wpun()->slack_notice()->maybe_send_email();

		$this->assertSame( 1, $this->sent_mail_count() );
		$mail = $this->sent_mail();
		$this->assertSame( 'admin@example.org', $this->recipient( $mail ) );
		$this->assertStringContainsString( 'no update notifications', $mail->body );
		$this->assertStringContainsString( admin_url( 'options-general.php?page=wp-updates-notifier' ), $mail->body );
	}

	/**
	 * A failed send leaves the flag unset, so the next run tries again.
	 */
	public function test_a_failed_email_is_retried() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);

		add_filter( 'pre_wp_mail', '__return_false' );
		sc_wpun()->slack_notice()->maybe_send_email();
		$this->assertFalse( $this->stored_state()['slack_notice_sent'] ?? false );
		$this->assertSame( 0, $this->sent_mail_count() );

		remove_filter( 'pre_wp_mail', '__return_false' );
		sc_wpun()->slack_notice()->maybe_send_email();
		$this->assertTrue( $this->stored_state()['slack_notice_sent'] );
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * A real mailer failure (a recipient PHPMailer rejects) is a failure too.
	 */
	public function test_a_rejected_recipient_is_retried() {
		$this->save_settings(
			array(
				'notify_to'           => 'not an address',
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);

		sc_wpun()->slack_notice()->maybe_send_email();

		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertFalse( $this->stored_state()['slack_notice_sent'] ?? false );
	}

	/**
	 * The flag does not disturb the rest of the run state, and old state without it reads as unset.
	 */
	public function test_the_flag_keeps_the_rest_of_the_state() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);
		$this->save_state( 1234 );
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '5.0.0' ) ) );
		$this->assertFalse( sc_wpun()->state()->get()['slack_notice_sent'] );

		sc_wpun()->slack_notice()->maybe_send_email();

		$state = sc_wpun()->state()->get();
		$this->assertTrue( $state['slack_notice_sent'] );
		$this->assertSame( '5.0.0', $state['channels']['email']['notified']['core']['core'] );
		$this->assertSame( 1234, $state['last_check_time'] );
	}

	/**
	 * Provides the sites the notice does not apply to.
	 *
	 * @return array
	 */
	public function quiet_sites() {
		return array(
			'slack instance switched off' => array( 'off' ),
			'no slack instance'           => array( 'none' ),
			'a slack channel registered'  => array( 'registered' ),
		);
	}

	/**
	 * Nothing is sent, shown or added to Site Health.
	 *
	 * @dataProvider quiet_sites
	 *
	 * @param string $site Which kind of site.
	 */
	public function test_nothing_happens_when_the_notice_does_not_apply( $site ) {
		if ( 'off' === $site ) {
			$this->save_settings( array( 'slack_webhook_url' => self::SLACK_URL ) );
		} else {
			$this->save_settings(
				array(
					'slack_notifications' => 1,
					'slack_webhook_url'   => self::SLACK_URL,
				)
			);
		}
		if ( 'none' === $site ) {
			sc_wpun_channels()->delete( 'slack' );
		}
		if ( 'registered' === $site ) {
			$this->register_slack_channel();
		}
		wp_set_current_user( self::$admin_id );

		sc_wpun()->slack_notice()->maybe_send_email();

		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertFalse( $this->stored_state()['slack_notice_sent'] ?? false );
		$this->assertStringNotContainsString( 'sc-wpun-slack-notice', $this->settings_page_html() );
		$this->assertSame( array(), sc_wpun()->slack_notice()->add_site_health_test( array() ) );
	}

	/**
	 * The notice shows on the plugin's settings page only.
	 */
	public function test_the_notice_is_on_the_settings_page_only() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);
		wp_set_current_user( self::$admin_id );
		$html = $this->settings_page_html();

		$this->assertStringContainsString( 'sc-wpun-slack-notice', $html );
		$this->assertStringContainsString( 'admin-post.php?action=sc_wpun_dismiss_slack_notice', $html );
		$this->assertSame( 1, has_action( 'admin_post_sc_wpun_dismiss_slack_notice', array( sc_wpun()->slack_notice(), 'handle_dismiss' ) ) ? 1 : 0 );
		$this->assertFalse( has_action( 'admin_notices', array( sc_wpun()->slack_notice(), 'render' ) ) );

		ob_start();
		do_action( 'admin_notices' );
		do_action( 'all_admin_notices' );
		$this->assertStringNotContainsString( 'sc-wpun-slack-notice', ob_get_clean() );
	}

	/**
	 * The dismiss link needs a valid nonce and an administrator.
	 */
	public function test_dismissing_needs_a_nonce_and_manage_options() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);
		$notice = sc_wpun()->slack_notice();

		wp_set_current_user( self::$admin_id );
		$this->assertFalse( $notice->dismiss_request(), 'No nonce.' );
		$_GET['_wpnonce'] = 'bad';
		$this->assertFalse( $notice->dismiss_request(), 'Bad nonce.' );
		$this->assertSame( '', get_user_meta( self::$admin_id, 'sc_wpun_dismissed_slack_notice', true ) );

		wp_set_current_user( self::$editor_id );
		$_GET['_wpnonce'] = wp_create_nonce( 'sc_wpun_dismiss_slack_notice' );
		$this->assertFalse( $notice->dismiss_request(), 'Not an administrator.' );
		$this->assertSame( '', get_user_meta( self::$editor_id, 'sc_wpun_dismissed_slack_notice', true ) );

		$this->expectException( WPDieException::class );
		$notice->handle_dismiss();
	}

	/**
	 * After dismissing, the notice is gone for that user and still shown to the others.
	 */
	public function test_dismissing_hides_the_notice_for_that_user_only() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);
		wp_set_current_user( self::$admin_id );
		$_GET['_wpnonce'] = wp_create_nonce( 'sc_wpun_dismiss_slack_notice' );

		$this->assertTrue( sc_wpun()->slack_notice()->dismiss_request() );
		$this->assertStringNotContainsString( 'sc-wpun-slack-notice', $this->settings_page_html() );

		wp_set_current_user( self::$other_admin_id );
		$this->assertStringContainsString( 'sc-wpun-slack-notice', $this->settings_page_html() );
	}

	/**
	 * The Site Health test exists only while the notice applies, and is a recommendation.
	 */
	public function test_site_health_gets_a_recommendation() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertArrayHasKey( 'sc_wpun_slack', $tests['direct'] );
		$result = call_user_func( $tests['direct']['sc_wpun_slack']['test'] );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertStringContainsString( 'options-general.php?page=wp-updates-notifier', $result['actions'] );

		$this->register_slack_channel();
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertArrayNotHasKey( 'sc_wpun_slack', $tests['direct'] );
	}

	/**
	 * The old public Slack methods still exist but do nothing and say they are deprecated.
	 */
	public function test_the_slack_methods_of_the_facade_are_deprecated_no_ops() {
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::send_slack_message' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::send_test_slack' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_settings_slack_notifications_text' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_settings_slack_notifications_field_slack_notifications' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_settings_slack_notifications_field_slack_webhook_url' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::sc_wpun_settings_slack_notifications_field_slack_channel_override' );
		$plugin = $this->plugin();

		ob_start();
		$this->assertFalse( $plugin->send_slack_message( 'Hello' ) );
		$plugin->send_test_slack();
		$plugin->sc_wpun_settings_slack_notifications_text();
		$plugin->sc_wpun_settings_slack_notifications_field_slack_notifications();
		$plugin->sc_wpun_settings_slack_notifications_field_slack_webhook_url();
		$plugin->sc_wpun_settings_slack_notifications_field_slack_channel_override();
		$this->assertSame( '', ob_get_clean() );
		$this->assertCount( 0, $this->requests_to( 'hooks.slack.com' ) );
	}

	/**
	 * A channel of type slack (a stand-in for a Slack module) receives the update through the dispatcher,
	 * the stored instance is not changed, and the notice no longer applies.
	 */
	public function test_a_registered_slack_channel_receives_the_update() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);
		$channel = new Test_Channel( 'slack' );
		sc_wpun()->channels()->register( $channel );
		$before = get_option( 'sc_wpun_channels' );
		$this->set_updates( '99.0.0' );

		do_action( 'sc_wpun_update_check' );

		$this->assertCount( 1, $channel->received['slack'] );
		$this->assertCount( 0, $this->requests_to( 'hooks.slack.com' ) );
		$this->assertSame( $before, get_option( 'sc_wpun_channels' ) );
		$this->assertFalse( sc_wpun()->slack_notice()->applies() );
		$this->assertFalse( $this->stored_state()['slack_notice_sent'] ?? false );
		$this->assertSame( 1, $this->sent_mail_count(), 'Only the update email.' );
	}
}
