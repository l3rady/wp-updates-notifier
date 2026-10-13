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

		$this->call_private( 'settings_up_to_date' );
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

		$this->assertSame( '8.1', get_option( 'sc_wpun_settings_ver' ), $name );
		$this->assertEqualsCanonicalizing( array_keys( $this->settings() ), array_keys( $this->stored_settings() ), $name );
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

		foreach ( array( 'frequency', 'notify_to', 'notify_from', 'notify_plugins', 'notify_themes', 'hide_updates' ) as $key ) {
			$this->assertSame( $options[ $key ], $stored[ $key ], "$name: $key" );
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
		$stored = $this->stored_settings();

		$this->assertSame( 1, $stored['email_notifications'] );
		$this->assertSame( 0, $stored['slack_notifications'] );
		$this->assertSame( 1, $stored['notify_automatic'], 'Added in version 5, defaults on.' );
	}

	/**
	 * From version 7 on, the user's email and Slack choices are kept.
	 */
	public function test_slack_and_email_choices_are_kept_from_version_7() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';

		$this->upgrade_from( $fixtures['1.6.0']['version'], $fixtures['1.6.0']['options'] );

		$expected = $fixtures['1.6.0']['options'];
		unset( $expected['notified'], $expected['last_check_time'] );
		$this->assertSame( $expected, $this->stored_settings() );
	}

	/**
	 * Settings already at the current version are left alone, including on later requests.
	 */
	public function test_current_settings_are_not_rewritten() {
		$this->save_settings( array( 'notify_to' => 'untouched@example.org' ) );
		$before = $this->stored_settings();

		$this->call_private( 'settings_up_to_date' );

		$this->assertSame( $before, $this->stored_settings() );
	}

	/**
	 * A new install gets the documented defaults.
	 */
	public function test_a_fresh_install_gets_defaults() {
		delete_option( 'sc_wpun_settings' );
		delete_option( 'sc_wpun_settings_ver' );
		update_option( 'admin_email', 'admin@example.org' );

		$this->call_private( 'settings_up_to_date' );

		$stored = $this->stored_settings();
		$this->assertSame( 'hourly', $stored['frequency'] );
		$this->assertSame( 'admin@example.org', $stored['notify_to'] );
		$this->assertSame( 'admin@example.org', $stored['notify_from'] );
		$this->assertSame( 1, $stored['notify_plugins'] );
		$this->assertSame( 1, $stored['notify_themes'] );
		$this->assertSame( 1, $stored['hide_updates'] );
		// `intval( false ) < 7` holds on a fresh install, so email is on from the start (and goes to the admin email).
		$this->assertSame( 1, $stored['email_notifications'] );
		$this->assertSame( 0, $stored['slack_notifications'] );
	}
}
