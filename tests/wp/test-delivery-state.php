<?php
/**
 * Delivery state is kept per channel instance (#95).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;

/**
 * What each instance was told, forgotten, pruned, skipped and upgraded.
 */
class Test_Delivery_State extends WPUN_Test_Case {

	const OWN_PLUGIN = 'wp-updates-notifier/wp-updates-notifier.php';

	/**
	 * Fake what core asks api.wordpress.org about plugins.
	 */
	public function set_up() {
		parent::set_up();

		$this->fake_http(
			'api.wordpress.org/plugins/info',
			$this->fake_response(
				200,
				wp_json_encode(
					array(
						'name'    => 'X',
						'slug'    => 'x',
						'version' => '9',
						'tested'  => '99',
					)
				)
			)
		);
	}

	/**
	 * Save an extra email instance.
	 *
	 * @param string $id      Id.
	 * @param string $to      Recipient.
	 * @param bool   $enabled Whether it is on.
	 */
	private function add_email( $id, $to, $enabled = true ) {
		sc_wpun_channels()->save( new Channel_Instance( $id, 'email', ucfirst( $id ), $enabled, array( 'to' => $to ) ) );
	}

	/**
	 * Run the check.
	 */
	private function run_check() {
		reset_phpmailer_instance();
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * Addresses mail went to in the last run.
	 *
	 * @return string[]
	 */
	private function recipients() {
		$out = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $i => $mail ) {
			$out[] = tests_retrieve_phpmailer_instance()->get_recipient( 'to', $i )->address;
		}
		sort( $out );

		return $out;
	}

	/**
	 * A fresh install has no history, so the first run sends what is pending.
	 */
	public function test_a_first_run_sends_everything_pending() {
		$this->save_settings();
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->run_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ) );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php' ) );
		$this->assertSame( array( 'email' ), array_keys( sc_wpun()->update_checker()->last_results() ) );
	}

	/**
	 * One instance failing while another succeeds: only the failed one is sent again, with the same items.
	 */
	public function test_only_the_failed_instance_retries() {
		$this->save_settings( array( 'notify_to' => 'ok@example.org' ) );
		$this->add_email( 'flaky', 'flaky@example.org' );
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		add_filter(
			'wp_mail',
			static function ( $args ) {
				if ( false !== strpos( (string) ( is_array( $args['to'] ) ? implode( ',', $args['to'] ) : $args['to'] ), 'flaky' ) ) {
					$args['to'] = 'not-an-address'; // The mailer rejects it.
				}
				return $args;
			}
		);

		$this->run_check();

		$results = sc_wpun()->update_checker()->last_results();
		$this->assertTrue( $results['email']->is_success() );
		$this->assertFalse( $results['flaky']->is_success() );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core', 'email' ) );
		$this->assertSame( array(), $this->channel_notified( 'flaky' ) );

		remove_all_filters( 'wp_mail' );
		$this->run_check();

		$this->assertSame( array( 'flaky@example.org' ), $this->recipients() );
		$this->assertStringContainsString( 'Hello Dolly', $this->sent_mail()->body );
		$this->assertStringContainsString( '99.0.0', $this->sent_mail()->body );
		$this->assertSame( array( 'flaky' ), array_keys( sc_wpun()->update_checker()->last_results() ) );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php', 'flaky' ) );
	}

	/**
	 * Upgrading from 1.6: announced items are not resent, unannounced pending ones are, and the legacy list is handed out once.
	 */
	public function test_an_upgrade_from_1_6_resends_nothing_announced() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';
		$fixture  = $fixtures['1.6.0'];
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', $fixture['options'] );
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings_ver', $fixture['version'] );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_channels' );
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		sc_wpun()->settings()->maybe_upgrade();
		$this->assertArrayHasKey( 'legacy_notified', $this->stored_state() );

		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', array_merge( $this->stored_settings(), array( 'notify_plugins' => 'all' ) ) ); // The fixture checks active plugins only.

		// Core 6.4 and hello.php 1.8 were announced; the own plugin was never announced.
		$this->set_updates(
			'6.4',
			array(
				'hello.php'      => array( 'new_version' => '1.8' ),
				self::OWN_PLUGIN => array( 'new_version' => '4.5.6' ),
			)
		);
		$this->run_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$body = $this->sent_mail()->body;
		$this->assertStringContainsString( 'WP Updates Notifier from version ', $body );
		$this->assertStringNotContainsString( 'Hello Dolly', $body );
		$this->assertStringNotContainsString( '6.4', $body );
		$this->assertArrayNotHasKey( 'legacy_notified', $this->stored_state() );
		$this->assertSame( '6.4', $this->notified_version( 'core', 'core' ) );
		$this->assertSame( '4.5.6', $this->notified_version( 'plugin', self::OWN_PLUGIN ) );

		// Added after the run: starts clean and gets everything pending.
		$this->add_email( 'later', 'later@example.org' );
		$this->run_check();
		$this->assertSame( array( 'later@example.org' ), $this->recipients() );
		$this->assertStringContainsString( 'Hello Dolly', $this->sent_mail()->body );
	}

	/**
	 * Deleting an instance clears its state at once, so a new instance with the same id starts clean.
	 */
	public function test_deleting_an_instance_clears_its_state() {
		$this->save_settings();
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '99.0.0' ) ) );
		$this->set_updates( '99.0.0' );

		sc_wpun_channels()->delete( 'email' );
		$this->assertArrayNotHasKey( 'email', $this->stored_state()['channels'] );

		$this->add_email( 'email', 'new@example.org' );
		$this->run_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * State of an id that is not in the store is pruned on a run.
	 */
	public function test_unknown_ids_are_pruned_on_a_run() {
		$this->save_settings();
		$this->save_channel_state( 'ghost', array( 'core' => array( 'core' => '1.0' ) ) );
		$this->set_updates();

		$this->run_check();

		$this->assertArrayNotHasKey( 'ghost', $this->stored_state()['channels'] );
	}

	/**
	 * A disabled instance still forgets what was updated, so a later version is sent when it is back on.
	 */
	public function test_a_disabled_instance_forgets_too() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->save_channel_state( 'email', array( 'plugin' => array( 'hello.php' => '2.0.0' ) ) );
		$this->set_updates();

		$this->run_check();
		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( array(), $this->channel_notified()['plugin'] );

		$this->save_settings( array( 'email_notifications' => 1 ) );
		$this->save_channel_state( 'email', array() );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '3.0.0' ) ) );
		$this->run_check();
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( '3.0.0', $this->notified_version( 'plugin', 'hello.php' ) );
	}

	/**
	 * Types that were not checked this run keep their history, and so do items of an add-on type; with no core data core is untouched.
	 */
	public function test_forgetting_leaves_unchecked_types_alone() {
		$this->save_settings();
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', array_merge( $this->global_settings(), array( 'notify_plugins' => 'none' ) ) );
		$this->save_channel_state(
			'email',
			array(
				'core'   => array( 'core' => '99.0.0' ),
				'plugin' => array( 'hello.php' => '2.0.0' ),
				'theme'  => array( 'gone' => '1.0' ),
				'addon'  => array( 'thing' => '5' ),
			)
		);
		$this->set_updates();
		delete_site_transient( 'update_core' );
		$this->fake_http(
			'api.wordpress.org/core/version-check',
			$this->fake_response(
				200,
				wp_json_encode(
					array(
						'offers'       => array(),
						'translations' => array(),
					)
				)
			)
		);

		$this->run_check();

		$notified = $this->channel_notified();
		$this->assertSame( '99.0.0', $notified['core']['core'], 'No core data: untouched.' );
		$this->assertSame( array( 'hello.php' => '2.0.0' ), $notified['plugin'], 'Plugins were not checked.' );
		$this->assertSame( array(), $notified['theme'], 'Themes were checked and none is waiting.' );
		$this->assertSame( array( 'thing' => '5' ), $notified['addon'] );
	}

	/**
	 * Add-on item types survive normalisation, and junk is dropped.
	 */
	public function test_add_on_item_types_survive_normalise() {
		$state = sc_wpun()->state()->normalise(
			array(
				'channels' => array(
					'one' => array(
						'notified'     => array(
							'addon' => array(
								'a'   => '1',
								'b'   => 2,
								'bad' => array( 'x' ),
							),
							'junk'  => 'text',
						),
						'skip_pending' => 1,
					),
				),
			)
		);

		$this->assertSame(
			array(
				'notified'     => array(
					'addon' => array(
						'a' => '1',
						'b' => '2',
					),
				),
				'skip_pending' => true,
			),
			$state['channels']['one']
		);
		$this->assertArrayNotHasKey( 'legacy_notified', $state );
	}

	/**
	 * The details kept with what was notified are cleaned, and left out when there are none.
	 */
	public function test_notified_meta_is_normalised() {
		$state = sc_wpun()->state()->normalise(
			array(
				'channels' => array(
					'one' => array(
						'notified'      => array( 'plugin' => array( 'a.php' => '2' ) ),
						'notified_meta' => array(
							'plugin' => array(
								'a.php' => array(
									'name'            => 5,
									'current_version' => array( 'x' ),
									'links'           => array(
										'details' => 'https://example.com',
										'bad'     => array( 'x' ),
									),
									'time'            => '1700000000',
								),
								'b.php' => 'junk',
								'c.php' => array( 'time' => '0.5' ),
								'd.php' => array( 'snoozed' => 1 ),
								'e.php' => array( 'snoozed' => 0 ),
							),
							'theme'  => 'junk',
						),
					),
					'two' => array( 'notified_meta' => 'junk' ),
				),
			)
		);

		$this->assertSame(
			array(
				'plugin' => array(
					'a.php' => array(
						'name'            => '5',
						'current_version' => '',
						'links'           => array( 'details' => 'https://example.com' ),
						'time'            => 1700000000,
					),
					'c.php' => array(
						'name'            => '',
						'current_version' => '',
						'links'           => array(),
						'time'            => 0,
					),
					'd.php' => array(
						'name'            => '',
						'current_version' => '',
						'links'           => array(),
						'time'            => 0,
						'snoozed'         => true,
					),
					'e.php' => array(
						'name'            => '',
						'current_version' => '',
						'links'           => array(),
						'time'            => 0,
					),
				),
			),
			$state['channels']['one']['notified_meta']
		);
		$this->assertArrayNotHasKey( 'notified_meta', $state['channels']['two'] );
	}

	/**
	 * Skipping pending also clears the details of what was notified.
	 */
	public function test_skip_pending_clears_the_notified_meta() {
		$state                      = sc_wpun()->state()->get();
		$state['channels']['email'] = array(
			'notified'      => array( 'plugin' => array( 'a.php' => '2' ) ),
			'notified_meta' => array(
				'plugin' => array(
					'a.php' => array(
						'name'            => 'A',
						'current_version' => '1',
						'links'           => array(),
						'time'            => 5,
					),
				),
			),
			'skip_pending'  => false,
		);
		sc_wpun()->state()->save( $state );

		sc_wpun()->state()->skip_pending( 'email' );

		$this->assertArrayNotHasKey( 'notified_meta', $this->stored_state()['channels']['email'] );
	}

	/**
	 * Skipping pending: the first run records without sending, the next sends only new items, and a disabled instance keeps the flag.
	 */
	public function test_skip_pending() {
		$this->save_settings();
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		sc_wpun()->state()->skip_pending( 'email' );

		$this->run_check();
		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php' ) );
		$this->assertFalse( $this->stored_state()['channels']['email']['skip_pending'] );

		$this->set_updates(
			'99.0.0',
			array(
				'hello.php'      => array( 'new_version' => '2.0.0' ),
				self::OWN_PLUGIN => array( 'new_version' => '4.5.6' ),
			)
		);
		$this->run_check();
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertStringNotContainsString( 'Hello Dolly', $this->sent_mail()->body );

		$this->save_settings( array( 'email_notifications' => 0 ) );
		sc_wpun()->state()->skip_pending( 'email' );
		$this->run_check();
		$this->assertTrue( $this->stored_state()['channels']['email']['skip_pending'] );
		$this->assertSame( array(), $this->channel_notified() );
	}

	/**
	 * An instance whose channel is not available keeps its flag.
	 */
	public function test_skip_pending_waits_for_the_channel() {
		$this->save_settings();
		sc_wpun_channels()->save( new Channel_Instance( 'chat', 'slack', 'Chat', true ) );
		$this->set_updates( '99.0.0' );
		sc_wpun()->state()->skip_pending( 'chat' );

		$this->run_check();

		$this->assertTrue( $this->stored_state()['channels']['chat']['skip_pending'] );
	}

	/**
	 * Forgetting an instance in the state removes only it.
	 */
	public function test_forget_channel() {
		$this->save_channel_state( 'a', array( 'core' => array( 'core' => '1' ) ) );
		$this->save_channel_state( 'b', array( 'core' => array( 'core' => '1' ) ) );

		sc_wpun()->state()->forget_channel( 'a' );

		$this->assertSame( array( 'b' ), array_keys( $this->stored_state()['channels'] ) );
	}

	/**
	 * A test send never touches the state.
	 */
	public function test_a_test_send_does_not_touch_the_state() {
		$this->save_settings();
		$this->save_state( 1234 );
		$before = $this->stored_state();
		$test   = new WPUN\Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array(), array( 'is_test' => true ) );

		sc_wpun()->dispatcher()->send_one( $test, sc_wpun_channels()->get( 'email' ) );

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( $before, $this->stored_state() );
	}

	/**
	 * Third-party callbacks on the public filters still decide what is sent.
	 */
	public function test_third_party_filters_still_remove_items() {
		$this->save_settings();
		$this->set_updates(
			null,
			array(
				'hello.php'      => array( 'new_version' => '2.0.0' ),
				self::OWN_PLUGIN => array( 'new_version' => '4.5.6' ),
			)
		);
		add_filter(
			'sc_wpun_plugins_need_update',
			static function ( $plugins ) {
				unset( $plugins['hello.php'] );
				return $plugins;
			}
		);

		$this->run_check();

		$this->assertStringNotContainsString( 'Hello Dolly', $this->sent_mail()->body );
		$this->assertStringContainsString( 'WP Updates Notifier from version ', $this->sent_mail()->body );
		$this->assertSame( '', $this->notified_version( 'plugin', 'hello.php' ) );
	}

	/**
	 * The deprecated facade filters remove an entry only when every stored instance was told that version.
	 */
	public function test_the_deprecated_facade_filters() {
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::check_plugins_against_notified' );
		$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::check_themes_against_notified' );
		$this->save_settings();
		$this->add_email( 'second', 'two@example.org' );
		$plugins = array(
			'hello.php' => (object) array( 'new_version' => '2.0.0' ),
			'other.php' => (object) array( 'new_version' => '1.0.0' ),
		);
		$themes  = array( 't' => array( 'new_version' => '3' ) );
		$plugin  = $this->plugin();

		$this->assertSame( $plugins, $plugin->check_plugins_against_notified( $plugins ), 'No state: unchanged.' );

		$this->save_channel_state(
			'email',
			array(
				'plugin' => array(
					'hello.php' => '2.0.0',
					'other.php' => '1.0.0',
				),
				'theme'  => array( 't' => '3' ),
			)
		);
		$this->assertSame( $plugins, $plugin->check_plugins_against_notified( $plugins ), 'Only one instance was told.' );

		$this->save_channel_state(
			'second',
			array(
				'plugin' => array(
					'hello.php' => '2.0.0',
					'other.php' => '0.9',
				),
				'theme'  => array( 't' => '3' ),
			)
		);
		$this->assertSame( array( 'other.php' => $plugins['other.php'] ), $plugin->check_plugins_against_notified( $plugins ) );
		$this->assertSame( array(), $plugin->check_themes_against_notified( $themes ) );
		$this->assertSame( 'junk', $plugin->check_plugins_against_notified( 'junk' ) );
		$this->assertSame( array( 'x' => 'junk' ), $plugin->check_plugins_against_notified( array( 'x' => 'junk' ) ) );
	}

	/**
	 * Recording a delivery keeps what the instance was already told, and its reminder.
	 */
	public function test_recording_a_delivery_keeps_the_rest_of_the_entry() {
		$this->save_channel_state( 'email', array( 'plugin' => array( 'hello.php' => '2.0.0' ) ) );
		$state                                       = $this->stored_state();
		$state['channels']['email']['last_reminder'] = 12345;
		sc_wpun()->network_context()->update_option( 'sc_wpun_state', $state );

		sc_wpun()->state()->record_delivery(
			Delivery_Result::success( 'Sent' ),
			new Notification( 'Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', array() ),
			sc_wpun_channels()->get( 'email' )
		);

		$entry = $this->stored_state()['channels']['email'];
		$this->assertSame( array( 'plugin' => array( 'hello.php' => '2.0.0' ) ), $entry['notified'] );
		$this->assertSame( 12345, $entry['last_reminder'] );
		$this->assertTrue( $entry['last_result']['success'] );
	}

	/**
	 * Recording a delivery for an instance with no entry yet starts one that has told it nothing.
	 */
	public function test_recording_a_delivery_starts_an_entry() {
		$this->save_settings();
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );

		sc_wpun()->state()->record_delivery(
			Delivery_Result::failure( 'Down' ),
			new Notification( 'Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', array() ),
			sc_wpun_channels()->get( 'email' )
		);

		$entry = $this->stored_state()['channels']['email'];
		$this->assertSame( array(), $entry['notified'] );
		$this->assertFalse( $entry['skip_pending'] );
		$this->assertFalse( $entry['last_result']['success'] );
	}

	/**
	 * A stored delivery time below one second is not a time: the last result is dropped.
	 */
	public function test_a_fractional_delivery_time_is_dropped() {
		$state = sc_wpun()->state()->normalise( array( 'channels' => array( 'one' => array( 'last_result' => array( 'time' => '0.5' ) ) ) ) );

		$this->assertArrayNotHasKey( 'last_result', $state['channels']['one'] );
	}

	/**
	 * State from before 2.0.0 is read whatever it lacks: a missing core version is empty, a number is text.
	 */
	public function test_the_old_global_list_is_normalised() {
		$state = sc_wpun()->state()->normalise( array( 'notified' => array( 'plugin' => array( 'a.php' => 3 ) ) ) );
		$this->assertSame(
			array(
				'core'   => '',
				'plugin' => array( 'a.php' => '3' ),
				'theme'  => array(),
			),
			$state['legacy_notified']
		);

		$state = sc_wpun()->state()->normalise( array( 'notified' => array( 'core' => 6 ) ) );
		$this->assertSame( '6', $state['legacy_notified']['core'] );
	}

	/**
	 * The version list helper is public for the code that reads old state.
	 */
	public function test_versions_are_normalised_to_text() {
		$this->assertSame(
			array(
				'a' => '1',
				'b' => '2',
			),
			sc_wpun()->state()->normalise_versions(
				array(
					'a' => 1,
					'b' => '2',
					'c' => array(),
				)
			)
		);
		$this->assertSame( array(), sc_wpun()->state()->normalise_versions( 'junk' ) );
	}

	/**
	 * A reminder time below one second is not a time.
	 */
	public function test_a_fractional_reminder_time_is_dropped() {
		$state = sc_wpun()->state()->normalise( array( 'channels' => array( 'one' => array( 'last_reminder' => '0.5' ) ) ) );

		$this->assertArrayNotHasKey( 'last_reminder', $state['channels']['one'] );
	}

	/**
	 * A delivery is recorded only for a real result, notification and instance.
	 */
	public function test_a_delivery_with_something_else_is_not_recorded() {
		$this->save_settings();
		sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
		$result       = Delivery_Result::success( 'Sent' );
		$notification = new Notification( 'Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', array() );
		$instance     = sc_wpun_channels()->get( 'email' );

		sc_wpun()->state()->record_delivery( $result, 'junk', $instance );
		sc_wpun()->state()->record_delivery( $result, $notification, 'junk' );
		sc_wpun()->state()->record_delivery( 'junk', $notification, $instance );

		$this->assertArrayNotHasKey( 'email', $this->stored_state()['channels'] ?? array() );
	}

	/**
	 * A time to wait below one second is not a time.
	 */
	public function test_a_fractional_retry_time_is_dropped() {
		$state = sc_wpun()->state()->normalise( array( 'channels' => array( 'one' => array( 'retry_at' => '0.5' ) ) ) );

		$this->assertArrayNotHasKey( 'retry_at', $state['channels']['one'] );
	}

	/**
	 * A list of what was told that is not a list is read as nothing.
	 */
	public function test_a_notified_list_that_is_not_a_list_is_nothing() {
		$state = sc_wpun()->state()->normalise( array( 'channels' => array( 'one' => array( 'notified' => 'junk' ) ) ) );

		$this->assertSame( array(), $state['channels']['one']['notified'] );
	}
}
