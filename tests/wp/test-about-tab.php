<?php
/**
 * The About tab and the code-only show_about setting (#185).
 *
 * A PHP constant cannot be undefined in one PHPUnit run, so the constant is covered by its name and the
 * other ways of supplying the value (test source, environment, filter) are tested in full.
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The tests set environment variables.

use WPUN\Admin\About_Tab;
use WPUN\Config_Context;
use WPUN\Plugin;

/**
 * Tab, setting, storage and the link list.
 */
class Test_About_Tab extends WPUN_Test_Case {

	/**
	 * Start from a clean resolver.
	 */
	public function set_up() {
		parent::set_up();
		sc_wpun()->config()->flush();
	}

	/**
	 * Clean up the environment, the request and the resolver.
	 */
	public function tear_down() {
		putenv( 'SC_WPUN_SETTING_SHOW_ABOUT' );
		putenv( 'SC_WPUN_LOAD_FROM_ENV' );
		unset( $_ENV['SC_WPUN_SETTING_SHOW_ABOUT'], $_ENV['SC_WPUN_LOAD_FROM_ENV'], $_GET['tab'] );
		sc_wpun()->config()->flush();
		parent::tear_down();
	}

	/**
	 * The settings page HTML, as an administrator.
	 *
	 * @return string
	 */
	private function html(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();

		return (string) ob_get_clean();
	}

	/**
	 * Supply show_about from a test source.
	 *
	 * @param mixed $value Value.
	 */
	private function supply( $value ) {
		$source = new Test_Config_Source( array( 'SC_WPUN_SETTING_SHOW_ABOUT' => $value ) );
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
	 * The slugs of the tabs in the bar.
	 *
	 * @param string $html Page HTML.
	 *
	 * @return string[]
	 */
	private function tab_slugs( string $html ): array {
		preg_match_all( '/<a class="nav-tab[^"]*" href="[^"]*&#038;tab=([a-z]+)"/', $html, $found );

		return $found[1];
	}

	/**
	 * Shown by default, last, with every link escaped and each marked as external for screen readers.
	 */
	public function test_shown_by_default_and_last() {
		$html = $this->html();

		$this->assertTrue( sc_wpun()->settings()->show_about() );
		$this->assertSame( array( 'general', 'channels', 'about' ), $this->tab_slugs( $html ) );

		$_GET['tab'] = 'about';
		$html        = $this->html();
		$this->assertStringContainsString( 'sc-wpun-about', $html );
		$this->assertSame( 1, preg_match_all( '/<h1[ >]/', $html ) );
		foreach ( About_Tab::links() as $slug => $url ) {
			$this->assertStringContainsString( 'href="' . esc_url( $url ) . '"', $html, $slug );
		}
		$this->assertSame( count( About_Tab::links() ), substr_count( $html, 'class="screen-reader-text"> (external link)</span></a>' ) );
		$this->assertStringContainsString( "define( 'SC_WPUN_SETTING_SHOW_ABOUT', false );", $html );
		$this->assertStringContainsString( 'Scott Cariss', $html );
	}

	/**
	 * Every link is an https address.
	 */
	public function test_links_are_secure_addresses() {
		foreach ( About_Tab::links() as $slug => $url ) {
			$this->assertStringStartsWith( 'https://', $url, $slug );
			$this->assertSame( esc_url_raw( $url ), $url, $slug );
		}
	}

	/**
	 * The addresses are on the documentation home page too, so one changed in only one place fails here.
	 */
	public function test_links_match_the_documentation_home_page() {
		$home = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/index.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		$this->assertNotSame( '', $home );

		$links = About_Tab::links();
		$this->assertSame( Plugin::SITE_URL, $links['documentation'], 'The site root.' );
		$this->assertSame( Plugin::SITE_URL . '#support-the-project', $links['donate'] );
		$this->assertMatchesRegularExpression( '/^## Support the project$/m', $home, 'The fragment of the donate link.' );

		foreach ( $links as $slug => $url ) {
			if ( str_starts_with( $url, Plugin::SITE_URL ) ) {
				continue; // The home page is the site root.
			}
			$this->assertStringContainsString( $url, $home, $slug );
		}
	}

	/**
	 * The readme's Donate link and the plugin header's Author URI point at the same sections of the documentation home page.
	 */
	public function test_readme_and_header_link_the_documentation_home_page() {
		$root   = dirname( __DIR__, 2 );
		$home   = (string) file_get_contents( $root . '/docs/index.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		$readme = get_file_data( $root . '/readme.txt', array( 'donate' => 'Donate link' ) );
		$header = get_file_data( SC_WPUN_FILE, array( 'author_uri' => 'Author URI' ) );

		$this->assertSame( About_Tab::links()['donate'], $readme['donate'] );
		$this->assertSame( Plugin::SITE_URL . '#about-the-developer', $header['author_uri'] );
		$this->assertMatchesRegularExpression( '/^## About the developer$/m', $home, 'The fragment of the Author URI.' );
	}

	/**
	 * Values that turn the setting off are honoured however they are supplied; the tab and its link go.
	 *
	 * @dataProvider data_hidden_by
	 *
	 * @param callable $hide Hides the tab.
	 */
	public function test_it_can_be_hidden( callable $hide ) {
		$hide( $this );

		$this->assertFalse( sc_wpun()->settings()->show_about() );
		$this->assertSame( array(), wp_list_pluck( sc_wpun()->config()->problems(), 'key' ) );
		$this->assertSame( array( 'general', 'channels' ), $this->tab_slugs( $this->html() ) );

		// The request for the tab falls back to General.
		$_GET['tab'] = 'about';
		$html        = $this->html();
		$this->assertStringNotContainsString( 'sc-wpun-about', $html );
		$this->assertStringContainsString( 'sc-wpun-last-check', $html );
		$this->assertMatchesRegularExpression( '/nav-tab-active" href="[^"]*tab=general"/', $html );
	}

	/**
	 * The ways of hiding the tab.
	 *
	 * @return array<string, array<int, callable>>
	 */
	public function data_hidden_by() {
		return array(
			'a configuration source'  => array(
				static function ( $test ) {
					$test->supply( false );
				},
			),
			'a source with a string'  => array(
				static function ( $test ) {
					$test->supply( 'off' );
				},
			),
			'an environment variable' => array(
				static function () {
					putenv( 'SC_WPUN_LOAD_FROM_ENV=1' );
					putenv( 'SC_WPUN_SETTING_SHOW_ABOUT=0' );
					sc_wpun()->config()->flush();
				},
			),
			'the config value filter' => array(
				static function () {
					add_filter(
						'sc_wpun_config_value',
						static function ( $value, $context ) {
							return 'show_about' === $context->get_setting() ? false : $value;
						},
						10,
						2
					);
					sc_wpun()->config()->flush();
				},
			),
		);
	}

	/**
	 * Without the opt-in the environment is not read.
	 */
	public function test_the_environment_needs_the_opt_in() {
		putenv( 'SC_WPUN_SETTING_SHOW_ABOUT=0' );
		sc_wpun()->config()->flush();

		$this->assertTrue( sc_wpun()->settings()->show_about() );
	}

	/**
	 * The constant is read under this name, and a source for the setting is named in the screen's terms.
	 */
	public function test_the_constant_name() {
		$this->assertSame( 'SC_WPUN_SETTING_SHOW_ABOUT', Config_Context::setting( 'show_about' )->get_key() );
		$this->assertSame( 'Test vault', ( new Test_Config_Source( array() ) )->get_label( 'SC_WPUN_SETTING_SHOW_ABOUT' ) );
		$this->supply( false );
		$this->assertSame( 'Test vault', sc_wpun()->settings()->external_source( 'show_about' ) );
	}

	/**
	 * An add-on's tab comes before About.
	 */
	public function test_addon_tabs_come_before_about() {
		add_filter(
			'sc_wpun_settings_tabs',
			static function ( $tabs ) {
				$tabs['mine'] = 'Mine';
				return $tabs;
			}
		);
		add_action( 'sc_wpun_settings_tab_mine', '__return_empty_string' );

		$this->assertSame( array( 'general', 'channels', 'mine', 'about' ), $this->tab_slugs( $this->html() ) );
		$this->assertSame( 'about', array_key_last( sc_wpun()->settings_page()->tabs() ) );
	}

	/**
	 * The General tab has no control for the setting.
	 */
	public function test_the_general_tab_has_no_control() {
		$html = $this->html();

		$this->assertStringNotContainsString( 'show_about', $html );
		$this->assertArrayHasKey( 'show_about', sc_wpun()->settings()->schemas() );
	}

	/**
	 * Saving never writes the setting, and a stray stored value does not change the tab.
	 */
	public function test_it_is_never_stored() {
		$this->save_settings();
		$page = sc_wpun()->settings_page();

		$valid = $page->sc_wpun_settings_validate(
			array(
				'frequency'  => 'daily',
				'show_about' => '0',
			)
		);
		$this->assertArrayNotHasKey( 'show_about', $valid );
		$this->assertSame( 'daily', $valid['frequency'] );

		$page->admin_settings_init();
		update_option( 'sc_wpun_settings', array_merge( $this->global_settings(), array( 'show_about' => '0' ) ) );
		$this->assertArrayNotHasKey( 'show_about', $this->stored_settings() );
		$this->assertArrayNotHasKey( 'show_about', sc_wpun()->settings()->stored() );
		$this->assertArrayNotHasKey( 'show_about', sc_wpun()->settings()->get() );
		$this->assertArrayNotHasKey( 'show_about', sc_wpun()->settings()->default_settings() );
		$this->assertTrue( sc_wpun()->settings()->show_about() );
	}

	/**
	 * The upgrade drops a stored show_about.
	 */
	public function test_the_upgrade_drops_a_stray_stored_value() {
		update_option( 'sc_wpun_settings', array_merge( $this->global_settings(), array( 'show_about' => '0' ) ) );
		update_option( 'sc_wpun_settings_ver', '12.0' );

		sc_wpun()->settings()->maybe_upgrade();

		$this->assertArrayNotHasKey( 'show_about', $this->stored_settings() );
		$this->assertTrue( sc_wpun()->settings()->show_about() );
	}

	/**
	 * An invalid value leaves the tab shown and is reported with the right fallback, without the value.
	 */
	public function test_an_invalid_value_is_reported() {
		$this->supply( 'maybe' );

		$this->assertTrue( sc_wpun()->settings()->show_about() );
		// Nothing resolved the setting through the page yet: the report does it.
		$tests  = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$result = call_user_func( $tests['direct']['sc_wpun_config']['test'] );
		$this->assertStringContainsString( 'SC_WPUN_SETTING_SHOW_ABOUT', $result['description'] );
		$this->assertStringContainsString( 'so the default is used', $result['description'] );
		$this->assertStringNotContainsString( 'maybe', $result['description'] );
		$this->assertStringNotContainsString( 'so the saved one is used', $result['description'] );

		$this->assertStringContainsString( 'SC_WPUN_SETTING_SHOW_ABOUT', $this->html() );
	}

	/**
	 * An invalid value of a stored setting still says the saved one is used.
	 */
	public function test_stored_settings_keep_their_message() {
		$source = new Test_Config_Source( array( 'SC_WPUN_SETTING_HIDE_UPDATES' => 'maybe' ) );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $source ) {
				$sources[] = $source;
				return $sources;
			}
		);
		sc_wpun()->config()->flush();
		sc_wpun()->settings()->get();

		$this->assertStringContainsString( 'saved one', sc_wpun()->config()->problems()[0]['message'] );
	}
}
