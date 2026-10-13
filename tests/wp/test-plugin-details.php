<?php
/**
 * Plugin and theme entries in the notification (#69).
 *
 * @package wp-updates-notifier
 */

/**
 * Links and entries for plugins from WordPress.org and elsewhere.
 */
class Test_Plugin_Details extends WPUN_Test_Case {

	/**
	 * Fake the plugin information request core makes for WordPress.org plugins (until #70 removes it).
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
		$this->save_settings();
	}

	/**
	 * Replace the plugin update entries with raw ones, as a third-party updater would.
	 *
	 * @param array $response Entries keyed by plugin file.
	 */
	private function set_raw_plugin_updates( array $response ) {
		$this->set_updates();
		$updates           = get_site_transient( 'update_plugins' );
		$updates->response = $response;
		set_site_transient( 'update_plugins', $updates );
	}

	/**
	 * WordPress.org plugins link to their page and to the changelog on the #developers anchor.
	 */
	public function test_wordpress_org_plugins_link_to_the_developers_anchor() {
		$this->set_updates(
			null,
			array(
				'hello.php' => array(
					'id'          => 'w.org/plugins/hello-dolly',
					'slug'        => 'hello-dolly',
					'url'         => 'https://wordpress.org/plugins/hello-dolly/',
					'new_version' => '2.0.0',
				),
			)
		);

		$this->plugin()->do_update_check();

		$body = $this->sent_mail()->body;
		$this->assertStringContainsString( 'Details: https://wordpress.org/plugins/hello-dolly/', $body );
		$this->assertStringContainsString( 'Changelog: https://wordpress.org/plugins/hello-dolly/#developers', $body );
		$this->assertStringNotContainsString( 'changelog/', $body );
	}

	/**
	 * A plugin updated from elsewhere links to its own URL, with no made-up changelog link and no WordPress.org request.
	 */
	public function test_other_plugins_use_their_own_url() {
		$this->set_raw_plugin_updates(
			array(
				'hello.php' => (object) array(
					'plugin'      => 'hello.php',
					'new_version' => '2.0.0',
					'url'         => 'https://example.com/premium-plugin/',
					'package'     => '',
				),
			)
		);

		$this->plugin()->do_update_check();

		$body = $this->sent_mail()->body;
		$this->assertStringContainsString( 'Details: https://example.com/premium-plugin/', $body );
		$this->assertStringNotContainsString( 'Changelog:', $body );
		$this->assertCount( 0, $this->requests_to( 'plugins/info' ) );
	}

	/**
	 * An entry without `url` or `slug` is reported without warnings.
	 */
	public function test_a_plugin_update_without_url_or_slug_does_not_warn() {
		$this->set_raw_plugin_updates(
			array(
				'hello.php' => (object) array(
					'plugin'      => 'hello.php',
					'new_version' => '2.0.0',
					'package'     => '',
				),
			)
		);

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertStringNotContainsString( 'Details:', $this->sent_mail()->body );
	}

	/**
	 * Some updaters add arrays rather than objects; they are reported, and remembered like any other.
	 */
	public function test_array_entries_are_reported_once() {
		$this->set_raw_plugin_updates(
			array(
				'hello.php' => array(
					'plugin'      => 'hello.php',
					'new_version' => '2.0.0',
				),
			)
		);

		$this->plugin()->do_update_check();
		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( '2.0.0', $this->stored_state()['notified']['plugin']['hello.php'] );
	}

	/**
	 * Entries without a new version, or that are not entries at all, are skipped.
	 */
	public function test_unusable_entries_are_skipped() {
		// Core asks WordPress.org again if the entries name plugins that are not installed, so use installed ones.
		$this->set_raw_plugin_updates(
			array(
				'hello.php' => (object) array( 'plugin' => 'hello.php' ),
				'wp-updates-notifier/class-sc-wp-updates-notifier.php' => 'not an entry',
			)
		);
		$entries = $this->call_private(
			'normalise_plugin_updates',
			array(
				'x.php' => array( 'new_version' => '' ),
				'y.php' => 7,
			)
		);
		$this->assertSame( array(), $entries, 'An empty version or a scalar is not an entry.' );

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * Theme updates link to the theme page.
	 */
	public function test_theme_updates_link_to_the_theme_page() {
		$theme = array_keys( wp_get_themes() )[0];
		$this->set_updates( null, array(), array( $theme => array( 'new_version' => '99.3' ) ) );

		$this->plugin()->do_update_check();

		$this->assertStringContainsString( 'Details: https://wordpress.org/themes/' . $theme . '/', $this->sent_mail()->body );
	}
}
