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

		$this->assertSame( '11.0', get_option( 'sc_wpun_settings_ver' ), $name );
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

		$this->assertSame( $options['frequency'], $stored['frequency'], "$name: frequency" );
		// Stored as 1 or 0 (a number or a numeric string) in 1.x, and as true or false since settings version 11.
		$this->assertSame( 1 === (int) $options['hide_updates'], $stored['hide_updates'], "$name: hide_updates" );

		// Stored as 0, 1 or 2 (a number or a numeric string) in 1.x.
		$keys = array(
			'0' => 'none',
			'1' => 'all',
			'2' => 'active',
		);
		foreach ( array( 'notify_plugins', 'notify_themes' ) as $key ) {
			$this->assertSame( $keys[ (string) $options[ $key ] ], $stored[ $key ], "$name: $key" );
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
		$this->assertSame( $options['notified'], $state['legacy_notified'], $name );
		$this->assertArrayNotHasKey( 'notified', $state, $name );
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
		$this->assertTrue( $this->stored_settings()['notify_automatic'], 'Added in version 5, defaults on.' );
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
				'to'             => $options['notify_to'],
				'from'           => get_option( 'admin_email' ) === $options['notify_from'] ? '' : $options['notify_from'],
				'reply_to'       => '',
				'format'         => 'text',
				'subject_prefix' => '',
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
		$this->assertSame( 'all', $stored['notify_plugins'] );
		$this->assertSame( 'all', $stored['notify_themes'] );
		$this->assertTrue( $stored['hide_updates'] );
		$this->assertTrue( $stored['notify_automatic'] );
		foreach ( $this->moved_keys() as $key ) {
			$this->assertArrayNotHasKey( $key, $stored, $key );
		}

		// `intval( false ) < 7` holds on a fresh install, so email is on from the start (and goes to the admin email).
		$email = $this->stored_instance( 'email' );
		$this->assertTrue( $email->is_enabled() );
		$this->assertSame( 'admin@example.org', $email->get_setting( 'to' ) );
		$this->assertSame(
			array(
				'to'             => 'admin@example.org',
				'from'           => '',
				'reply_to'       => '',
				'format'         => 'text',
				'subject_prefix' => '',
			),
			$email->get_settings()
		);
		$this->assertFalse( sc_wpun_channels()->has( 'slack' ) );
	}

	/**
	 * Settings version 10 turns 0, 1 and 2 (numbers or numeric strings) into none, all and active.
	 */
	public function test_notify_numbers_become_keys() {
		foreach ( array(
			array( 0, 'none' ),
			array( 1, 'all' ),
			array( 2, 'active' ),
			array( '0', 'none' ),
			array( '1', 'all' ),
			array( '2', 'active' ),
		) as $case ) {
			$this->upgrade_from(
				'9.0',
				$this->global_settings(
					array(
						'notify_plugins' => $case[0],
						'notify_themes'  => $case[0],
					)
				)
			);

			$stored = get_option( 'sc_wpun_settings' );
			$this->assertSame( $case[1], $stored['notify_plugins'], 'plugins ' . var_export( $case[0], true ) );
			$this->assertSame( $case[1], $stored['notify_themes'], 'themes ' . var_export( $case[0], true ) );
		}
	}

	/**
	 * Keys are left alone and a value that is not one falls back to the default.
	 */
	public function test_notify_keys_survive_and_junk_falls_back() {
		$this->upgrade_from(
			'9.0',
			$this->global_settings(
				array(
					'notify_plugins' => 'active',
					'notify_themes'  => 'bogus',
				)
			)
		);

		$stored = get_option( 'sc_wpun_settings' );
		$this->assertSame( 'active', $stored['notify_plugins'] );
		$this->assertSame( 'all', $stored['notify_themes'] );

		$this->upgrade_from( '9.0', $this->global_settings( array( 'notify_plugins' => 7 ) ) );
		$this->assertSame( 'all', get_option( 'sc_wpun_settings' )['notify_plugins'] );
	}

	/**
	 * After the upgrade a legacy "active only" plugin setting still limits the check to active plugins.
	 */
	public function test_the_update_check_behaves_the_same_after_the_upgrade() {
		$this->save_settings();
		update_option( 'sc_wpun_settings', $this->global_settings( array( 'notify_plugins' => 2 ) ) );
		update_option( 'sc_wpun_settings_ver', '9.0' );
		sc_wpun()->settings()->maybe_upgrade();
		update_option( 'active_plugins', array() );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();
		$this->assertSame( 0, $this->sent_mail_count() );

		update_option( 'active_plugins', array( 'hello.php' ) );
		$this->plugin()->do_update_check();
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * Numbers written to the option after the upgrade (`wp option update`, a filter) are still understood when read.
	 */
	public function test_legacy_numbers_are_understood_when_read() {
		add_filter(
			'sc_wpun_get_options_filter',
			static function ( $value, $field ) {
				if ( 'sc_wpun_settings' === $field && is_array( $value ) ) {
					$value['notify_plugins'] = 2;
					$value['notify_themes']  = '0';
				}
				return $value;
			},
			10,
			2
		);

		$stored = sc_wpun()->settings()->stored();

		$this->assertSame( 'active', $stored['notify_plugins'] );
		$this->assertSame( 'none', $stored['notify_themes'] );
	}

	/**
	 * A 1.x From equal to the admin email was the default, so it becomes empty (any case); a custom one is kept (#76).
	 */
	public function test_the_from_address_is_migrated_for_76() {
		update_option( 'admin_email', 'Admin@Example.org' );
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';

		foreach ( array(
			'admin@example.ORG '   => '',
			'ADMIN@example.org'    => '',
			'notifier@example.org' => 'notifier@example.org',
		) as $stored => $expected ) {
			$options                = $fixtures['1.6.0']['options'];
			$options['notify_from'] = $stored;
			$this->upgrade_from( '7.0', $options );
			$this->assertSame( $expected, $this->instance_setting( 'email', 'from' ), $stored );
		}

		$options = $fixtures['1.6.0']['options'];
		unset( $options['notify_from'] );
		$this->upgrade_from( '7.0', $options );
		$this->assertSame( '', $this->instance_setting( 'email', 'from' ), 'Missing From.' );
	}

	/**
	 * Stored 1 and 0, as numbers or strings, become true and false.
	 *
	 * @return array
	 */
	public function on_off_values() {
		return array(
			'ints on'     => array( 1, 1, true, true ),
			'ints off'    => array( 0, 0, false, false ),
			'strings on'  => array( '1', '1', true, true ),
			'strings off' => array( '0', '0', false, false ),
			'mixed'       => array( 1, 0, true, false ),
			'bools'       => array( false, true, false, true ),
			'unusable'    => array( 'maybe', array(), true, true ),
		);
	}

	/**
	 * The upgrade to settings version 11 stores the on/off settings as booleans.
	 *
	 * @dataProvider on_off_values
	 *
	 * @param mixed $automatic Stored notify_automatic.
	 * @param mixed $hide      Stored hide_updates.
	 * @param bool  $expect_automatic Expected notify_automatic.
	 * @param bool  $expect_hide      Expected hide_updates.
	 */
	public function test_on_off_settings_become_booleans( $automatic, $hide, $expect_automatic, $expect_hide ) {
		$this->upgrade_from(
			'10.0',
			array(
				'frequency'        => 'daily',
				'notify_plugins'   => 'all',
				'notify_themes'    => 'all',
				'notify_automatic' => $automatic,
				'hide_updates'     => $hide,
			)
		);

		$stored = $this->stored_settings();
		$this->assertSame( $expect_automatic, $stored['notify_automatic'] );
		$this->assertSame( $expect_hide, $stored['hide_updates'] );
		$this->assertSame( 'daily', $stored['frequency'] );
		$this->assertSame( '11.0', get_option( 'sc_wpun_settings_ver' ) );
	}

	/**
	 * After the upgrade the update notice is still hidden or shown, and the automatic update email redirected or not.
	 *
	 * @dataProvider on_off_values
	 *
	 * @param mixed $automatic Stored notify_automatic.
	 * @param mixed $hide      Stored hide_updates.
	 * @param bool  $expect_automatic Expected notify_automatic.
	 * @param bool  $expect_hide      Expected hide_updates.
	 */
	public function test_behaviour_is_unchanged_by_the_upgrade( $automatic, $hide, $expect_automatic, $expect_hide ) {
		$this->upgrade_from(
			'10.0',
			array(
				'frequency'        => 'daily',
				'notify_plugins'   => 'all',
				'notify_themes'    => 'all',
				'notify_automatic' => $automatic,
				'hide_updates'     => $hide,
			)
		);
		sc_wpun_channels()->save_raw( new WPUN\Channel_Instance( 'email', 'email', 'Email', true, array( 'to' => 'ops@example.org' ) ) );

		$email = apply_filters(
			'auto_core_update_email',
			array(
				'to'      => 'admin@example.org',
				'subject' => 'Subject',
				'body'    => 'Body',
				'headers' => '',
			),
			'success',
			new stdClass(),
			null
		);
		$this->assertSame( $expect_automatic ? 'ops@example.org' : 'admin@example.org', $email['to'] );

		add_action( 'admin_notices', 'update_nag', 3 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->plugin()->remove_update_nag_for_nonadmins();
		$this->assertSame( $expect_hide ? false : 3, has_action( 'admin_notices', 'update_nag' ) );
		remove_action( 'admin_notices', 'update_nag', 3 );
	}
}
