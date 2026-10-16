<?php
/**
 * The settings sanitizer (#74).
 *
 * @package wp-updates-notifier
 */

/**
 * `sc_wpun_settings_validate()`, which runs for every update of the settings once admin_init has run.
 */
class Test_Settings_Validation extends WPUN_Test_Case {

	/**
	 * Admin context: an administrator, with the setting registered as on admin_init.
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->save_settings();
		$this->plugin()->admin_settings_init();
		$GLOBALS['wp_settings_errors'] = array();
	}

	/**
	 * Clear the form data some tests set.
	 */
	public function tear_down() {
		unset( $_POST['option_page'], $_POST['_wpnonce'], $_POST['submitwithemail'] );
		$GLOBALS['wp_settings_errors'] = array();
		parent::tear_down();
	}

	/**
	 * Codes of the settings errors added, keyed by setting id.
	 *
	 * @return array
	 */
	private function errors() {
		$errors = array();
		foreach ( get_settings_errors() as $error ) {
			$errors[ $error['setting'] ] = $error['code'];
		}

		return $errors;
	}

	/**
	 * Updating the option outside the settings form (another plugin, a migration, our own code) must not die.
	 */
	public function test_updating_the_option_outside_the_settings_form_does_not_die() {
		update_option( 'sc_wpun_settings', $this->global_settings( array( 'notify_plugins' => 2 ) ) );

		$this->assertSame( 2, $this->stored_settings()['notify_plugins'] );
	}

	/**
	 * An update of the global settings alone, with no email or Slack fields, leaves the instances as they are.
	 */
	public function test_a_programmatic_update_of_the_global_settings_leaves_the_instances_alone() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => 'https://hooks.slack.com/services/T/B/X',
			)
		);
		$before = sc_wpun_channels()->all();

		update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'daily' ) ) );

		$this->assertSame( 'daily', $this->stored_settings()['frequency'] );
		$this->assertEquals( $before, sc_wpun_channels()->all() );
		$this->assertSame( array(), $this->errors() );
	}

	/**
	 * Saving the form stores the email fields in the email instance, not in the settings.
	 */
	public function test_saving_the_form_updates_the_instances() {
		$this->plugin()->sc_wpun_settings_validate(
			$this->settings(
				array(
					'email_notifications' => 1,
					'notify_to'           => 'a@example.org, b@example.org',
					'notify_from'         => 'from@example.org',
				)
			)
		);

		$email = $this->stored_instance( 'email' );
		$this->assertTrue( $email->is_enabled() );
		$this->assertSame( 'a@example.org,b@example.org', $email->get_setting( 'to' ) );
		$this->assertSame( 'from@example.org', $email->get_setting( 'from' ) );

		$this->assertSame( array(), $this->errors() );
		$this->assertSame( array_keys( $this->global_settings() ), array_keys( $this->stored_settings() ) );
	}

	/**
	 * A stored Slack instance is kept as it is by every save, whether or not the form still posts Slack fields.
	 */
	public function test_validation_leaves_the_slack_instance_untouched() {
		$slack = new WPUN\Channel_Instance(
			'slack',
			'slack',
			'Team chat',
			true,
			array(
				'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
				'channel'     => '#ops',
				'extra'       => 'kept',
			),
			array( 'types' => array( 'core' ) )
		);
		sc_wpun_channels()->save( $slack );

		$this->plugin()->sc_wpun_settings_validate( $this->settings() );
		$this->assertEquals( $slack, $this->stored_instance( 'slack' ), 'A form with no Slack fields.' );

		$this->plugin()->sc_wpun_settings_validate(
			$this->settings(
				array(
					'slack_notifications'    => 0,
					'slack_webhook_url'      => 'not a url',
					'slack_channel_override' => 'bad channel',
				)
			)
		);
		$this->assertEquals( $slack, $this->stored_instance( 'slack' ), 'A form that still posts Slack fields.' );
		$this->assertArrayNotHasKey( 'sc_wpun_settings_slack_notifications_slack_webhook_url', $this->errors() );
		$this->assertArrayNotHasKey( 'sc_wpun_settings_slack_notifications_slack_channel_override', $this->errors() );
	}

	/**
	 * Saving the form creates no Slack instance.
	 */
	public function test_validation_creates_no_slack_instance() {
		delete_option( 'sc_wpun_channels' );

		$this->plugin()->sc_wpun_settings_validate(
			$this->settings(
				array(
					'slack_notifications' => 1,
					'slack_webhook_url'   => 'https://hooks.slack.com/services/T/B/X',
				)
			)
		);

		$this->assertFalse( sc_wpun_channels()->has( 'slack' ) );
		$this->assertTrue( sc_wpun_channels()->has( 'email' ) );
	}

	/**
	 * The settings page shows the instance values, not what an old settings option might still hold.
	 */
	public function test_the_settings_page_reads_the_instances() {
		$this->save_settings(
			array(
				'notify_to'              => 'instance@example.org',
				'slack_notifications'    => 1,
				'slack_webhook_url'      => 'https://hooks.slack.com/services/T/B/X',
				'slack_channel_override' => '#instance',
			)
		);
		// Written behind the sanitizer's back, like an option left by an older release.
		remove_all_filters( 'sanitize_option_sc_wpun_settings' );
		update_option( 'sc_wpun_settings', array_merge( $this->global_settings(), array( 'notify_to' => 'old-option@example.org' ) ) );

		ob_start();
		$this->plugin()->settings_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'instance@example.org', $html );
		$this->assertStringNotContainsString( 'old-option@example.org', $html );
		$this->assertStringNotContainsString( 'https://hooks.slack.com/services/T/B/X', $html );
		$this->assertStringNotContainsString( 'slack_notifications', $html );
		$this->assertStringNotContainsString( 'slack_webhook_url', $html );
		$this->assertStringNotContainsString( 'slack_channel_override', $html );
		$this->assertStringNotContainsString( 'submitwithslack', $html );
	}

	/**
	 * Missing fields raise no notices.
	 */
	public function test_partial_input_does_not_raise_notices() {
		$result = $this->plugin()->sc_wpun_settings_validate( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'hourly', $result['frequency'], 'A missing frequency keeps the saved one.' );
	}

	/**
	 * Input that is not an array leaves the stored settings as they are.
	 */
	public function test_non_array_input_keeps_the_stored_settings() {
		$this->assertSame( $this->stored_settings(), $this->plugin()->sc_wpun_settings_validate( 'nonsense' ) );
	}

	/**
	 * The run state is not part of the settings: the sanitizer drops it if a caller passes it.
	 */
	public function test_the_run_state_is_not_stored_in_the_settings() {
		update_option(
			'sc_wpun_settings',
			array_merge(
				$this->settings(),
				array(
					'notified'        => array( 'core' => '99.0.0' ),
					'last_check_time' => 1,
				)
			)
		);

		$this->assertArrayNotHasKey( 'notified', $this->stored_settings() );
		$this->assertArrayNotHasKey( 'last_check_time', $this->stored_settings() );
	}

	/**
	 * A changed frequency reschedules the check, however the settings are saved.
	 */
	public function test_a_changed_frequency_reschedules_the_check() {
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		wp_schedule_event( time(), 'hourly', 'sc_wpun_update_check' );

		update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'daily' ) ) );

		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * Saving without changing the frequency leaves the next run where it was.
	 */
	public function test_an_unchanged_frequency_leaves_the_schedule_alone() {
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		$next = time() + 5000;
		wp_schedule_event( $next, 'hourly', 'sc_wpun_update_check' );

		update_option( 'sc_wpun_settings', $this->global_settings( array( 'notify_plugins' => 2 ) ) );

		$this->assertSame( $next, wp_next_scheduled( 'sc_wpun_update_check' ) );
	}

	/**
	 * An unknown frequency is refused with an error on the frequency field.
	 */
	public function test_an_unknown_frequency_is_refused() {
		$result = $this->plugin()->sc_wpun_settings_validate( $this->settings( array( 'frequency' => 'every_second' ) ) );

		$this->assertSame( 'hourly', $result['frequency'] );
		$this->assertArrayHasKey( 'sc_wpun_settings_main_frequency', $this->errors() );
	}

	/**
	 * Turning email on without addresses is refused, with the error on the email notifications field.
	 */
	public function test_email_without_addresses_is_refused_on_the_right_field() {
		$this->plugin()->sc_wpun_settings_validate(
			$this->settings(
				array(
					'email_notifications' => 1,
					'notify_to'           => '',
				)
			)
		);

		$this->assertFalse( $this->stored_instance( 'email' )->is_enabled() );
		$this->assertArrayHasKey( 'sc_wpun_settings_email_notifications_email_notifications', $this->errors() );
	}

	/**
	 * A programmatic update never queues a test send, even if the request happens to carry the button.
	 */
	public function test_programmatic_updates_do_not_queue_test_sends() {
		$_POST['submitwithemail'] = 'Save settings with test email';

		update_option( 'sc_wpun_settings', $this->global_settings( array( 'notify_plugins' => 2 ) ) );

		$this->assertFalse( get_transient( 'sc_wpun_send_test_email' ) );
	}

	/**
	 * The settings form itself still queues the test send (until #67 replaces the mechanism).
	 */
	public function test_the_settings_form_still_queues_test_sends() {
		$_POST['option_page']     = 'sc_wpun_settings';
		$_POST['_wpnonce']        = wp_create_nonce( 'sc_wpun_settings-options' );
		$_POST['submitwithemail'] = 'Save settings with test email';

		$this->plugin()->sc_wpun_settings_validate( $this->settings() );

		$this->assertSame( 1, get_transient( 'sc_wpun_send_test_email' ) );
	}
}
