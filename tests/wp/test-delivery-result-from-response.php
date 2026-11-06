<?php
/**
 * Delivery_Result::from_response() turns an HTTP API answer into a result (#409).
 *
 * @package wp-updates-notifier
 */

use WPUN\Delivery_Result;

/**
 * Each kind of answer, and that nothing private leaks.
 */
class Test_Delivery_Result_From_Response extends WPUN_Test_Case {

	/**
	 * Build a response like the one wp_remote_request() returns.
	 *
	 * @param int                   $code    Status.
	 * @param array<string, string> $headers Headers.
	 *
	 * @return array<string, mixed>
	 */
	private function response( int $code, array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => 'ignored',
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
		);
	}

	/**
	 * A WP_Error is an http_error with only its code, never its message.
	 */
	public function test_a_wp_error_keeps_only_the_error_code() {
		$error  = new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: secret.example.com/hook/TOKEN' );
		$result = Delivery_Result::from_response( $error );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'http_error', $result->get_code() );
		$this->assertNull( $result->get_status() );
		$this->assertSame( 'The request failed.', $result->get_message() );
		$this->assertSame( array( 'error_code' => 'http_request_failed' ), $result->get_context() );
		$this->assertStringNotContainsString( 'secret.example.com', $result->get_message() . wp_json_encode( $result->get_context() ) );
	}

	/**
	 * Something that is neither an array nor a WP_Error is a failure too.
	 */
	public function test_an_unexpected_value_is_a_failure() {
		$result = Delivery_Result::from_response( null );

		$this->assertSame( 'http_error', $result->get_code() );
		$this->assertSame( array(), $result->get_context() );
	}

	/**
	 * Any 2xx is a success.
	 */
	public function test_2xx_is_a_success() {
		foreach ( array( 200, 204, 299 ) as $code ) {
			$result = Delivery_Result::from_response( $this->response( $code ) );

			$this->assertTrue( $result->is_success(), (string) $code );
			$this->assertSame( 'success', $result->get_code() );
		}
	}

	/**
	 * Other statuses are bad_status with the status.
	 */
	public function test_3xx_4xx_and_5xx_are_bad_status() {
		foreach ( array( 301, 404, 500 ) as $code ) {
			$result = Delivery_Result::from_response( $this->response( $code ) );

			$this->assertFalse( $result->is_success(), (string) $code );
			$this->assertSame( 'bad_status', $result->get_code() );
			$this->assertSame( $code, $result->get_status() );
			$this->assertNull( $result->get_retry_after() );
			$this->assertSame( sprintf( 'The service answered with HTTP %d.', $code ), $result->get_message() );
		}
	}

	/**
	 * 429 is rate_limited and passes on Retry-After.
	 */
	public function test_429_passes_on_retry_after() {
		$result = Delivery_Result::from_response( $this->response( 429, array( 'retry-after' => '120' ) ) );

		$this->assertSame( 'rate_limited', $result->get_code() );
		$this->assertSame( 429, $result->get_status() );
		$this->assertSame( 120, $result->get_retry_after() );
		$this->assertSame( 'The service is limiting requests (HTTP 429).', $result->get_message() );
	}

	/**
	 * 429 without a usable Retry-After has no wait.
	 */
	public function test_429_without_retry_after_has_no_wait() {
		$result = Delivery_Result::from_response( $this->response( 429 ) );

		$this->assertSame( 'rate_limited', $result->get_code() );
		$this->assertNull( $result->get_retry_after() );
		$this->assertSame( 'The service is limiting requests (HTTP 429).', $result->get_message() );
	}
}
