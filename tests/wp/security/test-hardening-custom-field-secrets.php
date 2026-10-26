<?php
/**
 * An outside value is hidden from a custom field type unless the schema opts in (#325).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * A constant or environment value may be a secret the schema forgot to flag. The edit form already withholds it
 * from the text types; a type added through `sc_wpun_field_types` is treated the same, and only `number`,
 * `boolean` and `enum` (and their aliases) get the value.
 *
 * @group security
 */
class Test_Hardening_Custom_Field_Secrets extends WPUN_Security_Test_Case {

	const VALUE = 'vault-CUSTOM-4471';

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * What the custom renderer was given.
	 *
	 * @var array
	 */
	private $seen = array();

	/**
	 * Create the user.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * A custom type, a channel with a field of it and a source that supplies its value.
	 */
	public function set_up() {
		parent::set_up();

		$this->seen = array();
		sc_wpun()->config()->flush();
		$this->save_settings();
		wp_set_current_user( self::$admin_id );

		add_filter(
			'sc_wpun_field_types',
			function ( $types ) {
				$types['hardening_blob'] = array(
					'render'   => function ( $field, $name, $id, $value ) {
						$this->seen[] = $value;
						return '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
					},
					'sanitize' => static fn( $value ) => is_string( $value ) ? trim( $value ) : '',
				);
				return $types;
			}
		);
	}

	/**
	 * Remove the channel.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( 'hardening' );
		sc_wpun()->config()->flush();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'], $_GET['type'] );
		parent::tear_down();
	}

	/**
	 * Draw the edit form of an instance whose `blob` field comes from outside the database.
	 *
	 * @param array $field Extra keys of the field schema.
	 * @return string
	 */
	private function edit_form( array $field = array() ): string {
		sc_wpun()->channels()->register(
			new Test_Fields_Channel(
				'hardening',
				array(
					'blob' => array_merge(
						array(
							'type'  => 'hardening_blob',
							'label' => 'Blob',
						),
						$field
					),
				)
			)
		);
		sc_wpun_channels()->save( new Channel_Instance( 'hardening', 'hardening', 'Hardening', false, array( 'blob' => 'stored' ) ) );
		$source = new Test_Config_Source( array( 'SC_WPUN_CHANNEL_HARDENING__BLOB' => self::VALUE ) );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $source ) {
				$sources[] = $source;
				return $sources;
			}
		);
		sc_wpun()->config()->flush();

		$_GET['tab']    = 'channels';
		$_GET['action'] = 'edit';
		$_GET['id']     = 'hardening';

		return $this->capture( array( sc_wpun()->channels_tab(), 'render' ) );
	}

	/**
	 * The renderer of a custom type is not given an outside value.
	 */
	public function test_a_custom_type_does_not_get_an_outside_value() {
		$html = $this->edit_form();

		$this->assertStringContainsString( 'Set by Test vault', $html, 'The form did not show the source.' );
		$this->assertNotEmpty( $this->seen, 'The custom renderer was not used.' );
		$this->assertNotContains( self::VALUE, $this->seen );
		$this->assertStringNotContainsString( self::VALUE, $html );
	}

	/**
	 * A schema can opt in.
	 */
	public function test_a_custom_type_gets_an_outside_value_with_show_external() {
		$html = $this->edit_form( array( 'show_external' => true ) );

		$this->assertContains( self::VALUE, $this->seen );
		$this->assertStringContainsString( self::VALUE, $html );
	}
}
