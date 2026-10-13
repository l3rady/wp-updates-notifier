<?php
/**
 * Tests that document bugs which are still open.
 *
 * Each one asserts the CORRECT behaviour, so it fails today and passes once the bug is fixed.
 * They are excluded from the normal run by the `known-bug` group in phpunit.xml.dist; run them
 * with `make test-known-bugs` (they are expected to fail). The fix for an issue removes the
 * `@group known-bug` tag from its tests, so they join the normal run and guard against regressions.
 *
 * @package wp-updates-notifier
 */

/**
 * One section per bug issue.
 *
 * @group known-bug
 */
class Test_Known_Bugs extends WPUN_Test_Case {

	const BASENAME = 'wp-updates-notifier/class-sc-wp-updates-notifier.php';

	/**
	 * Plugin info for the one plugin update these tests use.
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

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
	 * Clean up request state some tests set.
	 */
	public function tear_down() {
		unset( $_POST['submitwithemail'], $_POST['submitwithslack'], $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * Two installed themes, to stand in for a parent and a child.
	 *
	 * @return array
	 */
	private function two_themes() {
		$slugs = array_keys( wp_get_themes() );

		return array( $slugs[0], $slugs[1] );
	}

	/**
	 * Render the settings page as an administrator.
	 *
	 * @return string
	 */
	private function render_settings_page() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$plugin = $this->plugin();
		$plugin->admin_settings_init();

		ob_start();
		$plugin->settings_page();

		return ob_get_clean();
	}

	/**
	 * #64: a saved frequency that is not a known schedule leaves no event, without a word.
	 */
	public function test_64_an_unknown_saved_frequency_still_leaves_a_schedule() {
		$this->save_settings( array( 'frequency' => 'schedule_from_a_removed_plugin' ) );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );

		$this->plugin()->enable_cron();

		$this->assertNotFalse( wp_next_scheduled( 'sc_wpun_update_check' ) );
	}

	/**
	 * #65: the plain-text email is HTML-escaped, so `&` arrives as an entity.
	 */
	public function test_65_plain_text_email_has_no_html_entities() {
		update_option( 'blogname', 'Bob & Sons' );
		$this->save_settings();
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$body = $this->sent_mail()->body;
		$this->assertStringContainsString( 'Bob & Sons', $body );
		$this->assertStringNotContainsString( '&amp;', $body );
	}

	/**
	 * #66: a delivered Slack message returns false, because the result is `is_wp_error()`.
	 */
	public function test_66_a_delivered_slack_message_returns_true() {
		$this->save_settings( array( 'slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X' ) );
		$this->fake_http( 'hooks.slack.com', $this->fake_response( 200, 'ok' ) );

		$this->assertTrue( $this->plugin()->send_slack_message( 'Hello' ) );
	}

	/**
	 * #66: a failed Slack request returns true.
	 */
	public function test_66_a_failed_slack_request_returns_false() {
		$this->save_settings( array( 'slack_webhook_url' => 'https://hooks.slack.com/services/T/B/X' ) );
		$this->fake_http( 'hooks.slack.com', new WP_Error( 'http_request_failed', 'Timed out' ) );

		$this->assertFalse( $this->plugin()->send_slack_message( 'Hello' ) );
	}

	/**
	 * #67: the settings save queues a test send in a global transient that the next page load consumes.
	 */
	public function test_67_saving_settings_does_not_queue_a_test_in_a_global_transient() {
		$this->save_settings();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce']     = wp_create_nonce( 'sc_wpun_settings-options' );
		$_POST['submitwithemail'] = 'Save settings with test email';

		$this->plugin()->sc_wpun_settings_validate( $this->settings() );

		$this->assertFalse( get_transient( 'sc_wpun_send_test_email' ) );
	}

	/**
	 * #68: when the API offers nothing (no `updates[0]`), the core check reads an index that is not there.
	 */
	public function test_68_the_core_check_survives_empty_update_data() {
		$this->save_settings();
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

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * #69: the changelog link points at the old `changelog/` page.
	 */
	public function test_69_the_changelog_link_uses_the_developers_anchor() {
		$this->save_settings();
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		$body = $this->sent_mail()->body;
		$this->assertStringContainsString( 'https://wordpress.org/plugins/hello/#developers', $body );
		$this->assertStringNotContainsString( 'changelog/', $body );
	}

	/**
	 * #69: updaters for plugins outside WordPress.org add entries with no `url` or `slug`.
	 */
	public function test_69_a_plugin_update_without_url_or_slug_does_not_warn() {
		$this->save_settings();
		$this->set_updates();
		$updates           = get_site_transient( 'update_plugins' );
		$updates->response = array(
			'hello.php' => (object) array(
				'plugin'      => 'hello.php',
				'new_version' => '2.0.0',
				'package'     => '',
			),
		);
		set_site_transient( 'update_plugins', $updates );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * #70: one blocking `plugins_api()` request per plugin, for data the update transient already holds.
	 */
	public function test_70_checking_plugin_updates_makes_no_plugin_info_request() {
		$this->save_settings();
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		$this->assertCount( 0, $this->requests_to( 'plugins/info' ) );
	}

	/**
	 * #71: "only active themes" compares with `template`, which is the parent theme.
	 */
	public function test_71_the_active_child_theme_is_notified_about() {
		list( $parent, $child ) = $this->two_themes();
		update_option( 'template', $parent );
		update_option( 'stylesheet', $child );
		$this->save_settings( array( 'notify_themes' => 2 ) );
		$this->set_updates( null, array(), array( $child => array( 'new_version' => '99.3' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * #71: network-activated plugins are not in `active_plugins`.
	 */
	public function test_71_a_network_activated_plugin_counts_as_active() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}

		update_option( 'active_plugins', array() );
		update_site_option( 'active_sitewide_plugins', array( 'hello.php' => time() ) );
		$this->save_settings( array( 'notify_plugins' => 2 ) );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * #72: the frequency field closes its select with `<select>`.
	 */
	public function test_72_every_select_on_the_settings_page_is_closed() {
		$html = $this->render_settings_page();

		$this->assertSame( substr_count( $html, '<select' ), substr_count( $html, '</select>' ) );
	}

	/**
	 * #72: the page heading is an h2.
	 */
	public function test_72_the_settings_page_has_an_h1() {
		$this->assertStringContainsString( '<h1', $this->render_settings_page() );
	}

	/**
	 * #73: "Last scanned" is formatted in UTC, not the site's timezone.
	 */
	public function test_73_last_scanned_uses_the_site_timezone() {
		update_option( 'timezone_string', 'Pacific/Auckland' );
		$timestamp = gmmktime( 12, 0, 0, 1, 15, 2024 );
		$this->save_settings( array( 'last_check_time' => $timestamp ) );

		$html = $this->render_settings_page();

		$this->assertStringContainsString( wp_date( get_option( 'time_format' ), $timestamp ), $html );
	}

	/**
	 * #74: the sanitize callback calls `check_admin_referer()`, so any update of the option during an
	 * admin request without that nonce dies.
	 */
	public function test_74_updating_the_option_outside_the_settings_form_does_not_die() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->plugin()->admin_settings_init();
		unset( $_REQUEST['_wpnonce'] );

		update_option( 'sc_wpun_settings', $this->settings( array( 'notify_to' => 'new@example.org' ) ) );

		$this->assertSame( 'new@example.org', $this->stored_settings()['notify_to'] );
	}

	/**
	 * #74: missing fields give undefined index notices.
	 */
	public function test_74_partial_input_does_not_raise_notices() {
		$this->save_settings();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'sc_wpun_settings-options' );

		$result = $this->plugin()->sc_wpun_settings_validate( array() );

		$this->assertIsArray( $result );
	}

	/**
	 * #75: option values saved as strings (imports, `wp option update`) switch notifications off silently.
	 */
	public function test_75_string_valued_options_still_notify() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';
		update_option( 'sc_wpun_settings', $fixtures['1.6.0-string-values']['options'] );
		update_option( 'sc_wpun_settings_ver', '7.0' );
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * #76: the configured address is forced as the From address.
	 */
	public function test_76_the_from_address_is_not_forced() {
		$this->save_settings( array( 'notify_from' => 'ops@example.org' ) );
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertNotSame( 'ops@example.org', tests_retrieve_phpmailer_instance()->From );
	}

	/**
	 * #76: a failed send is marked as notified anyway, so it is never retried.
	 */
	public function test_76_a_failed_email_is_retried_on_the_next_check() {
		$this->save_settings( array( 'slack_notifications' => 0 ) );
		$this->set_updates( '99.0.0' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$this->plugin()->do_update_check();

		$this->assertSame( '', $this->stored_settings()['notified']['core'] );
	}

	/**
	 * #77: the nag is core's, and core shows it by `update_core`.
	 */
	public function test_77_the_nag_is_kept_for_users_who_can_update_core() {
		$this->save_settings( array( 'hide_updates' => 1 ) );
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $user )->add_cap( 'update_core' );
		wp_set_current_user( $user );
		add_action( 'admin_notices', 'update_nag', 3 );

		$this->plugin()->remove_update_nag_for_nonadmins();

		$this->assertNotFalse( has_action( 'admin_notices', 'update_nag' ) );
	}

	/**
	 * #78: uninstall leaves the cron event and the test transients behind.
	 */
	public function test_78_uninstall_removes_the_event_and_transients() {
		$this->save_settings();
		do_action( 'sc_wpun_enable_cron', 'daily' );
		set_transient( 'sc_wpun_send_test_email', 1 );
		set_transient( 'sc_wpun_send_test_slack', 1 );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', self::BASENAME );
		}

		include WP_PLUGIN_DIR . '/wp-updates-notifier/uninstall.php';

		$this->assertFalse( get_option( 'sc_wpun_settings' ) );
		$this->assertFalse( wp_next_scheduled( 'sc_wpun_update_check' ), 'The cron event must go.' );
		$this->assertFalse( get_transient( 'sc_wpun_send_test_email' ) );
		$this->assertFalse( get_transient( 'sc_wpun_send_test_slack' ) );
	}

	/**
	 * #78: on multisite, uninstall only cleans the site it runs on.
	 */
	public function test_78_uninstall_cleans_every_site_of_a_network() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}

		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		$this->save_settings();
		restore_current_blog();
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', self::BASENAME );
		}

		include WP_PLUGIN_DIR . '/wp-updates-notifier/uninstall.php';

		switch_to_blog( $blog_id );
		$this->assertFalse( get_option( 'sc_wpun_settings' ) );
		restore_current_blog();
	}
}
