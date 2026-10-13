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
		unset( $_POST['submitwithemail'], $_POST['submitwithslack'], $_POST['option_page'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
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
		$_POST['_wpnonce']        = $_REQUEST['_wpnonce'];
		$_POST['option_page']     = 'sc_wpun_settings';
		$_POST['submitwithemail'] = 'Save settings with test email';

		$this->plugin()->sc_wpun_settings_validate( $this->settings() );

		$this->assertFalse( get_transient( 'sc_wpun_send_test_email' ) );
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
		$this->save_settings();
		$this->save_state( array(), $timestamp );

		$html = $this->render_settings_page();

		$this->assertStringContainsString( wp_date( get_option( 'time_format' ), $timestamp ), $html );
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
}
