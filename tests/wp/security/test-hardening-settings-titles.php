<?php
/**
 * The titles of the General tab are escaped (#325).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Settings_Page;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * Core prints the title of a Settings API section and field as it is, so the plugin escapes the text it passes,
 * translations included.
 *
 * @group security
 */
class Test_Hardening_Settings_Titles extends WPUN_Security_Test_Case {

	const PAYLOAD = '<img src=x onerror=alert(1)>';

	/**
	 * Start with no registered sections and fields.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_settings_sections, $wp_settings_fields;
		$wp_settings_sections = array();
		$wp_settings_fields   = array();
		$this->save_settings();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Forget the sections and fields.
	 */
	public function tear_down() {
		global $wp_settings_sections, $wp_settings_fields;
		$wp_settings_sections = array();
		$wp_settings_fields   = array();
		parent::tear_down();
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
		$page->admin_settings_init();
		$html = $this->capture(
			static function () {
				do_settings_sections( 'wp-updates-notifier' );
			}
		);

		$this->assertStringNotContainsString( self::PAYLOAD, $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;Settings', $html, 'The section title.' );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assert_no_live_markup( $html, 'General tab' );
	}
}
