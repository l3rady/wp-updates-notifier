<?php
/**
 * Settings types and the run state (#75).
 *
 * @package wp-updates-notifier
 */

/**
 * Settings are normalised on read; what was notified lives in its own option, written once per run.
 */
class Test_Settings_State extends WPUN_Test_Case {

	/**
	 * Fake the plugin information request core makes (until #70 removes it).
	 */
	public function set_up() {
		parent::set_up();

		$this->fake_http(
			'api.wordpress.org/plugins/info',
			$this->fake_response(
				200,
				wp_json_encode(
					array(
						'name'    => 'Hello Dolly',
						'slug'    => 'hello-dolly',
						'version' => '9.9.9',
						'tested'  => '99.0',
					)
				)
			)
		);
	}

	/**
	 * Option values saved as strings (imports, `wp option update`) used to switch notifications off silently.
	 */
	public function test_string_valued_options_still_notify() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';
		$options  = $fixtures['1.6.0-string-values']['options'];
		unset( $options['notified'], $options['last_check_time'] );
		// The email and Slack settings of version 7 become channel instances when the settings are upgraded.
		delete_option( 'sc_wpun_channels' );
		update_option( 'sc_wpun_settings', $options );
		update_option( 'sc_wpun_settings_ver', $fixtures['1.6.0-string-values']['version'] );
		sc_wpun()->settings()->maybe_upgrade();
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * Settings missing keys get their defaults when read.
	 */
	public function test_missing_settings_get_defaults() {
		delete_option( 'sc_wpun_channels' );
		update_option( 'sc_wpun_settings', array( 'frequency' => 'daily' ) );
		update_option( 'sc_wpun_settings_ver', self::SETTINGS_VERSION );
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		// Without an email instance nothing is sent, and no notice is raised on the way.
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * A failed send is not recorded as notified, so the next check tries again.
	 */
	public function test_a_failed_email_is_retried_on_the_next_check() {
		$this->save_settings();
		$this->set_updates( '99.0.0' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$this->plugin()->do_update_check();
		$this->assertSame( '', $this->notified_version( 'core', 'core' ) );

		remove_filter( 'pre_wp_mail', '__return_false' );
		$this->plugin()->do_update_check();
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ) );
	}

	/**
	 * With no channel switched on nothing reaches anyone, so nothing is recorded either.
	 */
	public function test_nothing_is_recorded_when_no_channel_is_on() {
		$this->save_settings(
			array(
				'email_notifications' => 0,
				'slack_notifications' => 0,
			)
		);
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertSame( '', $this->notified_version( 'core', 'core' ) );
	}

	/**
	 * Slack sends nothing any more, so an enabled Slack instance alone delivers nothing and nothing is recorded.
	 */
	public function test_a_slack_instance_alone_delivers_nothing() {
		$this->save_settings(
			array(
				'email_notifications' => 0,
				'slack_notifications' => 1,
				'slack_webhook_url'   => 'https://hooks.slack.com/services/T/B/X',
			)
		);
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertSame( '', $this->notified_version( 'core', 'core' ) );
		$this->assertCount( 0, $this->requests_to( 'hooks.slack.com' ) );
	}

	/**
	 * The run state is its own option, not autoloaded, and never part of the settings.
	 */
	public function test_the_run_state_has_its_own_option() {
		$this->save_settings();
		delete_option( 'sc_wpun_state' );
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertArrayNotHasKey( 'notified', $this->stored_settings() );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ) );
		$this->assertArrayNotHasKey( 'sc_wpun_state', wp_load_alloptions() );
	}

	/**
	 * Settings saved while a check runs are not overwritten by the run.
	 */
	public function test_settings_saved_during_a_run_are_kept() {
		$this->save_settings();
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		add_filter(
			'sc_wpun_plugins_need_update',
			function ( $plugins ) {
				update_option( 'sc_wpun_settings', $this->global_settings( array( 'frequency' => 'weekly' ) ) );
				return $plugins;
			},
			20
		);

		$this->plugin()->do_update_check();

		$this->assertSame( 'weekly', $this->stored_settings()['frequency'] );
	}

	/**
	 * A filter that returns something other than an array no longer causes a fatal error.
	 */
	public function test_a_filter_returning_a_non_array_does_not_fatal() {
		$this->save_settings();
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		add_filter(
			'sc_wpun_plugins_need_update',
			static function () {
				return 'not an array';
			},
			20
		);

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * Entries for plugins that are no longer waiting for an update are forgotten.
	 */
	public function test_stale_entries_are_pruned() {
		$this->save_settings();
		$this->save_channel_state(
			'email',
			array(
				'plugin' => array(
					'hello.php'           => '1.0.0',
					'deleted/deleted.php' => '3.0.0',
				),
			)
		);
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( array( 'hello.php' => '2.0.0' ), $this->channel_notified()['plugin'] );
	}

	/**
	 * Entries are also forgotten on a run that sends nothing.
	 */
	public function test_stale_entries_are_pruned_when_nothing_is_sent() {
		$this->save_settings();
		$this->save_channel_state( 'email', array( 'plugin' => array( 'hello.php' => '2.0.0' ) ) );
		$this->set_updates();

		$this->plugin()->do_update_check();

		$this->assertSame( array(), $this->channel_notified()['plugin'] );
	}
}
