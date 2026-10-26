<?php
/**
 * The error messages of the General tab are escaped (HN2 of the 2.0.0 security audit).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Settings_Page;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * Core prints the message of a settings error as it is, so the plugin escapes the text it passes, translations
 * included.
 *
 * @group security
 */
class Test_Hardening_Settings_Errors extends WPUN_Security_Test_Case {

	const PAYLOAD = '<img src=x onerror=alert(1)>';

	/**
	 * Start with no settings errors.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_settings_errors;
		$wp_settings_errors = array();
		$this->save_settings();
	}

	/**
	 * Forget the settings errors.
	 */
	public function tear_down() {
		global $wp_settings_errors;
		$wp_settings_errors = array();
		parent::tear_down();
	}

	/**
	 * A translation with markup in the message of a refused value is stored as text.
	 */
	public function test_a_translated_message_is_escaped() {
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
		$page->sc_wpun_settings_validate( array( 'frequency' => 'not-a-frequency' ) );

		$messages = wp_list_pluck( get_settings_errors( 'sc_wpun_settings_main_frequency' ), 'message' );
		$this->assertNotEmpty( $messages );
		foreach ( $messages as $message ) {
			$this->assertStringNotContainsString( self::PAYLOAD, $message );
			$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $message );
		}
	}
}
