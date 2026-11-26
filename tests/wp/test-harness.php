<?php
/**
 * The test setup itself.
 *
 * @package wp-updates-notifier
 */

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\SkippedTestError;
use PHPUnit\Framework\TestFailure;

/**
 * If these fail, nothing else in the suite means anything.
 */
class Test_Harness extends WPUN_Test_Case {

	/**
	 * The plugin under test is loaded, and from the directory WordPress uses as the plugins directory.
	 */
	public function test_the_plugin_is_loaded_from_the_plugins_directory() {
		$this->assertTrue( class_exists( 'SC_WP_Updates_Notifier' ) );

		$file = ( new ReflectionClass( 'SC_WP_Updates_Notifier' ) )->getFileName();
		$this->assertSame( WP_PLUGIN_DIR . '/wp-updates-notifier/includes/class-sc-wp-updates-notifier.php', $file );
	}

	/**
	 * Unfaked requests fail the test instead of reaching the network.
	 */
	public function test_unexpected_http_requests_are_blocked() {
		$this->http_fakes_probe();
	}

	/**
	 * A faked request is answered and recorded.
	 */
	public function test_faked_http_requests_are_answered_and_recorded() {
		$this->fake_http( 'example.test/ok', $this->fake_response( 200, 'fine' ) );

		$response = wp_remote_get( 'https://example.test/ok' );

		$this->assertSame( 'fine', wp_remote_retrieve_body( $response ) );
		$this->assertCount( 1, $this->requests_to( 'example.test' ) );
	}

	/**
	 * Mail goes to the mock mailer.
	 */
	public function test_mail_is_captured() {
		wp_mail( 'to@example.org', 'Subject', 'Body' );

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( 'Subject', $this->sent_mail()->subject );
	}

	/**
	 * Fixtures set the update transients so core makes no request.
	 */
	public function test_update_fixtures_prevent_core_requests() {
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );

		do_action( 'wp_version_check' );
		do_action( 'wp_update_plugins' );
		do_action( 'wp_update_themes' );

		$this->assertSame( array(), $this->http_requests, 'Core should read the fixtures, not call api.wordpress.org.' );
	}

	/**
	 * An item is the core update unless overridden.
	 */
	public function test_make_item_has_defaults_and_overrides() {
		$item = $this->make_item();
		$this->assertSame( 'core', $item->get_type() );
		$this->assertSame( 'core', $item->get_key() );
		$this->assertSame( 'WordPress', $item->get_name() );
		$this->assertSame( '6.0', $item->get_current_version() );
		$this->assertSame( '6.1', $item->get_new_version() );

		$plugin = $this->make_item(
			array(
				'type'        => 'plugin',
				'key'         => 'hello.php',
				'new_version' => '2.0',
			)
		);
		$this->assertSame( 'plugin', $plugin->get_type() );
		$this->assertSame( 'hello.php', $plugin->get_key() );
		$this->assertSame( '2.0', $plugin->get_new_version() );
		$this->assertSame( 'WordPress', $plugin->get_name() );
	}

	/**
	 * A notification has one default item unless given items, and carries its context.
	 */
	public function test_make_notification_has_defaults_and_takes_items_and_context() {
		$default = $this->make_notification();
		$this->assertCount( 1, $default->get_items() );
		$this->assertSame( 'core', $default->get_items()[0]->get_key() );
		$this->assertSame( 'Site', $default->get_site_name() );

		$items = array( $this->make_item( array( 'key' => 'a' ) ), $this->make_item( array( 'key' => 'b' ) ) );
		$two   = $this->make_notification( $items, array( 'is_test' => true ) );
		$this->assertSame( $items, $two->get_items() );
		$this->assertSame( array(), $this->make_notification( array() )->get_items() );
		$this->assertTrue( $two->is_test() );
		$this->assertFalse( $default->is_test() );
	}

	/**
	 * An instance is enabled, labelled from its type and not stored unless asked.
	 */
	public function test_make_instance_builds_and_optionally_saves() {
		$instance = $this->make_instance( 'webhook', array( 'url' => 'https://example.test/hook' ) );

		$this->assertSame( 'webhook', $instance->get_id() );
		$this->assertSame( 'webhook', $instance->get_type() );
		$this->assertSame( 'Webhook', $instance->get_label() );
		$this->assertTrue( $instance->is_enabled() );
		$this->assertSame( 'https://example.test/hook', $instance->get_setting( 'url' ) );
		$this->assertNull( $this->stored_instance( 'webhook' ) );

		$saved = $this->make_instance( 'webhook', array(), 'hook_2', true );
		$this->assertSame( 'hook_2', $saved->get_id() );
		$this->assertSame( 'webhook', $saved->get_type() );
		$this->assertNotNull( $this->stored_instance( 'hook_2' ) );
	}

	/**
	 * Invalid markup parses without a warning, and the caller's libxml setting is kept.
	 */
	public function test_dom_parses_invalid_markup_quietly_and_restores_libxml_state() {
		foreach ( array( false, true ) as $before ) {
			libxml_use_internal_errors( $before );

			$xpath = $this->dom( '<section><p id="a">One<p id="b">Two</section><custom-tag>x</custom-tag>' );

			$this->assertSame( $before, libxml_use_internal_errors( $before ) );
			$this->assertSame( array(), libxml_get_errors() );
			$this->assertSame( 2, $xpath->query( '//p' )->length );
		}
		libxml_use_internal_errors( false );
	}

	/**
	 * Render returns what the callback printed.
	 */
	public function test_render_returns_the_output() {
		$level = ob_get_level();

		$this->assertSame(
			'ab',
			$this->render(
				static function () {
					echo 'a';
					echo 'b';
				}
			)
		);
		$this->assertSame( '', $this->render( '__return_true' ) );
		$this->assertSame( $level, ob_get_level() );
	}

	/**
	 * Render closes its buffer when the callback throws, and the exception goes on.
	 */
	public function test_render_closes_the_buffer_when_the_callback_throws() {
		$level = ob_get_level();

		try {
			$this->render(
				static function () {
					echo 'partial';
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'The exception was swallowed.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertSame( $level, ob_get_level() );
	}

	/**
	 * Becoming an admin gives a current administrator.
	 */
	public function test_become_admin_sets_a_plain_administrator() {
		$id = $this->become_admin();

		$this->assertSame( $id, get_current_user_id() );
		$this->assertContains( 'administrator', get_userdata( $id )->roles );
		$this->assertTrue( current_user_can( 'manage_options' ) );
	}

	/**
	 * A fixture plugin is readable by WordPress, and a rewrite changes the version.
	 */
	public function test_fixture_plugin_writes_a_readable_plugin() {
		$this->fixture_plugin( 'wpun-harness-fixture/wpun-harness-fixture.php', '1.0', 'Harness Fixture' );
		$plugins = get_plugins();
		$this->assertSame( '1.0', $plugins['wpun-harness-fixture/wpun-harness-fixture.php']['Version'] );
		$this->assertSame( 'Harness Fixture', $plugins['wpun-harness-fixture/wpun-harness-fixture.php']['Name'] );

		$this->fixture_plugin( 'wpun-harness-fixture/wpun-harness-fixture.php', '2.0', 'Harness Fixture' );
		$this->assertSame( '2.0', get_plugins()['wpun-harness-fixture/wpun-harness-fixture.php']['Version'] );

		$this->fixture_plugin( 'wpun-harness-lone.php', '1.0' );
		$this->assertArrayHasKey( 'wpun-harness-lone.php', get_plugins() );
	}

	/**
	 * Removing the fixture plugins deletes the directory and the lone file, once.
	 */
	public function test_fixture_plugins_are_removed_by_the_cleanup() {
		$this->fixture_plugin( 'wpun-harness-fixture/wpun-harness-fixture.php', '1.0' );
		$this->fixture_plugin( 'wpun-harness-fixture/wpun-harness-fixture.php', '1.1' );
		$this->fixture_plugin( 'wpun-harness-lone.php', '1.0' );
		$this->assertDirectoryExists( WP_PLUGIN_DIR . '/wpun-harness-fixture' );
		$this->assertFileExists( WP_PLUGIN_DIR . '/wpun-harness-lone.php' );

		$this->remove_fixture_plugins();

		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/wpun-harness-fixture' );
		$this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/wpun-harness-lone.php' );
		$this->assertArrayNotHasKey( 'wpun-harness-lone.php', get_plugins() );
		$this->remove_fixture_plugins();
	}

	/**
	 * Remove-dir deletes the files and the directory, and ignores a missing directory.
	 */
	public function test_remove_dir_deletes_the_directory() {
		$dir = WP_PLUGIN_DIR . '/wpun-harness-dir';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/a.php', '<?php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$this->remove_dir( $dir );
		$this->remove_dir( $dir );

		$this->assertDirectoryDoesNotExist( $dir );
	}

	/**
	 * The site kind guards skip on the wrong kind of site only.
	 */
	public function test_require_guards_skip_on_the_wrong_kind_of_site() {
		$skips = array();
		foreach ( array( 'require_multisite', 'require_single_site' ) as $guard ) {
			try {
				$this->$guard();
				$skips[ $guard ] = null;
			} catch ( SkippedTestError $e ) {
				$skips[ $guard ] = $e->getMessage();
			}
		}

		$this->assertSame( is_multisite() ? null : 'Needs a multisite.', $skips['require_multisite'] );
		$this->assertSame( is_multisite() ? 'Needs a single site.' : null, $skips['require_single_site'] );
	}

	/**
	 * Network settings are stored with the email instance.
	 */
	public function test_save_network_settings_stores_settings_and_the_email_instance() {
		$this->save_network_settings( array( 'frequency' => 'daily' ) );

		$this->assertSame( 'daily', get_site_option( 'sc_wpun_settings' )['frequency'] );
		$this->assertNotNull( $this->stored_instance( 'email' ) );
	}

	/**
	 * The markup assertions pass for a match, with XPath and with text.
	 */
	public function test_markup_assertions_pass_for_both_kinds_of_needle() {
		$html = '<div class="box"><p id="a">Hello world</p></div>';

		$this->assertMarkupHas( '//p[@id="a"]', $html );
		$this->assertMarkupHas( '(//p)[1]', $html );
		$this->assertMarkupHas( './/p', $html );
		$this->assertMarkupHas( 'Hello world', $html );
		$this->assertMarkupLacks( '//p[@id="b"]', $html );
		$this->assertMarkupLacks( 'Goodbye', $html );
	}

	/**
	 * The markup assertions fail the other way round, with XPath and with text.
	 */
	public function test_markup_assertions_fail_for_both_kinds_of_needle() {
		$html = '<div class="box"><p id="a">Hello world</p></div>';

		$cases = array(
			'has xpath'   => array( 'assertMarkupHas', '//p[@id="b"]' ),
			'has text'    => array( 'assertMarkupHas', 'Goodbye' ),
			'lacks xpath' => array( 'assertMarkupLacks', '//p[@id="a"]' ),
			'lacks text'  => array( 'assertMarkupLacks', 'Hello' ),
		);
		foreach ( $cases as $name => $case ) {
			try {
				$this->{$case[0]}( $case[1], $html, 'Custom message.' );
				$this->fail( "$name did not fail." );
			} catch ( ExpectationFailedException $e ) {
				$this->assertStringContainsString( 'Custom message.', $e->getMessage(), $name );
				$this->assertStringContainsString( $case[1], $e->getMessage(), $name );
				$this->assertStringContainsString( strlen( $html ) . ' bytes', $e->getMessage(), $name );
			}
		}
	}

	/**
	 * A failure on a large page is short and has neither the page nor more than the first match, cut.
	 */
	public function test_a_markup_failure_on_a_large_page_stays_short() {
		$html = '<main>';
		for ( $i = 0; $i < 80; $i++ ) {
			$html .= "\n<section class=\"row\">\n<p>Line $i of the page</p>\n</section>";
		}
		$html .= "\n<div id=\"big\">" . str_repeat( "word\n", 200 ) . "</div>\n</main>";

		foreach ( array( '//div[@id="big"]', 'Line 3 of' ) as $needle ) {
			try {
				$this->assertMarkupLacks( $needle, $html, 'Page check.' );
				$this->fail( 'Lacks did not fail.' );
			} catch ( ExpectationFailedException $e ) {
				$this->assertLessThan( 10, count( explode( "\n", $e->getMessage() ) ), $needle );
				$this->assertLessThan( 10, count( explode( "\n", trim( TestFailure::exceptionToString( $e ) ) ) ), $needle );
				$this->assertStringNotContainsString( 'Line 40 of the page', TestFailure::exceptionToString( $e ), $needle );
				$this->assertLessThan( 700, strlen( $e->getMessage() ), $needle );
				$this->assertStringContainsString( strlen( $html ) . ' bytes', $e->getMessage(), $needle );
			}
		}

		try {
			$this->assertMarkupHas( '//nothing', $html );
			$this->fail( 'Has did not fail.' );
		} catch ( ExpectationFailedException $e ) {
			$this->assertLessThan( 10, count( explode( "\n", trim( TestFailure::exceptionToString( $e ) ) ) ) );
			$this->assertStringNotContainsString( 'Line 40 of the page', TestFailure::exceptionToString( $e ) );
		}
	}

	/**
	 * Calls wp_remote_get and expects the guard to fail the test.
	 */
	private function http_fakes_probe() {
		try {
			wp_remote_get( 'https://example.test/unfaked' );
		} catch ( PHPUnit\Framework\AssertionFailedError $e ) {
			$this->assertStringContainsString( 'Unexpected HTTP request', $e->getMessage() );
			return;
		}

		$this->fail( 'The guard did not stop an unfaked request.' );
	}
}
