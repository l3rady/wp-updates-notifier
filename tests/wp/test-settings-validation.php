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
	 * Each notify setting accepts only its own keys: major for core, active for plugins and themes.
	 */
	public function test_notify_settings_accept_only_their_own_keys() {
		$valid = $this->plugin()->sc_wpun_settings_validate( $this->settings( array( 'notify_core' => 'major' ) ) );
		$this->assertSame( 'major', $valid['notify_core'] );
		$this->assertSame( array(), $this->errors() );

		$valid = $this->plugin()->sc_wpun_settings_validate( $this->settings( array( 'notify_core' => 'none' ) ) );
		$this->assertSame( 'none', $valid['notify_core'] );

		$valid  = $this->plugin()->sc_wpun_settings_validate(
			$this->settings(
				array(
					'notify_core'    => 'active',
					'notify_plugins' => 'major',
					'notify_themes'  => 'major',
				)
			)
		);
		$errors = $this->errors();
		$this->assertSame( 'sc_wpun_settings_main_notify_core_error', $errors['sc_wpun_settings_main_notify_core'] );
		$this->assertSame( 'sc_wpun_settings_main_notify_plugins_error', $errors['sc_wpun_settings_main_notify_plugins'] );
		$this->assertSame( 'sc_wpun_settings_main_notify_themes_error', $errors['sc_wpun_settings_main_notify_themes'] );
		$this->assertSame( 'all', $valid['notify_core'], 'The stored value is kept.' );
		$this->assertSame( 'all', $valid['notify_plugins'] );
		$this->assertSame( 'all', $valid['notify_themes'] );
	}

	/**
	 * Updating the option outside the settings form (another plugin, a migration, our own code) must not die.
	 */
	public function test_updating_the_option_outside_the_settings_form_does_not_die() {
		update_option( 'sc_wpun_settings', $this->global_settings( array( 'notify_plugins' => 'active' ) ) );

		$this->assertSame( 'active', $this->stored_settings()['notify_plugins'] );
	}

	/**
	 * A key that is not one of the choices is refused with a settings error; valid keys are saved.
	 */
	public function test_an_unknown_notify_key_is_refused() {
		$valid = $this->plugin()->sc_wpun_settings_validate(
			$this->settings(
				array(
					'notify_plugins' => 'everything',
					'notify_themes'  => 'active',
				)
			)
		);

		$errors = $this->errors();
		$this->assertSame( 'sc_wpun_settings_main_notify_plugins_error', $errors['sc_wpun_settings_main_notify_plugins'] );
		$this->assertArrayNotHasKey( 'sc_wpun_settings_main_notify_themes', $errors );
		$this->assertSame( 'all', $valid['notify_plugins'], 'The stored value is kept.' );
		$this->assertSame( 'active', $valid['notify_themes'] );

		$this->plugin()->sc_wpun_settings_validate( $this->settings( array( 'notify_themes' => 2 ) ) );
		$this->assertArrayHasKey( 'sc_wpun_settings_main_notify_themes', $this->errors(), 'Numbers are not keys.' );
	}

	/**
	 * The settings page draws the notify settings from the schema, with the saved choice checked.
	 */
	public function test_the_page_draws_the_notify_radios() {
		$this->save_settings( array( 'notify_plugins' => 'active' ) );

		ob_start();
		$this->plugin()->settings_page();
		$html = ob_get_clean();

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( $html );
		libxml_clear_errors();
		$xpath  = new DOMXPath( $doc );
		$radios = $xpath->query( '//fieldset[@id="sc_wpun_settings_main_notify_plugins"]//input[@type="radio"][@name="sc_wpun_settings[notify_plugins]"]' );
		$this->assertSame( 3, $radios->length );
		$this->assertSame( array( 'none', 'all', 'active' ), array_map( static fn( $radio ) => $radio->getAttribute( 'value' ), iterator_to_array( $radios ) ) );
		$this->assertSame( 1, $xpath->query( '//fieldset[@id="sc_wpun_settings_main_notify_plugins"]//input[@value="active"][@checked]' )->length );
		$this->assertSame( 1, $xpath->query( '//fieldset[@id="sc_wpun_settings_main_notify_themes"]//input[@value="all"][@checked]' )->length );
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
	 * Saving the form creates no Slack instance, nor any other.
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
		$this->assertFalse( sc_wpun_channels()->has( 'email' ), 'Saving the form creates no instance at all any more.' );
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

		update_option( 'sc_wpun_settings', $this->global_settings( array( 'notify_plugins' => 'active' ) ) );

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
	 * No save queues a test send any more: the transient of the old flow is never set, even if the request carries the old button.
	 */
	public function test_saving_never_queues_a_test_send() {
		$_POST['option_page']     = 'sc_wpun_settings';
		$_POST['_wpnonce']        = wp_create_nonce( 'sc_wpun_settings-options' );
		$_POST['submitwithemail'] = 'Save settings with test email';

		$this->plugin()->sc_wpun_settings_validate( $this->settings() );
		update_option( 'sc_wpun_settings', $this->global_settings( array( 'notify_plugins' => 'active' ) ) );

		$this->assertFalse( get_transient( 'sc_wpun_send_test_email' ) );
	}

	/**
	 * Unticking a box submits 0, which stores false (before, a missing value meant off).
	 */
	public function test_a_submitted_zero_stores_false() {
		$valid = $this->plugin()->sc_wpun_settings_validate(
			array(
				'hide_updates' => '0',
			)
		);

		$this->assertFalse( $valid['hide_updates'] );
		$this->assertSame( array(), $this->errors() );

		$valid = $this->plugin()->sc_wpun_settings_validate(
			array(
				'hide_updates' => '1',
			)
		);
		$this->assertTrue( $valid['hide_updates'] );
	}

	/**
	 * A key that is missing keeps the stored value, for every setting.
	 */
	public function test_a_missing_key_keeps_the_stored_value() {
		$this->save_settings(
			array(
				'frequency'      => 'daily',
				'notify_plugins' => 'active',
				'notify_themes'  => 'none',
				'hide_updates'   => false,
			)
		);

		$valid = $this->plugin()->sc_wpun_settings_validate( array() );

		$this->assertSame(
			$this->global_settings(
				array(
					'frequency'      => 'daily',
					'notify_plugins' => 'active',
					'notify_themes'  => 'none',
					'hide_updates'   => false,
				)
			),
			$valid
		);
		$this->assertSame( array(), $this->errors() );
	}

	/**
	 * An invalid on or off value is refused with the existing error and keeps the stored value.
	 */
	public function test_an_invalid_boolean_is_refused() {
		$valid = $this->plugin()->sc_wpun_settings_validate(
			array(
				'hide_updates' => 'yes',
			)
		);

		$errors = $this->errors();
		$this->assertSame( 'sc_wpun_settings_main_hide_updates_error', $errors['sc_wpun_settings_main_hide_updates'] );
		$this->assertTrue( $valid['hide_updates'] );
	}

	/**
	 * A frequency that is not offered is refused with the existing error and keeps the stored one.
	 */
	public function test_an_invalid_frequency_is_refused() {
		$valid = $this->plugin()->sc_wpun_settings_validate( array( 'frequency' => 'every_second' ) );

		$this->assertSame( 'sc_wpun_settings_main_frequency_error', $this->errors()['sc_wpun_settings_main_frequency'] );
		$this->assertSame( 'hourly', $valid['frequency'] );
	}

	/**
	 * An invalid value marks its own field and describes it with the message that says what to choose; the others stay clean.
	 */
	public function test_an_invalid_value_is_shown_on_its_field() {
		$this->plugin()->sc_wpun_settings_validate(
			array(
				'frequency'      => 'every_second',
				'notify_plugins' => 'sometimes',
			)
		);
		get_settings_errors(); // The page title asks first.

		ob_start();
		$this->plugin()->settings_page();
		$html = ob_get_clean();

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( $html );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );

		$freq = $xpath->query( '//*[@id="sc_wpun_settings_main_frequency"]' )->item( 0 );
		$this->assertSame( 'true', $freq->getAttribute( 'aria-invalid' ) );
		$this->assertSame( 'sc_wpun_settings_main_frequency-error', $freq->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 'Error: Choose how often to check for updates.', trim( $xpath->query( '//p[@id="sc_wpun_settings_main_frequency-error"]' )->item( 0 )->textContent ) );

		$fieldset = $xpath->query( '//fieldset[@id="sc_wpun_settings_main_notify_plugins"]' )->item( 0 );
		$this->assertSame( 'true', $fieldset->getAttribute( 'aria-invalid' ) );
		$this->assertSame( 'Error: Choose whether to be told about plugin updates.', trim( $xpath->query( '//p[@id="sc_wpun_settings_main_notify_plugins-error"]' )->item( 0 )->textContent ) );

		$this->assertSame( 2, $xpath->query( '//p[contains(@class,"sc-wpun-field-error")]' )->length );
		$this->assertSame( 2, $xpath->query( '//*[@aria-invalid]' )->length );
		$this->assertSame( 0, $xpath->query( '//fieldset[@id="sc_wpun_settings_main_notify_themes"][@aria-invalid]' )->length );
		$this->assertStringContainsString( 'Choose how often to check for updates.', $html, 'Core still shows its notice.' );
	}

	/**
	 * The email keys of the 1.x form are ignored: not saved to the instance, not forwarded, not kept in the option.
	 */
	public function test_the_old_email_keys_are_ignored() {
		$email = $this->stored_instance( 'email' );
		$state = $this->snapshot_channels();

		$valid = $this->plugin()->sc_wpun_settings_validate(
			array(
				'frequency'           => 'daily',
				'email_notifications' => '0',
				'notify_to'           => 'typed@example.org',
				'notify_from'         => 'typed-from@example.org',
				'notify_reply_to'     => 'typed-reply@example.org',
			)
		);

		$this->assertSame( 'daily', $valid['frequency'], 'The real settings are still saved.' );
		foreach ( array( 'email_notifications', 'notify_to', 'notify_from', 'notify_reply_to' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $valid, $key );
		}
		$this->assertSame( array_keys( $this->global_settings() ), array_keys( $valid ) );
		$this->assertEquals( $email, $this->stored_instance( 'email' ) );
		$this->assertSame( $state, $this->snapshot_channels(), 'The channels option is not touched.' );
		$this->assertSame( array(), $this->errors(), 'Bad values raise no errors either.' );

		$this->plugin()->sc_wpun_settings_validate( array( 'notify_to' => 'not an address', 'email_notifications' => 'maybe' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array(), $this->errors() );

		update_option( 'sc_wpun_settings', array_merge( $this->global_settings(), array( 'notify_to' => 'x@example.org' ) ) );
		$this->assertArrayNotHasKey( 'notify_to', $this->stored_settings() );
	}

	/**
	 * The General tab has no email section, and the methods that drew and saved it are gone.
	 */
	public function test_the_general_tab_has_no_email_section() {
		ob_start();
		$this->plugin()->settings_page();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Email Notifications', $html );
		$this->assertStringNotContainsString( 'notify_to', $html );
		$this->assertStringNotContainsString( 'notify_from', $html );
		$this->assertStringNotContainsString( 'sc_wpun_settings_email_notifications', $html );
		$this->assertStringNotContainsString( 'ops@example.org', $html );

		$removed = array(
			'sc_wpun_settings_email_notifications_field_email_notifications',
			'sc_wpun_settings_email_notifications_field_notify_to',
			'sc_wpun_settings_email_notifications_field_notify_from',
			'sc_wpun_settings_email_notifications_field_notify_reply_to',
			'sc_wpun_settings_email_notifications_text',
			'email_enabled_schema',
			'email_read_only',
			'email_note',
			'instance_setting',
			'instance_enabled',
			'save_instance',
			'value_attribute',
		);
		foreach ( $removed as $method ) {
			$this->assertFalse( method_exists( sc_wpun()->settings_page(), $method ), $method );
			$this->assertFalse( method_exists( $this->plugin(), $method ), 'The facade: ' . $method );
		}
	}

	/**
	 * The channels option as stored.
	 *
	 * @return mixed
	 */
	private function snapshot_channels() {
		return get_option( 'sc_wpun_channels' );
	}
}
