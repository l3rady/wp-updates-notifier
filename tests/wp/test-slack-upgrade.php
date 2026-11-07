<?php
/**
 * A site upgraded from 1.x: its Slack setup is the `slack` instance, and it delivers again.
 *
 * @package wp-updates-notifier
 */

/**
 * The settings migration, then a whole update check.
 */
class Test_Slack_Upgrade extends WPUN_Slack_Test_Case {

	/**
	 * Seed the settings of a 1.x site (settings version 7.0) and run the upgrade the way the plugin does on a request.
	 *
	 * @param array $slack The 1.x Slack options to add to the 1.x settings.
	 */
	private function migrate_from_1x( array $slack ) {
		$context = sc_wpun()->network_context();
		$context->update_option(
			'sc_wpun_settings',
			array_merge(
				array(
					'frequency'           => 'hourly',
					'notify_to'           => 'old@example.org',
					'notify_from'         => 'old@example.org',
					'notify_plugins'      => 1,
					'notify_themes'       => 1,
					'notify_automatic'    => 0,
					'hide_updates'        => 1,
					'email_notifications' => 0,
				),
				$slack
			)
		);
		$context->update_option( 'sc_wpun_settings_ver', '7.0' );
		$context->delete_option( 'sc_wpun_channels' );
		$context->delete_option( 'sc_wpun_state' );

		sc_wpun()->settings()->maybe_upgrade();
	}

	/**
	 * Collect the results reported on a public action.
	 *
	 * @param string $action `sc_wpun_notification_sent` or `sc_wpun_notification_failed`.
	 * @return ArrayObject The results, filled while the check runs.
	 */
	private function results_of( $action ) {
		$results = new ArrayObject();
		add_action(
			$action,
			static function ( $result ) use ( $results ) {
				$results[] = $result;
			}
		);

		return $results;
	}

	/**
	 * The instance the migration stores finds the Slack channel and delivers without being set up again.
	 */
	public function test_a_switched_on_instance_delivers_at_the_first_check() {
		$this->migrate_from_1x(
			array(
				'slack_notifications'    => 1,
				'slack_webhook_url'      => self::URL,
				'slack_channel_override' => '#ops',
			)
		);

		// What the migration stored: the id and type slack, and the keys the channel reads.
		$instance = sc_wpun_channels()->get( 'slack' );
		$this->assertNotNull( $instance );
		$this->assertSame( 'slack', $instance->get_type() );
		$this->assertTrue( $instance->is_enabled() );
		$this->assertSame( self::URL, $instance->get_setting( 'webhook_url' ) );
		$this->assertSame( '#ops', $instance->get_setting( 'channel' ) );
		$this->assertTrue( sc_wpun()->channels()->has( 'slack' ) );

		$sent = $this->results_of( 'sc_wpun_notification_sent' );
		$this->slack_answers( 200 );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '9.9.9' ) ) );

		$this->plugin()->do_update_check();

		$requests = $this->requests_to( 'hooks.slack.com' );
		$this->assertCount( 1, $requests, 'Slack was posted to.' );
		$this->assertSame( self::URL, $requests[0]['url'] );
		$payload = json_decode( $requests[0]['args']['body'], true );
		$this->assertSame( '#ops', $payload['channel'] );
		$this->assertArrayNotHasKey( 'username', $payload );
		$this->assertArrayNotHasKey( 'icon_emoji', $payload );
		$this->assertStringContainsString( 'Hello Dolly', wp_json_encode( $payload['blocks'] ) );
		$this->assertStringContainsString( '9.9.9', wp_json_encode( $payload['blocks'] ) );

		$this->assertCount( 1, $sent );
		$this->assertTrue( $sent[0]->is_success() );

		// Delivered: not sent again by the next check.
		$this->plugin()->do_update_check();
		$this->assertCount( 1, $this->requests_to( 'hooks.slack.com' ) );
	}

	/**
	 * A switched-off 1.x Slack stays off: nothing is posted, and the upgrade does not switch it on.
	 */
	public function test_a_switched_off_instance_stays_off() {
		$this->migrate_from_1x(
			array(
				'slack_notifications' => 0,
				'slack_webhook_url'   => self::URL,
			)
		);

		$instance = sc_wpun_channels()->get( 'slack' );
		$this->assertNotNull( $instance );
		$this->assertFalse( $instance->is_enabled() );

		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '9.9.9' ) ) );
		$this->plugin()->do_update_check();

		$this->assertSame( array(), $this->http_requests );
		$this->assertFalse( sc_wpun_channels()->get( 'slack' )->is_enabled() );
	}

	/**
	 * A 1.x webhook on another host is not posted to, whatever the settings say. The failure does not show the address.
	 */
	public function test_a_migrated_address_on_another_host_is_not_requested() {
		$this->migrate_from_1x(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => 'https://chat.example.org/services/T000/B000/XXXX',
			)
		);

		$failed = $this->results_of( 'sc_wpun_notification_failed' );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '9.9.9' ) ) );
		$this->plugin()->do_update_check();

		$this->assertSame( array(), $this->http_requests );
		$this->assertCount( 1, $failed );
		$this->assertSame( 'invalid_url', $failed[0]->get_code() );
		$this->assert_result_hides( $failed[0], 'https://chat.example.org/services/T000/B000/XXXX' );
	}

	/**
	 * A webhook deleted in Slack since 1.x fails with the no_service message, which the Channels tab and Site Health show.
	 */
	public function test_a_webhook_deleted_meanwhile_fails_with_no_service() {
		$this->migrate_from_1x(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::URL,
			)
		);
		$this->slack_answers( 404, 'no_service' );
		$failed = $this->results_of( 'sc_wpun_notification_failed' );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '9.9.9' ) ) );

		$this->plugin()->do_update_check();

		$this->assertCount( 1, $failed );
		$this->assertSame( 'no_service', $failed[0]->get_code() );
		$this->assertStringContainsString( 'revoked', $failed[0]->get_message() );
		$this->assert_result_hides( $failed[0] );
	}

	/**
	 * Settings version 14 removes what the retired notice left: its mark in the run state and the dismissals of the users.
	 */
	public function test_the_upgrade_removes_what_the_notice_left() {
		$context = sc_wpun()->network_context();
		$this->save_settings();
		$context->update_option( 'sc_wpun_settings_ver', '13.0' );
		$context->update_option(
			'sc_wpun_state',
			array(
				'channels'          => array(),
				'last_check_time'   => 1700000000,
				'slack_notice_sent' => true,
			)
		);
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'sc_wpun_dismissed_slack_notice', 1 );
		update_user_meta( 1, 'sc_wpun_dismissed_slack_notice', 1 );
		update_user_meta( $user_id, 'sc_wpun_notice', 'kept' );

		sc_wpun()->settings()->maybe_upgrade();

		$this->assertSame( '14.0', $context->get_option( 'sc_wpun_settings_ver' ) );
		$this->assertArrayNotHasKey( 'slack_notice_sent', $this->stored_state() );
		$this->assertSame( 1700000000, $this->stored_state()['last_check_time'], 'The rest of the state is kept.' );
		$this->assertSame( '', get_user_meta( $user_id, 'sc_wpun_dismissed_slack_notice', true ) );
		$this->assertSame( '', get_user_meta( 1, 'sc_wpun_dismissed_slack_notice', true ) );
		$this->assertSame( 'kept', get_user_meta( $user_id, 'sc_wpun_notice', true ) );
	}

	/**
	 * A state that never had the mark is left as it is.
	 */
	public function test_a_state_without_the_mark_is_kept() {
		$context = sc_wpun()->network_context();
		$this->save_settings();
		$context->update_option( 'sc_wpun_settings_ver', '13.0' );
		$context->update_option(
			'sc_wpun_state',
			array(
				'channels'        => array(),
				'last_check_time' => 5,
			)
		);

		sc_wpun()->settings()->maybe_upgrade();

		$this->assertSame( 5, $this->stored_state()['last_check_time'] );
		$this->assertArrayNotHasKey( 'slack_notice_sent', $this->stored_state() );
	}

	/**
	 * Whatever the state of the Slack channel, the notice that Slack is unsupported is gone: no email from the cron, no
	 * markup on the settings page, no Site Health test, no action to dismiss it.
	 */
	public function test_the_notice_about_slack_is_gone() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::URL,
			)
		);
		// The case the notice was for: a switched-on instance and no channel for its type.
		sc_wpun()->channels()->unregister( 'slack' );
		$this->set_updates();
		reset_phpmailer_instance();

		do_action( WPUN\Scheduler::CRON_NAME );

		$this->assertSame( 0, $this->sent_mail_count(), 'No email about Slack.' );
		$this->assertSame( array(), $this->http_requests );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'sc-wpun-slack-notice', $html );
		$this->assertStringNotContainsString( 'sc_wpun_dismiss_slack_notice', $html );

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertArrayNotHasKey( 'sc_wpun_slack', $tests['direct'] );
		$this->assertFalse( has_action( 'admin_post_sc_wpun_dismiss_slack_notice' ) );
		$this->assertFalse( class_exists( 'WPUN\Slack_Notice' ) );
	}
}
