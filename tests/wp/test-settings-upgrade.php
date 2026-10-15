<?php
/**
 * Settings saved by older releases must upgrade without losing what the user chose.
 *
 * @package wp-updates-notifier
 */

/**
 * Upgrades from the shapes in tests/fixtures/settings.php.
 */
class Test_Settings_Upgrade extends WPUN_Test_Case {

	/**
	 * Fixtures of every release.
	 *
	 * @return array
	 */
	public function fixtures() {
		$data = array();
		foreach ( require dirname( __DIR__ ) . '/fixtures/settings.php' as $name => $fixture ) {
			$data[ $name ] = array( $name, $fixture['version'], $fixture['options'] );
		}

		return $data;
	}

	/**
	 * Store a fixture and run the plugin's upgrade routine.
	 *
	 * @param string $version Settings version.
	 * @param array  $options Saved settings.
	 */
	private function upgrade_from( $version, array $options ) {
		update_option( 'sc_wpun_settings', $options );
		update_option( 'sc_wpun_settings_ver', $version );

		delete_option( 'sc_wpun_channels' );

		sc_wpun()->settings()->maybe_upgrade();
	}

	/**
	 * The keys the settings had before version 9 moved to the channel instances.
	 *
	 * @return array
	 */
	private function moved_keys() {
		return array( 'email_notifications', 'notify_to', 'notify_from', 'slack_notifications', 'slack_webhook_url', 'slack_channel_override' );
	}

	/**
	 * After the upgrade the settings have the current version and exactly the current keys.
	 *
	 * @dataProvider fixtures
	 *
	 * @param string $name    Fixture name.
	 * @param string $version Settings version.
	 * @param array  $options Saved settings.
	 */
	public function test_the_upgrade_produces_the_current_shape( $name, $version, array $options ) {
		$this->upgrade_from( $version, $options );

		$this->assertSame( '9.0', get_option( 'sc_wpun_settings_ver' ), $name );
		$this->assertEqualsCanonicalizing( array_keys( $this->global_settings() ), array_keys( $this->stored_settings() ), $name );
	}

	/**
	 * Values the user set survive; keys that no longer exist are dropped.
	 *
	 * @dataProvider fixtures
	 *
	 * @param string $name    Fixture name.
	 * @param string $version Settings version.
	 * @param array  $options Saved settings.
	 */
	public function test_user_values_survive_and_obsolete_keys_go( $name, $version, array $options ) {
		$this->upgrade_from( $version, $options );
		$stored = $this->stored_settings();

		foreach ( array( 'frequency', 'notify_plugins', 'notify_themes', 'hide_updates' ) as $key ) {
			$this->assertSame( $options[ $key ], $stored[ $key ], "$name: $key" );
		}
		foreach ( $this->moved_keys() as $key ) {
			$this->assertArrayNotHasKey( $key, $stored, "$name: $key" );
		}

		$this->assertArrayNotHasKey( 'cron_method', $stored, $name );
		$this->assertArrayNotHasKey( 'security_key', $stored, $name );
	}

	/**
	 * Settings version 8 moved what was notified and when the last check ran into their own option.
	 *
	 * @dataProvider fixtures
	 *
	 * @param string $name    Fixture name.
	 * @param string $version Settings version.
	 * @param array  $options Saved settings.
	 */
	public function test_the_run_state_moves_to_its_own_option( $name, $version, array $options ) {
		delete_option( 'sc_wpun_state' );

		$this->upgrade_from( $version, $options );
		$stored = $this->stored_settings();
		$state  = $this->stored_state();

		$this->assertArrayNotHasKey( 'notified', $stored, $name );
		$this->assertArrayNotHasKey( 'last_check_time', $stored, $name );
		$this->assertSame( $options['notified'], $state['notified'], $name );
		$this->assertSame( (int) $options['last_check_time'], $state['last_check_time'], $name );
		$this->assertArrayNotHasKey( 'sc_wpun_state', wp_load_alloptions(), "$name: the run state should not be autoloaded." );
	}

	/**
	 * Releases before settings version 7 had email only, so the upgrade turns email notifications on.
	 */
	public function test_email_is_enabled_when_upgrading_from_before_version_7() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';

		$this->upgrade_from( $fixtures['1.4']['version'], $fixtures['1.4']['options'] );

		$this->assertTrue( $this->stored_instance( 'email' )->is_enabled() );
		$this->assertFalse( sc_wpun_channels()->has( 'slack' ) );
		$this->assertSame( 1, $this->stored_settings()['notify_automatic'], 'Added in version 5, defaults on.' );
	}

	/**
	 * Every fixture gets the email instance its settings described: type, label, addresses and on or off.
	 *
	 * @dataProvider fixtures
	 *
	 * @param string $name    Fixture name.
	 * @param string $version Settings version.
	 * @param array  $options Saved settings.
	 */
	public function test_the_email_instance_is_created_from_the_settings( $name, $version, array $options ) {
		$this->upgrade_from( $version, $options );

		$email = $this->stored_instance( 'email' );
		$this->assertNotNull( $email, $name );
		$this->assertSame( 'email', $email->get_type(), $name );
		$this->assertSame( 'Email', $email->get_label(), $name );
		$this->assertSame( array(), $email->get_routing(), $name );
		$this->assertSame(
			array(
				'to'   => $options['notify_to'],
				'from' => $options['notify_from'],
			),
			$email->get_settings(),
			$name
		);
		// Before version 7 email was always on; from then on the saved choice (also saved as a string) counts.
		$this->assertSame( (int) $version < 7 || 1 === (int) $options['email_notifications'], $email->is_enabled(), $name );
	}

	/**
	 * Slack gets an instance when it was set up, with its values and flag copied as they were.
	 */
	public function test_the_slack_instance_is_created_when_slack_was_configured() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';

		$this->upgrade_from( $fixtures['1.6.0']['version'], $fixtures['1.6.0']['options'] );

		$slack = $this->stored_instance( 'slack' );
		$this->assertSame( 'slack', $slack->get_type() );
		$this->assertSame( 'Slack', $slack->get_label() );
		$this->assertTrue( $slack->is_enabled() );
		$this->assertSame(
			array(
				'webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX',
				'channel'     => '#ops',
			),
			$slack->get_settings()
		);
	}

	/**
	 * A webhook that was saved but switched off still makes an (disabled) instance; an unused Slack makes none.
	 */
	public function test_slack_is_carried_over_only_when_it_was_set_up() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';
		$options  = $fixtures['1.6.0']['options'];

		$options['slack_notifications'] = 0;
		$this->upgrade_from( '7.0', $options );
		$this->assertFalse( $this->stored_instance( 'slack' )->is_enabled() );
		$this->assertSame( '#ops', $this->instance_setting( 'slack', 'channel' ) );

		foreach ( array( '1.4', '1.4.x', '1.6.0-string-values' ) as $name ) {
			$this->upgrade_from( $fixtures[ $name ]['version'], $fixtures[ $name ]['options'] );
			$this->assertFalse( sc_wpun_channels()->has( 'slack' ), $name );
		}
	}

	/**
	 * From version 7 on, the user's email choice is kept: off stays off.
	 */
	public function test_an_email_choice_of_off_is_kept_from_version_7() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';
		$options  = $fixtures['1.6.0']['options'];

		$options['email_notifications'] = 0;
		$this->upgrade_from( '7.0', $options );

		$this->assertFalse( $this->stored_instance( 'email' )->is_enabled() );
	}

	/**
	 * Running the upgrade again changes nothing, and instances that exist are never overwritten.
	 */
	public function test_the_migration_runs_once_and_never_overwrites_instances() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';

		$this->upgrade_from( $fixtures['1.6.0']['version'], $fixtures['1.6.0']['options'] );
		$channels = sc_wpun_channels()->all();
		$settings = $this->stored_settings();

		sc_wpun()->settings()->maybe_upgrade();
		$this->assertEquals( $channels, sc_wpun_channels()->all() );
		$this->assertSame( $settings, $this->stored_settings() );

		// Older settings arriving again (a restore, an import) do not replace what is stored.
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'email', 'email', 'Email', false, array( 'to' => 'mine@example.org' ) ) );
		$channels = sc_wpun_channels()->all();
		update_option( 'sc_wpun_settings', $fixtures['1.6.0']['options'] );
		update_option( 'sc_wpun_settings_ver', '7.0' );

		sc_wpun()->settings()->maybe_upgrade();

		$this->assertEquals( $channels, sc_wpun_channels()->all() );
	}

	/**
	 * Settings already at the current version are left alone, including on later requests.
	 */
	public function test_current_settings_are_not_rewritten() {
		$this->save_settings( array( 'notify_to' => 'untouched@example.org' ) );
		$before = $this->stored_settings();
		$kept   = sc_wpun_channels()->all();

		sc_wpun()->settings()->maybe_upgrade();

		$this->assertSame( $before, $this->stored_settings() );
		$this->assertEquals( $kept, sc_wpun_channels()->all() );
	}

	/**
	 * A new install gets the documented defaults.
	 */
	public function test_a_fresh_install_gets_defaults() {
		delete_option( 'sc_wpun_settings' );
		delete_option( 'sc_wpun_settings_ver' );
		delete_option( 'sc_wpun_channels' );
		update_option( 'admin_email', 'admin@example.org' );

		sc_wpun()->settings()->maybe_upgrade();

		$stored = $this->stored_settings();
		$this->assertSame( 'hourly', $stored['frequency'] );
		$this->assertSame( 1, $stored['notify_plugins'] );
		$this->assertSame( 1, $stored['notify_themes'] );
		$this->assertSame( 1, $stored['hide_updates'] );
		foreach ( $this->moved_keys() as $key ) {
			$this->assertArrayNotHasKey( $key, $stored, $key );
		}

		// `intval( false ) < 7` holds on a fresh install, so email is on from the start (and goes to the admin email).
		$email = $this->stored_instance( 'email' );
		$this->assertTrue( $email->is_enabled() );
		$this->assertSame( 'admin@example.org', $email->get_setting( 'to' ) );
		$this->assertSame( 'admin@example.org', $email->get_setting( 'from' ) );
		$this->assertFalse( sc_wpun_channels()->has( 'slack' ) );
	}
}
