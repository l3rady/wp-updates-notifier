<?php
/**
 * Nothing is translated while the plugin loads (#61).
 *
 * @package wp-updates-notifier
 */

/**
 * Since WordPress 6.7, translating a string before `after_setup_theme` makes core report "Translation loading for the
 * wp-updates-notifier domain was triggered too early". Core's notice cannot be triggered here, because the test
 * suite is past `after_setup_theme`. So this counts every translation call instead while the plugin does what it does
 * on `plugins_loaded`: it must be none.
 */
class Test_No_Early_Translation extends WPUN_Test_Case {

	/**
	 * Translation calls for the plugin's text domain since the filters were added.
	 *
	 * @var string[]
	 */
	private $calls = array();

	/**
	 * Count calls to every translation function.
	 */
	public function set_up() {
		parent::set_up();

		$this->calls = array();
		foreach ( array( 'gettext', 'ngettext', 'gettext_with_context', 'ngettext_with_context' ) as $function ) {
			add_filter(
				$function . '_wp-updates-notifier',
				function ( $translation ) use ( $function ) {
					$this->calls[] = $function . ': ' . ( is_string( $translation ) ? $translation : '' );
					return $translation;
				}
			);
		}
	}

	/**
	 * Forget what the test seeded.
	 */
	public function tear_down() {
		delete_option( 'sc_wpun_settings' );
		delete_option( 'sc_wpun_settings_ver' );
		delete_option( 'sc_wpun_channels' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Load the plugin as `plugins_loaded` does, on a 1.x installation whose email and Slack settings become channels.
	 */
	private function load_plugin_on_1x_settings() {
		$fixtures = require dirname( __DIR__ ) . '/fixtures/settings.php';
		delete_option( 'sc_wpun_channels' );
		update_option( 'sc_wpun_settings', $fixtures['1.6.0']['options'] );
		update_option( 'sc_wpun_settings_ver', $fixtures['1.6.0']['version'] );

		$plugin = new \WPUN\Plugin();
		$plugin->register();
		do_action( 'plugins_loaded' );
		$plugin->boot();

		// The channel migration ran, so it was covered.
		$this->assertArrayHasKey( 'email', get_option( 'sc_wpun_channels' ) );
		$this->assertArrayHasKey( 'slack', get_option( 'sc_wpun_channels' ) );
	}

	/**
	 * The front end: no admin classes are loaded.
	 */
	public function test_nothing_is_translated_while_loading_on_the_front_end() {
		set_current_screen( 'front' );

		$this->load_plugin_on_1x_settings();

		$this->assertSame( array(), $this->calls );
	}

	/**
	 * An admin screen: the admin hooks are registered too.
	 */
	public function test_nothing_is_translated_while_loading_in_the_admin() {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );

		$this->load_plugin_on_1x_settings();

		$this->assertSame( array(), $this->calls );
	}
}
