<?php
/**
 * Redirecting WordPress's background core update email (#63).
 *
 * @package wp-updates-notifier
 */

/**
 * The `auto_core_update_email` filter.
 */
class Test_Auto_Update_Email extends WPUN_Test_Case {

	/**
	 * Run the filter on an email shaped like the ones core builds.
	 *
	 * @param mixed $headers Headers as core or another filter passes them.
	 * @return array
	 */
	private function filter_email( $headers ) {
		return apply_filters(
			'auto_core_update_email',
			array(
				'to'      => 'admin@example.org',
				'subject' => 'Subject',
				'body'    => 'Body',
				'headers' => $headers,
			),
			'success',
			new stdClass(),
			null
		);
	}

	/**
	 * Core builds `headers` as an empty string; appending to it used to be a fatal error.
	 */
	public function test_string_headers_from_core_do_not_fatal() {
		$this->save_settings(
			array(
				'notify_automatic' => 1,
				'notify_from'      => 'notifier@example.org',
			)
		);

		$email = $this->filter_email( '' );

		$this->assertSame( array( 'From: WP Updates Notifier <notifier@example.org>' ), $email['headers'] );
		$this->assertSame( 'ops@example.org', $email['to'] );
	}

	/**
	 * Headers another filter added as a string are kept.
	 */
	public function test_existing_string_headers_are_kept() {
		$this->save_settings(
			array(
				'notify_automatic' => 1,
				'notify_from'      => 'notifier@example.org',
			)
		);

		$email = $this->filter_email( "X-One: 1\r\nX-Two: 2\n" );

		$this->assertSame( array( 'X-One: 1', 'X-Two: 2', 'From: WP Updates Notifier <notifier@example.org>' ), $email['headers'] );
	}

	/**
	 * Array headers are kept and appended to.
	 */
	public function test_array_headers_are_appended_to() {
		$this->save_settings(
			array(
				'notify_automatic' => 1,
				'notify_from'      => 'notifier@example.org',
			)
		);

		$email = $this->filter_email( array( 'X-One: 1' ) );

		$this->assertSame( array( 'X-One: 1', 'From: WP Updates Notifier <notifier@example.org>' ), $email['headers'] );
	}

	/**
	 * A From header set by someone else is not duplicated.
	 */
	public function test_an_existing_from_header_is_not_duplicated() {
		$this->save_settings(
			array(
				'notify_automatic' => 1,
				'notify_from'      => 'notifier@example.org',
			)
		);

		$email = $this->filter_email( 'from: Someone <someone@example.org>' );

		$this->assertSame( array( 'from: Someone <someone@example.org>' ), $email['headers'] );
	}

	/**
	 * Without a From address the headers are left exactly as they came.
	 */
	public function test_headers_are_untouched_without_a_from_address() {
		$this->save_settings(
			array(
				'notify_automatic' => 1,
				'notify_from'      => '',
			)
		);

		$this->assertSame( '', $this->filter_email( '' )['headers'] );
	}

	/**
	 * The result goes through wp_mail() without errors.
	 */
	public function test_the_filtered_email_can_be_sent() {
		$this->save_settings(
			array(
				'notify_automatic' => 1,
				'notify_from'      => 'notifier@example.org',
			)
		);

		$email = $this->filter_email( '' );

		$this->assertTrue( wp_mail( $email['to'], $email['subject'], $email['body'], $email['headers'] ) );
		$this->assertSame( 'notifier@example.org', tests_retrieve_phpmailer_instance()->From );
	}
}
