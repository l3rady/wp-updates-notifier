<?php
/**
 * The dev-only field showcase plugin in docker/dev-plugins/wpun-field-showcase (#172).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channel_Form;
use WPUN_Field_Showcase\Showcase_Channel;
use WPUN_Field_Showcase\Showcase_Custom_Channel;

// Not loaded by tests/bootstrap.php: only this test loads the showcase, the way WordPress loads a plugin file.
// Loading it only defines things; nothing is wired until a test calls WPUN_Field_Showcase\boot().
require_once dirname( __DIR__, 2 ) . '/docker/dev-plugins/wpun-field-showcase/wpun-field-showcase.php';

/**
 * The showcase keeps working with the field API: its forms render without notices and its errors reach the right fields.
 */
class Test_Field_Showcase extends WPUN_Test_Case {

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
	 * "Activate" the showcase and sign in.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::$admin_id );
		WPUN_Field_Showcase\boot();
		// The first read of the registry fires the registration action, which the showcase now answers too.
		foreach ( array( new Showcase_Channel(), new Showcase_Custom_Channel() ) as $channel ) {
			if ( ! sc_wpun()->channels()->has( $channel->get_type() ) ) {
				sc_wpun()->channels()->register( $channel );
			}
		}
		sc_wpun()->config()->flush();
	}

	/**
	 * Put the registry back as the rest of the suite expects it.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( 'field_showcase' );
		sc_wpun()->channels()->unregister( 'field_showcase_custom' );
		sc_wpun()->config()->flush();
		$_POST = array();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'], $_GET['type'] );
		parent::tear_down();
	}

	/**
	 * The markup of the add form of a module.
	 *
	 * @param string $type Module type.
	 * @return string
	 */
	private function add_form( string $type ): string {
		$_GET['tab']    = 'channels';
		$_GET['action'] = 'new';
		$_GET['type']   = $type;
		ob_start();
		sc_wpun()->channels_tab()->render();

		return (string) ob_get_clean();
	}

	/**
	 * Parse markup.
	 *
	 * @param string $html Markup.
	 * @return DOMXPath
	 */
	private function parse( string $html ): DOMXPath {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return new DOMXPath( $doc );
	}

	/**
	 * Save a new instance with these settings, and return the errors by field.
	 *
	 * @param string $type     Module type.
	 * @param array  $settings Settings as submitted.
	 * @return array
	 */
	private function save_new( string $type, array $settings ): array {
		$_POST = array(
			'action'   => Channel_Form::SAVE_ACTION,
			'mode'     => 'new',
			'type'     => $type,
			'label'    => 'Showcase',
			'enabled'  => '0',
			'routing'  => array(
				'scope' => 'all',
				'core'  => 'all',
				'types' => array( '' ),
			),
			'settings' => $settings,
			'_wpnonce' => wp_create_nonce( Channel_Form::SAVE_ACTION . '_new_' . $type ),
		);
		sc_wpun()->channel_form()->process_save();
		$meta = get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true );

		return is_array( $meta ) && isset( $meta['errors'] ) ? $meta['errors'] : array();
	}

	/**
	 * Both modules are registered, with the labels the picker shows.
	 */
	public function test_the_modules_are_registered() {
		$this->assertSame( 'Field showcase (dev only)', sc_wpun()->channels()->get( 'field_showcase' )->get_label() );
		$this->assertSame( 'Field showcase: custom render (dev only)', sc_wpun()->channels()->get( 'field_showcase_custom' )->get_label() );
		$this->assertTrue( sc_wpun()->channels()->get( 'field_showcase' )->supports( 'test_message' ) );
		$this->assertArrayNotHasKey( 'field_showcase', sc_wpun()->channels()->get_rejected() );
	}

	/**
	 * The add form draws a control for every field. A notice, or an unknown field type, fails the test.
	 */
	public function test_the_add_form_renders_every_field() {
		$xpath  = $this->parse( $this->add_form( 'field_showcase' ) );
		$fields = ( new Showcase_Channel() )->get_fields();

		$this->assertGreaterThan( 30, count( $fields ) );
		foreach ( array_keys( $fields ) as $name ) {
			$found = $xpath->query( '//*[starts-with(@name,"settings[' . $name . ']")]' );
			$this->assertGreaterThan( 0, $found->length, 'No control for ' . $name );
		}
		$this->assertSame( 1, $xpath->query( '//input[@type="color"][@name="settings[custom_colour]"]' )->length );
	}

	/**
	 * The custom render module draws its own markup.
	 */
	public function test_the_custom_render_form_renders() {
		$xpath = $this->parse( $this->add_form( 'field_showcase_custom' ) );

		$this->assertSame( 1, $xpath->query( '//div[@class="wpun-field-showcase-custom"]//input[@name="settings[greeting]"]' )->length );
	}

	/**
	 * A submission with every required field filled in, and these settings replaced.
	 *
	 * @param array $replace Settings to replace.
	 * @return array
	 */
	private function valid_settings( array $replace ): array {
		return array_merge(
			array(
				'text_required'       => 'Text',
				'advanced_required'   => 'Text',
				'url_required'        => 'https://example.org',
				'email_list_required' => 'ops@example.org',
				'secret_required'     => 'secret',
				'number_required'     => '5',
				'number_range'        => '5',
				'custom_colour'       => '#00aa00',
			),
			$replace
		);
	}

	/**
	 * A valid submission has no errors, so that the cases below fail for the value they change.
	 */
	public function test_a_valid_submission_has_no_errors() {
		$this->assertSame( array(), $this->save_new( 'field_showcase', $this->valid_settings( array() ) ) );
	}

	/**
	 * An empty required field, a number out of range, a bad colour and the failing callback are each reported on their field.
	 * The plugin reports the first problem of a save, so each case is saved alone.
	 */
	public function test_bad_values_are_reported_on_the_fields() {
		$cases = array(
			'text_required'     => array( 'text_required' => '' ),
			'url_required'      => array( 'url_required' => 'javascript:alert(1)' ),
			'number_range'      => array( 'number_range' => '11' ),
			'custom_colour'     => array( 'custom_colour' => 'red' ),
			'callback_validate' => array( 'callback_validate' => 'fail' ),
		);
		foreach ( $cases as $field => $replace ) {
			$errors = $this->save_new( 'field_showcase', $this->valid_settings( $replace ) );
			$this->assertSame( array( 'settings.' . $field ), array_keys( $errors ), $field . ' ' . wp_json_encode( $errors ) );
		}
	}

	/**
	 * The callbacks do what the labels say.
	 */
	public function test_the_callbacks_behave() {
		$this->assertSame( 'LOUD', Showcase_Channel::shout( ' loud ' ) );
		$this->assertTrue( Showcase_Channel::refuse_fail( 'fine' ) );
		$this->assertWPError( Showcase_Channel::refuse_fail( 'fail' ) );
	}

	/**
	 * The custom render module reports its errors too.
	 */
	public function test_the_custom_render_module_reports_a_missing_greeting() {
		$errors = $this->save_new( 'field_showcase_custom', array( 'greeting' => '' ) );

		$this->assertArrayHasKey( 'settings.greeting', $errors );
	}
}
