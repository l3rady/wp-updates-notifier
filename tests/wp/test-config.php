<?php
/**
 * Configuration from constants, environment variables and custom sources (#107).
 *
 * A PHP constant cannot be undefined in one PHPUnit run, so the precedence is tested through a
 * test source, the filters and putenv(); Constant_Source itself is tested with throwaway names.
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The tests set environment variables.

use WPUN\Channel_Instance;
use WPUN\Config_Context;
use WPUN\Config_Value;
use WPUN\Constant_Source;
use WPUN\Env_Source;

/**
 * The resolver, the global settings overlay and everything around them.
 */
class Test_Config extends WPUN_Test_Case {

	const SECRET = 'SECRET-VALUE-XYZ';

	/**
	 * Start from a clean resolver.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->config()->flush();
		$this->save_settings( array( 'notify_plugins' => 'all' ) );
	}

	/**
	 * Clean up the environment and the resolver.
	 */
	public function tear_down() {
		putenv( 'SC_WPUN_SETTING_FREQUENCY' );
		putenv( 'SC_WPUN_LOAD_FROM_ENV' );
		unset( $_ENV['SC_WPUN_SETTING_FREQUENCY'], $_ENV['SC_WPUN_LOAD_FROM_ENV'] );
		sc_wpun()->config()->flush();
		parent::tear_down();
	}

	/**
	 * Register a test source (and flush, since the list is built once).
	 *
	 * @param array $values Values by key.
	 * @return Test_Config_Source
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

		return $source;
	}

	/**
	 * The settings page output.
	 *
	 * @return string
	 */
	private function page() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();

		return ob_get_clean();
	}

	/**
	 * Key names.
	 */
	public function test_context_keys() {
		$setting = Config_Context::setting( 'frequency' );
		$this->assertSame( 'SC_WPUN_SETTING_FREQUENCY', $setting->get_key() );
		$this->assertSame( 'setting', $setting->get_scope() );
		$this->assertSame( 'frequency', $setting->get_setting() );
		$this->assertSame( get_current_blog_id(), $setting->get_site_id() );

		$field = Config_Context::channel_field( 'ops_slack', 'webhook_url' );
		$this->assertSame( 'SC_WPUN_CHANNEL_OPS_SLACK__WEBHOOK_URL', $field->get_key() );
		$this->assertSame( 'channel', $field->get_scope() );
		$this->assertSame( 'ops_slack', $field->get_instance_id() );
		$this->assertSame( 'webhook_url', $field->get_field() );
	}

	/**
	 * The constant source reads defined scalars, and returns arrays as they are for the resolver to reject.
	 */
	public function test_constant_source() {
		define( 'SC_WPUN_TEST_THROWAWAY_SCALAR', 'from a constant' );
		define( 'SC_WPUN_TEST_THROWAWAY_ARRAY', array( 'x' ) );
		$source  = new Constant_Source();
		$context = Config_Context::setting( 'test_throwaway' );

		$this->assertSame( 'from a constant', $source->get( 'SC_WPUN_TEST_THROWAWAY_SCALAR', $context ) );
		$this->assertSame( array( 'x' ), $source->get( 'SC_WPUN_TEST_THROWAWAY_ARRAY', $context ) );
		$this->assertNull( $source->get( 'SC_WPUN_TEST_NOT_DEFINED', $context ) );
		$this->assertSame( 'constant SC_WPUN_TEST_X', $source->get_label( 'SC_WPUN_TEST_X' ) );
	}

	/**
	 * The environment source reads getenv() and $_ENV.
	 */
	public function test_env_source() {
		$source  = new Env_Source();
		$context = Config_Context::setting( 'frequency' );
		$this->assertNull( $source->get( 'SC_WPUN_SETTING_FREQUENCY', $context ) );

		putenv( 'SC_WPUN_SETTING_FREQUENCY=daily' );
		$this->assertSame( 'daily', $source->get( 'SC_WPUN_SETTING_FREQUENCY', $context ) );

		putenv( 'SC_WPUN_SETTING_FREQUENCY' );
		$_ENV['SC_WPUN_SETTING_FREQUENCY'] = 'weekly';
		$this->assertSame( 'weekly', $source->get( 'SC_WPUN_SETTING_FREQUENCY', $context ) );
		$this->assertSame( 'environment variable SC_WPUN_SETTING_FREQUENCY', $source->get_label( 'SC_WPUN_SETTING_FREQUENCY' ) );
	}

	/**
	 * The default list: constants first, no environment variables unless asked, and the filter sees the list.
	 */
	public function test_default_sources() {
		$seen = array();
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( &$seen ) {
				$seen[] = array_map( 'get_class', $sources );
				return $sources;
			}
		);
		sc_wpun()->config()->flush();
		sc_wpun()->config()->resolve( Config_Context::setting( 'frequency' ) );
		$this->assertSame( array( array( Constant_Source::class ) ), $seen );

		$seen = array();
		add_filter( 'sc_wpun_load_from_env', '__return_true' );
		sc_wpun()->config()->flush();
		sc_wpun()->config()->resolve( Config_Context::setting( 'frequency' ) );
		$this->assertSame( array( array( Constant_Source::class, Env_Source::class ) ), $seen );
	}

	/**
	 * Environment variables are read once SC_WPUN_LOAD_FROM_ENV in the environment is an "on" value.
	 *
	 * @dataProvider data_env_switch
	 *
	 * @param string $value   Value of SC_WPUN_LOAD_FROM_ENV.
	 * @param bool   $reads   Whether environment variables are read.
	 * @param bool   $problem Whether the value is reported as invalid.
	 */
	public function test_the_environment_switches_itself_on( $value, $reads, $problem ) {
		putenv( 'SC_WPUN_SETTING_FREQUENCY=weekly' );
		putenv( 'SC_WPUN_LOAD_FROM_ENV=' . $value );
		$this->save_settings( array( 'frequency' => 'daily' ) );
		sc_wpun()->config()->flush();

		$this->assertSame( $reads ? 'weekly' : 'daily', sc_wpun()->settings()->get()['frequency'] );
		$this->assertSame( $problem, in_array( 'SC_WPUN_LOAD_FROM_ENV', wp_list_pluck( sc_wpun()->config()->problems(), 'key' ), true ) );
	}

	/**
	 * Values of the switch.
	 *
	 * @return array
	 */
	public function data_env_switch() {
		return array(
			'1'       => array( '1', true, false ),
			'true'    => array( 'true', true, false ),
			'Yes'     => array( 'Yes', true, false ),
			'on'      => array( 'on', true, false ),
			'0'       => array( '0', false, false ),
			'false'   => array( 'false', false, false ),
			'empty'   => array( '', false, false ),
			'unknown' => array( 'maybe', false, true ),
		);
	}

	/**
	 * The filter has the last word on the switch.
	 */
	public function test_the_filter_overrides_the_environment_switch() {
		putenv( 'SC_WPUN_SETTING_FREQUENCY=weekly' );
		putenv( 'SC_WPUN_LOAD_FROM_ENV=1' );
		$this->save_settings( array( 'frequency' => 'daily' ) );
		add_filter( 'sc_wpun_load_from_env', '__return_false' );
		sc_wpun()->config()->flush();

		$this->assertSame( 'daily', sc_wpun()->settings()->get()['frequency'] );
	}

	/**
	 * Filter, then sources in order (environment only when opted in), then the database.
	 */
	public function test_precedence_for_a_setting() {
		putenv( 'SC_WPUN_SETTING_FREQUENCY=twicedaily' );
		$settings = sc_wpun()->settings();
		$this->save_settings( array( 'frequency' => 'daily' ) );

		$this->assertSame( 'daily', $settings->get()['frequency'], 'Database.' );
		$this->assertNull( $settings->external_source( 'frequency' ) );

		$this->add_source( array( 'SC_WPUN_SETTING_FREQUENCY' => 'weekly' ) );
		$this->assertSame( 'weekly', $settings->get()['frequency'], 'Add-on source; environment is ignored without opt-in.' );
		$this->assertSame( 'Test vault', $settings->external_source( 'frequency' ) );
		$this->assertSame( 'daily', $settings->stored()['frequency'] );

		add_filter( 'sc_wpun_load_from_env', '__return_true' );
		sc_wpun()->config()->flush();
		$this->assertSame( 'twicedaily', $settings->get()['frequency'], 'Environment, once opted in, before add-on sources.' );
		$this->assertSame( 'environment variable SC_WPUN_SETTING_FREQUENCY', $settings->external_source( 'frequency' ) );

		add_filter(
			'sc_wpun_config_value',
			static function ( $value, $context ) {
				return 'frequency' === $context->get_setting() ? 'hourly' : $value;
			},
			10,
			2
		);
		sc_wpun()->config()->flush();
		$this->assertSame( 'hourly', $settings->get()['frequency'], 'The filter has the last word.' );
		$this->assertSame( 'the sc_wpun_config_value filter', $settings->external_source( 'frequency' ) );
	}

	/**
	 * The filter gets the source's value and label, and returning null falls back to the database.
	 */
	public function test_the_value_filter() {
		$this->add_source( array( 'SC_WPUN_SETTING_NOTIFY_THEMES' => 'active' ) );
		$seen = array();
		add_filter(
			'sc_wpun_config_value',
			static function ( $value, $context, $source ) use ( &$seen ) {
				$seen[ $context->get_setting() ] = array( $value, $source );
				return 'notify_themes' === $context->get_setting() ? null : $value;
			},
			10,
			3
		);

		$this->assertSame( 'all', sc_wpun()->settings()->get()['notify_themes'], 'Null removes the external value.' );
		$this->assertSame( array( 'active', 'Test vault' ), $seen['notify_themes'] );
		$this->assertSame( array( null, null ), $seen['frequency'] );
	}

	/**
	 * The notify settings take the keys; a number from the old scheme is reported and not used.
	 */
	public function test_notify_settings_take_keys_only() {
		$this->save_settings( array( 'notify_themes' => 'none' ) );
		$this->add_source( array( 'SC_WPUN_SETTING_NOTIFY_PLUGINS' => 'active' ) );
		$this->assertSame( 'active', sc_wpun()->settings()->get()['notify_plugins'] );
		$this->assertSame( array(), sc_wpun()->config()->problems() );

		foreach ( array( '2', 'bogus' ) as $bad ) {
			remove_all_filters( 'sc_wpun_config_sources' );
			$this->add_source(
				array(
					'SC_WPUN_SETTING_NOTIFY_PLUGINS' => $bad,
					'SC_WPUN_SETTING_NOTIFY_THEMES'  => 'all',
				)
			);

			$settings = sc_wpun()->settings()->get();

			$this->assertSame( 'all', $settings['notify_plugins'], $bad );
			$this->assertSame( 'all', $settings['notify_themes'] );
			$this->assertSame( array( 'SC_WPUN_SETTING_NOTIFY_PLUGINS' ), wp_list_pluck( sc_wpun()->config()->problems(), 'key' ), $bad );
		}
	}

	/**
	 * The core notify setting takes none, all and major from outside the database; active is not one of its keys.
	 */
	public function test_notify_core_is_supplied_from_outside() {
		$this->save_settings( array( 'notify_core' => 'all' ) );
		$this->add_source( array( 'SC_WPUN_SETTING_NOTIFY_CORE' => 'major' ) );
		$this->assertSame( 'major', sc_wpun()->settings()->get()['notify_core'] );
		$this->assertSame( 'all', $this->stored_settings()['notify_core'], 'Nothing from outside is written to the database.' );
		$this->assertSame( array(), sc_wpun()->config()->problems() );

		remove_all_filters( 'sc_wpun_config_sources' );
		$this->add_source( array( 'SC_WPUN_SETTING_NOTIFY_CORE' => 'active' ) );
		$this->assertSame( 'all', sc_wpun()->settings()->get()['notify_core'] );
		$this->assertSame( array( 'SC_WPUN_SETTING_NOTIFY_CORE' ), wp_list_pluck( sc_wpun()->config()->problems(), 'key' ) );

		remove_all_filters( 'sc_wpun_config_sources' );
		$this->add_source( array( 'SC_WPUN_SETTING_NOTIFY_PLUGINS' => 'major' ) );
		$this->assertSame( 'all', sc_wpun()->settings()->get()['notify_plugins'], 'major is not a plugin key.' );
		$this->assertSame( array( 'SC_WPUN_SETTING_NOTIFY_PLUGINS' ), wp_list_pluck( sc_wpun()->config()->problems(), 'key' ) );
	}

	/**
	 * An invalid value is reported without its value, and the saved value is used.
	 */
	public function test_an_invalid_value_is_reported_not_used() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		$this->add_source(
			array(
				'SC_WPUN_SETTING_FREQUENCY'      => self::SECRET,
				'SC_WPUN_SETTING_NOTIFY_PLUGINS' => '7',
				'SC_WPUN_SETTING_HIDE_UPDATES'   => 'maybe',
			)
		);

		$settings = sc_wpun()->settings()->get();

		$this->assertSame( 'daily', $settings['frequency'] );
		$this->assertSame( 'all', $settings['notify_plugins'] );
		$problems = sc_wpun()->config()->problems();
		$this->assertSame( array( 'SC_WPUN_SETTING_FREQUENCY', 'SC_WPUN_SETTING_NOTIFY_PLUGINS', 'SC_WPUN_SETTING_HIDE_UPDATES' ), wp_list_pluck( $problems, 'key' ) );
		$this->assertSame( 'Test vault', $problems[0]['source'] );
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( $problems ) );

		$page = $this->page();
		$this->assertStringContainsString( 'sc-wpun-config-problems', $page );
		$this->assertStringContainsString( 'SC_WPUN_SETTING_FREQUENCY', $page );
		$this->assertStringNotContainsString( self::SECRET, $page );

		$tests  = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$result = call_user_func( $tests['direct']['sc_wpun_config']['test'] );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertStringContainsString( 'SC_WPUN_SETTING_FREQUENCY', $result['description'] );
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( $result ) );
	}

	/**
	 * Without problems there is no notice and no Site Health test.
	 */
	public function test_no_problems_no_report() {
		$this->add_source( array( 'SC_WPUN_SETTING_FREQUENCY' => 'weekly' ) );

		$this->assertStringNotContainsString( 'sc-wpun-config-problems', $this->page() );
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertArrayNotHasKey( 'sc_wpun_config', $tests['direct'] );
	}

	/**
	 * A source that throws is reported by class only, and the next source is asked.
	 */
	public function test_a_throwing_source_is_skipped() {
		$bad         = $this->add_source( array() );
		$bad->throws = true;
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) {
				$sources[] = new Test_Config_Source( array( 'SC_WPUN_SETTING_FREQUENCY' => 'weekly' ) );
				return $sources;
			}
		);
		sc_wpun()->config()->flush();

		$this->assertSame( 'weekly', sc_wpun()->settings()->get()['frequency'] );
		$problems = sc_wpun()->config()->problems();
		$this->assertNotEmpty( $problems );
		$this->assertStringContainsString( 'RuntimeException', $problems[0]['message'] );
		$this->assertStringNotContainsString( 'vault-token-123', wp_json_encode( $problems ) );
	}

	/**
	 * Entries that are not sources are dropped with a notice to the developer.
	 */
	public function test_a_junk_source_is_dropped() {
		$this->setExpectedIncorrectUsage( 'sc_wpun_config_sources' );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) {
				$sources[] = 'junk';
				$sources[] = new Test_Config_Source( array( 'SC_WPUN_SETTING_FREQUENCY' => 'weekly' ) );
				return $sources;
			}
		);
		sc_wpun()->config()->flush();

		$this->assertSame( 'weekly', sc_wpun()->settings()->get()['frequency'] );
	}

	/**
	 * Values are cached for the request, and flushed when the site changes.
	 */
	public function test_the_cache_is_flushed_on_switch_blog() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}
		$source = $this->add_source( array( 'SC_WPUN_SETTING_FREQUENCY' => 'weekly' ) );
		$config = sc_wpun()->config();
		$config->resolve( Config_Context::setting( 'frequency' ) );
		$config->resolve( Config_Context::setting( 'frequency' ) );
		$this->assertSame( 1, $source->calls );

		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		$config->resolve( Config_Context::setting( 'frequency' ) );
		restore_current_blog();

		$this->assertGreaterThan( 1, $source->calls );
	}

	/**
	 * An externally supplied setting is shown read-only with its source.
	 */
	public function test_external_settings_render_read_only() {
		$this->add_source(
			array(
				'SC_WPUN_SETTING_FREQUENCY'      => 'weekly',
				'SC_WPUN_SETTING_NOTIFY_PLUGINS' => 'active',
			)
		);

		$page = $this->page();

		$this->assertMatchesRegularExpression( '/name="sc_wpun_settings\[frequency\]" value="weekly" checked="checked" disabled="disabled"/', $page );
		$this->assertDoesNotMatchRegularExpression( '/<input(?![^>]*disabled)[^>]*name="sc_wpun_settings\[frequency\]"/', $page );
		$this->assertMatchesRegularExpression( '/name="sc_wpun_settings\[notify_plugins\]" value="active" checked="checked" disabled="disabled"/', $page );
		$this->assertStringContainsString( 'Set by Test vault', $page );
		$this->assertDoesNotMatchRegularExpression( '/name="sc_wpun_settings\[notify_themes\]"[^>]*disabled/', $page );
	}

	/**
	 * Saving never writes an external value, and keeps the stored one whatever is submitted.
	 */
	public function test_saving_never_writes_an_external_value() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		$this->add_source(
			array(
				'SC_WPUN_SETTING_FREQUENCY'      => 'weekly',
				'SC_WPUN_SETTING_NOTIFY_PLUGINS' => 'active',
			)
		);

		$valid = sc_wpun()->settings_page()->sc_wpun_settings_validate(
			array(
				'frequency'      => 'hourly',
				'notify_plugins' => 'none',
				'notify_themes'  => 'active',
			)
		);

		$this->assertSame( 'daily', $valid['frequency'] );
		$this->assertSame( 'all', $valid['notify_plugins'] );
		$this->assertSame( 'active', $valid['notify_themes'], 'Settings that are not external still save.' );

		update_option( 'sc_wpun_settings', $valid );
		$this->assertSame( 'daily', $this->stored_settings()['frequency'] );
		$this->assertSame( 'weekly', sc_wpun()->settings()->get()['frequency'] );
	}

	/**
	 * The scheduled check follows the effective frequency, not only the saved one.
	 */
	public function test_the_cron_follows_the_effective_frequency() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		wp_clear_scheduled_hook( 'sc_wpun_update_check' );
		sc_wpun()->scheduler()->enable_cron();
		$this->assertSame( 'daily', wp_get_schedule( 'sc_wpun_update_check' ) );

		$this->add_source( array( 'SC_WPUN_SETTING_FREQUENCY' => 'weekly' ) );
		sc_wpun()->scheduler()->maybe_schedule();

		$this->assertSame( 'weekly', wp_get_schedule( 'sc_wpun_update_check' ) );
		$this->assertSame( 'daily', $this->stored_settings()['frequency'] );

		// Changing the saved frequency by other means does not override the external one.
		update_option( 'sc_wpun_settings', array_merge( $this->stored_settings(), array( 'frequency' => 'twicedaily' ) ) );
		$this->assertSame( 'weekly', wp_get_schedule( 'sc_wpun_update_check' ) );
	}

	/**
	 * The value never shows in debug output, casting or serialising.
	 */
	public function test_a_config_value_hides_its_value() {
		$value = new Config_Value( self::SECRET, 'constant X', 'X' );

		$this->assertSame( self::SECRET, $value->get_value() );
		$this->assertFalse( method_exists( $value, '__toString' ) );
		$this->assertStringNotContainsString( self::SECRET, print_r( $value, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		$this->assertStringNotContainsString( self::SECRET, serialize( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( $value ) );
		ob_start();
		var_dump( $value ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump
		$this->assertStringNotContainsString( self::SECRET, ob_get_clean() );
	}

	/**
	 * Casting by field type.
	 *
	 * @dataProvider casts
	 *
	 * @param mixed $raw   Raw value.
	 * @param array $field Field schema.
	 * @param bool  $ok    Whether it is accepted.
	 * @param mixed $value Expected value.
	 */
	public function test_cast( $raw, array $field, $ok, $value = null ) {
		$cast = sc_wpun()->config()->cast( $raw, $field );

		$this->assertSame( $ok, $cast['ok'] );
		if ( $ok ) {
			$this->assertSame( $value, $cast['value'] );
		}
	}

	/**
	 * Cast cases.
	 *
	 * @return array
	 */
	public function casts() {
		$select = array(
			'type'    => 'select',
			'options' => array(
				'a' => 'A',
				'b' => 'B',
			),
		);
		$number = array(
			'type' => 'number',
			'min'  => 0,
			'max'  => 2,
		);
		$check  = array( 'type' => 'checkbox' );

		return array(
			'true'          => array( 'yes', $check, true, true ),
			'on'            => array( 'ON', $check, true, true ),
			'one'           => array( 1, $check, true, true ),
			'false'         => array( 'off', $check, true, false ),
			'empty'         => array( '', $check, true, false ),
			'zero'          => array( '0', $check, true, false ),
			'bad bool'      => array( 'maybe', $check, false ),
			'number'        => array( '2', $number, true, 2 ),
			'too big'       => array( '3', $number, false ),
			'not numeric'   => array( 'two', $number, false ),
			'bool number'   => array( true, $number, false ),
			'select'        => array( 'b', $select, true, 'b' ),
			'bad select'    => array( 'c', $select, false ),
			'text'          => array( 42, array( 'type' => 'text' ), true, '42' ),
			'secret'        => array( 'abc', array( 'type' => 'secret' ), true, 'abc' ),
			'array'         => array( array( 'x' ), array( 'type' => 'text' ), false ),
			'bool for text' => array( true, array( 'type' => 'url' ), false ),
		);
	}

	/**
	 * Instance ids are safe to use in constant names.
	 */
	public function test_instance_id_rules() {
		foreach ( array( 'email', 'slack', 'slack_1', 'ops_slack', 'a1_b2_c3' ) as $id ) {
			$this->assertSame( $id, ( new Channel_Instance( $id, 'email', 'L', true ) )->get_id() );
		}
		foreach ( array( 'a__b', '_a', 'a_', '_', '__', 'A', 'a-b', '' ) as $id ) {
			try {
				new Channel_Instance( $id, 'email', 'L', true );
				$this->fail( 'Accepted id ' . $id );
			} catch ( InvalidArgumentException $e ) {
				$this->assertInstanceOf( InvalidArgumentException::class, $e );
			}
		}
		$this->assertSame( 'a__b', ( new Channel_Instance( 'ok', 'a__b', 'L', true ) )->get_type(), 'Types keep their rule.' );
	}
}
