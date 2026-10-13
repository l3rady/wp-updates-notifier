<?php
/**
 * The scheduled update check as it behaves today: what it detects, who it tells, and when it stays quiet.
 *
 * These pin current behaviour so the restructure (#51) and the module system (#91) can be checked
 * against it. Where today's behaviour is a bug, the test lives in test-known-bugs.php instead.
 *
 * @package wp-updates-notifier
 */

/**
 * Update check and notification.
 */
class Test_Update_Check extends WPUN_Test_Case {

	const SLACK_URL = 'https://hooks.slack.com/services/T000/B000/XXXX';

	/**
	 * Fake what core asks api.wordpress.org about a plugin.
	 */
	public function set_up() {
		parent::set_up();

		$this->fake_http(
			'api.wordpress.org/plugins/info',
			$this->fake_response( 200, wp_json_encode( array( 'name' => 'Hello Dolly', 'slug' => 'hello-dolly', 'version' => '9.9.9', 'tested' => '99.0' ) ) )
		);
	}

	/**
	 * Stylesheets of two installed themes. Core drops updates for themes that are not installed.
	 *
	 * @return array [ first, second ]
	 */
	private function installed_themes() {
		$slugs = array_keys( wp_get_themes() );
		$this->assertGreaterThanOrEqual( 2, count( $slugs ), 'The checkout should ship default themes.' );

		return array( $slugs[0], $slugs[1] );
	}

	/**
	 * Nothing to report means no mail, but the check is still recorded.
	 */
	public function test_nothing_is_sent_when_everything_is_up_to_date() {
		$this->save_settings();
		$this->set_updates();

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertGreaterThan( 0, $this->stored_settings()['last_check_time'] );
	}

	/**
	 * A core update is reported with both versions.
	 */
	public function test_a_core_update_is_emailed() {
		$this->save_settings();
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$mail = $this->sent_mail();
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( 'ops@example.org', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
		$this->assertStringContainsString( '99.0.0', $mail->body );
		$this->assertStringContainsString( wp_get_wp_version(), $mail->body );
		$this->assertStringContainsString( admin_url( 'update-core.php' ), $mail->body );
	}

	/**
	 * The same core version is reported once, however often the check runs.
	 */
	public function test_a_core_update_is_reported_only_once() {
		$this->save_settings();
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();
		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( '99.0.0', $this->stored_settings()['notified']['core'] );
	}

	/**
	 * A newer core version after the one already reported is a new notification.
	 */
	public function test_a_newer_core_version_is_reported_again() {
		$this->save_settings();
		$this->set_updates( '99.0.0' );
		$this->plugin()->do_update_check();

		$this->set_updates( '99.1.0' );
		$this->plugin()->do_update_check();

		$this->assertSame( 2, $this->sent_mail_count() );
	}

	/**
	 * After updating, the record is cleared so the next release is reported.
	 */
	public function test_the_core_record_is_cleared_once_core_is_current() {
		$this->save_settings( array( 'notified' => array( 'core' => '99.0.0', 'plugin' => array(), 'theme' => array() ) ) );
		$this->set_updates();

		$this->plugin()->do_update_check();

		$this->assertSame( '', $this->stored_settings()['notified']['core'] );
	}

	/**
	 * A plugin update is reported with its name and both versions.
	 */
	public function test_a_plugin_update_is_emailed() {
		$this->save_settings( array( 'notify_plugins' => 1 ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$body = $this->sent_mail()->body;
		$this->assertStringContainsString( 'Hello Dolly', $body );
		$this->assertStringContainsString( '2.0.0', $body );
		$this->assertSame( '2.0.0', $this->stored_settings()['notified']['plugin']['hello.php'] );
	}

	/**
	 * A plugin version already reported is not reported again.
	 */
	public function test_a_plugin_update_is_reported_only_once() {
		$this->save_settings();
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();
		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * Switching plugin notifications off silences plugin updates but not core.
	 */
	public function test_plugin_updates_are_skipped_when_switched_off() {
		$this->save_settings( array( 'notify_plugins' => 0 ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * "Only active plugins" ignores updates for plugins that are not active.
	 */
	public function test_only_active_plugins_when_asked() {
		$this->save_settings( array( 'notify_plugins' => 2 ) );
		update_option( 'active_plugins', array() );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();
		$this->assertSame( 0, $this->sent_mail_count() );

		update_option( 'active_plugins', array( 'hello.php' ) );
		$this->plugin()->do_update_check();
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * Other plugins can add to or remove from the list through the documented filter.
	 */
	public function test_the_plugins_filter_can_remove_an_update() {
		$this->save_settings();
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		add_filter( 'sc_wpun_plugins_need_update', '__return_empty_array', 20 );

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * A theme update is reported with its name and versions, and once only.
	 */
	public function test_a_theme_update_is_emailed_once() {
		list( $theme ) = $this->installed_themes();
		$this->save_settings();
		$this->set_updates( null, array(), array( $theme => array( 'new_version' => '99.3' ) ) );

		$this->plugin()->do_update_check();
		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertStringContainsString( '99.3', $this->sent_mail()->body );
		$this->assertSame( '99.3', $this->stored_settings()['notified']['theme'][ $theme ] );
	}

	/**
	 * "Only active themes" keeps the active theme only.
	 */
	public function test_only_the_active_theme_when_asked() {
		list( $active, $other ) = $this->installed_themes();
		$this->save_settings( array( 'notify_themes' => 2 ) );
		update_option( 'template', $active );
		$this->set_updates( null, array(), array( $other => array( 'new_version' => '99.3' ) ) );

		$this->plugin()->do_update_check();
		$this->assertSame( 0, $this->sent_mail_count() );

		$this->set_updates( null, array(), array( $active => array( 'new_version' => '99.3' ) ) );
		$this->plugin()->do_update_check();
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * With email off, nothing is mailed.
	 */
	public function test_email_can_be_switched_off() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * Slack gets a JSON payload with the message, posted to the configured webhook.
	 */
	public function test_slack_receives_the_message() {
		$this->save_settings(
			array(
				'email_notifications'    => 0,
				'slack_notifications'    => 1,
				'slack_webhook_url'      => self::SLACK_URL,
				'slack_channel_override' => '#ops',
			)
		);
		$this->fake_http( 'hooks.slack.com', $this->fake_response( 200, 'ok' ) );
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$requests = $this->requests_to( 'hooks.slack.com' );
		$this->assertCount( 1, $requests );
		$this->assertSame( self::SLACK_URL, $requests[0]['url'] );
		$payload = json_decode( $requests[0]['args']['body']['payload'], true );
		$this->assertSame( '#ops', $payload['channel'] );
		$this->assertStringContainsString( '99.0.0', $payload['text'] );
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * Both channels can be on at once.
	 */
	public function test_email_and_slack_both_send() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);
		$this->fake_http( 'hooks.slack.com', $this->fake_response( 200, 'ok' ) );
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertCount( 1, $this->requests_to( 'hooks.slack.com' ) );
	}

	/**
	 * The email carries every kind of update in one message.
	 */
	public function test_one_message_lists_core_plugins_and_themes() {
		list( $theme ) = $this->installed_themes();
		$this->save_settings();
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ), array( $theme => array( 'new_version' => '99.3' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$body = $this->sent_mail()->body;
		$this->assertStringContainsString( '99.0.0', $body );
		$this->assertStringContainsString( 'Hello Dolly', $body );
		$this->assertStringContainsString( '99.3', $body );
	}

	/**
	 * Background core updates go to the configured recipient when asked to.
	 */
	public function test_the_automatic_core_update_email_is_redirected() {
		$this->save_settings( array( 'notify_automatic' => 1, 'notify_to' => 'ops@example.org' ) );

		$email = apply_filters(
			'auto_core_update_email',
			array(
				'to'      => 'admin@example.org',
				'subject' => 'Subject',
				'body'    => 'Body',
				'headers' => array(),
			),
			'success',
			new stdClass(),
			null
		);

		$this->assertSame( 'ops@example.org', $email['to'] );
	}

	/**
	 * Leaving that setting off leaves WordPress's own email alone.
	 */
	public function test_the_automatic_core_update_email_is_left_alone_when_off() {
		$this->save_settings( array( 'notify_automatic' => 0 ) );
		$original = array(
			'to'      => 'admin@example.org',
			'subject' => 'Subject',
			'body'    => 'Body',
			'headers' => array(),
		);

		$this->assertSame( $original, apply_filters( 'auto_core_update_email', $original, 'success', new stdClass(), null ) );
	}
}
