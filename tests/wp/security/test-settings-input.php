<?php
/**
 * Hostile values submitted to the settings: every field type, built-in and add-on schemas (#220).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channel_Form;
use WPUN\Field_Types;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * Field types reject or neutralise payloads on save, and the forms draw whatever was stored or refused safely.
 *
 * @group security
 */
class Test_Security_Settings_Input extends WPUN_Security_Test_Case {

	/**
	 * Marks a secret value, so the tests can look for it in the page.
	 */
	const SECRET = 'SECRETTOKEN-4711';

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
	 * An administrator, the default email channel and an add-on channel with a field of every type.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->register_admin_hooks();
		sc_wpun()->config()->flush();
		$this->save_settings();
		wp_set_current_user( self::$admin_id );
		sc_wpun()->channels()->register( new Test_Fields_Channel( 'security_fields', $this->schema() ) );
	}

	/**
	 * Remove the add-on channel and the request.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( 'security_fields' );
		sc_wpun()->config()->flush();
		$_POST = array();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'], $_GET['type'] );
		parent::tear_down();
	}

	/**
	 * An add-on's schema: one field of every type, and the aliases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schema(): array {
		$options = array(
			'a' => 'Option A',
			'b' => 'Option B',
		);

		return array(
			'f_text'        => array( 'type' => 'text' ),
			'f_textarea'    => array( 'type' => 'textarea' ),
			'f_url'         => array( 'type' => 'url' ),
			'f_email_list'  => array( 'type' => 'email_list' ),
			'f_secret'      => array(
				'type'   => 'secret',
				'secret' => true,
			),
			'f_text_secret' => array(
				'type'   => 'text',
				'secret' => true,
			),
			'f_number'      => array(
				'type' => 'number',
				'min'  => 1,
				'max'  => 10,
			),
			'f_boolean'     => array( 'type' => 'boolean' ),
			'f_checkbox'    => array( 'type' => 'checkbox' ),
			'f_enum'        => array(
				'type'    => 'enum',
				'options' => $options,
			),
			'f_select'      => array(
				'type'    => 'select',
				'options' => $options,
			),
			'f_multi'       => array(
				'type'     => 'enum',
				'options'  => $options,
				'multiple' => true,
			),
		);
	}

	/**
	 * Values the schema accepts.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_settings(): array {
		return array(
			'f_text'        => 'Text',
			'f_textarea'    => 'Text area',
			'f_url'         => 'https://example.org/hook',
			'f_email_list'  => 'ops@example.org',
			'f_secret'      => self::SECRET,
			'f_text_secret' => self::SECRET,
			'f_number'      => '5',
			'f_boolean'     => '1',
			'f_checkbox'    => '1',
			'f_enum'        => 'a',
			'f_select'      => 'b',
			'f_multi'       => array( 'a', 'b' ),
		);
	}

	// Field types.

	/**
	 * A payload in a text-like field is cleaned of markup; a payload that can not be a number, a switch, an
	 * address, a URL or a choice is refused.
	 *
	 * @dataProvider payloads_provider
	 *
	 * @param string $payload The payload.
	 */
	public function test_every_field_type_refuses_or_neutralises_a_payload( $payload ) {
		foreach ( $this->schema() as $name => $field ) {
			$result = Field_Types::validate( array( $name => $field ), array( $name => $payload ), array() );
			$type   = Field_Types::canonical( $field['type'] );

			if ( $result instanceof WP_Error ) {
				continue; // Refused.
			}
			$this->assertArrayHasKey( $name, $result, "$name: neither stored nor refused." );
			$value = $result[ $name ];

			switch ( $type ) {
				case 'text':
				case 'textarea':
					if ( ! empty( $field['secret'] ) ) {
						$this->assertIsString( $value );
						break;
					}
					$this->assertIsString( $value );
					$this->assertDoesNotMatchRegularExpression( '/</', $value, "$name kept a tag." );
					break;
				case 'secret':
					$this->assertIsString( $value );
					$this->assertDoesNotMatchRegularExpression( '/[\x00-\x1F\x7F]/', $value, "$name kept control characters." );
					break;
				case 'email_list':
					foreach ( explode( ',', (string) $value ) as $address ) {
						$this->assertNotFalse( is_email( $address ), "$name stored $address." );
					}
					break;
				case 'url':
					$this->assertMatchesRegularExpression( '#^https?://#i', (string) $value, "$name stored a URL that is not http(s)." );
					break;
				case 'number':
					$this->assertIsInt( $value, "$name stored something that is not a number." );
					break;
				case 'boolean':
					$this->assertIsBool( $value, "$name stored something that is not a switch." );
					break;
				case 'enum':
					$allowed = array_keys( $field['options'] );
					foreach ( (array) $value as $choice ) {
						$this->assertContains( $choice, $allowed, "$name stored a choice that is not offered." );
					}
					break;
				default:
					$this->fail( "No check for the field type $type." );
			}
		}
	}

	/**
	 * Every built-in type is covered above, and a field type added later has to be added to the schema.
	 */
	public function test_the_schema_has_every_built_in_type() {
		$used = array();
		foreach ( $this->schema() as $field ) {
			$used[] = Field_Types::canonical( $field['type'] );
		}
		$types = array_keys( Field_Types::all() );
		sort( $types );

		$this->assertSame( $types, array_values( array_unique( array_intersect( $types, $used ) ) ), 'A built-in field type is not in the security schema.' );
	}

	/**
	 * Arrays and other shapes where a string is expected are refused or cleaned, never stored as they are.
	 */
	public function test_values_of_the_wrong_shape_are_not_stored_raw() {
		$shapes = array(
			'array'  => array( '<script>' ),
			'object' => (object) array( 'a' => '<script>' ),
			'null'   => null,
		);
		foreach ( $this->schema() as $name => $field ) {
			foreach ( $shapes as $shape => $value ) {
				$result = Field_Types::validate( array( $name => $field ), array( $name => $value ), array() );
				$stored = is_array( $result ) && isset( $result[ $name ] ) ? (string) wp_json_encode( $result[ $name ] ) : '';
				if ( empty( $field['secret'] ) && 'secret' !== $field['type'] ) {
					$this->assertStringNotContainsString( '<script', $stored, "$name stored a $shape with markup." );
				} else {
					$this->assertIsString( $stored ); // A secret is stored as given and never drawn.
				}
			}
		}
	}

	/**
	 * URL fields accept http and https only, whatever the case, spaces or control characters in the scheme.
	 */
	public function test_url_fields_reject_script_schemes() {
		$bad = array(
			'javascript:alert(1)',
			'JaVaScRiPt:alert(1)',
			' javascript:alert(1)',
			"\tjava\nscript:alert(1)",
			'javascript://example.org/%0aalert(1)',
			'data:text/html,<script>alert(1)</script>',
			'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
			'file:///etc/passwd',
			'file://localhost/etc/passwd',
			'vbscript:msgbox(1)',
			'ftp://example.org/file',
			'gopher://example.org/',
			'//example.org/hook',
			'example.org/hook',
			'http://',
			'https:///hook',
		);
		foreach ( $bad as $url ) {
			$result = Field_Types::validate( array( 'u' => array( 'type' => 'url' ) ), array( 'u' => $url ), array() );
			$this->assertInstanceOf( WP_Error::class, $result, "The URL $url was accepted." );
		}

		foreach ( array( 'https://example.org/hook', 'HTTP://Example.org/hook?x=1' ) as $url ) {
			$result = Field_Types::validate( array( 'u' => array( 'type' => 'url' ) ), array( 'u' => $url ), array() );
			$this->assertIsArray( $result, "The URL $url was refused." );
		}

		// A script in a URL that is otherwise fine is encoded.
		$result = Field_Types::validate( array( 'u' => array( 'type' => 'url' ) ), array( 'u' => 'https://example.org/?q="><script>alert(1)</script>' ), array() );
		$this->assertTrue( is_array( $result ) ? false === strpos( $result['u'], '<' ) && false === strpos( $result['u'], '"' ) : true );
	}

	// Saving.

	/**
	 * Save a channel the way the form does.
	 *
	 * @param string $mode     `new` or `edit`.
	 * @param string $key      Type or id.
	 * @param array  $settings Submitted settings.
	 * @param array  $more     Other submitted fields.
	 * @return string|null The address to go to.
	 */
	private function save( string $mode, string $key, array $settings, array $more = array() ): ?string {
		$_POST = array_merge(
			array(
				'action'                        => Channel_Form::SAVE_ACTION,
				'mode'                          => $mode,
				'new' === $mode ? 'type' : 'id' => $key,
				'label'                         => 'Label',
				'enabled'                       => '1',
				'routing'                       => array(
					'scope' => 'all',
					'core'  => 'all',
					'types' => array( '' ),
				),
				'settings'                      => $settings,
				'_wpnonce'                      => wp_create_nonce( Channel_Form::SAVE_ACTION . '_' . $mode . '_' . $key ),
			),
			$more
		);

		return sc_wpun()->channel_form()->process_save();
	}

	/**
	 * The Channels tab for a request.
	 *
	 * @param array $get Query arguments.
	 * @return string
	 */
	private function tab( array $get ): string {
		$_GET = array_merge( array( 'tab' => 'channels' ), $get );

		return $this->capture( array( sc_wpun()->channels_tab(), 'render' ) );
	}

	/**
	 * What the stored options hold.
	 *
	 * @return array
	 */
	private function stored(): array {
		return array( get_option( 'sc_wpun_channels' ), get_option( 'sc_wpun_state' ), get_option( 'sc_wpun_settings' ) );
	}

	/**
	 * Every field of the add-on's schema hostile: the save is refused, nothing is stored and the form shows the
	 * refusal without live markup. Then the fields that can hold text carry the payload and the rest are valid:
	 * the save works, no markup is stored and the edit form is safe.
	 *
	 * @dataProvider payloads_provider
	 *
	 * @param string $payload The payload.
	 */
	public function test_an_add_on_schema_refuses_or_cleans_a_payload( $payload ) {
		$before = $this->stored();

		// 1. Everything is the payload: a number or a choice can not be one.
		$hostile = array();
		foreach ( array_keys( $this->schema() ) as $name ) {
			$hostile[ $name ] = 'f_multi' === $name ? array( $payload ) : $payload;
		}
		$url = $this->save( 'new', 'security_fields', $hostile, array( 'label' => $payload ) );
		$this->assertNotNull( $url );
		$this->assertSame( $before, $this->stored(), 'A refused save changed the options.' );
		$html = $this->tab(
			array(
				'action' => 'new',
				'type'   => 'security_fields',
			)
		);
		$this->assertStringContainsString( '<form', $html );
		$this->assert_no_live_markup( $html, 'Form after a refused save' );

		// 2. Text-like fields carry the payload, the others are valid.
		$_POST = array();
		$mixed = $this->valid_settings();
		$leaky = $payload . self::SECRET;
		$mixed = array_merge(
			$mixed,
			array(
				'f_text'        => $payload,
				'f_textarea'    => $payload,
				'f_url'         => 'https://example.org/?q=' . rawurlencode( $payload ) . $payload,
				'f_secret'      => $leaky,
				'f_text_secret' => $leaky,
			)
		);
		$url   = $this->save( 'new', 'security_fields', $mixed, array( 'label' => 'Label ' . $payload ) );
		$this->assertNotNull( $url );
		sc_wpun()->notices()->take();

		$ids = array_diff( array_keys( sc_wpun_channels()->all() ), array( 'email' ) );
		$this->assertNotEmpty( $ids, 'The save with text fields holding a payload was refused.' );

		$id       = reset( $ids );
		$instance = sc_wpun_channels()->get( $id );
		foreach ( array( 'f_text', 'f_textarea' ) as $name ) {
			$this->assertDoesNotMatchRegularExpression( '/</', (string) $instance->get_setting( $name ), "$name kept a tag." );
		}
		$this->assertDoesNotMatchRegularExpression( '/</', $instance->get_label(), 'The label kept markup.' );
		$this->assertMatchesRegularExpression( '#^https?://#', (string) $instance->get_setting( 'f_url' ) );
		$this->assertDoesNotMatchRegularExpression( '/[<"]/', (string) $instance->get_setting( 'f_url' ), 'The URL kept markup characters.' );

		$edit = $this->tab(
			array(
				'action' => 'edit',
				'id'     => $id,
			)
		);
		$this->assertStringContainsString( 'name="settings[f_text]"', $edit );
		$this->assert_no_live_markup( $edit, 'Edit form' );
		$this->assert_no_live_markup( $this->tab( array() ), 'Channels list' );
		$this->assertStringNotContainsString( self::SECRET, $edit, 'A secret was drawn in the edit form.' );
		$this->assertStringNotContainsString( self::SECRET, $this->tab( array() ), 'A secret was drawn in the list.' );
	}

	/**
	 * The built-in email schema, with the payload in every field: refused with nothing stored, or cleaned.
	 *
	 * @dataProvider payloads_provider
	 *
	 * @param string $payload The payload.
	 */
	public function test_the_email_schema_refuses_or_cleans_a_payload( $payload ) {
		$before = $this->stored();

		// Every field the payload, address included: not an address.
		$settings = array(
			'to'             => $payload,
			'from'           => $payload,
			'reply_to'       => $payload,
			'format'         => $payload,
			'subject_prefix' => $payload,
		);
		$routing  = array(
			'scope' => $payload,
			'core'  => $payload,
			'types' => array( $payload ),
		);
		$this->save( 'edit', 'email', $settings, array( 'label' => $payload, 'routing' => $routing ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( $before, $this->stored(), 'A refused save changed the options.' );
		sc_wpun()->notices()->take();
		$this->assert_no_live_markup(
			$this->tab(
				array(
					'action' => 'edit',
					'id'     => 'email',
				)
			),
			'Email form after a refused save'
		);

		// The address is right, the other fields are the payload.
		$settings['to'] = 'ops@example.org';
		$this->save( 'edit', 'email', $settings, array( 'label' => 'Email ' . $payload ) );
		sc_wpun()->notices()->take();
		$email = sc_wpun_channels()->get( 'email' );
		$this->assertStringStartsWith( 'Email', $email->get_label(), 'The save with the payload in the text fields was refused.' );
		foreach ( array( 'to', 'from', 'reply_to', 'subject_prefix' ) as $name ) {
			$this->assertDoesNotMatchRegularExpression( '/</', (string) $email->get_setting( $name ), "$name kept a tag." );
		}
		$this->assertContains( $email->get_setting( 'format' ), array( 'text', 'html' ), 'The format is not one of the two.' );
		$this->assertDoesNotMatchRegularExpression( '/</', $email->get_label() );
		$this->assert_no_live_markup(
			$this->tab(
				array(
					'action' => 'edit',
					'id'     => 'email',
				)
			),
			'Email edit form'
		);
		$this->assert_no_live_markup( $this->tab( array() ), 'Channels list' );
	}

	/**
	 * A line break in an address or a prefix can not add a mail header.
	 */
	public function test_a_line_break_in_the_email_settings_adds_no_header() {
		$this->save(
			'edit',
			'email',
			array(
				'to'             => 'ops@example.org',
				'from'           => "notifier@example.org\r\nBcc: evil@example.net",
				'reply_to'       => "reply@example.org\nBcc: evil@example.net",
				'format'         => 'text',
				'subject_prefix' => "[Updates]\r\nBcc: evil@example.net",
			)
		);
		sc_wpun()->notices()->take();

		foreach ( array( 'from', 'reply_to', 'subject_prefix' ) as $name ) {
			$this->assertDoesNotMatchRegularExpression( '/[\r\n]/', (string) sc_wpun_channels()->get( 'email' )->get_setting( $name ), "$name kept a line break." );
		}

		$this->set_updates( '99.0.0' );
		sc_wpun()->update_checker()->do_update_check();
		$mail = $this->sent_mail();
		$this->assertNotFalse( $mail );
		$this->assertDoesNotMatchRegularExpression( '/^Bcc:/mi', $mail->header, 'A Bcc header was added.' );
		$this->assertDoesNotMatchRegularExpression( '/[\r\n]/', $mail->subject, 'The subject has a line break.' );
		$this->assertStringNotContainsString( 'evil@example.net', (string) wp_json_encode( $mail->to ) );
	}
}
