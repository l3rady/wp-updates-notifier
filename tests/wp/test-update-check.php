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
		$this->assertGreaterThan( 0, $this->stored_state()['last_check_time'] );
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
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ) );
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
		$this->save_settings();
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '99.0.0' ) ) );
		$this->set_updates();

		$this->plugin()->do_update_check();

		$this->assertSame( '', $this->notified_version( 'core', 'core' ) );
	}

	/**
	 * A plugin update is reported with its name and both versions.
	 */
	public function test_a_plugin_update_is_emailed() {
		$this->save_settings( array( 'notify_plugins' => 'all' ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$body = $this->sent_mail()->body;
		$this->assertStringContainsString( 'Hello Dolly', $body );
		$this->assertStringContainsString( '2.0.0', $body );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php' ) );
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
		$this->save_settings( array( 'notify_plugins' => 'none' ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * "Only active plugins" ignores updates for plugins that are not active.
	 */
	public function test_only_active_plugins_when_asked() {
		$this->save_settings( array( 'notify_plugins' => 'active' ) );
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
		$this->assertSame( '99.3', $this->notified_version( 'theme', $theme ) );
	}

	/**
	 * "Only active themes" keeps the active theme only.
	 */
	public function test_only_the_active_theme_when_asked() {
		list( $active, $other ) = $this->installed_themes();
		$this->save_settings( array( 'notify_themes' => 'active' ) );
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
	 * A stored, enabled Slack instance is kept but never posted to; the email still goes out.
	 */
	public function test_a_stored_slack_instance_gets_no_request_and_email_still_sends() {
		$this->save_settings(
			array(
				'slack_notifications'    => 1,
				'slack_webhook_url'      => self::SLACK_URL,
				'slack_channel_override' => '#ops',
			)
		);
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertCount( 0, $this->requests_to( 'hooks.slack.com' ) );
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( self::SLACK_URL, sc_wpun_channels()->get( 'slack' )->get_setting( 'webhook_url' ) );
	}

	/**
	 * Every enabled email instance gets the mail, a disabled one does not.
	 */
	public function test_every_enabled_email_instance_is_sent_to() {
		$this->save_settings( array( 'notify_to' => 'ops@example.org' ) );
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'client', 'email', 'Client', true, array( 'to' => 'client@example.org' ) ) );
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'off', 'email', 'Off', false, array( 'to' => 'off@example.org' ) ) );
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertSame( 2, $this->sent_mail_count() );
		$recipients = array(
			tests_retrieve_phpmailer_instance()->get_recipient( 'to', 0 )->address,
			tests_retrieve_phpmailer_instance()->get_recipient( 'to', 1 )->address,
		);
		sort( $recipients );
		$this->assertSame( array( 'client@example.org', 'ops@example.org' ), $recipients );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ) );
	}

	/**
	 * An unavailable Slack instance is not a delivery: with the email failing too, nothing is recorded and the next run retries.
	 */
	public function test_an_unavailable_instance_does_not_count_as_delivered() {
		$this->save_settings(
			array(
				'slack_notifications' => 1,
				'slack_webhook_url'   => self::SLACK_URL,
			)
		);
		$this->set_updates( '99.0.0' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$this->plugin()->do_update_check();

		$this->assertSame( '', $this->notified_version( 'core', 'core' ) );

		remove_filter( 'pre_wp_mail', '__return_false' );
		$this->plugin()->do_update_check();
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ) );
		$this->assertSame( 1, $this->sent_mail_count() );
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
	 * Run the check and return the items it built, before any instance got its share.
	 *
	 * @return WPUN\Notification_Item[]
	 */
	private function checked_items() {
		$items = array();
		add_filter(
			'sc_wpun_notification',
			static function ( $notification ) use ( &$items ) {
				$items = $notification->get_items();
				return $notification;
			}
		);
		$this->plugin()->do_update_check();

		return $items;
	}

	/**
	 * The facts about a plugin update are text, whatever type the update data had them in.
	 */
	public function test_plugin_flags_are_text() {
		$this->save_settings();
		$this->set_updates(
			null,
			array(
				'hello.php' => array(
					'new_version'  => '2.0',
					'tested'       => 6,
					'requires_php' => 8,
					'requires'     => 5.5,
				),
			)
		);

		$flags = $this->checked_items()[0]->get_flags();

		$this->assertSame( '6', $flags['tested'] );
		$this->assertSame( '8', $flags['requires_php'] );
		$this->assertSame( '5.5', $flags['requires_wp'] );
	}

	/**
	 * A slug on WordPress.org is lowercase letters, digits and dashes, all of it; anything else gets no link.
	 */
	public function test_a_wporg_slug_must_be_valid_all_through() {
		$this->save_settings();

		foreach ( array( 'Bad Slug', 'good slug', "good\nslug" ) as $slug ) {
			$this->set_updates(
				null,
				array(
					'hello.php' => array(
						'new_version' => '2.0',
						'slug'        => $slug,
						'url'         => '',
					),
				)
			);
			$this->assertSame( array(), $this->checked_items()[0]->get_links(), $slug );
		}

		$this->set_updates(
			null,
			array(
				'hello.php' => array(
					'new_version' => '2.0',
					'slug'        => 'good-slug-2',
				),
			)
		);
		$this->assertSame( 'https://wordpress.org/plugins/good-slug-2/', $this->checked_items()[0]->get_links()['details'] );
	}

	/**
	 * Entries that are not updates are left out without hiding the ones after them.
	 */
	public function test_unusable_plugin_updates_are_skipped() {
		$updates = $this->plugin_checker()->normalise_plugin_updates(
			array(
				'junk'      => 'text',
				'empty.php' => array( 'new_version' => '' ),
				'hello.php' => array( 'new_version' => 2 ),
			)
		);

		$this->assertSame( array( 'hello.php' ), array_keys( $updates ) );
		$this->assertSame( '2', $updates['hello.php']->new_version );
	}

	/**
	 * The update checker.
	 *
	 * @return WPUN\Update_Checker
	 */
	private function plugin_checker() {
		return sc_wpun()->update_checker();
	}

	/**
	 * A theme whose folder name is a number is still the active theme.
	 */
	public function test_a_theme_named_with_digits_is_active() {
		update_option( 'stylesheet', '2020' );
		update_option( 'template', '2020' );
		remove_all_actions( 'wp_update_themes' ); // Core would ask api.wordpress.org about a theme that is not installed.
		$this->save_settings();
		$this->set_updates( null, array(), array( '2020' => array( 'new_version' => '3.0' ) ) );

		$items = $this->checked_items();

		$this->assertSame( '2020', $items[0]->get_key() );
		$this->assertTrue( $items[0]->get_flags()['active'] );
	}

	/**
	 * A version that came as a number counts as told when the text of it was.
	 */
	public function test_a_numeric_version_counts_as_notified() {
		$this->save_settings();
		$this->save_channel_state( 'email', array( 'plugin' => array( 'hello.php' => '3' ) ) );
		$plugins = array( 'hello.php' => (object) array( 'new_version' => 3 ) );

		$this->assertSame( array(), $this->plugin_checker()->check_plugins_against_notified( $plugins ) );
	}

	/**
	 * A version that cannot be compared does not stop the check of the ones after it.
	 */
	public function test_an_update_without_a_version_does_not_end_the_notified_check() {
		$this->save_settings();
		$this->save_channel_state( 'email', array( 'plugin' => array( 'b.php' => '3' ) ) );
		$plugins = array(
			'a.php' => array( 'new_version' => array( 'x' ) ),
			'b.php' => array( 'new_version' => '3' ),
		);

		$this->assertSame( array( 'a.php' ), array_keys( $this->plugin_checker()->check_plugins_against_notified( $plugins ) ) );
	}

	/**
	 * Core is asked to refresh its core update data before the check reads it.
	 */
	public function test_core_is_asked_to_refresh_its_update_data() {
		$this->save_settings();
		$this->set_updates();
		$asked = 0;
		add_action(
			'wp_version_check',
			static function () use ( &$asked ) {
				++$asked;
			}
		);

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $asked );
	}

	/**
	 * A fact about a plugin update that is false or empty is not a fact.
	 */
	public function test_empty_plugin_facts_are_left_out() {
		$this->save_settings();
		$this->set_updates(
			null,
			array(
				'hello.php' => array(
					'new_version'  => '2.0',
					'tested'       => false,
					'requires_php' => '',
					'requires'     => '5.5',
				),
			)
		);

		$flags = $this->checked_items()[0]->get_flags();

		$this->assertArrayNotHasKey( 'tested', $flags );
		$this->assertArrayNotHasKey( 'requires_php', $flags );
		$this->assertSame( '5.5', $flags['requires_wp'] );
	}

	/**
	 * The global veto is public, for code that asks the same question.
	 */
	public function test_should_notify_is_public() {
		$item = new WPUN\Notification_Item(
			array(
				'type'            => 'plugin',
				'key'             => 'x/x.php',
				'name'            => 'X',
				'current_version' => '1',
				'new_version'     => '2',
			)
		);

		$this->assertTrue( $this->plugin_checker()->should_notify( $item ) );
		add_filter( 'sc_wpun_should_notify_item', '__return_false' );
		$this->assertFalse( $this->plugin_checker()->should_notify( $item ) );
	}

	/**
	 * Run the check and return what PHP reported while it ran.
	 *
	 * @return string[]
	 */
	private function php_messages_during_a_check() {
		$messages = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Collecting what PHP reports.
			static function ( $number, $message ) use ( &$messages ) {
				$messages[] = $message;
				return true;
			}
		);
		try {
			$this->plugin()->do_update_check();
		} finally {
			restore_error_handler();
		}

		return $messages;
	}

	/**
	 * Update data that has no list of updates is read as no updates, without a PHP warning.
	 */
	public function test_update_data_without_a_response_is_no_updates() {
		$this->save_settings();
		$this->set_updates();
		set_site_transient( 'update_plugins', (object) array( 'last_checked' => time() ) );
		set_site_transient( 'update_themes', (object) array( 'last_checked' => time() ) );
		remove_all_actions( 'wp_update_plugins' );
		remove_all_actions( 'wp_update_themes' );

		$this->assertSame( array(), $this->php_messages_during_a_check() );
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * Entries of the active plugins that are not file names are ignored.
	 */
	public function test_active_plugins_that_are_not_text_are_ignored() {
		update_option( 'active_plugins', array( 'hello.php', array( 'junk' ), 5 ) );
		$this->save_settings( array( 'notify_plugins' => 'active' ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->assertSame( array(), $this->php_messages_during_a_check() );
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * The link of a theme update is text, whatever type it had.
	 */
	public function test_a_theme_update_link_is_text() {
		list( $theme ) = $this->installed_themes();
		$this->save_settings();
		$this->set_updates( null, array(), array( $theme => array( 'url' => 5 ) ) );

		$this->assertSame( array( 'details' => '5' ), $this->checked_items()[0]->get_links() );
	}

	/**
	 * An offer without a usable version does not hide the offer after it.
	 */
	public function test_a_core_offer_without_a_version_is_skipped() {
		$this->save_settings();
		$this->set_updates();
		$core                  = new stdClass();
		$core->last_checked    = time();
		$core->version_checked = wp_get_wp_version();
		$core->translations    = array();
		$core->updates         = array(
			(object) array(
				'response' => 'upgrade',
				'current'  => false,
			),
			(object) array(
				'response' => 'upgrade',
				'current'  => '99.0.0',
			),
		);
		set_site_transient( 'update_core', $core );

		$items = $this->checked_items();

		$this->assertSame( '99.0.0', $items[0]->get_new_version() );
	}

	/**
	 * Core is asked to refresh its plugin and theme data before they are read.
	 */
	public function test_core_is_asked_to_refresh_plugin_and_theme_data() {
		$this->save_settings();
		$this->set_updates();
		$asked = array();
		foreach ( array( 'wp_update_plugins', 'wp_update_themes' ) as $hook ) {
			remove_all_actions( $hook );
			add_action(
				$hook,
				static function () use ( &$asked, $hook ) {
					$asked[] = $hook;
				}
			);
		}

		$this->plugin()->do_update_check();

		$this->assertSame( array( 'wp_update_plugins', 'wp_update_themes' ), $asked );
	}

	/**
	 * An update that is an object of another class is read like any other.
	 */
	public function test_an_update_object_of_another_class_is_read() {
		$updates = $this->plugin_checker()->normalise_plugin_updates( array( 'hello.php' => new ArrayObject( array( 'new_version' => '2.0' ), ArrayObject::ARRAY_AS_PROPS ) ) );

		$this->assertSame( array(), $updates, 'An ArrayObject has no public properties of its own.' );

		$entry   = new class() {
			/**
			 * Version.
			 *
			 * @var string
			 */
			public $new_version = '2.0';
		};
		$updates = $this->plugin_checker()->normalise_plugin_updates( array( 'hello.php' => $entry ) );

		$this->assertSame( array( 'hello.php' ), array_keys( $updates ) );
		$this->assertSame( '2.0', $updates['hello.php']->new_version );

		$updates = $this->plugin_checker()->normalise_plugin_updates(
			array(
				'hello.php' => array(
					'new_version' => '2.0',
					'url'         => 5,
					'slug'        => 6,
					'id'          => 7,
				),
			)
		);
		$this->assertSame( array( '5', '6', '7' ), array( $updates['hello.php']->url, $updates['hello.php']->slug, $updates['hello.php']->id ) );
	}

	/**
	 * A theme entry that is not an update does not hide the ones after it.
	 */
	public function test_an_unusable_theme_update_is_skipped() {
		list( $first, $second ) = $this->installed_themes();
		$this->save_settings();
		$this->set_updates( null, array(), array( $second => array( 'new_version' => '3.0' ) ) );
		$transient           = get_site_transient( 'update_themes' );
		$transient->response = array( $first => 'junk' ) + $transient->response;
		set_site_transient( 'update_themes', $transient );

		$items = $this->checked_items();

		$this->assertCount( 1, $items );
		$this->assertSame( $second, $items[0]->get_key() );
	}

	/**
	 * A plugin is on WordPress.org by its id or by its link, and nothing else.
	 */
	public function test_a_plugin_is_on_wordpress_org_by_id_or_link() {
		$this->save_settings();

		$this->set_updates(
			null,
			array(
				'hello.php' => array(
					'new_version' => '2.0',
					'id'          => 'elsewhere/hello',
					'slug'        => 'hello',
					'url'         => 'https://WordPress.org/plugins/hello/',
				),
			)
		);
		$this->assertArrayHasKey( 'changelog', $this->checked_items()[0]->get_links(), 'By its link.' );

		$this->set_updates(
			null,
			array(
				'hello.php' => array(
					'new_version' => '2.0',
					'id'          => 'elsewhere/hello',
					'slug'        => 'hello',
					'url'         => 'https://example.org/hello/',
				),
			)
		);
		$this->assertSame( array( 'details' => 'https://example.org/hello/' ), $this->checked_items()[0]->get_links(), 'Not on WordPress.org.' );

		$this->set_updates(
			null,
			array(
				'hello.php' => array(
					'new_version' => '2.0',
					'id'          => 'elsewhere/hello',
					'slug'        => 'hello',
					'url'         => 'https://example.org/?from=https://WordPress.org/plugins/hello/',
				),
			)
		);
		$this->assertSame( array( 'details' => 'https://example.org/?from=https://WordPress.org/plugins/hello/' ), $this->checked_items()[0]->get_links(), 'The address only mentions WordPress.org.' );
	}
}
