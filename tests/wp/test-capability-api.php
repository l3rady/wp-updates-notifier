<?php
/**
 * The public functions for the capabilities: sc_wpun_capability() and sc_wpun_mute_capability() (#391).
 *
 * @package wp-updates-notifier
 */

/**
 * Both functions return what the plugin checks, after its filters.
 */
class Test_Capability_Api extends WPUN_Test_Case {

	/**
	 * The defaults, without the suite's `manage_options` on multisite.
	 *
	 * @var bool
	 */
	protected $default_capability = true;

	/**
	 * `manage_options` on a single site, `manage_network_options` on multisite, for both.
	 */
	public function test_defaults() {
		$expected = is_multisite() ? 'manage_network_options' : 'manage_options';

		$this->assertSame( $expected, sc_wpun_capability() );
		$this->assertSame( $expected, sc_wpun_mute_capability() );
	}

	/**
	 * They work on the front end, a REST route or WP-CLI, where the admin classes are not loaded yet.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_work_outside_the_admin() {
		$this->assertFalse( is_admin() );
		$this->assertFalse( class_exists( 'WPUN\Admin\Settings_Page', false ) );

		$expected = is_multisite() ? 'manage_network_options' : 'manage_options';
		$this->assertSame( $expected, sc_wpun_capability() );
		$this->assertSame( $expected, sc_wpun_mute_capability() );
	}

	/**
	 * The same values as the methods the plugin checks.
	 */
	public function test_match_the_plugins_checks() {
		add_filter( 'sc_wpun_capability', static fn() => 'edit_pages' );
		add_filter( 'sc_wpun_mute_capability', static fn() => 'update_plugins' );

		$this->assertSame( \WPUN\Admin\Settings_Page::capability(), sc_wpun_capability() );
		$this->assertSame( \WPUN\Admin\Mute_Actions::capability(), sc_wpun_mute_capability() );
	}

	/**
	 * Each follows its own filter.
	 */
	public function test_follow_their_filters() {
		add_filter( 'sc_wpun_capability', static fn() => 'edit_pages' );
		add_filter( 'sc_wpun_mute_capability', static fn() => 'update_plugins' );

		$this->assertSame( 'edit_pages', sc_wpun_capability() );
		$this->assertSame( 'update_plugins', sc_wpun_mute_capability() );
	}

	/**
	 * The mute capability follows the settings capability when only that is filtered.
	 */
	public function test_mute_capability_defaults_to_the_settings_capability() {
		add_filter( 'sc_wpun_capability', static fn() => 'edit_pages' );

		$this->assertSame( 'edit_pages', sc_wpun_mute_capability() );
	}

	/**
	 * An empty or non-string filter value falls back to the default.
	 *
	 * @dataProvider data_unusable_values
	 *
	 * @param mixed $value What a filter returns.
	 */
	public function test_unusable_filter_values_fall_back_to_the_default( $value ) {
		$expected = is_multisite() ? 'manage_network_options' : 'manage_options';
		add_filter( 'sc_wpun_capability', static fn() => $value );
		add_filter( 'sc_wpun_mute_capability', static fn() => $value );

		$this->assertSame( $expected, sc_wpun_capability() );
		$this->assertSame( $expected, sc_wpun_mute_capability() );
	}

	/**
	 * Unusable filter values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function data_unusable_values() {
		return array(
			'empty string' => array( '' ),
			'false'        => array( false ),
			'null'         => array( null ),
			'array'        => array( array( 'manage_options' ) ),
		);
	}
}
