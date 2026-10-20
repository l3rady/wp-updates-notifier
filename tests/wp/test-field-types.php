<?php
/**
 * The text, textarea, url, email_list, secret and number field types, and the field type registry (#164).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Field_Renderer;
use WPUN\Field_Types;

/**
 * Drawing, sanitising and validating the built-in value types, and adding or replacing types with a filter.
 */
class Test_Field_Types extends WPUN_Test_Case {

	/**
	 * Start clean.
	 */
	public function set_up() {
		parent::set_up();

		Field_Types::forget_warnings();
	}

	/**
	 * The markup parsed.
	 *
	 * @param string $html Markup.
	 * @return DOMXPath
	 */
	private function xpath( $html ) {
		$doc = new DOMDocument();
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );

		return new DOMXPath( $doc );
	}

	/**
	 * A channel with these fields.
	 *
	 * @param array $fields Fields.
	 * @return Test_Fields_Channel
	 */
	private function channel( array $fields ) {
		return new Test_Fields_Channel( 'test_types', $fields );
	}

	/**
	 * Validate one field with the default rules.
	 *
	 * @param array $field    Schema.
	 * @param mixed $value    Submitted value.
	 * @param array $stored   Stored settings.
	 * @param array $extra    More submitted settings.
	 * @return array|WP_Error
	 */
	private function check( array $field, $value, array $stored = array(), array $extra = array() ) {
		return $this->channel( array( 'f' => $field ) )->validate_settings( array( 'f' => $value ) + $extra, $stored );
	}

	/**
	 * Text and textarea.
	 */
	public function test_text_and_textarea() {
		$this->assertSame( array( 'f' => 'a b' ), $this->check( array( 'type' => 'text' ), " <b>a</b>\n b " ) );
		$this->assertSame( array( 'f' => "a\nb" ), $this->check( array( 'type' => 'textarea' ), "a\nb" ) );
		$this->assertSame( array( 'f' => '5' ), $this->check( array( 'type' => 'text' ), 5 ) );
		foreach ( array( 'text', 'textarea', 'url', 'email_list', 'secret' ) as $type ) {
			$error = $this->check( array( 'type' => $type ), array( 'x' ) );
			$this->assertInstanceOf( WP_Error::class, $error, $type );
			$this->assertSame( 'f', $error->get_error_data()['field'] );
		}
		$this->assertInstanceOf( WP_Error::class, $this->check( array( 'type' => 'text' ), true ) );
	}

	/**
	 * Urls.
	 */
	public function test_url() {
		$field = array( 'type' => 'url' );
		$this->assertSame( array( 'f' => 'https://example.com/path?q=1' ), $this->check( $field, ' https://example.com/path?q=1 ' ) );
		$this->assertSame( array( 'f' => 'http://example.com' ), $this->check( $field, 'HTTP://example.com' ) );
		$this->assertSame( array( 'f' => '' ), $this->check( $field, '' ) );
		foreach ( array( 'javascript:alert(1)', '/relative', '//example.com', 'ftp://x', 'example.com', 'https://', 'http:///x' ) as $bad ) {
			$error = $this->check( $field, $bad );
			$this->assertInstanceOf( WP_Error::class, $error, $bad );
			$this->assertSame( array( 'field' => 'f' ), $error->get_error_data() );
		}
	}

	/**
	 * Email lists.
	 */
	public function test_email_list() {
		$field = array( 'type' => 'email_list' );
		$this->assertSame( array( 'f' => 'a@example.com,b@example.org' ), $this->check( $field, ' a@example.com , b@example.org ' ) );
		$this->assertSame( array( 'f' => 'a@example.com,b@example.org' ), $this->check( $field, 'a@example.com,,b@example.org,' ) );
		$this->assertSame( array( 'f' => 'a@example.com,b@example.org' ), $this->check( $field, array( 'a@example.com', 'b@example.org' ) ) );
		$this->assertSame( array( 'f' => '' ), $this->check( $field, ' , ' ) );
		$this->assertInstanceOf( WP_Error::class, $this->check( $field, 'a@example.com, nope' ) );
		$this->assertInstanceOf( WP_Error::class, $this->check( $field, array( 'a@example.com', 5 ) ) );
	}

	/**
	 * Numbers.
	 */
	public function test_number() {
		$field = array(
			'type' => 'number',
			'min'  => 1,
			'max'  => 10,
		);
		$this->assertSame( array( 'f' => 5 ), $this->check( $field, '5' ) );
		$this->assertSame( array( 'f' => 5 ), $this->check( $field, ' 5 ' ) );
		$this->assertSame( array( 'f' => 10 ), $this->check( $field, 10 ) );
		foreach ( array( '0', 11, '1.5', 'abc', true, array( 5 ), 1.5 ) as $bad ) {
			$error = $this->check( $field, $bad );
			$this->assertInstanceOf( WP_Error::class, $error, var_export( $bad, true ) );
			$this->assertSame( array( 'field' => 'f' ), $error->get_error_data() );
		}

		$this->assertSame( array(), $this->check( $field, '' ), 'An empty number is left out.' );
		$this->assertSame( array(), $this->check( $field, null ) );
		$this->assertSame( array( 'f' => -3 ), $this->check( array( 'type' => 'number' ), '-3' ) );
	}

	/**
	 * Required.
	 */
	public function test_required() {
		foreach ( array( 'text', 'textarea', 'url', 'email_list', 'number', 'secret', 'enum', 'select' ) as $type ) {
			$field = array(
				'type'     => $type,
				'label'    => 'Thing',
				'required' => true,
				'options'  => array( 'a' => 'A' ),
				'multiple' => 'enum' === $type,
			);
			$error = $this->check( $field, '' );
			$this->assertInstanceOf( WP_Error::class, $error, $type );
			$this->assertSame( 'invalid_field', $error->get_error_code() );
			$this->assertSame( 'Thing is required.', $error->get_error_message(), $type );
			$this->assertSame( array( 'field' => 'f' ), $error->get_error_data() );
		}

		// A required field missing from the submission.
		$channel = $this->channel(
			array(
				'f' => array(
					'type'     => 'text',
					'required' => true,
				),
			)
		);
		$error   = $channel->validate_settings( array() );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'f is required.', $error->get_error_message() );
		$this->assertSame( array(), $channel->validate_settings( array(), array( 'f' => 'saved' ) ), 'Stored, so it can stay missing.' );

		// False and 0 are values.
		$this->assertSame(
			array( 'f' => false ),
			$this->check(
				array(
					'type'     => 'boolean',
					'required' => true,
				),
				'0'
			)
		);
		$this->assertSame(
			array( 'f' => 0 ),
			$this->check(
				array(
					'type'     => 'number',
					'required' => true,
				),
				'0'
			)
		);
	}

	/**
	 * Validate callbacks.
	 */
	public function test_validate_callback() {
		$seen  = array();
		$field = array(
			'type'              => 'text',
			'sanitize_callback' => 'strtoupper',
			'validate_callback' => function ( $value ) use ( &$seen ) {
				$seen[] = $value;
				return 'BAD' === $value ? new WP_Error( 'nope', 'Not that one.' ) : ( 'NO' === $value ? false : true );
			},
		);

		$this->assertSame( array( 'f' => 'OK' ), $this->check( $field, 'ok' ) );
		$this->assertSame( array( 'OK' ), $seen, 'It gets the clean value.' );

		$error = $this->check( $field, 'bad' );
		$this->assertSame( 'nope', $error->get_error_code() );
		$this->assertSame( 'Not that one.', $error->get_error_message() );
		$this->assertSame( array( 'field' => 'f' ), $error->get_error_data() );

		$error = $this->check( $field, 'no' );
		$this->assertSame( 'invalid_field', $error->get_error_code() );
		$this->assertSame( array( 'field' => 'f' ), $error->get_error_data() );

		$field['validate_callback'] = function () {
			return new WP_Error( 'x', 'Msg', array( 'other' => 1 ) );
		};
		$this->assertSame(
			array(
				'other' => 1,
				'field' => 'f',
			),
			$this->check( $field, 'a' )->get_error_data()
		);
	}

	/**
	 * Keeping, clearing and replacing a secret.
	 */
	public function test_secret_keep_and_clear() {
		foreach ( array(
			array( 'type' => 'secret' ),
			array(
				'type'   => 'text',
				'secret' => true,
			),
			array(
				'type'   => 'url',
				'secret' => true,
			),
		) as $field ) {
			$url = 'url' === $field['type'];
			$old = $url ? 'https://old.example.com' : 'old';
			$new = $url ? 'https://new.example.com' : 'new';
			$msg = $field['type'];

			$this->assertSame( array( 'f' => $new ), $this->check( $field, $new, array( 'f' => $old ) ), $msg );
			$this->assertSame( array( 'f' => $old ), $this->check( $field, '', array( 'f' => $old ) ), $msg );
			$this->assertSame( array( 'f' => $old ), $this->channel( array( 'f' => $field ) )->validate_settings( array(), array( 'f' => $old ) ), $msg );
			$this->assertSame( array(), $this->check( $field, '', array() ), $msg );
			$this->assertSame( array( 'f' => '' ), $this->check( $field, '', array( 'f' => $old ), array( 'f__clear' => '1' ) ), $msg );
			$this->assertSame( array( 'f' => '' ), $this->check( $field, $new, array( 'f' => $old ), array( 'f__clear' => true ) ), $msg );
			$this->assertSame( array( 'f' => $old ), $this->check( $field, '', array( 'f' => $old ), array( 'f__clear' => '0' ) ), $msg );

			$required = $field + array( 'required' => true );
			$this->assertSame( array( 'f' => $old ), $this->check( $required, '', array( 'f' => $old ) ), $msg );
			$this->assertInstanceOf( WP_Error::class, $this->check( $required, '', array( 'f' => $old ), array( 'f__clear' => '1' ) ), $msg );
			$this->assertInstanceOf( WP_Error::class, $this->check( $required, '', array() ), $msg );
			$this->assertSame( array( 'f' => $new ), $this->check( $required, $new, array() ), $msg );

			$error = $this->check( $field, $url ? 'javascript:x' : array( 'x' ), array( 'f' => $old ) );
			$this->assertSame( array( 'field' => 'f' ), $error->get_error_data(), $msg );
		}

		// A secret keeps characters a text field would strip, and loses only control characters and outer spaces.
		$this->assertSame( array( 'f' => 'a<b>%41 c&d' ), $this->check( array( 'type' => 'secret' ), " a<b>%41 c&d\n\t" ) );
		$this->assertSame( array( 'f' => 'ab' ), $this->check( array( 'type' => 'secret' ), "a\x00b" ) );

		$result = $this->check( array( 'type' => 'secret' ), 'x', array(), array( 'f__clear' => '1' ) );
		$this->assertArrayNotHasKey( 'f__clear', $result );
	}

	/**
	 * The secret flag works on every type, built in or added.
	 */
	public function test_secret_flag_on_other_types() {
		$this->assertFalse( Field_Types::is_secret( array( 'type' => 'text' ) ) );
		$this->assertFalse( Field_Types::is_secret( array( 'type' => 'text', 'secret' => 'yes' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( Field_Types::is_secret( array( 'type' => 'secret' ) ) );
		foreach ( array( 'textarea', 'number', 'boolean', 'enum', 'whatever' ) as $type ) {
			$this->assertTrue( Field_Types::is_secret( array( 'type' => $type, 'secret' => true ) ), $type ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
	}

	/**
	 * Only declared fields are kept, and each error names its field.
	 */
	public function test_declared_fields_only_and_field_in_errors() {
		$channel = $this->channel(
			array(
				'a' => array( 'type' => 'text' ),
				'b' => array( 'type' => 'number' ),
			)
		);
		$this->assertSame(
			array(
				'a' => 'x',
				'b' => 2,
			),
			$channel->validate_settings(
				array(
					'a'     => 'x',
					'b'     => '2',
					'other' => 'y',
				)
			)
		);
		$this->assertSame( array( 'field' => 'b' ), $channel->validate_settings( array( 'b' => 'no' ) )->get_error_data() );
	}

	/**
	 * Markup of the plain inputs.
	 */
	public function test_render_inputs() {
		$value = '"><script>alert(1)</script>';
		$cases = array(
			'text'       => array( 'input', 'text', 'regular-text' ),
			'url'        => array( 'input', 'url', 'regular-text' ),
			'email_list' => array( 'input', 'text', 'regular-text' ),
			'number'     => array( 'input', 'number', 'small-text' ),
		);
		foreach ( $cases as $type => $expect ) {
			$html = Field_Renderer::field( array( 'type' => $type ), 'opt[x]', 'opt-x', $value, true, 'opt-x-desc' );
			$xp   = $this->xpath( $html );
			$node = $xp->query( '//input[@id="opt-x"]' );
			$this->assertSame( 1, $node->length, $type );
			$input = $node->item( 0 );
			$this->assertSame( $expect[1], $input->getAttribute( 'type' ), $type );
			$this->assertSame( $expect[2], $input->getAttribute( 'class' ), $type );
			$this->assertSame( 'opt[x]', $input->getAttribute( 'name' ), $type );
			$this->assertSame( $value, $input->getAttribute( 'value' ), $type );
			$this->assertSame( 'disabled', $input->getAttribute( 'disabled' ), $type );
			$this->assertSame( 'opt-x-desc', $input->getAttribute( 'aria-describedby' ), $type );
			$this->assertStringNotContainsString( '<script>', $html, $type );
			$this->assertSame( 0, $xp->query( '//script' )->length );
		}

		$html = Field_Renderer::field( array( 'type' => 'text' ), 'n', 'i', 'v' );
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringNotContainsString( 'aria-describedby', $html );
		$this->assertStringNotContainsString( 'aria-invalid', $html );
	}

	/**
	 * Number bounds are attributes.
	 */
	public function test_render_number_bounds() {
		$input = $this->xpath(
			Field_Renderer::field(
				array(
					'type' => 'number',
					'min'  => 1,
					'max'  => '10',
				),
				'n',
				'i',
				3
			)
		)->query( '//input' )->item( 0 );
		$this->assertSame( '1', $input->getAttribute( 'min' ) );
		$this->assertSame( '10', $input->getAttribute( 'max' ) );
		$this->assertSame( '3', $input->getAttribute( 'value' ) );

		$input = $this->xpath( Field_Renderer::field( array( 'type' => 'number' ), 'n', 'i', '' ) )->query( '//input' )->item( 0 );
		$this->assertFalse( $input->hasAttribute( 'min' ) );
		$this->assertFalse( $input->hasAttribute( 'max' ) );
	}

	/**
	 * Textarea markup is escaped.
	 */
	public function test_render_textarea() {
		$html = Field_Renderer::field( array( 'type' => 'textarea' ), 'n', 'i', "</textarea><b>x</b>\nline" );
		$area = $this->xpath( $html )->query( '//textarea[@id="i"]' )->item( 0 );
		$this->assertSame( 'large-text', $area->getAttribute( 'class' ) );
		$this->assertSame( '5', $area->getAttribute( 'rows' ) );
		$this->assertSame( "</textarea><b>x</b>\nline", $area->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 0, $this->xpath( $html )->query( '//b' )->length );
	}

	/**
	 * A secret never outputs its value, whatever the state.
	 */
	public function test_render_secret_never_outputs_value() {
		$secret = 'S3cr3t-"value"';
		foreach ( array(
			array( 'type' => 'secret' ),
			array(
				'type'   => 'text',
				'secret' => true,
			),
			array(
				'type'   => 'url',
				'secret' => true,
			),
			array(
				'type'   => 'email_list',
				'secret' => true,
			),
			array(
				'type'   => 'textarea',
				'secret' => true,
			),
		) as $field ) {
			foreach ( array( array( false, '' ), array( true, '' ), array( false, 'Went wrong' ), array( true, 'Went wrong' ) ) as $state ) {
				$html = Field_Renderer::field( $field, 'n', 'i', $secret, $state[0], 'd', array(), $state[1] );
				$this->assertStringNotContainsString( $secret, $html );
				$this->assertStringNotContainsString( 'S3cr3t', $html );
				$input = $this->xpath( $html )->query( '//input[@id="i"]' )->item( 0 );
				$this->assertSame( 'password', $input->getAttribute( 'type' ) );
				$this->assertSame( 'off', $input->getAttribute( 'autocomplete' ) );
				$this->assertSame( 'regular-text', $input->getAttribute( 'class' ) );
				$this->assertSame( '', $input->getAttribute( 'value' ) );
			}
		}
	}

	/**
	 * The Clear checkbox appears only for a saved value in an enabled field.
	 */
	public function test_render_secret_clear_checkbox() {
		$field = array( 'type' => 'secret' );

		$xp = $this->xpath( Field_Renderer::field( $field, 'token', 'tok', 'saved' ) );
		$this->assertSame( 1, $xp->query( '//input[@type="checkbox"][@name="token__clear"][@id="tok__clear"][@value="1"]' )->length );
		$this->assertSame( 1, $xp->query( '//label[@for="tok__clear"]' )->length );

		$xp = $this->xpath( Field_Renderer::field( $field, 'opt[token]', 'tok', 'saved' ) );
		$this->assertSame( 1, $xp->query( '//input[@type="checkbox"][@name="opt[token__clear]"]' )->length );

		$this->assertSame( 0, $this->xpath( Field_Renderer::field( $field, 'token', 'tok', '' ) )->query( '//input[@type="checkbox"]' )->length, 'Nothing saved.' );
		$this->assertSame( 0, $this->xpath( Field_Renderer::field( $field, 'token', 'tok', null ) )->query( '//input[@type="checkbox"]' )->length );
		$this->assertSame( 0, $this->xpath( Field_Renderer::field( $field, 'token', 'tok', 'saved', true ) )->query( '//input[@type="checkbox"]' )->length, 'Disabled.' );
	}

	/**
	 * Errors are drawn after the control and linked to it.
	 */
	public function test_render_error() {
		$field = array(
			'type'     => 'enum',
			'options'  => array(
				'a' => 'A',
				'b' => 'B',
			),
			'display'  => 'select',
			'multiple' => false,
		);
		$types = array(
			array( array( 'type' => 'text' ), '//input[@id="i"]' ),
			array( array( 'type' => 'textarea' ), '//textarea[@id="i"]' ),
			array( array( 'type' => 'number' ), '//input[@id="i"]' ),
			array( array( 'type' => 'secret' ), '//input[@id="i"]' ),
			array( $field, '//select[@id="i"]' ),
			array( array( 'type' => 'boolean' ), '//input[@id="i"][@type="checkbox"]' ),
			array( array_merge( $field, array( 'display' => 'radio' ) ), '//fieldset[@id="i"]' ),
			array(
				array(
					'type'    => 'boolean',
					'display' => 'radio',
				),
				'//fieldset[@id="i"]',
			),
		);
		foreach ( $types as $case ) {
			$html = Field_Renderer::field( $case[0], 'n', 'i', 'a', false, 'i-desc', array(), 'Needs <b>fixing</b>' );
			$xp   = $this->xpath( $html );
			$node = $xp->query( $case[1] )->item( 0 );
			$this->assertNotNull( $node, $html );
			$this->assertSame( 'true', $node->getAttribute( 'aria-invalid' ), $html );
			$this->assertSame( 'secret' === $case[0]['type'] ? 'i-saved i-desc i-error' : 'i-desc i-error', $node->getAttribute( 'aria-describedby' ), $html ); // A saved secret is also described by its own note.
			$p = $xp->query( '//p[@class="sc-wpun-field-error"][@id="i-error"]' );
			$this->assertSame( 1, $p->length );
			$this->assertSame( 'Error: Needs <b>fixing</b>', $p->item( 0 )->textContent );
			$this->assertSame( 1, $xp->query( '//p[@id="i-error"]/strong[text()="Error:"]' )->length );
			$this->assertSame( 0, $xp->query( '//b' )->length );
		}

		$html = Field_Renderer::field( array( 'type' => 'text' ), 'n', 'i', 'a', false, '', array(), 'Bad' );
		$this->assertSame( 'i-error', $this->xpath( $html )->query( '//input' )->item( 0 )->getAttribute( 'aria-describedby' ) );

		$html = Field_Renderer::field( array( 'type' => 'text' ), 'n', 'i', 'a' );
		$this->assertStringNotContainsString( 'sc-wpun-field-error', $html );
	}

	/**
	 * Enum extra options still pass through field().
	 */
	public function test_field_passes_extra_options_to_enum() {
		$field = array(
			'type'    => 'enum',
			'options' => array( 'a' => 'A' ),
		);
		$this->assertSame( Field_Renderer::enum( $field, 'n', 'i', 'old', false, '', array( 'old' => 'Old' ) ), Field_Renderer::field( $field, 'n', 'i', 'old', false, '', array( 'old' => 'Old' ) ) );
	}

	/**
	 * A type nothing handles complains once, in the renderer and in validation.
	 */
	public function test_unknown_type() {
		$this->setExpectedIncorrectUsage( 'WPUN\Field_Types::unknown' );

		$this->assertSame( '', Field_Renderer::field( array( 'type' => 'nope' ), 'n', 'i', 'x' ) );
		$this->assertSame( '', Field_Renderer::field( array( 'type' => 'nope' ), 'n', 'i', 'x' ) );
		$this->assertSame( array(), $this->channel( array( 'f' => array( 'type' => 'nope' ) ) )->validate_settings( array( 'f' => 'x' ) ), 'The field is dropped.' );
	}

	/**
	 * Unknown type in validate_settings alone.
	 */
	public function test_unknown_type_in_validation() {
		$this->setExpectedIncorrectUsage( 'WPUN\Field_Types::unknown' );

		$channel = $this->channel(
			array(
				'f' => array( 'type' => 'nope' ),
				'g' => array( 'type' => 'text' ),
			)
		);
		$this->assertSame(
			array( 'g' => 'y' ),
			$channel->validate_settings(
				array(
					'f' => 'x',
					'g' => 'y',
				)
			)
		);
	}

	/**
	 * A custom type renders and sanitises.
	 */
	public function test_filter_adds_a_type() {
		add_filter(
			'sc_wpun_field_types',
			function ( $types ) {
				$types['colour'] = array(
					'render'   => function ( $field, $name, $id, $value, $disabled, $described_by, $error ) {
						return '<input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" data-by="' . esc_attr( $described_by ) . '" data-error="' . esc_attr( $error ) . '" />';
					},
					'sanitize' => function ( $value, $field ) {
						$hex = sanitize_hex_color( $value );
						return $hex ? $hex : new WP_Error( 'colour', 'Not a colour.', array( 'name' => $field['name'] ) );
					},
				);
				return $types;
			}
		);

		$input = $this->xpath( Field_Renderer::field( array( 'type' => 'colour' ), 'n', 'i', '#fff', false, 'd', array(), 'E' ) )->query( '//input' )->item( 0 );
		$this->assertSame( 'color', $input->getAttribute( 'type' ) );
		$this->assertSame( '#fff', $input->getAttribute( 'value' ) );
		$this->assertSame( 'd i-error', $input->getAttribute( 'data-by' ) );
		$this->assertSame( 'E', $input->getAttribute( 'data-error' ) );

		$channel = $this->channel( array( 'c' => array( 'type' => 'colour' ) ) );
		$this->assertSame( array( 'c' => '#ffffff' ), $channel->validate_settings( array( 'c' => '#ffffff' ) ) );

		$error = $channel->validate_settings( array( 'c' => 'red' ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'colour', $error->get_error_code() );
		$this->assertSame( 'Not a colour.', $error->get_error_message() );
		$this->assertSame(
			array(
				'name'  => 'c',
				'field' => 'c',
			),
			$error->get_error_data(),
			'The error names the field and the schema had its name.'
		);
	}

	/**
	 * A built-in type can be replaced.
	 */
	public function test_filter_replaces_a_builtin() {
		add_filter(
			'sc_wpun_field_types',
			function ( $types ) {
				$types['text']['render']   = function ( $field, $name ) {
					return '<span>custom ' . esc_html( $name ) . '</span>';
				};
				$types['text']['sanitize'] = function ( $value ) {
					return strtoupper( $value );
				};
				return $types;
			}
		);

		$this->assertSame( '<span>custom n</span>', Field_Renderer::field( array( 'type' => 'text' ), 'n', 'i', 'x' ) );
		$this->assertSame( array( 'f' => 'ABC' ), $this->check( array( 'type' => 'text' ), 'abc' ) );
		$this->assertStringContainsString( 'type="url"', Field_Renderer::field( array( 'type' => 'url' ), 'n', 'i', 'x' ), 'Others stay.' );
	}

	/**
	 * Replacing boolean replaces its checkbox alias; the select alias still shows a select.
	 */
	public function test_aliases_resolve_before_lookup() {
		add_filter(
			'sc_wpun_field_types',
			function ( $types ) {
				$types['boolean']['render'] = function ( $field ) {
					return '<i>' . esc_html( $field['type'] ) . '</i>';
				};
				return $types;
			}
		);

		$this->assertSame( '<i>checkbox</i>', Field_Renderer::field( array( 'type' => 'checkbox' ), 'n', 'i', 1 ) );
		$this->assertSame( '<i>boolean</i>', Field_Renderer::field( array( 'type' => 'boolean' ), 'n', 'i', 1 ) );

		$html = Field_Renderer::field(
			array(
				'type'    => 'select',
				'options' => array(
					'a' => 'A',
					'b' => 'B',
				),
			),
			'n',
			'i',
			'a'
		);
		$this->assertSame( 1, $this->xpath( $html )->query( '//select[@id="i"]' )->length );

		$this->assertSame( 'boolean', Field_Types::canonical( 'checkbox' ) );
		$this->assertSame( 'enum', Field_Types::canonical( 'select' ) );
		$this->assertSame( 'text', Field_Types::canonical( 'text' ) );
		$this->assertSame( '', Field_Types::canonical( array() ) );
	}

	/**
	 * Replacing enum is honoured by field().
	 */
	public function test_replaced_enum_render_is_called() {
		add_filter(
			'sc_wpun_field_types',
			function ( $types ) {
				$types['enum']['render'] = function () {
					return '<em>mine</em>';
				};
				return $types;
			}
		);

		$this->assertSame( '<em>mine</em>', Field_Renderer::field( array( 'type' => 'select' ), 'n', 'i', 'a' ) );
	}

	/**
	 * Malformed entries are dropped with one notice.
	 */
	public function test_malformed_entries() {
		$this->setExpectedIncorrectUsage( 'WPUN\Field_Types::all' );
		$this->setExpectedIncorrectUsage( 'WPUN\Field_Types::unknown' );

		add_filter(
			'sc_wpun_field_types',
			function ( $types ) {
				$types['bad']            = array(
					'render'   => 'not_a_function',
					'sanitize' => 'strtoupper',
				);
				$types['str']            = 'just a string';
				$types['text']['render'] = 'not_a_function';
				return $types;
			}
		);

		$types = Field_Types::all();
		$this->assertArrayNotHasKey( 'bad', $types );
		$this->assertArrayNotHasKey( 'str', $types );
		$this->assertArrayHasKey( 'text', $types, 'A malformed replacement leaves the built-in type.' );
		$this->assertTrue( is_callable( $types['text']['render'] ) );
		Field_Types::all();
		$this->assertSame( '', Field_Renderer::field( array( 'type' => 'bad' ), 'n', 'i', 'x' ) );
	}

	/**
	 * The filter result is not cached between lookups.
	 */
	public function test_filter_is_applied_on_every_lookup() {
		$this->assertNull( Field_Types::get( 'late' ) );
		add_filter(
			'sc_wpun_field_types',
			function ( $types ) {
				$types['late'] = array(
					'render'   => '__return_empty_string',
					'sanitize' => '__return_empty_string',
				);
				return $types;
			}
		);
		$this->assertNotNull( Field_Types::get( 'late' ) );
	}
}
