<?php
/**
 * The enum field type (#155).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Field_Renderer;
use WPUN\Channel_Instance;
use WPUN\Enum_Field;

/**
 * Normalising, casting, sanitising and drawing enum fields, and how channels and the settings use them.
 */
class Test_Enum_Field extends WPUN_Test_Case {

	/**
	 * Start clean.
	 */
	public function set_up() {
		parent::set_up();

		Enum_Field::forget_warnings();
		sc_wpun()->config()->flush();
	}

	/**
	 * Remove what tests registered.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( 'test_enum' );
		sc_wpun()->config()->flush();
		parent::tear_down();
	}

	/**
	 * Options with n entries.
	 *
	 * @param int $count How many.
	 * @return array
	 */
	private function options( $count ) {
		$options = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$options[ 'k' . $i ] = 'Key ' . $i;
		}

		return $options;
	}

	/**
	 * Register a channel with enum fields and a plain one.
	 *
	 * @return Test_Fields_Channel
	 */
	private function register_enum_channel() {
		$channel = new Test_Fields_Channel(
			'test_enum',
			array(
				'mode'  => array(
					'type'    => 'enum',
					'label'   => 'Mode',
					'options' => array(
						'fast' => 'Fast',
						'slow' => 'Slow',
					),
					'default' => 'fast',
				),
				'types' => array(
					'type'     => 'enum',
					'label'    => 'Types',
					'multiple' => true,
					'options'  => array(
						'core'   => 'Core',
						'plugin' => 'Plugin',
						'theme'  => 'Theme',
					),
					'max'      => 2,
				),
				'note'  => array( 'type' => 'text' ),
			)
		);
		sc_wpun()->channels()->register( $channel );

		return $channel;
	}

	/**
	 * Register a test source.
	 *
	 * @param array $values Values by key.
	 */
	private function add_source( array $values ) {
		$source = new Test_Config_Source( $values );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $source ) {
				$sources[] = $source;
				return $sources;
			}
		);
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
	 * The select alias is a single enum shown as a select; other fields get the documented defaults.
	 */
	public function test_normalise_defaults() {
		$select = Enum_Field::normalise(
			array(
				'type'    => 'select',
				'options' => $this->options( 2 ),
			)
		);
		$this->assertSame( 'enum', $select['type'] );
		$this->assertFalse( $select['multiple'] );
		$this->assertSame( 'select', $select['display'] );

		$radio_select = Enum_Field::normalise(
			array(
				'type'    => 'select',
				'display' => 'radio',
				'options' => $this->options( 2 ),
			)
		);
		$this->assertSame( 'radio', $radio_select['display'] );

		$this->assertSame( 'radio', Enum_Field::normalise( array( 'type' => 'enum', 'options' => $this->options( 5 ) ) )['display'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'select', Enum_Field::normalise( array( 'type' => 'enum', 'options' => $this->options( 6 ) ) )['display'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'checkboxes', Enum_Field::normalise( array( 'type' => 'enum', 'multiple' => true, 'options' => $this->options( 5 ) ) )['display'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'select', Enum_Field::normalise( array( 'type' => 'enum', 'multiple' => true, 'options' => $this->options( 6 ) ) )['display'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'select', Enum_Field::normalise( array( 'type' => 'enum', 'display' => 'select', 'options' => $this->options( 2 ) ) )['display'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array(), Enum_Field::normalise( array( 'type' => 'enum' ) )['options'] );
	}

	/**
	 * A display that does not fit falls back to the default, with one notice per field.
	 */
	public function test_normalise_invalid_displays_fall_back_and_warn_once() {
		$this->setExpectedIncorrectUsage( 'WPUN\Enum_Field::normalise' );

		$checkboxes = array(
			'type'    => 'enum',
			'display' => 'checkboxes',
			'options' => $this->options( 2 ),
		);
		$this->assertSame( 'radio', Enum_Field::normalise( $checkboxes )['display'] );
		$this->assertSame( 'radio', Enum_Field::normalise( $checkboxes )['display'] );

		$radio = array(
			'type'     => 'enum',
			'multiple' => true,
			'display'  => 'radio',
			'options'  => $this->options( 2 ),
		);
		$this->assertSame( 'checkboxes', Enum_Field::normalise( $radio )['display'] );

		$unknown = array(
			'type'    => 'enum',
			'display' => 'dropdown',
			'options' => $this->options( 7 ),
		);
		$this->assertSame( 'select', Enum_Field::normalise( $unknown )['display'] );
	}

	/**
	 * The default is the field's own when valid, else the first option or an empty list.
	 */
	public function test_default_value() {
		$single = array(
			'type'    => 'enum',
			'options' => $this->options( 3 ),
		);
		$this->assertSame( 'k1', Enum_Field::default_value( $single ) );
		$this->assertSame( 'k2', Enum_Field::default_value( $single + array( 'default' => 'k2' ) ) );
		$this->assertSame( 'k1', Enum_Field::default_value( $single + array( 'default' => 'nope' ) ) );
		$this->assertSame( '', Enum_Field::default_value( array( 'type' => 'enum' ) ) );

		$multiple = $single + array( 'multiple' => true );
		$this->assertSame( array(), Enum_Field::default_value( $multiple ) );
		$this->assertSame( array( 'k1', 'k3' ), Enum_Field::default_value( $multiple + array( 'default' => array( 'k3', 'k1' ) ) ), 'In the order of the options.' );
		$this->assertSame( array(), Enum_Field::default_value( $multiple + array( 'default' => array( 'nope' ) ) ) );
	}

	/**
	 * Values from outside the database: a key, an integer key, nothing unknown.
	 */
	public function test_cast_external_single() {
		$field = array(
			'type'    => 'enum',
			'options' => array(
				'none' => 'No',
				'7'    => 'Seven',
			),
		);

		$this->assertSame(
			array(
				'ok'    => true,
				'value' => 'none',
			),
			Enum_Field::cast_external( 'none', $field )
		);
		$this->assertSame( '7', Enum_Field::cast_external( 7, $field )['value'] );
		$this->assertSame( '7', Enum_Field::cast_external( '7', $field )['value'] );
		foreach ( array( 'bogus', 'None', ' none', '', 8, true, false, 1.5, array( 'none' ), null ) as $bad ) {
			$this->assertFalse( Enum_Field::cast_external( $bad, $field )['ok'], var_export( $bad, true ) );
		}
		$this->assertTrue( Enum_Field::cast_external( 'select-alias', array( 'type' => 'select', 'options' => array( 'select-alias' => 'A' ) ) )['ok'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * A list from outside the database is a comma-separated string, and one unknown key spoils it.
	 */
	public function test_cast_external_multiple() {
		$field = array(
			'type'     => 'enum',
			'multiple' => true,
			'options'  => $this->options( 4 ) + array( '5' => 'Five' ),
			'max'      => 3,
		);

		$this->assertSame( array( 'k1', 'k3' ), Enum_Field::cast_external( 'k3, k1 ,,k3', $field )['value'], 'Trimmed, de-duplicated, in the order of the options.' );
		$this->assertSame(
			array(
				'ok'    => true,
				'value' => array(),
			),
			Enum_Field::cast_external( '', $field )
		);
		$this->assertSame( array( '5' ), Enum_Field::cast_external( 5, $field )['value'] );
		$this->assertFalse( Enum_Field::cast_external( 'k1,typo', $field )['ok'], 'An unknown key must not widen or narrow the list.' );
		$this->assertFalse( Enum_Field::cast_external( 'k1,k2,k3,k4', $field )['ok'], 'Above max.' );
		$this->assertFalse( Enum_Field::cast_external( array( 'k1' ), $field )['ok'], 'Arrays are not accepted.' );
		$this->assertFalse( Enum_Field::cast_external( true, $field )['ok'] );

		$min        = $field + array( 'min' => 1 );
		$min['min'] = 2;
		$this->assertFalse( Enum_Field::cast_external( 'k1', $min )['ok'], 'Below min.' );
		$this->assertFalse( Enum_Field::cast_external( '', $min )['ok'], 'Empty is below min.' );
		$this->assertTrue( Enum_Field::cast_external( 'k1,k2', $min )['ok'] );
	}

	/**
	 * Config::cast delegates enum and select to the field type.
	 */
	public function test_config_casts_enums() {
		$config = sc_wpun()->config();

		$this->assertSame( 'k2', $config->cast( 'k2', array( 'type' => 'enum', 'options' => $this->options( 2 ) ) )['value'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'k2', $config->cast( 'k2', array( 'type' => 'select', 'options' => $this->options( 2 ) ) )['value'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( $config->cast( 'k9', array( 'type' => 'enum', 'options' => $this->options( 2 ) ) )['ok'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( $config->cast( array( 'k1' ), array( 'type' => 'enum', 'multiple' => true, 'options' => $this->options( 2 ) ) )['ok'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * A single choice must be an option, else the default comes back.
	 */
	public function test_sanitize_single() {
		$field = array(
			'type'    => 'enum',
			'options' => $this->options( 3 ),
			'default' => 'k2',
		);

		$this->assertSame(
			array(
				'ok'      => true,
				'value'   => 'k3',
				'unknown' => array(),
			),
			Enum_Field::sanitize( 'k3', $field )
		);
		foreach ( array( 'bogus', '', null, array( 'k1' ), true ) as $bad ) {
			$result = Enum_Field::sanitize( $bad, $field );
			$this->assertFalse( $result['ok'], var_export( $bad, true ) );
			$this->assertSame( 'k2', $result['value'] );
		}
	}

	/**
	 * A list drops and reports unknown keys, accepts nothing chosen, and enforces min and max.
	 */
	public function test_sanitize_multiple() {
		$field = array(
			'type'     => 'enum',
			'multiple' => true,
			'options'  => $this->options( 4 ),
		);

		$result = Enum_Field::sanitize( array( 'k3', 'nope', 'k1', 'k3', 'also-nope' ), $field );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'k1', 'k3' ), $result['value'] );
		$this->assertSame( array( 'nope', 'also-nope' ), $result['unknown'] );

		foreach ( array( array(), '', null, array( '' ) ) as $empty ) {
			$result = Enum_Field::sanitize( $empty, $field );
			$this->assertTrue( $result['ok'], var_export( $empty, true ) );
			$this->assertSame( array(), $result['value'] );
		}

		$this->assertSame( array( 'k1', 'k2' ), Enum_Field::sanitize( 'k1,k2', $field )['value'] );
		$this->assertSame( array( 'k1' ), Enum_Field::sanitize( array( 'k1', array( 'x' ), true ), $field )['value'], 'Non-scalar entries are dropped.' );
		$this->assertFalse( Enum_Field::sanitize( true, $field )['ok'] );

		$limited = $field + array(
			'min'     => 1,
			'max'     => 2,
			'default' => array( 'k4' ),
		);
		$result  = Enum_Field::sanitize( array(), $limited );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( array( 'k4' ), $result['value'] );
		$this->assertFalse( Enum_Field::sanitize( array( 'k1', 'k2', 'k3' ), $limited )['ok'] );
		$this->assertTrue( Enum_Field::sanitize( array( 'k1', 'nope' ), $limited )['ok'] );
		$this->assertFalse( Enum_Field::sanitize( array( 'nope' ), $limited )['ok'], 'Unknown keys do not count towards min.' );
	}

	/**
	 * Radios sit in a fieldset with a legend, each with a label; the stored choice is checked.
	 */
	public function test_render_radio() {
		$field = array(
			'type'    => 'enum',
			'label'   => 'Plugin <updates>',
			'display' => 'radio',
			'options' => array(
				'none' => 'No',
				'all'  => 'Yes & more',
			),
		);
		$html  = Field_Renderer::enum( $field, 'opt[mode]', 'opt_mode', 'all', false, 'opt_note' );
		$xpath = $this->xpath( $html );

		$this->assertSame( 1, $xpath->query( '//fieldset[@id="opt_mode"][@aria-describedby="opt_note"]/legend[contains(@class,"screen-reader-text")]' )->length );
		$this->assertSame( 'Plugin <updates>', $xpath->query( '//fieldset/legend' )->item( 0 )->textContent );
		$radios = $xpath->query( '//fieldset//input[@type="radio"][@name="opt[mode]"]' );
		$this->assertSame( 2, $radios->length );
		$this->assertSame( array( 'none', 'all' ), array( $radios->item( 0 )->getAttribute( 'value' ), $radios->item( 1 )->getAttribute( 'value' ) ) );
		$this->assertFalse( $radios->item( 0 )->hasAttribute( 'checked' ) );
		$this->assertTrue( $radios->item( 1 )->hasAttribute( 'checked' ) );
		$this->assertFalse( $radios->item( 1 )->hasAttribute( 'disabled' ) );
		$this->assertSame( 2, $xpath->query( '//fieldset//label[@for]' )->length );
		$this->assertSame( 1, $xpath->query( '//label[@for="' . $radios->item( 1 )->getAttribute( 'id' ) . '"]' )->length );
		$this->assertStringContainsString( 'Yes &amp; more', $html );
		$this->assertStringNotContainsString( '<updates>', $html );
	}

	/**
	 * A disabled field disables every input.
	 */
	public function test_render_disabled() {
		$field = array(
			'type'     => 'enum',
			'multiple' => true,
			'options'  => $this->options( 2 ),
		);
		$xpath = $this->xpath( Field_Renderer::enum( $field, 'opt[types]', 'opt_types', array( 'k1' ), true ) );

		$this->assertSame( 0, $xpath->query( '//input[not(@disabled)]' )->length );
		$this->assertSame( 3, $xpath->query( '//input[@disabled]' )->length, 'Two checkboxes and the empty value.' );
	}

	/**
	 * Checkboxes submit a list, preceded by an empty value so that choosing nothing is submitted.
	 */
	public function test_render_checkboxes() {
		$field = array(
			'type'     => 'enum',
			'label'    => 'Types',
			'multiple' => true,
			'options'  => $this->options( 3 ),
		);
		$xpath = $this->xpath( Field_Renderer::enum( $field, 'opt[types]', 'opt_types', array( 'k1', 'k3' ) ) );

		$this->assertSame( 1, $xpath->query( '//fieldset/legend' )->length );
		$inputs = $xpath->query( '//input' );
		$this->assertSame( 4, $inputs->length );
		$this->assertSame( 'hidden', $inputs->item( 0 )->getAttribute( 'type' ) );
		$this->assertSame( 'opt[types]', $inputs->item( 0 )->getAttribute( 'name' ) );
		$this->assertSame( '', $inputs->item( 0 )->getAttribute( 'value' ) );
		$boxes = $xpath->query( '//fieldset//input[@type="checkbox"][@name="opt[types][]"]' );
		$this->assertSame( 3, $boxes->length );
		$this->assertSame( array( true, false, true ), array_map( static fn( $box ) => $box->hasAttribute( 'checked' ), iterator_to_array( $boxes ) ) );
		$this->assertSame( 3, $xpath->query( '//label[@for]' )->length );
	}

	/**
	 * A single select, and a multiple select with the empty value.
	 */
	public function test_render_selects() {
		$single = array(
			'type'    => 'select',
			'options' => $this->options( 3 ),
		);
		$xpath  = $this->xpath( Field_Renderer::enum( $single, 'opt[mode]', 'opt_mode', 'k2' ) );
		$this->assertSame( 1, $xpath->query( '//select[@id="opt_mode"][@name="opt[mode]"][not(@multiple)]' )->length );
		$options = $xpath->query( '//select/option' );
		$this->assertSame( 3, $options->length );
		$this->assertSame( array( false, true, false ), array_map( static fn( $option ) => $option->hasAttribute( 'selected' ), iterator_to_array( $options ) ) );
		$this->assertSame( 0, $xpath->query( '//fieldset|//input' )->length );

		$multiple = array(
			'type'     => 'enum',
			'multiple' => true,
			'display'  => 'select',
			'options'  => $this->options( 3 ),
		);
		$xpath    = $this->xpath( Field_Renderer::enum( $multiple, 'opt[types]', 'opt_types', array( 'k1', 'k2' ), false, 'help' ) );
		$this->assertSame( 1, $xpath->query( '//select[@id="opt_types"][@name="opt[types][]"][@multiple][@aria-describedby="help"]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@type="hidden"][@name="opt[types]"][@value=""]' )->length );
		$this->assertSame( 2, $xpath->query( '//option[@selected]' )->length );
	}

	/**
	 * The sentinel makes a form with nothing chosen submit an empty list.
	 */
	public function test_the_sentinel_sanitises_to_an_empty_list() {
		$field = array(
			'type'     => 'enum',
			'multiple' => true,
			'options'  => $this->options( 2 ),
		);

		$this->assertSame( array(), Enum_Field::sanitize( '', $field )['value'] );
		$this->assertSame( array( 'k2' ), Enum_Field::sanitize( array( '', 'k2' ), $field )['value'] );
	}

	/**
	 * The default validate_settings() returns a WP_Error naming the field for a bad single choice.
	 */
	public function test_default_validation_of_a_single_choice() {
		$channel = $this->register_enum_channel();

		$error = $channel->validate_settings( array( 'mode' => 'warp' ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( array( 'field' => 'mode' ), $error->get_error_data() );
		$this->assertStringContainsString( 'Mode', $error->get_error_message() );

		$this->assertSame( array( 'mode' => 'slow' ), $channel->validate_settings( array( 'mode' => 'slow' ) ) );
		$this->assertSame( array(), $channel->validate_settings( array( 'other' => 'x' ) ), 'Missing fields stay missing.' );
	}

	/**
	 * A list drops unknown keys and is checked against min and max.
	 */
	public function test_default_validation_of_a_list() {
		$channel = $this->register_enum_channel();

		$this->assertSame( array( 'types' => array( 'core', 'theme' ) ), $channel->validate_settings( array( 'types' => array( 'theme', 'bogus', 'core' ) ) ) );
		$this->assertSame( array( 'types' => array() ), $channel->validate_settings( array( 'types' => '' ) ) );

		$error = $channel->validate_settings( array( 'types' => array( 'core', 'plugin', 'theme' ) ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( array( 'field' => 'types' ), $error->get_error_data() );
	}

	/**
	 * The sanitize callback runs first, and its result is then checked against the options.
	 */
	public function test_the_sanitize_callback_runs_before_the_enum_check() {
		$channel = new Test_Fields_Channel(
			'test_enum',
			array(
				'mode' => array(
					'type'              => 'enum',
					'options'           => array( 'fast' => 'Fast' ),
					'sanitize_callback' => 'strtolower',
				),
			)
		);

		$this->assertSame( array( 'mode' => 'fast' ), $channel->validate_settings( array( 'mode' => 'FAST' ) ) );
	}

	/**
	 * A list supplied as a comma-separated constant is used; one unknown key makes the instance misconfigured.
	 */
	public function test_a_list_from_a_constant() {
		$this->register_enum_channel();
		$this->save_settings();
		sc_wpun_channels()->save_raw( new Channel_Instance( 'ops', 'test_enum', 'Ops', true, array( 'mode' => 'fast' ) ) );

		$this->add_source( array( 'SC_WPUN_CHANNEL_OPS__TYPES' => 'plugin,core' ) );
		$this->assertSame( array( 'core', 'plugin' ), sc_wpun_channels()->get( 'ops' )->get_setting( 'types' ) );
		$this->assertSame( array(), sc_wpun_channels()->problems( 'ops' ) );

		remove_all_filters( 'sc_wpun_config_sources' );
		$this->add_source( array( 'SC_WPUN_CHANNEL_OPS__TYPES' => 'plugin,typo' ) );
		$this->assertSame( array( 'SC_WPUN_CHANNEL_OPS__TYPES' ), sc_wpun_channels()->problems( 'ops' ) );
		$this->assertNotEmpty( sc_wpun()->config()->problems() );
		$this->assertNull( sc_wpun_channels()->get( 'ops' )->get_setting( 'types' ), 'Nothing from the bad value is used.' );
	}

	/**
	 * A stored value that is not valid is not blamed on a valid value supplied from outside the database.
	 */
	public function test_a_stored_problem_is_not_reported_against_an_external_source() {
		$this->register_enum_channel();
		$this->save_settings();
		sc_wpun_channels()->save_raw( new Channel_Instance( 'ops', 'test_enum', 'Ops', true, array( 'mode' => 'warp' ) ) );

		$this->add_source( array( 'SC_WPUN_CHANNEL_OPS__NOTE' => 'from the vault' ) );

		$instance = sc_wpun_channels()->get( 'ops' );
		$this->assertSame( 'from the vault', $instance->get_setting( 'note' ) );
		$this->assertSame( array(), sc_wpun_channels()->problems( 'ops' ) );
		$this->assertSame( array(), sc_wpun()->config()->problems() );
		$this->assertSame( 'warp', $instance->get_setting( 'mode' ), 'The stored value is untouched.' );
	}

	/**
	 * Routing describes its keys as enum fields.
	 */
	public function test_routing_fields() {
		$fields = WPUN\Routing::fields();

		$this->assertSame( array( 'scope', 'core', 'remind', 'types' ), array_keys( $fields ) );
		$this->assertSame( array( 'all', 'active' ), array_keys( $fields['scope']['options'] ) );
		$this->assertSame( array( 'all', 'major' ), array_keys( $fields['core']['options'] ) );
		$this->assertSame( 'all', $fields['core']['default'] );
		$this->assertTrue( $fields['types']['multiple'] );
		$this->assertSame( 'checkboxes', Enum_Field::normalise( $fields['types'] )['display'] );
		$this->assertSame( array_keys( WPUN\Item_Types::all() ), array_keys( $fields['types']['options'] ) );
		$this->assertSame( array(), $fields['types']['default'] );
	}

	/**
	 * Types of an add-on that is no longer active stay in the routing instead of widening it to every type.
	 */
	public function test_routing_keeps_unregistered_types() {
		$routing = WPUN\Routing::normalise(
			array(
				'types' => array( 'php' ),
				'scope' => 'bogus',
				'core'  => 'major',
			)
		);

		$this->assertSame( array( 'php' ), $routing['types'] );
		$this->assertSame( 'all', $routing['scope'] );
		$this->assertSame( 'major', $routing['core'] );
	}

	/**
	 * A value from outside that cannot be one of the choices is refused, with nothing else in the answer.
	 */
	public function test_cast_external_refuses_with_the_whole_answer() {
		$refused = array(
			'ok'    => false,
			'value' => null,
		);
		$field   = array(
			'type'    => 'enum',
			'options' => array(
				'a' => 'A',
				'b' => 'B',
			),
		);

		$this->assertSame( $refused, Enum_Field::cast_external( array( 'a' ), $field ) );
		$this->assertSame( $refused, Enum_Field::cast_external( 'c', $field ) );
		$this->assertSame( $refused, Enum_Field::cast_external( 'a,c', array_merge( $field, array( 'multiple' => true ) ) ) );
	}

	/**
	 * A schema without options has no keys.
	 */
	public function test_keys_of_a_schema_without_options() {
		$this->assertSame( array(), Enum_Field::keys( array() ) );
	}

	/**
	 * Both names of the type are enums, and nothing else is.
	 */
	public function test_is_enum() {
		$this->assertTrue( Enum_Field::is_enum( array( 'type' => 'enum' ) ) );
		$this->assertTrue( Enum_Field::is_enum( array( 'type' => 'select' ) ) );
		$this->assertFalse( Enum_Field::is_enum( array( 'type' => 'text' ) ) );
		$this->assertFalse( Enum_Field::is_enum( array() ) );
	}
}
