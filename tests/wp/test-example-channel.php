<?php
/**
 * The example add-on in examples/wpun-example-channel, the reference for people writing a channel (#106).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN_Example_Channel\Example_Channel;

// Not loaded by tests/bootstrap.php: this test loads the add-on the way WordPress loads a plugin file.
// Loading it only defines things; nothing is wired until a test calls WPUN_Example_Channel\boot().
require_once dirname( __DIR__, 2 ) . '/examples/wpun-example-channel/wpun-example-channel.php';

/**
 * Registration, delivery, results and limits of the example channel.
 */
class Test_Example_Channel extends WPUN_Test_Case {

	/**
	 * The secret address of the webhook.
	 */
	const URL = 'https://chat.example.org/hooks/SECRET-TOKEN-123';

	/**
	 * "Activate" the add-on: wire it, and register its channel in the registry that is already loaded.
	 */
	public function set_up() {
		parent::set_up();

		WPUN_Example_Channel\boot();
		// The first read of the registry fires the registration action, which the add-on now answers too.
		if ( ! sc_wpun()->channels()->has( Example_Channel::TYPE ) ) {
			sc_wpun()->channels()->register( new Example_Channel() );
		}

		sc_wpun()->config()->flush();
		// The default email instance is off, so only the example instance receives anything.
		$this->save_settings( array( 'email_notifications' => 0 ) );
	}

	/**
	 * Put the registry back as the rest of the suite expects it.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( Example_Channel::TYPE );
		if ( ! sc_wpun()->channels()->has( 'email' ) ) {
			sc_wpun()->channels()->register( new WPUN\Modules\Email\Email_Channel() );
		}
		sc_wpun()->config()->flush();
		parent::tear_down();
	}

	/**
	 * Store an instance of the example channel.
	 *
	 * @param array $settings Settings to replace.
	 * @return Channel_Instance
	 */
	private function instance( array $settings = array() ) {
		$instance = new Channel_Instance( 'example_1', Example_Channel::TYPE, 'Example', true, array_merge( array( 'webhook_url' => self::URL ), $settings ) );
		sc_wpun_channels()->save( $instance );

		return $instance;
	}

	/**
	 * A notification of plugin updates.
	 *
	 * @param int    $count  Number of plugins.
	 * @param string $prefix Start of the name of each.
	 * @param bool   $test   Whether it is a test notification.
	 * @return Notification
	 */
	private function notification( $count = 2, $prefix = 'Plugin ', $test = false ) {
		$items = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$items[] = new Notification_Item(
				array(
					'type'            => 'plugin',
					'key'             => 'plugin-' . $i . '/plugin-' . $i . '.php',
					'name'            => $prefix . $i,
					'current_version' => '1.0',
					'new_version'     => '2.0',
				)
			);
		}

		return new Notification( 'My Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', $items, array( 'is_test' => $test ) );
	}

	/**
	 * Fake the answer of the webhook.
	 *
	 * @param int|WP_Error $response Status code, or an error.
	 * @param array        $headers  Response headers.
	 */
	private function webhook_answers( $response, array $headers = array() ) {
		if ( is_int( $response ) ) {
			$response            = $this->fake_response( $response );
			$response['headers'] = $headers;
		}
		$this->fake_http( 'chat.example.org', $response );
	}

	/**
	 * The JSON body of the one request that was made.
	 *
	 * @return array
	 */
	private function sent_payload() {
		$requests = $this->requests_to( 'chat.example.org' );
		$this->assertCount( 1, $requests );

		return json_decode( $requests[0]['args']['body'], true );
	}

	// Registration.

	/**
	 * The channel registers itself through the public action, on a registry that has not been read yet.
	 */
	public function test_it_registers_through_the_action() {
		$registry = new Channel_Registry();

		$this->assertTrue( $registry->has( 'example' ) );
		$this->assertInstanceOf( Example_Channel::class, $registry->get( 'example' ) );
		$this->assertTrue( $registry->has( 'email' ), 'Next to the built-in module.' );
		$this->assertNotFalse( has_action( 'sc_wpun_register_channels' ) );
		$this->assertTrue( $registry->get( 'example' )->supports( 'test_message' ) );
		$this->assertFalse( $registry->get( 'example' )->supports( 'something_else' ) );
	}

	/**
	 * It does not clash with the test add-on's channel when both are loaded.
	 */
	public function test_it_does_not_clash_with_the_test_add_on() {
		$this->assertNotSame( 'test_addon', Example_Channel::TYPE );
		$this->assertNotSame( 'fixture', Example_Channel::TYPE );
	}

	/**
	 * Booted the way WordPress calls a `plugins_loaded` callback, with an empty string as its argument.
	 */
	public function test_it_boots_from_plugins_loaded() {
		sc_wpun()->channels()->unregister( Example_Channel::TYPE );
		remove_all_actions( 'sc_wpun_register_channels' );
		remove_all_actions( 'admin_notices' );

		call_user_func_array( 'WPUN_Example_Channel\boot', array( '' ) );

		$this->assertTrue( WPUN_Example_Channel\api_is_supported() );
		$this->assertNotFalse( has_action( 'sc_wpun_register_channels' ) );
		$this->assertFalse( has_action( 'admin_notices' ), 'No notice when the API is there.' );
		$registry = new Channel_Registry();
		$this->assertTrue( $registry->has( 'example' ) );
	}

	/**
	 * The notice says the plugin is missing or too old.
	 */
	public function test_the_notice_asks_for_a_newer_plugin() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin ); // Only a super admin can activate plugins on a network.
		}
		wp_set_current_user( $admin );

		ob_start();
		WPUN_Example_Channel\api_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'needs a newer version of WP Updates Notifier', $html );
	}

	/**
	 * The notice is for people who can do something about it.
	 */
	public function test_the_notice_is_for_administrators_only() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		WPUN_Example_Channel\api_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	// The fields.

	/**
	 * The webhook address is required, a secret and a URL; the name is optional.
	 */
	public function test_the_fields() {
		$channel = new Example_Channel();
		$fields  = $channel->get_fields();

		$this->assertSame( array( 'webhook_url', 'username' ), array_keys( $fields ) );
		$this->assertSame( 'url', $fields['webhook_url']['type'] );
		$this->assertTrue( $fields['webhook_url']['secret'] );
		$this->assertTrue( $fields['webhook_url']['required'] );
		$this->assertArrayNotHasKey( 'required', $fields['username'] );
	}

	/**
	 * The plugin validates the settings from the schema: the address is required and must be an http(s) URL.
	 */
	public function test_the_webhook_address_is_validated() {
		$channel = new Example_Channel();

		foreach ( array( '', 'not a url', 'javascript:alert(1)' ) as $bad ) {
			$result = $channel->validate_settings( array( 'webhook_url' => $bad ) );
			$this->assertInstanceOf( WP_Error::class, $result, $bad );
			$this->assertSame( array( 'field' => 'webhook_url' ), $result->get_error_data(), $bad );
		}

		$result = $channel->validate_settings( array( 'webhook_url' => self::URL, 'username' => ' <b>Bot</b> ' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertIsArray( $result );
		$this->assertSame( self::URL, $result['webhook_url'] );
		$this->assertSame( 'Bot', $result['username'] );

		$kept = $channel->validate_settings( array(), array( 'webhook_url' => self::URL ) );
		$this->assertSame( self::URL, $kept['webhook_url'], 'Submitting nothing keeps the saved address.' );
	}

	// Delivery.

	/**
	 * A 2xx answer is a success; the body is JSON with the text of the notification.
	 */
	public function test_a_successful_delivery() {
		$this->webhook_answers( 204 );
		$instance = $this->instance( array( 'username' => 'Updates' ) );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $instance );

		$this->assertTrue( $result->is_success() );
		$request = $this->requests_to( 'chat.example.org' )[0];
		$this->assertSame( self::URL, $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertLessThanOrEqual( 10, $request['args']['timeout'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$payload = $this->sent_payload();
		$this->assertSame( 'Updates', $payload['username'] );
		$this->assertStringContainsString( 'Plugin 1: 1.0 → 2.0', $payload['text'] );
		$this->assertStringContainsString( 'Plugin 2: 1.0 → 2.0', $payload['text'] );
	}

	/**
	 * Without a name the field is left out of the body.
	 */
	public function test_the_name_is_left_out_when_empty() {
		$this->webhook_answers( 200 );

		sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );

		$this->assertSame( array( 'text' ), array_keys( $this->sent_payload() ) );
	}

	/**
	 * A test notification has a message of its own.
	 */
	public function test_the_test_message() {
		$this->webhook_answers( 200 );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification( 0, 'Plugin ', true ), $this->instance() );

		$this->assertTrue( $result->is_success() );
		$this->assertStringContainsString( 'test message', $this->sent_payload()['text'] );
	}

	/**
	 * Site code can change the payload through the public filter, and a filter that returns the wrong shape is ignored.
	 */
	public function test_the_payload_can_be_filtered() {
		$this->webhook_answers( 200 );
		add_filter(
			'sc_wpun_channel_payload_example',
			static function ( $payload ) {
				$payload['text'] = 'Changed';
				return $payload;
			}
		);
		sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );
		$this->assertSame( 'Changed', $this->sent_payload()['text'] );

		$this->reset_http();
		$this->webhook_answers( 200 );
		remove_all_filters( 'sc_wpun_channel_payload_example' );
		add_filter( 'sc_wpun_channel_payload_example', '__return_false' );
		sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );
		$this->assertStringContainsString( 'Plugin 1', $this->sent_payload()['text'] );
	}

	/**
	 * An instance without an address fails without a request.
	 */
	public function test_no_address_is_a_failure() {
		$instance = new Channel_Instance( 'example_empty', Example_Channel::TYPE, 'Empty', true, array() );

		$result = ( new Example_Channel() )->send( $this->notification(), $instance );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'not_configured', $result->get_code() );
		$this->assertSame( array(), $this->http_requests );
	}

	/**
	 * A 500 is a failure with the status; the address is not in it.
	 */
	public function test_a_server_error_is_a_failure() {
		$this->webhook_answers( 500 );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 500, $result->get_status() );
		$this->assertNull( $result->get_retry_after() );
		$this->assertStringContainsString( '500', $result->get_message() );
		$this->assertStringNotContainsString( 'SECRET-TOKEN', $result->get_message() );
		$this->assertStringNotContainsString( 'chat.example.org', wp_json_encode( $result->get_context() ) );
	}

	/**
	 * A 4xx other than 429 is a failure with the status too.
	 */
	public function test_a_client_error_is_a_failure() {
		$this->webhook_answers( 404 );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 404, $result->get_status() );
		$this->assertNull( $result->get_retry_after() );
	}

	/**
	 * A transport error is a failure with code http_error. Its message holds the address (cURL does that), and must not be passed on.
	 */
	public function test_a_wp_error_is_a_failure_without_the_address() {
		$this->webhook_answers( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out for ' . self::URL ) );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'http_error', $result->get_code() );
		$this->assertNull( $result->get_status() );
		$this->assertStringNotContainsString( 'SECRET-TOKEN', $result->get_message() );
		$this->assertStringNotContainsString( 'SECRET-TOKEN', (string) wp_json_encode( $result->get_context() ) );
		$this->assertSame( 'http_request_failed', $result->get_context()['error_code'] );
	}

	/**
	 * A 429 passes on the number of seconds in Retry-After.
	 */
	public function test_rate_limiting_sets_retry_after() {
		$this->webhook_answers( 429, array( 'retry-after' => '30' ) );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 429, $result->get_status() );
		$this->assertSame( 30, $result->get_retry_after() );
		$this->assertStringNotContainsString( 'SECRET-TOKEN', $result->get_message() );
	}

	/**
	 * A 429 without a usable Retry-After has none.
	 */
	public function test_rate_limiting_without_a_usable_header() {
		foreach ( array( array(), array( 'retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT' ) ) as $headers ) {
			$this->reset_http();
			$this->webhook_answers( 429, $headers );

			$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );

			$this->assertSame( 429, $result->get_status() );
			$this->assertNull( $result->get_retry_after() );
		}
	}

	/**
	 * A failed delivery is retried by the next update check; a successful one is not repeated.
	 */
	public function test_failed_updates_are_retried_on_the_next_run() {
		$this->instance();
		$plugins = array( 'hello.php' => array( 'new_version' => '2.0.0' ) );

		$this->webhook_answers( 429, array( 'retry-after' => '60' ) );
		$this->set_updates( null, $plugins );
		sc_wpun()->update_checker()->do_update_check();

		$this->assertSame( 60, sc_wpun()->update_checker()->last_results()['example_1']->get_retry_after() );
		$this->assertSame( '', $this->notified_version( 'plugin', 'hello.php', 'example_1' ), 'Not marked as notified.' );

		$this->reset_http();
		$this->webhook_answers( 200 );
		sc_wpun()->update_checker()->do_update_check();

		$this->assertCount( 1, $this->requests_to( 'chat.example.org' ), 'Tried again.' );
		$this->assertSame( '2.0.0', $this->notified_version( 'plugin', 'hello.php', 'example_1' ) );

		sc_wpun()->update_checker()->do_update_check();
		$this->assertCount( 1, $this->requests_to( 'chat.example.org' ), 'Not repeated.' );
	}

	// Limits.

	/**
	 * More updates than the item limit are summarised.
	 */
	public function test_a_long_list_is_summarised_by_count() {
		$this->webhook_answers( 200 );
		$total = Example_Channel::MAX_ITEMS + 7;

		$result = sc_wpun()->dispatcher()->send_one( $this->notification( $total ), $this->instance() );

		$this->assertTrue( $result->is_success() );
		$text = $this->sent_payload()['text'];
		$this->assertStringContainsString( 'Plugin ' . Example_Channel::MAX_ITEMS . ':', $text );
		$this->assertStringNotContainsString( 'Plugin ' . ( Example_Channel::MAX_ITEMS + 1 ) . ':', $text );
		$this->assertStringContainsString( '…and 7 more', $text );
		$this->assertStringEndsWith( '…and 7 more', $text );
	}

	/**
	 * A message above the length limit is cut to fewer updates, and says how many were left out.
	 */
	public function test_a_long_message_is_summarised_by_length() {
		$this->webhook_answers( 200 );

		sc_wpun()->dispatcher()->send_one( $this->notification( 40, str_repeat( 'Long name ', 12 ) ), $this->instance() );

		$text = $this->sent_payload()['text'];
		$this->assertLessThanOrEqual( Example_Channel::MAX_LENGTH, mb_strlen( $text ) );
		$this->assertMatchesRegularExpression( '/…and (\d+) more$/D', $text );
		preg_match( '/…and (\d+) more$/D', $text, $m );
		$this->assertSame( 40, substr_count( $text, ': 1.0 → 2.0' ) + (int) $m[1], 'Every update is either listed or counted.' );
		$this->assertGreaterThan( 0, substr_count( $text, ': 1.0 → 2.0' ) );
	}

	/**
	 * A short list is sent whole, without a summary.
	 */
	public function test_a_short_list_is_not_summarised() {
		$this->webhook_answers( 200 );

		sc_wpun()->dispatcher()->send_one( $this->notification( 3 ), $this->instance() );

		$this->assertStringNotContainsString( 'more', $this->sent_payload()['text'] );
	}

	/**
	 * Failure messages never contain the secret address, whatever the failure.
	 */
	public function test_no_failure_message_holds_the_address() {
		$answers = array( 400, 401, 403, 404, 429, 500, 502, new WP_Error( 'http_request_failed', self::URL ) );
		foreach ( $answers as $answer ) {
			$this->reset_http();
			$this->webhook_answers( $answer );

			$result = sc_wpun()->dispatcher()->send_one( $this->notification(), $this->instance() );

			$this->assertFalse( $result->is_success() );
			$this->assertStringNotContainsString( 'SECRET-TOKEN', $result->get_message() );
			$this->assertStringNotContainsString( 'chat.example.org', $result->get_message() );
		}
	}
}
