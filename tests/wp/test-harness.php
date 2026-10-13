<?php
/**
 * The test setup itself.
 *
 * @package wp-updates-notifier
 */

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
		$this->assertSame( WP_PLUGIN_DIR . '/wp-updates-notifier/class-sc-wp-updates-notifier.php', $file );
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
