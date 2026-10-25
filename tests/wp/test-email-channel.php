<?php
/**
 * The email module (#97), tested through the mock mailer.
 *
 * Written the way a third-party channel would be tested: only the public API is used.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Modules\Email\Email_Channel;
use WPUN\Notification;
use WPUN\Notification_Item;

/**
 * Email_Channel.
 */
class Test_Email_Channel extends WPUN_Test_Case {

	/**
	 * The channel under test.
	 *
	 * @var Email_Channel
	 */
	private $channel;

	/**
	 * Create the channel.
	 */
	public function set_up() {
		parent::set_up();

		$this->channel = new Email_Channel();
		update_option( 'blogname', "Bob & Sons' Shop" );
	}

	/**
	 * An email instance.
	 *
	 * @param array $settings Settings to override.
	 * @return Channel_Instance
	 */
	private function instance( array $settings = array() ) {
		return new Channel_Instance( 'email', 'email', 'Email', true, array_merge( array( 'to' => 'ops@example.org' ), $settings ) );
	}

	/**
	 * A notification with one plugin update.
	 *
	 * @param array $context Context.
	 * @return Notification
	 */
	private function notification( array $context = array() ) {
		$item = new Notification_Item(
			array(
				'type'            => 'plugin',
				'key'             => 'hello.php',
				'name'            => 'Hello & <Dolly>',
				'current_version' => '1.0',
				'new_version'     => '2.0',
				'links'           => array( 'details' => 'https://example.org/hello?a=1&b=2' ),
			)
		);

		return new Notification( get_bloginfo(), 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $item ), $context );
	}

	/**
	 * The mailer.
	 *
	 * @return MockPHPMailer
	 */
	private function mailer() {
		return tests_retrieve_phpmailer_instance();
	}

	/**
	 * Mail header lines of the first sent mail.
	 *
	 * @return string
	 */
	private function headers() {
		return $this->sent_mail()->header;
	}

	/**
	 * The channel is the email type, supports test messages and describes its fields with core types.
	 */
	public function test_the_channel_describes_itself() {
		$this->assertSame( 'email', $this->channel->get_type() );
		$this->assertSame( 'Email', $this->channel->get_label() );
		$this->assertTrue( $this->channel->supports( 'test_message' ) );
		$this->assertFalse( $this->channel->supports( 'anything_else' ) );

		$fields = $this->channel->get_fields();
		$this->assertSame( array( 'to', 'from', 'reply_to', 'format', 'subject_prefix' ), array_keys( $fields ) );
		$this->assertSame( 'email_list', $fields['to']['type'] );
		$this->assertTrue( $fields['to']['required'] );
		$this->assertSame( 'enum', $fields['format']['type'] );
		$this->assertSame( 'text', $fields['format']['default'] );
		$this->assertSame( array( 'text', 'html' ), array_keys( $fields['format']['options'] ) );
	}

	/**
	 * Mail goes to every address of the list.
	 */
	public function test_it_sends_to_every_recipient() {
		$result = $this->channel->send( $this->notification(), $this->instance( array( 'to' => 'a@example.org, b@example.org' ) ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertSame( 'a@example.org', $this->mailer()->get_recipient( 'to', 0, 0 )->address );
		$this->assertSame( 'b@example.org', $this->mailer()->get_recipient( 'to', 0, 1 )->address );
	}

	/**
	 * Nothing forces the From address (#76).
	 */
	public function test_from_is_the_wordpress_default_unless_set() {
		$this->channel->send( $this->notification(), $this->instance() );

		$this->assertStringStartsWith( 'wordpress@', $this->mailer()->From );
		$this->assertStringNotContainsString( 'Reply-To', $this->headers() );
	}

	/**
	 * An explicit From and a Reply-To are set as headers, for this mail only.
	 */
	public function test_from_and_reply_to_are_set_when_asked_for() {
		$this->channel->send(
			$this->notification(),
			$this->instance(
				array(
					'from'     => 'ops@example.org',
					'reply_to' => 'help@example.org',
				)
			)
		);

		$this->assertSame( 'ops@example.org', $this->mailer()->From );
		$this->assertSame( 'WP Updates Notifier', $this->mailer()->FromName );
		$this->assertStringContainsString( 'Reply-To: help@example.org', $this->headers() );
		$this->assertFalse( has_filter( 'wp_mail_from' ) );
		$this->assertFalse( has_filter( 'wp_mail_from_name' ) );
	}

	/**
	 * Plain text is not escaped (#65).
	 */
	public function test_the_plain_body_has_no_html_entities() {
		$this->channel->send( $this->notification(), $this->instance() );

		$sent = $this->sent_mail();
		$this->assertStringContainsString( "Bob & Sons' Shop", $sent->body );
		$this->assertStringContainsString( 'Hello & <Dolly> from version 1.0', $sent->body );
		$this->assertStringNotContainsString( '&amp;', $sent->body );
		$this->assertStringNotContainsString( '&#039;', $sent->body );
		$this->assertSame( "WP Updates Notifier: updates available for Bob & Sons' Shop", $sent->subject );
		$this->assertSame( 'text/plain', $this->mailer()->ContentType );
	}

	/**
	 * HTML format sends text/html with every value escaped.
	 */
	public function test_the_html_format_escapes_values() {
		$this->channel->send( $this->notification(), $this->instance( array( 'format' => 'html' ) ) );

		$this->assertStringStartsWith( 'text/html', $this->mailer()->ContentType );
		$body = $this->sent_mail()->body;
		$this->assertStringContainsString( 'Hello &amp; &lt;Dolly&gt;', $body );
		$this->assertStringNotContainsString( '<Dolly>', $body );
		$this->assertStringContainsString( '<ul>', $body );
	}

	/**
	 * The HTML body is a document that declares its language, direction, charset and title, for notifications and the test message.
	 */
	public function test_the_html_body_is_a_document() {
		foreach ( array( false, true ) as $is_test ) {
			reset_phpmailer_instance();
			$this->channel->send( $this->notification( array( 'is_test' => $is_test ) ), $this->instance( array( 'format' => 'html' ) ) );
			$body = $this->sent_mail()->body;

			$this->assertStringStartsWith( '<!DOCTYPE html>', $body );
			$this->assertStringContainsString( '<html lang="en-US" dir="ltr">', $body );
			$this->assertStringContainsString( '<meta charset="' . get_bloginfo( 'charset' ) . '">', $body );
			$this->assertStringContainsString( '<title>WP Updates Notifier: ' . ( $is_test ? 'test message' : 'updates available' ) . ' for Bob &amp; Sons&#039; Shop</title>', $body );
			$this->assertSame( 1, substr_count( $body, '<body>' ) );
			$this->assertMatchesRegularExpression( '#<body>\s*<p>.*</p>\s*</body>\s*</html>\s*$#s', $body );
		}
	}

	/**
	 * A right-to-left locale sets the direction and its language.
	 */
	public function test_the_document_follows_the_locale_direction() {
		global $wp_locale;
		$direction                 = $wp_locale->text_direction;
		$locale                    = static fn() => 'ar';
		add_filter( 'locale', $locale );
		$wp_locale->text_direction = 'rtl';

		try {
			$this->channel->send( $this->notification(), $this->instance( array( 'format' => 'html' ) ) );
			$body = $this->sent_mail()->body;
		} finally {
			$wp_locale->text_direction = $direction;
			remove_filter( 'locale', $locale );
		}

		$this->assertStringContainsString( '<html lang="ar" dir="rtl">', $body );
	}

	/**
	 * A subject with markup characters is escaped in the title, and the plain text body is not a document.
	 */
	public function test_the_title_is_escaped_and_plain_text_is_unchanged() {
		$filter = static fn() => 'Fix <b> & more';
		add_filter( 'sc_wpun_email_subject', $filter );
		$this->channel->send( $this->notification(), $this->instance( array( 'format' => 'html' ) ) );
		$html = $this->sent_mail()->body;

		reset_phpmailer_instance();
		$this->channel->send( $this->notification(), $this->instance() );
		$plain = $this->sent_mail()->body;
		remove_filter( 'sc_wpun_email_subject', $filter );

		$this->assertStringContainsString( '<title>Fix &lt;b&gt; &amp; more</title>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<!DOCTYPE', $plain );
		$this->assertStringNotContainsString( '<html', $plain );
	}

	/**
	 * A site-wide HTML content type does not change a plain text email, and is left in place afterwards.
	 */
	public function test_a_global_html_filter_does_not_change_the_plain_format() {
		$html = static function () {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $html );

		$this->channel->send( $this->notification(), $this->instance() );

		$this->assertSame( 'text/plain', $this->mailer()->ContentType );
		$this->assertSame( 10, has_filter( 'wp_mail_content_type', $html ) );
		$this->assertSame( 'text/html', apply_filters( 'wp_mail_content_type', 'text/plain' ) );
	}

	/**
	 * The subject carries the site name, and the prefix when there is one.
	 */
	public function test_the_subject_prefix() {
		$this->channel->send( $this->notification(), $this->instance( array( 'subject_prefix' => '[Client]' ) ) );

		$this->assertSame( "[Client] WP Updates Notifier: updates available for Bob & Sons' Shop", $this->sent_mail()->subject );
	}

	/**
	 * A test notification gets the module's own short message.
	 */
	public function test_the_test_message() {
		$this->channel->send( $this->notification( array( 'is_test' => true ) ), $this->instance() );

		$sent = $this->sent_mail();
		$this->assertSame( "WP Updates Notifier: test message for Bob & Sons' Shop", $sent->subject );
		$this->assertStringContainsString( "This is a test message from WP Updates Notifier for Bob & Sons' Shop (http://example.org).", $sent->body );
		$this->assertStringNotContainsString( 'Hello', $sent->body );

		reset_phpmailer_instance();
		$this->channel->send( $this->notification( array( 'is_test' => true ) ), $this->instance( array( 'format' => 'html' ) ) );
		$this->assertStringContainsString( '<p>This is a test message', $this->sent_mail()->body );
		$this->assertStringContainsString( 'Bob &amp; Sons', $this->sent_mail()->body );
	}

	/**
	 * The legacy filters get the notification and the instance and can change the result.
	 */
	public function test_the_legacy_filters_receive_three_arguments() {
		$seen = array();
		add_filter(
			'sc_wpun_email_subject',
			static function ( $subject, $notification, $instance ) use ( &$seen ) {
				$seen[] = array( $notification, $instance );
				return $subject . ' (filtered)';
			},
			10,
			3
		);
		add_filter(
			'sc_wpun_email_content',
			static function ( $body, $notification, $instance ) use ( &$seen ) {
				$seen[] = array( $notification, $instance );
				return $body . "\nSent by filter";
			},
			10,
			3
		);
		$notification = $this->notification();
		$instance     = $this->instance();

		$this->channel->send( $notification, $instance );

		$this->assertCount( 2, $seen );
		$this->assertSame( array( $notification, $instance ), $seen[0] );
		$this->assertSame( array( $notification, $instance ), $seen[1] );
		$this->assertStringEndsWith( '(filtered)', $this->sent_mail()->subject );
		$this->assertStringContainsString( 'Sent by filter', $this->sent_mail()->body );
	}

	/**
	 * A mail wp_mail() cannot send is a failure with the code, the mailer's message and no recipients in the context.
	 */
	public function test_a_wp_mail_failure_is_reported() {
		// Another plugin rewrites the recipient to something the mailer rejects, which fails inside wp_mail().
		add_filter(
			'wp_mail',
			static function ( $args ) {
				$args['to'] = 'not-an-address';
				return $args;
			}
		);

		$result = $this->channel->send( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'wp_mail_failed', $result->get_code() );
		$this->assertNotSame( '', $result->get_message() );
		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertArrayHasKey( 'error_code', $result->get_context() );
		$this->assertStringNotContainsString( 'ops@example.org', wp_json_encode( $result->get_context() ) );
		$this->assertFalse( has_action( 'wp_mail_failed' ), 'The listener is removed.' );
		$this->assertFalse( has_filter( 'wp_mail_content_type' ), 'The content type filter is removed.' );
	}

	/**
	 * A failure with no error to read (a short-circuited wp_mail) still gets a generic message.
	 */
	public function test_a_failure_without_an_error_has_a_generic_message() {
		add_filter( 'pre_wp_mail', '__return_false' );

		$result = $this->channel->send( $this->notification(), $this->instance() );

		$this->assertSame( 'wp_mail_failed', $result->get_code() );
		$this->assertSame( 'WordPress could not send the email.', $result->get_message() );
	}

	/**
	 * A listener left on wp_mail_failed by the site stays, and a thrown exception still cleans up.
	 */
	public function test_filters_are_removed_when_wp_mail_throws() {
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);

		try {
			$this->channel->send( $this->notification(), $this->instance() );
			$this->fail( 'The exception should reach the caller (the dispatcher handles it).' );
		} catch ( RuntimeException $e ) {
			$this->assertFalse( has_filter( 'wp_mail_content_type' ) );
			$this->assertFalse( has_action( 'wp_mail_failed' ) );
		}
	}

	/**
	 * No recipient: nothing is sent.
	 */
	public function test_an_instance_without_recipients_is_not_configured() {
		$result = $this->channel->send( $this->notification(), $this->instance( array( 'to' => ' , ' ) ) );

		$this->assertSame( 'not_configured', $result->get_code() );
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * Valid settings are cleaned and returned.
	 */
	public function test_validate_settings_accepts_good_input() {
		$clean = $this->channel->validate_settings(
			array(
				'to'             => ' A@Example.org , b@example.org,',
				'from'           => 'ops@example.org',
				'reply_to'       => '',
				'format'         => 'html',
				'subject_prefix' => " <b>[Client]</b>\n",
			)
		);

		$this->assertSame(
			array(
				'to'             => 'A@Example.org,b@example.org',
				'from'           => 'ops@example.org',
				'reply_to'       => '',
				'format'         => 'html',
				'subject_prefix' => '[Client]',
			),
			$clean
		);
	}

	/**
	 * Bad input is refused with the field at fault.
	 *
	 * @dataProvider bad_settings
	 *
	 * @param array  $settings Settings.
	 * @param string $field    Field in the error data.
	 */
	public function test_validate_settings_rejects_bad_input( array $settings, $field ) {
		$result = $this->channel->validate_settings( $settings );

		$this->assertWPError( $result );
		$this->assertSame( array( 'field' => $field ), $result->get_error_data() );
	}

	/**
	 * Settings that must be refused.
	 *
	 * @return array
	 */
	public function bad_settings() {
		return array(
			'no recipients'  => array( array( 'to' => '' ), 'to' ),
			'only commas'    => array( array( 'to' => ' , ' ), 'to' ),
			'bad recipient'  => array( array( 'to' => 'a@example.org, nope' ), 'to' ),
			'bad from'       => array(
				array(
					'to'   => 'a@example.org',
					'from' => 'nope',
				),
				'from',
			),
			'bad reply to'   => array(
				array(
					'to'       => 'a@example.org',
					'reply_to' => 'nope',
				),
				'reply_to',
			),
			'unknown format' => array(
				array(
					'to'     => 'a@example.org',
					'format' => 'markdown',
				),
				'format',
			),
		);
	}

	/**
	 * Blank entries between addresses are skipped, not the end of the list.
	 */
	public function test_validate_settings_skips_blank_entries() {
		$clean = $this->channel->validate_settings( array( 'to' => 'a@example.org, ,, b@example.org' ) );

		$this->assertSame( 'a@example.org,b@example.org', $clean['to'] );
	}

	/**
	 * The From header names the sender and the address.
	 */
	public function test_the_from_header_has_the_name_and_the_address() {
		$this->channel->send( $this->notification(), $this->instance( array( 'from' => 'ops@example.org' ) ) );

		$this->assertStringContainsString( 'From: WP Updates Notifier <ops@example.org>', $this->headers() );
	}

	/**
	 * A failure carries the message the mailer gave.
	 */
	public function test_a_failure_carries_the_message_of_the_mailer() {
		add_filter(
			'wp_mail',
			static function ( $args ) {
				$args['to'] = 'not-an-address';
				return $args;
			}
		);

		$result = $this->channel->send( $this->notification(), $this->instance() );

		$this->assertStringContainsString( 'recipient', $result->get_message() );
		$this->assertNotSame( 'WordPress could not send the email.', $result->get_message() );
	}

	/**
	 * What a payload filter returns is used where it is the right type and ignored where it is not.
	 */
	public function test_the_payload_filter_may_only_return_safe_values() {
		add_filter(
			'sc_wpun_channel_payload',
			static function ( $payload ) {
				$payload['to']      = array( '   ', 5, "evil@example.org\nBcc: x@example.org", 'good@example.org' );
				$payload['headers'] = array( 'X-Ok: 1', "X-Bad: 1\nBcc: evil@example.org", 5 );
				$payload['message'] = array( 'not text' );
				$payload['subject'] = 7;
				return $payload;
			}
		);

		$result = $this->channel->send( $this->notification(), $this->instance() );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'good@example.org', $this->mailer()->get_recipient( 'to', 0, 0 )->address );
		$this->assertFalse( $this->mailer()->get_recipient( 'to', 0, 1 ) );
		$this->assertStringContainsString( 'X-Ok: 1', $this->headers() );
		$this->assertStringNotContainsString( 'Bcc', $this->headers() );
		$this->assertStringContainsString( 'Hello & <Dolly>', $this->sent_mail()->body );
	}

	/**
	 * A payload filter that returns only some of the keys leaves the rest as they were.
	 */
	public function test_the_payload_filter_may_leave_keys_out() {
		add_filter(
			'sc_wpun_channel_payload',
			static function () {
				return array( 'message' => 'Only a message' );
			}
		);
		$errors = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Collecting what PHP reports.
			static function ( $number, $message ) use ( &$errors ) {
				$errors[] = $message;
				return true;
			}
		);
		try {
			$result = $this->channel->send( $this->notification(), $this->instance( array( 'from' => 'ops@example.org' ) ) );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $errors );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'ops@example.org', $this->mailer()->get_recipient( 'to', 0, 0 )->address );
		$this->assertStringContainsString( 'WP Updates Notifier', $this->sent_mail()->subject );
		$this->assertStringContainsString( 'From: WP Updates Notifier', $this->headers() );
	}

	/**
	 * A payload filter may replace the subject and the message with text.
	 */
	public function test_the_payload_filter_may_replace_the_subject_and_message() {
		add_filter(
			'sc_wpun_channel_payload',
			static function ( $payload ) {
				$payload['subject'] = 'A new subject';
				$payload['message'] = 'A new message';
				return $payload;
			}
		);

		$this->channel->send( $this->notification(), $this->instance() );

		$this->assertSame( 'A new subject', $this->sent_mail()->subject );
		$this->assertStringContainsString( 'A new message', $this->sent_mail()->body );
	}

	/**
	 * Spaces around the format are not part of it.
	 */
	public function test_validate_settings_trims_the_format() {
		$clean = $this->channel->validate_settings(
			array(
				'to'     => 'a@example.org',
				'format' => ' html ',
			)
		);

		$this->assertSame( 'html', $clean['format'] );
	}
}
