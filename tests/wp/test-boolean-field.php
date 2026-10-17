<?php
/**
 * The boolean field type (#160).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Field_Renderer;
use WPUN\Boolean_Field;

/**
 * Normalising, casting, sanitising and drawing boolean fields, and how channels use them.
 */
class Test_Boolean_Field extends WPUN_Test_Case {

	/**
	 * Start clean.
	 */
	public function set_up() {
		parent::set_up();

		Boolean_Field::forget_warnings();
		sc_wpun()->config()->flush();
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
	 * Defaults for display and labels.
	 */
	public function test_normalise_defaults() {
		$checkbox = Boolean_Field::normalise(
			array(
				'type'  => 'checkbox',
				'label' => 'Notify',
			)
		);
		$this->assertSame( 'boolean', $checkbox['type'] );
		$this->assertSame( 'checkbox', $checkbox['display'] );
		$this->assertSame( 'Notify', $checkbox['labels']['on'], 'The checkbox text is the label.' );
		$this->assertSame( 'No', $checkbox['labels']['off'] );

		$radio = Boolean_Field::normalise(
			array(
				'type'    => 'boolean',
				'label'   => 'Notify',
				'display' => 'radio',
			)
		);
		$this->assertSame( 'Yes', $radio['labels']['on'] );
		$this->assertSame( 'No', $radio['labels']['off'] );

		$custom = Boolean_Field::normalise(
			array(
				'type'    => 'boolean',
				'display' => 'select',
				'labels'  => array(
					'on'  => 'Hide',
					'off' => 5,
				),
			)
		);
		$this->assertSame( 'select', $custom['display'] );
		$this->assertSame( 'Hide', $custom['labels']['on'] );
		$this->assertSame( 'No', $custom['labels']['off'], 'A label that is not a string is replaced.' );
	}

	/**
	 * An unknown display falls back to checkbox, with one notice per field.
	 */
	public function test_unknown_display_warns_once_and_falls_back() {
		$this->setExpectedIncorrectUsage( 'WPUN\Boolean_Field::normalise' );

		$field = array(
			'type'    => 'boolean',
			'label'   => 'Notify',
			'display' => 'toggle',
		);
		$this->assertSame( 'checkbox', Boolean_Field::normalise( $field )['display'] );
		$this->assertSame( 'checkbox', Boolean_Field::normalise( $field )['display'] );
		$this->assertStringContainsString( 'type="checkbox"', Field_Renderer::boolean( $field, 'n', 'i', true ) );

		$field['display'] = array( 'radio' );
		$this->assertSame( 'checkbox', Boolean_Field::normalise( $field )['display'] );
	}

	/**
	 * The default is the field's own when valid, else false.
	 */
	public function test_default_value() {
		$this->assertFalse( Boolean_Field::default_value( array( 'type' => 'boolean' ) ) );
		$this->assertTrue( Boolean_Field::default_value( array( 'default' => true ) ) );
		$this->assertTrue( Boolean_Field::default_value( array( 'default' => 1 ) ) );
		$this->assertFalse( Boolean_Field::default_value( array( 'default' => false ) ) );
		$this->assertFalse( Boolean_Field::default_value( array( 'default' => 'maybe' ) ) );
		$this->assertFalse( Boolean_Field::default_value( array( 'default' => array( true ) ) ) );
	}

	/**
	 * Submitted and stored values.
	 */
	public function test_sanitize() {
		$field = array( 'type' => 'boolean' );

		foreach ( array( true, 1, '1' ) as $on ) {
			$this->assertSame(
				array(
					'ok'    => true,
					'value' => true,
				),
				Boolean_Field::sanitize( $on, $field ),
				var_export( $on, true )
			);
		}
		foreach ( array( false, 0, '0', '' ) as $off ) {
			$this->assertSame(
				array(
					'ok'    => true,
					'value' => false,
				),
				Boolean_Field::sanitize( $off, $field ),
				var_export( $off, true )
			);
		}
	}

	/**
	 * The hidden 0 on its own is off; followed by a ticked box PHP receives the last one, '1'.
	 */
	public function test_sanitize_hidden_input_with_and_without_a_ticked_box() {
		$field = array( 'type' => 'boolean' );

		parse_str( 'opt=0', $unticked );
		$this->assertFalse( Boolean_Field::sanitize( $unticked['opt'], $field )['value'] );

		parse_str( 'opt=0&opt=1', $ticked );
		$this->assertSame( '1', $ticked['opt'] );
		$this->assertTrue( Boolean_Field::sanitize( $ticked['opt'], $field )['value'] );
	}

	/**
	 * Anything else is invalid and the default comes back.
	 */
	public function test_sanitize_invalid_values_give_the_default() {
		foreach ( array( true, false ) as $default ) {
			$field = array(
				'type'    => 'boolean',
				'default' => $default,
			);
			foreach ( array( 2, -1, 'yes', 'on', 'true', ' 1', '1.0', 1.0, 0.0, array(), array( '1' ), null ) as $bad ) {
				$result = Boolean_Field::sanitize( $bad, $field );
				$this->assertFalse( $result['ok'], var_export( $bad, true ) );
				$this->assertSame( $default, $result['value'], var_export( $bad, true ) );
			}
		}
	}

	/**
	 * Values from outside the database.
	 */
	public function test_cast_external() {
		$field = array( 'type' => 'boolean' );

		foreach ( array( true, 1, '1', 'true', 'yes', 'on', 'TRUE', 'Yes', ' On ', "YES\n" ) as $on ) {
			$this->assertSame(
				array(
					'ok'    => true,
					'value' => true,
				),
				Boolean_Field::cast_external( $on, $field ),
				var_export( $on, true )
			);
		}
		foreach ( array( false, 0, '0', 'false', 'no', 'off', '', 'FALSE', 'No', ' Off ', '  ' ) as $off ) {
			$this->assertSame(
				array(
					'ok'    => true,
					'value' => false,
				),
				Boolean_Field::cast_external( $off, $field ),
				var_export( $off, true )
			);
		}
		foreach ( array( 'maybe', 'y', '2', 2, 1.0, null, array(), array( 'yes' ) ) as $bad ) {
			$this->assertFalse( Boolean_Field::cast_external( $bad, $field )['ok'], var_export( $bad, true ) );
		}
	}

	/**
	 * Config::cast uses the same rules for boolean and for the checkbox alias.
	 */
	public function test_config_casts_booleans_and_the_checkbox_alias() {
		$config = sc_wpun()->config();
		$values = array( true, 1, '1', 'true', 'YES', ' on ', false, 0, '0', 'false', 'No', 'off', '', 'maybe', '2', 2, array( 'yes' ), array(), null );

		foreach ( $values as $value ) {
			$boolean = $config->cast( $value, array( 'type' => 'boolean' ) );
			$this->assertSame( $boolean, $config->cast( $value, array( 'type' => 'checkbox' ) ), var_export( $value, true ) );
			$this->assertSame( $boolean, Boolean_Field::cast_external( $value, array( 'type' => 'boolean' ) ), var_export( $value, true ) );
		}

		$this->assertTrue( $config->cast( 'Yes', array( 'type' => 'boolean' ) )['value'] );
		$this->assertFalse( $config->cast( 'off', array( 'type' => 'boolean' ) )['value'] );
		$this->assertFalse( $config->cast( 'maybe', array( 'type' => 'boolean' ) )['ok'] );
		$this->assertFalse( $config->cast( array( 'yes' ), array( 'type' => 'boolean' ) )['ok'] );
	}

	/**
	 * A checkbox: hidden 0 first, then a labelled box, ticked when on.
	 */
	public function test_render_checkbox() {
		$field = array(
			'type'   => 'boolean',
			'label'  => 'Ignore <all> & more',
			'labels' => array( 'on' => 'Hide it <now>' ),
		);

		$html   = Field_Renderer::boolean( $field, 'opt[hide]', 'opt_hide', 1, false, 'opt_note' );
		$xpath  = $this->xpath( $html );
		$inputs = $xpath->query( '//input' );
		$this->assertSame( 2, $inputs->length );
		$this->assertSame( 'hidden', $inputs->item( 0 )->getAttribute( 'type' ) );
		$this->assertSame( 'opt[hide]', $inputs->item( 0 )->getAttribute( 'name' ) );
		$this->assertSame( '0', $inputs->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'checkbox', $inputs->item( 1 )->getAttribute( 'type' ) );
		$this->assertSame( '1', $inputs->item( 1 )->getAttribute( 'value' ) );
		$this->assertSame( 'opt[hide]', $inputs->item( 1 )->getAttribute( 'name' ) );
		$this->assertSame( 'opt_note', $inputs->item( 1 )->getAttribute( 'aria-describedby' ) );
		$this->assertTrue( $inputs->item( 1 )->hasAttribute( 'checked' ) );
		$this->assertFalse( $inputs->item( 1 )->hasAttribute( 'disabled' ) );
		$this->assertLessThan( strpos( $html, 'type="checkbox"' ), strpos( $html, 'type="hidden"' ) );
		$this->assertSame( 1, $xpath->query( '//label[@for="opt_hide"]/input[@id="opt_hide"]' )->length );
		$this->assertSame( ' Hide it <now>', $xpath->query( '//label' )->item( 0 )->textContent );
		$this->assertStringNotContainsString( '<now>', $html );

		foreach ( array( true, '1' ) as $on ) {
			$this->assertSame( 1, $this->xpath( Field_Renderer::boolean( $field, 'n', 'i', $on ) )->query( '//input[@type="checkbox"][@checked]' )->length );
		}
		foreach ( array( false, 0, '0', '', null, 'bogus' ) as $off ) {
			$this->assertSame( 0, $this->xpath( Field_Renderer::boolean( $field, 'n', 'i', $off ) )->query( '//input[@type="checkbox"][@checked]' )->length, var_export( $off, true ) );
		}

		$plain = Field_Renderer::boolean(
			array(
				'type'  => 'boolean',
				'label' => 'Notify',
			),
			'n',
			'i',
			false
		);
		$this->assertSame( ' Notify', $this->xpath( $plain )->query( '//label' )->item( 0 )->textContent, 'The label is the default text.' );
	}

	/**
	 * Radios sit in a fieldset with a legend, on first.
	 */
	public function test_render_radio() {
		$field = array(
			'type'    => 'boolean',
			'label'   => 'Notify <me>',
			'display' => 'radio',
		);

		foreach ( array( true, false ) as $on ) {
			$html   = Field_Renderer::boolean( $field, 'opt[notify]', 'opt_notify', $on, false, 'help' );
			$xpath  = $this->xpath( $html );
			$radios = $xpath->query( '//fieldset[@id="opt_notify"][@aria-describedby="help"]//input[@type="radio"][@name="opt[notify]"]' );
			$this->assertSame( 1, $xpath->query( '//fieldset/legend[contains(@class,"screen-reader-text")]' )->length );
			$this->assertSame( 'Notify <me>', $xpath->query( '//fieldset/legend' )->item( 0 )->textContent );
			$this->assertSame( 2, $radios->length );
			$this->assertSame( array( '1', '0' ), array( $radios->item( 0 )->getAttribute( 'value' ), $radios->item( 1 )->getAttribute( 'value' ) ) );
			$this->assertSame( array( $on, ! $on ), array( $radios->item( 0 )->hasAttribute( 'checked' ), $radios->item( 1 )->hasAttribute( 'checked' ) ) );
			$this->assertSame( 0, $xpath->query( '//input[@type="hidden"]' )->length );
			$this->assertSame( 1, $xpath->query( '//label[@for="' . $radios->item( 0 )->getAttribute( 'id' ) . '"]' )->length );
			$this->assertSame( 1, $xpath->query( '//label[@for="' . $radios->item( 1 )->getAttribute( 'id' ) . '"]' )->length );
			$this->assertNotSame( $radios->item( 0 )->getAttribute( 'id' ), $radios->item( 1 )->getAttribute( 'id' ) );
			$this->assertSame( array( ' Yes', ' No' ), array_map( static fn( $label ) => $label->textContent, iterator_to_array( $xpath->query( '//label' ) ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$this->assertStringNotContainsString( '<me>', $html );
		}
	}

	/**
	 * A select has the on option first.
	 */
	public function test_render_select() {
		$field = array(
			'type'    => 'boolean',
			'display' => 'select',
			'labels'  => array(
				'on'  => 'Hide it',
				'off' => 'Show it',
			),
		);

		$xpath   = $this->xpath( Field_Renderer::boolean( $field, 'opt[hide]', 'opt_hide', '1', false, 'help' ) );
		$options = $xpath->query( '//select[@id="opt_hide"][@name="opt[hide]"][@aria-describedby="help"]/option' );
		$this->assertSame( 2, $options->length );
		$this->assertSame( array( '1', '0' ), array( $options->item( 0 )->getAttribute( 'value' ), $options->item( 1 )->getAttribute( 'value' ) ) );
		$this->assertSame( array( 'Hide it', 'Show it' ), array( $options->item( 0 )->textContent, $options->item( 1 )->textContent ) );
		$this->assertSame( array( true, false ), array( $options->item( 0 )->hasAttribute( 'selected' ), $options->item( 1 )->hasAttribute( 'selected' ) ) );
		$this->assertSame( 0, $xpath->query( '//input|//fieldset' )->length );

		$xpath   = $this->xpath( Field_Renderer::boolean( $field, 'n', 'i', false ) );
		$options = $xpath->query( '//option' );
		$this->assertSame( array( false, true ), array( $options->item( 0 )->hasAttribute( 'selected' ), $options->item( 1 )->hasAttribute( 'selected' ) ) );
	}

	/**
	 * Disabled applies to every input, the hidden one included, so nothing is submitted.
	 */
	public function test_render_disabled() {
		foreach ( array( 'checkbox' => 2, 'radio' => 2 ) as $display => $count ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$xpath = $this->xpath( Field_Renderer::boolean( array( 'type' => 'boolean', 'display' => $display ), 'n', 'i', true, true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->assertSame( 0, $xpath->query( '//input[not(@disabled)]' )->length, $display );
			$this->assertSame( $count, $xpath->query( '//input[@disabled]' )->length, $display );
		}

		$xpath = $this->xpath( Field_Renderer::boolean( array( 'type' => 'boolean', 'display' => 'select' ), 'n', 'i', true, true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 1, $xpath->query( '//select[@disabled]' )->length );
	}

	/**
	 * The checkbox alias is the same as boolean with the checkbox display.
	 */
	public function test_checkbox_alias_is_boolean_with_the_checkbox_display() {
		$alias   = array(
			'type'    => 'checkbox',
			'label'   => 'Notify',
			'default' => true,
		);
		$boolean = array(
			'type'    => 'boolean',
			'display' => 'checkbox',
			'label'   => 'Notify',
			'default' => true,
		);

		$this->assertTrue( Boolean_Field::is_boolean( $alias ) );
		$this->assertTrue( Boolean_Field::is_boolean( $boolean ) );
		$this->assertFalse( Boolean_Field::is_boolean( array( 'type' => 'text' ) ) );
		$this->assertFalse( Boolean_Field::is_boolean( array() ) );
		$this->assertEquals( Boolean_Field::normalise( $boolean ), Boolean_Field::normalise( $alias ) );

		foreach ( array( true, false, 1, 0, '1', '0', '', 'yes', 2, null, array() ) as $value ) {
			$this->assertSame( Boolean_Field::sanitize( $value, $boolean ), Boolean_Field::sanitize( $value, $alias ), var_export( $value, true ) );
			$this->assertSame( Boolean_Field::cast_external( $value, $boolean ), Boolean_Field::cast_external( $value, $alias ), var_export( $value, true ) );
			$this->assertSame( Field_Renderer::boolean( $boolean, 'n', 'i', $value ), Field_Renderer::boolean( $alias, 'n', 'i', $value ), var_export( $value, true ) );
		}
	}

	/**
	 * The dispatcher draws by type.
	 */
	public function test_field_dispatches_by_type() {
		$boolean = array(
			'type'  => 'boolean',
			'label' => 'Notify',
		);
		$enum    = array(
			'type'    => 'select',
			'options' => array(
				'a' => 'A',
				'b' => 'B',
			),
		);

		$this->assertSame( Field_Renderer::boolean( $boolean, 'n', 'i', true, true, 'd' ), Field_Renderer::field( $boolean, 'n', 'i', true, true, 'd' ) );
		$this->assertSame( Field_Renderer::boolean( array( 'type' => 'checkbox' ), 'n', 'i', true ), Field_Renderer::field( array( 'type' => 'checkbox' ), 'n', 'i', true ) );
		$this->assertSame( Field_Renderer::enum( $enum, 'n', 'i', 'b' ), Field_Renderer::field( $enum, 'n', 'i', 'b' ) );
		$this->assertSame( Field_Renderer::enum( array_merge( $enum, array( 'type' => 'enum' ) ), 'n', 'i', 'a' ), Field_Renderer::field( array_merge( $enum, array( 'type' => 'enum' ) ), 'n', 'i', 'a' ) );
		$this->assertStringContainsString( 'type="text"', Field_Renderer::field( array( 'type' => 'text' ), 'n', 'i', 'x' ) );
	}

	/**
	 * The default validate_settings() sanitises a boolean and names the field when it is not valid.
	 */
	public function test_default_validation() {
		$channel = new Test_Fields_Channel(
			'test_boolean',
			array(
				'hide'  => array(
					'type'  => 'boolean',
					'label' => 'Hide it',
				),
				'alias' => array( 'type' => 'checkbox' ),
				'note'  => array( 'type' => 'text' ),
			)
		);

		$this->assertSame( array( 'hide' => true ), $channel->validate_settings( array( 'hide' => '1' ) ) );
		$this->assertSame( array( 'hide' => true ), $channel->validate_settings( array( 'hide' => true ) ) );
		$this->assertSame( array( 'hide' => false ), $channel->validate_settings( array( 'hide' => '0' ) ) );
		$this->assertSame( array( 'hide' => false ), $channel->validate_settings( array( 'hide' => '' ) ) );
		$this->assertSame( array( 'alias' => true ), $channel->validate_settings( array( 'alias' => 1 ) ) );
		$this->assertSame( array(), $channel->validate_settings( array( 'other' => '1' ) ), 'Missing fields stay missing.' );

		foreach ( array( '2', 'yes', array( '1' ), null ) as $bad ) {
			$error = $channel->validate_settings( array( 'hide' => $bad ) );
			$this->assertInstanceOf( WP_Error::class, $error, var_export( $bad, true ) );
			$this->assertSame( 'invalid_field', $error->get_error_code() );
			$this->assertSame( array( 'field' => 'hide' ), $error->get_error_data() );
			$this->assertStringContainsString( 'Hide it', $error->get_error_message() );
		}

		$error = $channel->validate_settings( array( 'alias' => 'on' ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( array( 'field' => 'alias' ), $error->get_error_data() );
	}
}
