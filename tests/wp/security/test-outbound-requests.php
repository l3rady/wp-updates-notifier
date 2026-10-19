<?php
/**
 * Outbound requests go through the safe HTTP API, and private addresses are refused (#220).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channels_Tab;
use WPUN\Channel_Instance;
use WPUN\Modules\Email\Email_Channel;
use WPUN\Notification;
use WPUN_Example_Channel\Example_Channel;

require_once __DIR__ . '/class-wpun-security-test-case.php';
// Not loaded by tests/bootstrap.php: loading only defines things, as in test-example-channel.php.
require_once dirname( __DIR__, 3 ) . '/examples/wpun-example-channel/wpun-example-channel.php';

/**
 * The example add-on is the only code of the repository that makes HTTP requests, with the address a site owner
 * typed in as the webhook. `wp_safe_remote_post()` sets `reject_unsafe_urls`, and core then refuses addresses on
 * the server's own network unless the site owner opts in with `http_request_host_is_external`.
 *
 * The base test case answers `pre_http_request` before core checks the address, so the refusal tests let the
 * request through that filter instead. Core then checks the address, and a request that passes is stopped by an
 * exception thrown from the `requests-requests.before_request` action, just before the transport would connect.
 * So the tests see both outcomes (refused at core's check, or about to be sent) and nothing leaves the process.
 *
 * @group security
 */
class Test_Security_Outbound_Requests extends WPUN_Security_Test_Case {

	const WEBHOOK = 'https://chat.example.org/hooks/SECRET-TOKEN';

	/**
	 * Addresses that passed core's check and were about to be sent.
	 *
	 * @var string[]
	 */
	private $reached = array();

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Create the user.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Activate the add-on and leave only its instance enabled.
	 */
	public function set_up() {
		parent::set_up();

		WPUN_Example_Channel\boot();
		if ( ! sc_wpun()->channels()->has( Example_Channel::TYPE ) ) {
			sc_wpun()->channels()->register( new Example_Channel() );
		}
		sc_wpun()->config()->flush();
		$this->save_settings( array( 'email_notifications' => 0 ) );
		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Put the registry back as the rest of the suite expects it.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( Example_Channel::TYPE );
		if ( ! sc_wpun()->channels()->has( 'email' ) ) {
			sc_wpun()->channels()->register( new Email_Channel() );
		}
		sc_wpun()->config()->flush();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Store an example instance with a webhook address.
	 *
	 * @param string $url The address.
	 * @return Channel_Instance
	 */
	private function instance( string $url ): Channel_Instance {
		$instance = new Channel_Instance( 'example_1', Example_Channel::TYPE, 'Example', true, array( 'webhook_url' => $url ) );
		sc_wpun_channels()->save( $instance );

		return $instance;
	}

	/**
	 * A notification with one pending update.
	 *
	 * @param bool $test Whether it is a test notification.
	 * @return Notification
	 */
	private function notification( bool $test = false ): Notification {
		return new Notification( 'My Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', array(), array( 'is_test' => $test ) );
	}

	/**
	 * Send through the add-on and say whether the address got past core's check.
	 *
	 * @param string $url The webhook address.
	 * @return bool True if the request was about to be sent; false if core refused it.
	 */
	private function is_let_through( string $url ): bool {
		$this->reached = array();
		$result        = ( new Example_Channel() )->send( $this->notification(), $this->instance( $url ) );

		$this->assertFalse( $result->is_success(), "$url: the request was sent." );
		$this->assertSame( 'http_error', $result->get_code(), "$url: not an HTTP API error." );
		$this->assertSame( 'http_request_failed', $result->get_context()['error_code'] ?? '' );

		return array() !== $this->reached;
	}

	/**
	 * Let requests reach core's address check, and stop the ones that pass before anything is sent.
	 */
	private function reach_core_check() {
		remove_filter( 'pre_http_request', array( $this, 'handle_http' ), 1 );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->http_requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $pre;
			},
			1,
			3
		);
		add_action(
			'requests-requests.before_request',
			function ( $url ) {
				$this->reached[] = $url;
				throw new WpOrg\Requests\Exception( 'Stopped by the test.', 'wpun_stop' );
			}
		);
	}

	// Safe API.

	/**
	 * A send and a test message post to the webhook with `reject_unsafe_urls`, and make no other request.
	 */
	public function test_every_request_of_the_add_on_is_a_safe_request() {
		$this->fake_http( 'chat.example.org', $this->fake_response( 200 ) );
		$this->instance( self::WEBHOOK );

		// A delivery with an update waiting.
		$this->set_updates( '99.0.0' );
		sc_wpun()->update_checker()->do_update_check();
		$sent = $this->requests_to( 'chat.example.org' );
		$this->assertCount( 1, $sent, 'The update was not posted to the webhook.' );

		// A test message from the Channels tab.
		$_POST = array(
			'id'       => 'example_1',
			'_wpnonce' => wp_create_nonce( Channels_Tab::TEST_ACTION . '_example_1' ),
		);
		$this->assertTrue( sc_wpun()->channels_tab()->process_test_send() );
		$this->assertCount( 2, $this->requests_to( 'chat.example.org' ), 'The test message was not posted to the webhook.' );

		foreach ( $this->requests_to( 'chat.example.org' ) as $request ) {
			$this->assertSame( 'POST', $request['args']['method'] );
			$this->assertTrue( $request['args']['reject_unsafe_urls'], 'The webhook request does not reject unsafe URLs.' );
		}
		// What else was asked for is core's own update API, not the add-on.
		foreach ( $this->http_requests as $request ) {
			if ( false === strpos( $request['url'], 'chat.example.org' ) ) {
				$this->assertStringContainsString( 'wordpress.org', $request['url'], 'An unexpected host was contacted.' );
			}
		}
	}

	/**
	 * The assertion above can tell: a request through the unsafe API does not carry the flag.
	 */
	public function test_an_unsafe_request_would_be_noticed() {
		$this->fake_http( 'plain.example.org', $this->fake_response( 200 ) );
		$this->fake_http( 'tidy.example.org', $this->fake_response( 200 ) );

		wp_remote_post( 'https://plain.example.org/' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_post_wp_remote_post -- The unsafe call under test.
		wp_safe_remote_post( 'https://tidy.example.org/' );

		$this->assertFalse( $this->requests_to( 'plain.example.org' )[0]['args']['reject_unsafe_urls'] );
		$this->assertTrue( $this->requests_to( 'tidy.example.org' )[0]['args']['reject_unsafe_urls'] );
	}

	/**
	 * The email module sends mail and nothing else.
	 */
	public function test_the_email_module_makes_no_http_request() {
		$email = sc_wpun()->channels()->get( 'email' );
		$this->assertInstanceOf( Email_Channel::class, $email );
		$instance = new Channel_Instance( 'email', 'email', 'Email', true, array( 'to' => 'ops@example.org' ) );

		$this->assertTrue( $email->send( $this->notification(), $instance )->is_success() );
		$this->assertTrue( $email->send( $this->notification( true ), $instance )->is_success() );
		$this->assertSame( 2, $this->sent_mail_count() );
		$this->assertSame( array(), $this->http_requests, 'The email module made an HTTP request.' );
	}

	/**
	 * The plugin's code uses `wp_safe_remote_*` for HTTP, and no other client. (The Semgrep rule
	 * `wpun-outbound-http-safe-only` checks the same in CI; this keeps the test group self-contained.)
	 */
	public function test_the_source_uses_only_the_safe_http_functions() {
		$root   = dirname( __DIR__, 3 );
		$unsafe = array();
		$files  = array( $root . '/wp-updates-notifier.php', $root . '/uninstall.php' );
		foreach ( array( 'includes', 'modules', 'examples' ) as $dir ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		$this->assertGreaterThan( 20, count( $files ), 'The source files were not found.' );

		// PHP 8 reads a qualified name as one token; PHP 7.4 reads it as names and separators.
		$name_tokens = array( T_STRING, T_NS_SEPARATOR );
		foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED' ) as $constant ) {
			if ( defined( $constant ) ) {
				$name_tokens[] = constant( $constant );
			}
		}

		$forbidden = '/^(wp_remote_(get|post|head|request|fopen)|curl_\w+|fsockopen|pfsockopen|stream_socket_client|wp_http_.*_request)$/';
		foreach ( $files as $path ) {
			$tokens = token_get_all( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
			foreach ( $tokens as $i => $token ) {
				if ( is_array( $token ) && T_STRING === $token[0] && 1 === preg_match( $forbidden, $token[1] ) ) {
					$unsafe[] = substr( $path, strlen( $root ) + 1 ) . ':' . $token[2] . ' ' . $token[1];
				}
				if ( is_array( $token ) && T_NEW === $token[0] ) {
					$name = '';
					for ( $j = $i + 1; $j < $i + 6 && isset( $tokens[ $j ] ); $j++ ) {
						if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $name_tokens, true ) ) {
							$name .= $tokens[ $j ][1];
						} elseif ( ! is_array( $tokens[ $j ] ) || T_WHITESPACE !== $tokens[ $j ][0] ) {
							break;
						}
					}
					if ( 1 === preg_match( '/^\\\\?(WP_Http|Requests|WpOrg\\\\Requests\\\\Requests)$/', $name ) ) {
						$unsafe[] = substr( $path, strlen( $root ) + 1 ) . ':' . $token[2] . ' new ' . $name;
					}
				}
			}
		}

		$this->assertSame( array(), $unsafe, 'These calls bypass wp_safe_remote_*.' );
	}

	// Private addresses.

	/**
	 * Addresses on the server's own network.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function private_urls() {
		return array(
			'loopback'                => array( 'http://127.0.0.1/hook' ),
			'loopback, other address' => array( 'http://127.1.2.3/hook' ),
			'cloud metadata'          => array( 'http://169.254.169.254/latest/meta-data/' ),
			'private 10/8'            => array( 'http://10.0.0.5/hook' ),
			'private 172.16/12'       => array( 'http://172.16.0.9/hook' ),
			'private 192.168/16'      => array( 'http://192.168.1.1/hook' ),
			'this network'            => array( 'http://0.0.0.0/hook' ),
		);
	}

	/**
	 * A private address is refused at core's check, with `reject_unsafe_urls` set, whatever the port.
	 *
	 * @dataProvider private_urls
	 *
	 * @param string $url The address.
	 */
	public function test_private_addresses_are_refused( $url ) {
		$this->reach_core_check();

		$this->assertFalse( $this->is_let_through( $url ), "$url was not refused." );
		$this->assertCount( 1, $this->requests_to( $url ), "$url was not requested once." );
		$this->assertTrue( $this->requests_to( $url )[0]['args']['reject_unsafe_urls'], "$url was requested without reject_unsafe_urls." );
		$this->assertFalse( wp_http_validate_url( $url ), "$url passes core's check." );
	}

	/**
	 * A host that needs brackets is never accepted, with or without the opt-in: core refuses a host with `[` or `:`.
	 */
	public function test_an_ipv6_loopback_address_is_refused_even_when_allowed() {
		$this->reach_core_check();

		$this->assertFalse( $this->is_let_through( 'http://[::1]/hook' ) );

		add_filter( 'http_request_host_is_external', '__return_true' );
		$this->assertFalse( $this->is_let_through( 'http://[::1]/hook' ), 'The opt-in lets an IPv6 loopback address through.' );
		$this->assertFalse( wp_http_validate_url( 'http://[::1]/hook' ) );
	}

	/**
	 * A site owner can opt in for a host with core's filter. Only that host is let through: the request then
	 * gets as far as the transport, which these tests have taken away.
	 *
	 * @dataProvider private_urls
	 *
	 * @param string $url The address.
	 */
	public function test_a_site_can_allow_one_private_host( $url ) {
		$this->reach_core_check();
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		add_filter(
			'http_request_host_is_external',
			static function ( $external, $requested ) use ( $host ) {
				return $requested === $host ? true : $external;
			},
			10,
			2
		);

		$this->assertTrue( $this->is_let_through( $url ), "$url was not let through after the opt-in." );
		$this->assertNotFalse( wp_http_validate_url( $url ) );
		// Another private host is still refused.
		$other = '127.0.0.1' === $host ? 'http://10.9.9.9/hook' : 'http://127.0.0.1/hook';
		$this->assertFalse( $this->is_let_through( $other ), "$other was let through by the opt-in for $host." );
	}

	/**
	 * Other schemes, user information and odd ports are refused too, as the URL field and core agree.
	 */
	public function test_other_unsafe_forms_are_refused() {
		$this->reach_core_check();

		foreach ( array( 'ftp://chat.example.org/hook', 'file:///etc/passwd', 'http://user:pass@chat.example.org/hook', 'https://chat.example.org:22/hook', 'gopher://chat.example.org/' ) as $url ) {
			$this->assertFalse( $this->is_let_through( $url ), "$url was not refused." );
		}
	}
}
