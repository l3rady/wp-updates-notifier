<?php
/**
 * Settings groups registered by add-ons (#455).
 *
 * @package wp-updates-notifier
 */

use WPUN\Config_Context;
use WPUN\Config_Report;
use WPUN\Config_Source;
use WPUN\Constant_Source;
use WPUN\Settings_Groups;
use WPUN\Site_Health;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The tests set environment variables.

/**
 * Registration, resolution, storage, the read API and the reports of settings groups.
 *
 * Constants cannot be undefined, so every test that defines one uses a group id of its own.
 */
class Test_Settings_Groups extends WPUN_Test_Case {

	const SECRET = 'bad-SECRET-VALUE-123';

	/**
	 * The plugin's own groups, put back after each test.
	 *
	 * @var Settings_Groups
	 */
	private $shared;

	/**
	 * The notices to the developer so far.
	 *
	 * @var string[]
	 */
	private $notices = array();

	/**
	 * Give the plugin fresh groups, because the registration action fires once per object.
	 */
	public function set_up() {
		parent::set_up();

		$this->shared  = sc_wpun()->settings_groups();
		$this->notices = array();
		$this->set_groups( new Settings_Groups( sc_wpun()->config(), sc_wpun()->channels(), sc_wpun()->settings(), sc_wpun()->network_context() ) );
		WPUN\Field_Types::forget_warnings();
		sc_wpun()->config()->flush();
		$this->save_settings();

		add_action(
			'doing_it_wrong_run',
			function ( $function_name ) {
				$this->notices[] = $function_name;
			}
		);
	}

	/**
	 * Put the plugin's groups back and clean up.
	 */
	public function tear_down() {
		$this->set_groups( $this->shared );
		foreach ( array( 'SC_WPUN_LOAD_FROM_ENV', 'SC_WPUN_SETTING_ENVG__TOKEN', 'SC_WPUN_SETTING_ENVH__TOKEN' ) as $name ) {
			putenv( $name );
			unset( $_ENV[ $name ] );
		}
		sc_wpun()->config()->flush();
		parent::tear_down();
	}

	/**
	 * Swap the groups the plugin hands out.
	 *
	 * @param Settings_Groups $groups Groups.
	 */
	private function set_groups( Settings_Groups $groups ) {
		$property = new ReflectionProperty( sc_wpun(), 'settings_groups' );
		$property->setAccessible( true );
		$property->setValue( sc_wpun(), $groups );
	}

	/**
	 * Register through the action, as an add-on does, and load the groups.
	 *
	 * @param callable $callback Called from the action; its return value is returned.
	 * @return mixed
	 */
	private function in_action( callable $callback ) {
		$result = null;
		add_action(
			'sc_wpun_register_settings',
			static function () use ( $callback, &$result ) {
				$result = $callback();
			}
		);
		sc_wpun_settings_groups();

		return $result;
	}

	/**
	 * Register one group through the action.
	 *
	 * @param string $group  Id.
	 * @param array  $fields Fields.
	 * @param array  $args   Arguments.
	 * @return bool
	 */
	private function register( $group, array $fields, array $args = array() ) {
		return $this->in_action(
			static function () use ( $group, $fields, $args ) {
				return sc_wpun_register_settings_group( $group, $args + array( 'label' => 'Test ' . $group ), $fields );
			}
		);
	}

	/**
	 * The off switch of the issue: code-only, on by default, off for a bad value.
	 *
	 * @return array
	 */
	private function kill_switch() {
		return array(
			'enabled' => array(
				'type'           => 'boolean',
				'default'        => true,
				'storage'        => false,
				'on_invalid'     => false,
				'constant_first' => true,
			),
		);
	}

	/**
	 * A text field and a secret.
	 *
	 * @return array
	 */
	private function text_fields() {
		return array(
			'name'  => array(
				'type'    => 'text',
				'default' => 'fallback',
			),
			'token' => array(
				'type'   => 'secret',
				'secret' => true,
			),
		);
	}

	/**
	 * A source that records the contexts it is asked about.
	 *
	 * @param array $values  Values by key.
	 * @param array $seen    Filled with the contexts.
	 * @return Config_Source
	 */
	private function recording_source( array $values, array &$seen ) {
		return new class( $values, $seen ) implements Config_Source {
			/**
			 * Values by key.
			 *
			 * @var array
			 */
			private $values;

			/**
			 * The contexts asked about.
			 *
			 * @var array
			 */
			private $seen;

			/**
			 * Constructor.
			 *
			 * @param array $values Values.
			 * @param array $seen   Contexts.
			 */
			public function __construct( array $values, array &$seen ) {
				$this->values = $values;
				$this->seen   =& $seen;
			}

			/**
			 * The value.
			 *
			 * @param string         $key     Key.
			 * @param Config_Context $context Context.
			 * @return mixed
			 */
			public function get( string $key, Config_Context $context ) {
				$this->seen[ $key ] = array( $context->get_group(), $context->get_setting() );
				return $this->values[ $key ] ?? null;
			}

			/**
			 * The label.
			 *
			 * @param string $key Key.
			 * @return string
			 */
			public function get_label( string $key ): string {
				return 'Test source ' . $key;
			}
		};
	}

	/**
	 * Add a source to the configuration.
	 *
	 * @param Config_Source $source Source.
	 */
	private function add_source( Config_Source $source ) {
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
	 * Notices to the developer from the registration function.
	 *
	 * @return int
	 */
	private function registration_notices() {
		return count( array_filter( $this->notices, static fn( $name ) => 'sc_wpun_register_settings_group' === $name ) );
	}

	// Context.

	/**
	 * The key of a field of a group has the group in it; the key of a setting does not.
	 */
	public function test_context_keys_and_group() {
		$field = Config_Context::setting( 'enabled', 'acme' );
		$own   = Config_Context::setting( 'frequency' );

		$this->assertSame( 'SC_WPUN_SETTING_ACME__ENABLED', $field->get_key() );
		$this->assertSame( 'acme', $field->get_group() );
		$this->assertSame( 'enabled', $field->get_setting() );
		$this->assertSame( 'SC_WPUN_SETTING_FREQUENCY', $own->get_key() );
		$this->assertSame( '', $own->get_group() );
		$this->assertSame( '', Config_Context::channel_field( 'email', 'to' )->get_group() );
	}

	// Registration.

	/**
	 * A valid group registers, and its arguments get their defaults.
	 */
	public function test_a_valid_group_registers() {
		$this->assertTrue( $this->register( 'acme', $this->kill_switch(), array( 'description' => 'About' ) ) );

		$groups = sc_wpun_settings_groups();
		$this->assertSame( array( 'acme' ), array_keys( $groups ) );
		$this->assertSame( 'Test acme', $groups['acme']['label'] );
		$this->assertSame( 'About', $groups['acme']['description'] );
		$this->assertSame( 'general', $groups['acme']['tab'] );
		$this->assertSame( '', $groups['acme']['capability'] );
		$this->assertFalse( $groups['acme']['autoload'] );
		$this->assertSame( $this->kill_switch(), $groups['acme']['fields'] );
		$this->assertSame( array(), $this->notices );
	}

	/**
	 * The action fires after the channels, once, and a read inside it does not fire it again.
	 */
	public function test_the_action_fires_once_after_the_channels() {
		$order = array();
		add_action(
			'sc_wpun_register_channels',
			static function () use ( &$order ) {
				$order[] = 'channels';
			}
		);
		$this->set_groups( new Settings_Groups( sc_wpun()->config(), new WPUN\Channel_Registry(), sc_wpun()->settings(), sc_wpun()->network_context() ) );
		$during = null;
		$this->in_action(
			function () use ( &$order, &$during ) {
				$order[] = 'settings';
				$during  = sc_wpun_settings_groups();
				sc_wpun_register_settings_group( 'early', array( 'label' => 'Early' ), $this->kill_switch() );
			}
		);

		$this->assertSame( array( 'channels', 'settings' ), $order );
		$this->assertSame( array(), $during, 'A read inside the action sees the groups so far and does not recurse.' );
		$this->assertSame( array( 'early' ), array_keys( sc_wpun_settings_groups() ) );
	}

	/**
	 * Nothing the plugin does while it loads fires the registration action.
	 */
	public function test_booting_does_not_register_settings() {
		foreach ( array( 'front', 'dashboard' ) as $screen ) {
			set_current_screen( $screen );
			$before = did_action( 'sc_wpun_register_settings' );

			$plugin = new WPUN\Plugin();
			$plugin->register();
			do_action( 'plugins_loaded' );
			$plugin->boot();

			$this->assertSame( $before, did_action( 'sc_wpun_register_settings' ), $screen );
		}
		set_current_screen( 'front' );
	}

	/**
	 * Ids that are not valid.
	 *
	 * @return array
	 */
	public function data_bad_ids() {
		return array(
			'upper case'        => array( 'Acme' ),
			'starts with a 1'   => array( '1acme' ),
			'single character'  => array( 'a' ),
			'dash'              => array( 'ac-me' ),
			'double underscore' => array( 'ac__me' ),
			'too long'          => array( str_repeat( 'a', 32 ) ),
			'empty'             => array( '' ),
		);
	}

	/**
	 * A bad id is refused with one notice.
	 *
	 * @dataProvider data_bad_ids
	 *
	 * @param string $id Id.
	 */
	public function test_a_bad_id_is_refused( $id ) {
		$this->setExpectedIncorrectUsage( 'sc_wpun_register_settings_group' );

		$this->assertFalse( $this->register( $id, $this->kill_switch() ) );
		$this->assertSame( 1, $this->registration_notices() );
		$this->assertSame( array(), sc_wpun_settings_groups() );
	}

	/**
	 * Reserved ids.
	 *
	 * @return array
	 */
	public function data_reserved_ids() {
		$ids = array_merge(
			array_keys( WPUN\Settings::GLOBAL_SETTINGS ),
			array( 'show_about', 'general', 'channels', 'muted', 'about', 'ver', 'main', 'tab', 'tabs', 'validate', 'group' )
		);

		return array_map( static fn( $id ) => array( $id ), array_combine( $ids, $ids ) );
	}

	/**
	 * Each reserved id is refused with one notice.
	 *
	 * @dataProvider data_reserved_ids
	 *
	 * @param string $id Id.
	 */
	public function test_a_reserved_id_is_refused( $id ) {
		$this->setExpectedIncorrectUsage( 'sc_wpun_register_settings_group' );

		$this->assertFalse( $this->register( $id, $this->kill_switch() ) );
		$this->assertSame( 1, $this->registration_notices() );
	}

	/**
	 * A second group with the same id is refused and the first stays.
	 */
	public function test_a_duplicate_is_refused() {
		$this->setExpectedIncorrectUsage( 'sc_wpun_register_settings_group' );

		$results = $this->in_action(
			function () {
				return array(
					sc_wpun_register_settings_group( 'acme', array( 'label' => 'First' ), $this->kill_switch() ),
					sc_wpun_register_settings_group( 'acme', array( 'label' => 'Second' ), $this->kill_switch() ),
				);
			}
		);

		$this->assertSame( array( true, false ), $results );
		$this->assertSame( 1, $this->registration_notices() );
		$this->assertSame( 'First', sc_wpun_settings_groups()['acme']['label'] );
	}

	/**
	 * Outside the action nothing registers.
	 */
	public function test_registering_outside_the_action_is_refused() {
		$this->setExpectedIncorrectUsage( 'sc_wpun_register_settings_group' );

		$this->assertFalse( sc_wpun_register_settings_group( 'acme', array( 'label' => 'Acme' ), $this->kill_switch() ) );
		$this->assertSame( 1, $this->registration_notices() );
		$this->assertSame( array(), sc_wpun_settings_groups() );
	}

	/**
	 * Arguments and schemas that are not valid.
	 *
	 * @return array
	 */
	public function data_bad_registrations() {
		$ok = array(
			'x' => array( 'type' => 'text' ),
		);

		return array(
			'no label'                 => array( array(), $ok ),
			'empty label'              => array( array( 'label' => ' ' ), $ok ),
			'label not a string'       => array( array( 'label' => 5 ), $ok ),
			'description not a string' => array( array( 'description' => array() ), $ok ),
			'tab channels'             => array( array( 'tab' => 'channels' ), $ok ),
			'tab muted'                => array( array( 'tab' => 'muted' ), $ok ),
			'tab about'                => array( array( 'tab' => 'about' ), $ok ),
			'tab not a slug'           => array( array( 'tab' => 'My Tab' ), $ok ),
			'capability not a string'  => array( array( 'capability' => true ), $ok ),
			'callback not callable'    => array( array( 'validate_callback' => 'nope_not_a_function' ), $ok ),
			'autoload not a bool'      => array( array( 'autoload' => 'yes' ), $ok ),
			'field groups not array'   => array( array( 'field_groups' => 'x' ), $ok ),
			'field group no label'     => array( array( 'field_groups' => array( 'adv' => array() ) ), $ok ),
			'no fields'                => array( array(), array() ),
			'field name upper case'    => array( array(), array( 'X' => array( 'type' => 'text' ) ) ),
			'field name double __'     => array( array(), array( 'a__b' => array( 'type' => 'text' ) ) ),
			'field not an array'       => array( array(), array( 'x' => 'text' ) ),
			'field no type'            => array( array(), array( 'x' => array() ) ),
			'field unknown type'       => array( array(), array( 'x' => array( 'type' => 'nope' ) ) ),
			'on_invalid not usable'    => array(
				array(),
				array(
					'x' => array(
						'type'       => 'number',
						'min'        => 1,
						'on_invalid' => 0,
					),
				),
			),
			'on_invalid wrong type'    => array(
				array(),
				array(
					'x' => array(
						'type'       => 'enum',
						'options'    => array( 'a' => 'A' ),
						'on_invalid' => 'b',
					),
				),
			),
			'constant_first not bool'  => array(
				array(),
				array(
					'x' => array(
						'type'           => 'text',
						'constant_first' => 'yes',
					),
				),
			),
			'storage not a bool'       => array(
				array(),
				array(
					'x' => array(
						'type'    => 'text',
						'storage' => 0,
					),
				),
			),
		);
	}

	/**
	 * A bad registration is refused with exactly one notice, and nothing is registered.
	 *
	 * @dataProvider data_bad_registrations
	 *
	 * @param array $args   Arguments, with a label unless the case is about the label.
	 * @param array $fields Fields.
	 */
	public function test_a_bad_registration_is_reported_once( array $args, array $fields ) {
		$this->setExpectedIncorrectUsage( 'sc_wpun_register_settings_group' );
		if ( ! array_key_exists( 'label', $args ) && 'no label' !== $this->dataName() ) {
			$args['label'] = 'Acme';
		}

		$result = $this->in_action(
			static function () use ( $args, $fields ) {
				return sc_wpun_register_settings_group( 'acme', $args, $fields );
			}
		);

		$this->assertFalse( $result );
		$this->assertSame( 1, count( $this->notices ), 'One notice.' );
		$this->assertSame( array(), sc_wpun_settings_groups() );
	}

	/**
	 * An on_invalid that a multiple choice accepts as a list is valid.
	 */
	public function test_on_invalid_is_checked_with_the_type_of_the_field() {
		$fields = array(
			'kinds' => array(
				'type'       => 'enum',
				'multiple'   => true,
				'options'    => array(
					'a' => 'A',
					'b' => 'B',
				),
				'on_invalid' => array( 'a' ),
			),
		);

		$this->assertTrue( $this->register( 'acme', $fields ) );
	}

	/**
	 * A field in a field group that is not declared is drawn without one, and reported.
	 */
	public function test_an_unknown_field_group_is_reported_but_registers() {
		$this->setExpectedIncorrectUsage( WPUN\Field_Types::class . '::unknown_group' );

		$this->assertTrue(
			$this->register(
				'acme',
				array(
					'x' => array(
						'type'  => 'text',
						'group' => 'missing',
					),
				)
			)
		);
	}

	// Resolution.

	/**
	 * The issue's example: the default is on, a constant turns it off for good.
	 */
	public function test_the_kill_switch_defaults_on_and_a_constant_turns_it_off() {
		define( 'SC_WPUN_SETTING_KILLA__ENABLED', false );
		$this->in_action(
			function () {
				sc_wpun_register_settings_group( 'killa', array( 'label' => 'A' ), $this->kill_switch() );
				sc_wpun_register_settings_group( 'killb', array( 'label' => 'B' ), $this->kill_switch() );
			}
		);

		$this->assertFalse( sc_wpun_setting( 'killa', 'enabled' ) );
		$this->assertSame( 'constant SC_WPUN_SETTING_KILLA__ENABLED', sc_wpun_setting_source( 'killa', 'enabled' ) );
		$this->assertTrue( sc_wpun_setting( 'killb', 'enabled' ) );
		$this->assertNull( sc_wpun_setting_source( 'killb', 'enabled' ) );
		$this->assertSame( array(), sc_wpun()->config()->problems() );
	}

	/**
	 * A mistyped value fails closed with on_invalid, and the problem never holds the value.
	 */
	public function test_an_invalid_value_uses_on_invalid_and_is_reported_without_it() {
		define( 'SC_WPUN_SETTING_KILLC__ENABLED', 'maybe-LEAK' );
		$this->register( 'killc', $this->kill_switch() );

		$this->assertFalse( sc_wpun_setting( 'killc', 'enabled' ) );
		$this->assertSame( 'constant SC_WPUN_SETTING_KILLC__ENABLED', sc_wpun_setting_source( 'killc', 'enabled' ) );

		$problems = sc_wpun()->config()->problems();
		$this->assertCount( 1, $problems );
		$this->assertSame( 'SC_WPUN_SETTING_KILLC__ENABLED', $problems[0]['key'] );
		$this->assertStringContainsString( 'chose', $problems[0]['message'] );
		$this->assertStringNotContainsString( 'LEAK', wp_json_encode( $problems ) );
	}

	/**
	 * Without on_invalid an invalid value is ignored: the stored value applies, else the default.
	 */
	public function test_an_invalid_value_without_on_invalid_uses_the_stored_value_then_the_default() {
		define( 'SC_WPUN_SETTING_PLAINA__LIMIT', 'many-LEAK' );
		define( 'SC_WPUN_SETTING_PLAINB__LIMIT', 'many-LEAK' );
		$fields = array(
			'limit' => array(
				'type'    => 'number',
				'default' => 7,
			),
		);
		update_option( 'sc_wpun_settings_plaina', array( 'limit' => 3 ) );
		update_option( 'sc_wpun_settings_plainb', array() );
		if ( sc_wpun_is_network() ) {
			update_site_option( 'sc_wpun_settings_plaina', array( 'limit' => 3 ) );
			update_site_option( 'sc_wpun_settings_plainb', array() );
		}
		$this->in_action(
			function () use ( $fields ) {
				sc_wpun_register_settings_group( 'plaina', array( 'label' => 'A' ), $fields );
				sc_wpun_register_settings_group( 'plainb', array( 'label' => 'B' ), $fields );
			}
		);

		$this->assertSame( 3, sc_wpun_setting( 'plaina', 'limit' ) );
		$this->assertNull( sc_wpun_setting_source( 'plaina', 'limit' ) );
		$this->assertSame( 7, sc_wpun_setting( 'plainb', 'limit' ) );
		$messages = wp_list_pluck( sc_wpun()->config()->problems(), 'message' );
		$this->assertCount( 2, $messages );
		$this->assertStringNotContainsString( 'LEAK', wp_json_encode( $messages ) );
	}

	/**
	 * A constant wins over the stored value, and a usable value is cast to the type.
	 */
	public function test_a_constant_wins_over_the_stored_value() {
		define( 'SC_WPUN_SETTING_CONSTA__NAME', 'from-constant' );
		define( 'SC_WPUN_SETTING_CONSTA__LIMIT', '12' );
		$fields = $this->text_fields() + array( 'limit' => array( 'type' => 'number' ) );
		$this->register( 'consta', $fields );
		sc_wpun_settings_groups();
		$this->assertTrue( sc_wpun()->settings_groups()->save( 'consta', array( 'name' => 'stored' ) ) );
		sc_wpun()->config()->flush();

		$this->assertSame( 'from-constant', sc_wpun_setting( 'consta', 'name' ) );
		$this->assertSame( 12, sc_wpun_setting( 'consta', 'limit' ) );
		$this->assertSame( 'constant SC_WPUN_SETTING_CONSTA__NAME', sc_wpun_setting_source( 'consta', 'name' ) );
	}

	/**
	 * Nothing set and nothing stored: the default, and the empty value of the type without one.
	 */
	public function test_defaults_and_empty_values() {
		$this->register(
			'dflts',
			array(
				'name'  => array(
					'type'    => 'text',
					'default' => 'fallback',
				),
				'plain' => array( 'type' => 'text' ),
				'on'    => array( 'type' => 'boolean' ),
				'count' => array( 'type' => 'number' ),
				'one'   => array(
					'type'    => 'enum',
					'options' => array( 'a' => 'A' ),
				),
				'many'  => array(
					'type'     => 'enum',
					'multiple' => true,
					'options'  => array( 'a' => 'A' ),
				),
			)
		);

		$this->assertSame( 'fallback', sc_wpun_setting( 'dflts', 'name' ) );
		$this->assertSame( '', sc_wpun_setting( 'dflts', 'plain' ) );
		$this->assertFalse( sc_wpun_setting( 'dflts', 'on' ) );
		$this->assertSame( 0, sc_wpun_setting( 'dflts', 'count' ) );
		$this->assertSame( '', sc_wpun_setting( 'dflts', 'one' ) );
		$this->assertSame( array(), sc_wpun_setting( 'dflts', 'many' ) );
		$this->assertNull( sc_wpun_setting_source( 'dflts', 'name' ) );
	}

	/**
	 * An environment variable is read only once the site opted in.
	 */
	public function test_environment_variables_need_the_opt_in() {
		$this->register( 'envg', $this->text_fields() );
		putenv( 'SC_WPUN_SETTING_ENVG__TOKEN=from-env' );
		sc_wpun()->config()->flush();

		$this->assertSame( '', sc_wpun_setting( 'envg', 'token' ), 'Without the opt-in.' );
		$this->assertNull( sc_wpun_setting_source( 'envg', 'token' ) );

		putenv( 'SC_WPUN_LOAD_FROM_ENV=1' );
		sc_wpun()->config()->flush();

		$this->assertSame( 'from-env', sc_wpun_setting( 'envg', 'token' ) );
		$this->assertSame( 'environment variable SC_WPUN_SETTING_ENVG__TOKEN', sc_wpun_setting_source( 'envg', 'token' ) );
	}

	/**
	 * A source added by an add-on sees the group of the field.
	 */
	public function test_a_custom_source_sees_the_group() {
		$seen = array();
		$this->add_source( $this->recording_source( array( 'SC_WPUN_SETTING_SRCG__NAME' => 'from-source' ), $seen ) );
		$this->register( 'srcg', $this->text_fields() );

		$this->assertSame( 'from-source', sc_wpun_setting( 'srcg', 'name' ) );
		$this->assertSame( 'Test source SC_WPUN_SETTING_SRCG__NAME', sc_wpun_setting_source( 'srcg', 'name' ) );
		$this->assertSame( array( 'srcg', 'name' ), $seen['SC_WPUN_SETTING_SRCG__NAME'] );

		// The plugin's own settings have no group.
		sc_wpun()->settings()->get();
		$this->assertSame( array( '', 'frequency' ), $seen['SC_WPUN_SETTING_FREQUENCY'] );
	}

	/**
	 * With constant_first, a filter that drops the constant source, or tries to override the constant, changes nothing.
	 */
	public function test_constant_first_cannot_be_overridden() {
		define( 'SC_WPUN_SETTING_FIRSTG__ENABLED', false );
		define( 'SC_WPUN_SETTING_FIRSTG__PLAIN', 'constant' );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) {
				return array_values(
					array_filter(
						$sources,
						static function ( $source ) {
							return ! $source instanceof Constant_Source;
						}
					)
				);
			}
		);
		add_filter(
			'sc_wpun_config_value',
			static function ( $value, $context ) {
				return 'firstg' === $context->get_group() ? 'overridden' : $value;
			},
			10,
			2
		);
		sc_wpun()->config()->flush();
		$fields          = $this->kill_switch();
		$fields['plain'] = array( 'type' => 'text' );
		$this->register( 'firstg', $fields );

		$this->assertFalse( sc_wpun_setting( 'firstg', 'enabled' ) );
		$this->assertSame( 'constant SC_WPUN_SETTING_FIRSTG__ENABLED', sc_wpun_setting_source( 'firstg', 'enabled' ) );
		// Without constant_first the same filters win over the constant.
		$this->assertSame( 'overridden', sc_wpun_setting( 'firstg', 'plain' ) );
	}

	/**
	 * A constant_first field falls back to the other sources when the constant is not defined.
	 */
	public function test_constant_first_without_a_constant_uses_the_sources() {
		$seen = array();
		$this->add_source( $this->recording_source( array( 'SC_WPUN_SETTING_FIRSTH__ENABLED' => 'no' ), $seen ) );
		$this->register( 'firsth', $this->kill_switch() );

		$this->assertFalse( sc_wpun_setting( 'firsth', 'enabled' ) );
		$this->assertSame( 'Test source SC_WPUN_SETTING_FIRSTH__ENABLED', sc_wpun_setting_source( 'firsth', 'enabled' ) );
	}

	/**
	 * The filter can supply a value for a field without constant_first.
	 */
	public function test_the_value_filter_applies_to_a_group_field() {
		add_filter(
			'sc_wpun_config_value',
			static function ( $value, $context ) {
				return 'filtg' === $context->get_group() && 'name' === $context->get_setting() ? 'filtered' : $value;
			},
			10,
			2
		);
		sc_wpun()->config()->flush();
		$this->register( 'filtg', $this->text_fields() );

		$this->assertSame( 'filtered', sc_wpun_setting( 'filtg', 'name' ) );
		$this->assertSame( 'the sc_wpun_config_value filter', sc_wpun_setting_source( 'filtg', 'name' ) );
	}

	/**
	 * An unknown group or field gives null and a notice.
	 */
	public function test_unknown_settings_give_null_and_a_notice() {
		$this->setExpectedIncorrectUsage( 'sc_wpun_setting' );
		$this->setExpectedIncorrectUsage( 'sc_wpun_setting_source' );
		$this->register( 'unkg', $this->text_fields() );

		$this->assertNull( sc_wpun_setting( 'nope', 'x' ) );
		$this->assertNull( sc_wpun_setting( 'unkg', 'x' ) );
		$this->assertNull( sc_wpun_setting( '', 'nope' ) );
		$this->assertNull( sc_wpun_setting_source( 'nope', 'x' ) );
		$this->assertNull( sc_wpun_setting_source( '', 'nope' ) );
	}

	/**
	 * The empty group is the plugin's own settings.
	 */
	public function test_the_empty_group_reads_the_plugins_settings() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		sc_wpun()->config()->flush();

		$this->assertSame( 'daily', sc_wpun_setting( '', 'frequency' ) );
		$this->assertTrue( sc_wpun_setting( '', 'show_about' ) );
		$this->assertNull( sc_wpun_setting_source( '', 'frequency' ) );
		$this->assertNull( sc_wpun_setting_source( '', 'show_about' ) );
	}

	/**
	 * A channel field's source is public.
	 */
	public function test_the_source_of_a_channel_field() {
		$this->assertNull( sc_wpun_channel_field_source( 'email', 'to' ) );

		define( 'SC_WPUN_CHANNEL_SRCCH__TO', 'x@example.com' );
		sc_wpun()->config()->flush();
		$this->assertSame( 'constant SC_WPUN_CHANNEL_SRCCH__TO', sc_wpun_channel_field_source( 'srcch', 'to' ) );
		$this->assertNull( sc_wpun_channel_field_source( 'srcch', 'Bad Name' ) );
	}

	// Storage and the cache.

	/**
	 * Values are stored in one option per group, and only the declared stored fields.
	 */
	public function test_save_stores_one_option_per_group() {
		$fields         = $this->text_fields();
		$fields['gone'] = array(
			'type'    => 'text',
			'storage' => false,
		);
		$this->register( 'storg', $fields );

		$this->assertTrue(
			sc_wpun()->settings_groups()->save(
				'storg',
				array_merge(
					$this->text_stored(),
					array(
						'gone'  => 'x',
						'other' => 'y',
					)
				)
			)
		);

		$option = sc_wpun()->network_context()->get_option( 'sc_wpun_settings_storg' );
		$this->assertSame( $this->text_stored(), $option, 'No code-only field and no undeclared field.' );
		$this->assertSame( $option, is_multisite() && sc_wpun_is_network() ? get_site_option( 'sc_wpun_settings_storg' ) : get_option( 'sc_wpun_settings_storg' ) );
		$this->assertSame( 'stored-name', sc_wpun_setting( 'storg', 'name' ), 'A save drops the cache.' );
		$this->assertSame( 'stored-secret', sc_wpun_setting( 'storg', 'token' ) );
	}

	/**
	 * What a save stores in the tests.
	 *
	 * @return array
	 */
	private function text_stored() {
		return array(
			'name'  => 'stored-name',
			'token' => 'stored-secret',
		);
	}

	/**
	 * A field supplied from configuration keeps what was stored before.
	 */
	public function test_save_never_stores_a_supplied_value() {
		define( 'SC_WPUN_SETTING_SUPPG__NAME', 'from-constant' );
		$this->register( 'suppg', $this->text_fields() );
		update_option( 'sc_wpun_settings_suppg', array( 'name' => 'old' ) );
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings_suppg', array( 'name' => 'old' ) );

		sc_wpun()->settings_groups()->save(
			'suppg',
			array(
				'name'  => 'posted',
				'token' => 't',
			)
		);

		$this->assertSame(
			array(
				'name'  => 'old',
				'token' => 't',
			),
			sc_wpun()->network_context()->get_option( 'sc_wpun_settings_suppg' )
		);
	}

	/**
	 * A group that is not registered cannot be saved.
	 */
	public function test_save_refuses_an_unknown_group() {
		$this->assertFalse( sc_wpun()->settings_groups()->save( 'nosuch', array( 'a' => 'b' ) ) );
		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_settings_nosuch' ) );
	}

	/**
	 * Code-only fields are never stored, even if something put a value in the option.
	 */
	public function test_a_code_only_field_ignores_the_stored_value() {
		$this->register( 'codeo', $this->kill_switch() );
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings_codeo', array( 'enabled' => false ) );

		$this->assertTrue( sc_wpun_setting( 'codeo', 'enabled' ) );
	}

	/**
	 * The get and put filters see the option and its name.
	 */
	public function test_the_option_filters_apply() {
		$this->register( 'filtr', $this->text_fields() );
		$seen = array();
		add_filter(
			'sc_wpun_put_options_filter',
			static function ( $value, $field ) use ( &$seen ) {
				if ( 'sc_wpun_settings_filtr' === $field ) {
					$seen[]        = 'put';
					$value['name'] = 'put-' . $value['name'];
				}
				return $value;
			},
			10,
			2
		);
		add_filter(
			'sc_wpun_get_options_filter',
			static function ( $value, $field ) use ( &$seen ) {
				if ( 'sc_wpun_settings_filtr' === $field && is_array( $value ) ) {
					$seen[]        = 'get';
					$value['name'] = 'get-' . $value['name'];
				}
				return $value;
			},
			10,
			2
		);

		sc_wpun()->settings_groups()->save( 'filtr', array( 'name' => 'x' ) );

		$this->assertSame( 'get-put-x', sc_wpun_setting( 'filtr', 'name' ) );
		$this->assertSame( array( 'put', 'get' ), array_slice( array_unique( $seen ), 0, 2 ) );
	}

	/**
	 * The option is not loaded on every request unless the group says so.
	 */
	public function test_autoload_follows_the_group() {
		if ( sc_wpun_is_network() ) {
			$this->markTestSkipped( 'Network options have no autoload.' );
		}
		$this->in_action(
			function () {
				sc_wpun_register_settings_group(
					'autoy',
					array(
						'label'    => 'Y',
						'autoload' => true,
					),
					$this->text_fields()
				);
				sc_wpun_register_settings_group( 'auton', array( 'label' => 'N' ), $this->text_fields() );
			}
		);

		sc_wpun()->settings_groups()->save( 'autoy', array( 'name' => 'x' ) );
		sc_wpun()->settings_groups()->save( 'auton', array( 'name' => 'x' ) );

		$loaded = wp_load_alloptions( true );
		$this->assertArrayHasKey( 'sc_wpun_settings_autoy', $loaded );
		$this->assertArrayNotHasKey( 'sc_wpun_settings_auton', $loaded );
	}

	/**
	 * In network mode the option is a network option.
	 */
	public function test_network_mode_stores_a_network_option() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->assertTrue( sc_wpun_is_network() );
		$this->register( 'netwk', $this->text_fields() );

		sc_wpun()->settings_groups()->save( 'netwk', array( 'name' => 'x' ) );

		$this->assertSame( array( 'name' => 'x' ), get_site_option( 'sc_wpun_settings_netwk' ) );
		$this->assertFalse( get_option( 'sc_wpun_settings_netwk' ) );
		$this->assertSame( 'x', sc_wpun_setting( 'netwk', 'name' ) );

		sc_wpun_delete_settings_group( 'netwk' );
		$this->assertFalse( get_site_option( 'sc_wpun_settings_netwk' ) );
	}

	/**
	 * Deleting removes the option, for a registered group or not, and refuses ids that are not groups.
	 */
	public function test_delete() {
		$this->register( 'delg', $this->text_fields() );
		sc_wpun()->settings_groups()->save( 'delg', array( 'name' => 'x' ) );
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings_gone', array( 'a' => 1 ) );
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings_ver', '14.0' );
		$version = sc_wpun()->network_context()->get_option( 'sc_wpun_settings_ver' );

		sc_wpun_delete_settings_group( 'delg' );
		sc_wpun_delete_settings_group( 'gone' );
		sc_wpun_delete_settings_group( 'ver' );
		sc_wpun_delete_settings_group( 'Bad Id' );

		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_settings_delg' ) );
		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_settings_gone' ) );
		$this->assertSame( $version, sc_wpun()->network_context()->get_option( 'sc_wpun_settings_ver' ), 'A reserved id is not a group.' );
		$this->assertSame( 'fallback', sc_wpun_setting( 'delg', 'name' ) );
	}

	/**
	 * Values are cached until the configuration is flushed.
	 */
	public function test_values_are_cached_until_the_configuration_is_flushed() {
		$this->register( 'cachg', $this->text_fields() );
		$this->assertSame( 'fallback', sc_wpun_setting( 'cachg', 'name' ) );

		sc_wpun()->network_context()->update_option( 'sc_wpun_settings_cachg', array( 'name' => 'changed' ) );
		$this->assertSame( 'fallback', sc_wpun_setting( 'cachg', 'name' ), 'Cached.' );

		sc_wpun()->config()->flush();
		$this->assertSame( 'changed', sc_wpun_setting( 'cachg', 'name' ) );
	}

	/**
	 * A value from a source that changes after a flush is read again.
	 */
	public function test_a_flush_reads_the_sources_again() {
		$seen = array();
		$this->add_source( $this->recording_source( array(), $seen ) );
		$this->register( 'flshg', $this->text_fields() );
		$this->assertNull( sc_wpun_setting_source( 'flshg', 'name' ) );

		$this->add_source( $this->recording_source( array( 'SC_WPUN_SETTING_FLSHG__NAME' => 'now' ), $seen ) );

		$this->assertSame( 'now', sc_wpun_setting( 'flshg', 'name' ) );
	}

	// Reports.

	/**
	 * Site Health lists an unusable value with its key and source, never the value.
	 */
	public function test_site_health_reports_an_unusable_value_without_it() {
		define( 'SC_WPUN_SETTING_REPG__LIMIT', 'many-LEAK' );
		$this->register( 'repg', array( 'limit' => array( 'type' => 'number' ) ) );
		$report = new Config_Report( sc_wpun()->config(), sc_wpun()->settings(), sc_wpun()->channel_store() );

		$tests = $report->add_site_health_test( array() );
		$this->assertArrayHasKey( 'sc_wpun_config', $tests['direct'] );

		$result = $report->site_health_result();
		$this->assertStringContainsString( 'SC_WPUN_SETTING_REPG__LIMIT', $result['description'] );
		$this->assertStringContainsString( 'constant', $result['description'] );
		$this->assertStringNotContainsString( 'LEAK', $result['description'] );
		$this->assertStringNotContainsString( 'LEAK', wp_json_encode( $report->problems() ) );
	}

	/**
	 * The site info has a section per group, with the secrets masked and the sources named.
	 */
	public function test_site_info_has_a_section_per_group() {
		define( 'SC_WPUN_SETTING_INFOG__NAME', 'from-constant' );
		$fields            = $this->text_fields();
		$fields['enabled'] = $this->kill_switch()['enabled'];
		$this->register( 'infog', $fields, array( 'label' => 'Info group' ) );
		sc_wpun()->settings_groups()->save( 'infog', array( 'token' => self::SECRET ) );

		$plugin = sc_wpun();
		$health = new Site_Health( $plugin->settings(), $plugin->state(), $plugin->channel_store(), new Config_Report( $plugin->config(), $plugin->settings(), $plugin->channel_store() ), $plugin->status_report() );
		$info   = $health->add_debug_info( array() );

		$section = $info[ Site_Health::SECTION . '-infog' ];
		$this->assertSame( 'Info group', $section['label'] );
		$this->assertSame( 'from-constant (constant SC_WPUN_SETTING_INFOG__NAME)', $section['fields']['name']['value'] );
		$this->assertSame( 'set (database)', $section['fields']['token']['value'] );
		$this->assertSame( 'yes (default)', $section['fields']['enabled']['value'] );
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( $info ) );
		$this->assertArrayHasKey( Site_Health::SECTION, $info );
	}
}
