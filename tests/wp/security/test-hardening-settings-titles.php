<?php
/**
 * The titles of the General tab are escaped (#325).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Settings_Page;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * The plugin escapes the title of the settings panel and the label of each field, translations included.
 *
 * @group security
 */
class Test_Hardening_Settings_Titles extends WPUN_Security_Test_Case {

	const PAYLOAD = '<img src=x onerror=alert(1)>';

	/**
	 * Put the plugin's objects back.
	 */
	public function tear_down() {
		foreach ( $this->shared as $name => $value ) {
			$this->set_plugin_property( $name, $value );
		}
		unset( $_GET['tab'] );
		parent::tear_down();
	}

	/**
	 * A property of the plugin.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	private function plugin_property( $name ) {
		$property = new ReflectionProperty( sc_wpun(), $name );
		$property->setAccessible( true );

		return $property->getValue( sc_wpun() );
	}

	/**
	 * Set a property of the plugin.
	 *
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 */
	private function set_plugin_property( $name, $value ) {
		$property = new ReflectionProperty( sc_wpun(), $name );
		$property->setAccessible( true );
		$property->setValue( sc_wpun(), $value );
	}

	/**
	 * The plugin's objects a test swapped, to put back.
	 *
	 * @var array<string, mixed>
	 */
	private $shared = array();

	/**
	 * Save the settings and become an administrator. Drawing the page loads the settings groups, so it gets its own,
	 * and the plugin's own are left for the tests that register groups later.
	 */
	public function set_up() {
		parent::set_up();

		foreach ( array( 'settings_groups', 'settings_group_form', 'settings_page', 'config_report' ) as $name ) {
			$this->shared[ $name ] = $this->plugin_property( $name );
		}
		$groups = new WPUN\Settings_Groups( sc_wpun()->config(), sc_wpun()->channels(), sc_wpun()->settings(), sc_wpun()->network_context() );
		$this->set_plugin_property( 'settings_groups', $groups );
		$this->set_plugin_property( 'config_report', new WPUN\Config_Report( sc_wpun()->config(), sc_wpun()->settings(), $this->plugin_property( 'channel_store' ), $groups, sc_wpun()->network_context() ) );
		$this->set_plugin_property( 'settings_group_form', null );
		$this->set_plugin_property( 'settings_page', null );
		$this->save_settings();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * A translation with markup in the section title or in a field label shows as text.
	 */
	public function test_a_translated_title_is_escaped() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		add_filter(
			'gettext',
			static function ( $translation, $text, $domain ) {
				return 'wp-updates-notifier' === $domain ? self::PAYLOAD . $text : $translation;
			},
			10,
			3
		);

		$page = sc_wpun()->settings_page();
		$this->assertInstanceOf( Settings_Page::class, $page );
		$_GET['tab'] = 'general';
		$html        = $this->capture( array( $page, 'settings_page' ) );

		$this->assertStringNotContainsString( self::PAYLOAD, $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;Settings', $html, 'The panel title.' );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;Frequency to check', $html, 'A field label.' );
		$this->assert_no_live_markup( $html, 'General tab' );
	}
}
