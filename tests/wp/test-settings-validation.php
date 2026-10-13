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
		unset( $_POST['option_page'], $_POST['_wpnonce'], $_POST['submitwithemail'], $_POST['submitwithslack'] );
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
		update_option( 'sc_wpun_settings', $this->settings( array( 'notify_to' => 'new@example.org' ) ) );

		$this->assertSame( 'new@example.org', $this->stored_settings()['notify_to'] );
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

		update_option( 'sc_wpun_settings', $this->settings( array( 'frequency' => 'daily' ) ) );

		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * Saving without changing the frequency leaves the next run where it was.
	 */
	public function test_an_unchanged_frequency_leaves_the_schedule_alone() {
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		$next = time() + 5000;
		wp_schedule_event( $next, 'hourly', 'sc_wpun_update_check' );

		update_option( 'sc_wpun_settings', $this->settings( array( 'notify_to' => 'other@example.org' ) ) );

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
	 * Only https webhook URLs with a host are accepted.
	 *
	 * @dataProvider webhook_urls
	 *
	 * @param string $url      Entered URL.
	 * @param bool   $accepted Whether it should be accepted.
	 */
	public function test_webhook_urls( $url, $accepted ) {
		$result = $this->plugin()->sc_wpun_settings_validate( $this->settings( array( 'slack_webhook_url' => $url ) ) );

		if ( $accepted ) {
			$this->assertSame( $url, $result['slack_webhook_url'] );
			$this->assertArrayNotHasKey( 'sc_wpun_settings_slack_notifications_slack_webhook_url', $this->errors() );
		} else {
			$this->assertSame( '', $result['slack_webhook_url'], 'The previous (empty) value is kept.' );
			$this->assertArrayHasKey( 'sc_wpun_settings_slack_notifications_slack_webhook_url', $this->errors() );
		}
	}

	/**
	 * Webhook URLs and whether they are accepted.
	 *
	 * @return array
	 */
	public function webhook_urls() {
		return array(
			'slack'      => array( 'https://hooks.slack.com/services/T000/B000/XXXX', true ),
			'plain http' => array( 'http://hooks.slack.com/services/T000/B000/XXXX', false ),
			'javascript' => array( 'javascript:alert(1)', false ),
			'ftp'        => array( 'ftp://example.org/hook', false ),
			'no host'    => array( 'https://', false ),
		);
	}

	/**
	 * Channel names must start with # or @ and contain no whitespace.
	 */
	public function test_channel_names() {
		$plugin = $this->plugin();

		$this->assertSame( '#ops', $plugin->sc_wpun_settings_validate( $this->settings( array( 'slack_channel_override' => '#ops' ) ) )['slack_channel_override'] );
		$this->assertSame( '', $plugin->sc_wpun_settings_validate( $this->settings( array( 'slack_channel_override' => 'ops' ) ) )['slack_channel_override'] );
		$this->assertSame( '', $plugin->sc_wpun_settings_validate( $this->settings( array( 'slack_channel_override' => "#ops\tteam" ) ) )['slack_channel_override'] );
	}

	/**
	 * Turning email on without addresses is refused, with the error on the email notifications field.
	 */
	public function test_email_without_addresses_is_refused_on_the_right_field() {
		$result = $this->plugin()->sc_wpun_settings_validate(
			$this->settings(
				array(
					'email_notifications' => 1,
					'notify_to'           => '',
				)
			)
		);

		$this->assertSame( 0, $result['email_notifications'] );
		$this->assertArrayHasKey( 'sc_wpun_settings_email_notifications_email_notifications', $this->errors() );
	}

	/**
	 * A programmatic update never queues a test send, even if the request happens to carry the button.
	 */
	public function test_programmatic_updates_do_not_queue_test_sends() {
		$_POST['submitwithemail'] = 'Save settings with test email';

		update_option( 'sc_wpun_settings', $this->settings( array( 'notify_to' => 'x@example.org' ) ) );

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
