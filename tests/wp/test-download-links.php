<?php
/**
 * The optional download links of plugin, theme and WordPress items (#337).
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification_Renderer;

/**
 * The checker adds a `download` link only with the setting on, and the renderers show it like other links.
 */
class Test_Download_Links extends WPUN_Test_Case {

	/**
	 * These tests need the default capability.
	 *
	 * @var bool
	 */
	protected $default_capability = true;

	/**
	 * No settings errors left over from another test.
	 */
	public function set_up() {
		parent::set_up();
		unset( $GLOBALS['wp_settings_errors'] );
	}

	/**
	 * Remove what tests added.
	 */
	public function tear_down() {
		sc_wpun()->config()->flush();
		parent::tear_down();
	}

	/**
	 * Offer a core, a plugin and a theme update, with these package addresses.
	 *
	 * @param mixed $plugin_package Package of the plugin update.
	 * @param mixed $theme_package  Package of the theme update.
	 * @param array $core_offer     Extra properties of the core offer.
	 */
	private function offer( $plugin_package, $theme_package, array $core_offer ) {
		$this->set_updates(
			'99.0.0',
			array(
				'hello.php' => array(
					'new_version' => '2.0.0',
					'package'     => $plugin_package,
				),
			),
			array(
				get_stylesheet() => array(
					'new_version' => '99.3',
					'package'     => $theme_package,
				),
			),
			$core_offer
		);
	}

	/**
	 * Offer updates with http packages everywhere.
	 */
	private function offer_downloads() {
		$this->offer(
			'https://downloads.example.org/hello.zip',
			'https://downloads.example.org/theme.zip',
			array( 'download' => 'https://downloads.example.org/wordpress.zip' )
		);
	}

	/**
	 * Run a check and return the notification it built.
	 *
	 * @return WPUN\Notification
	 */
	private function check() {
		reset_phpmailer_instance();
		sc_wpun()->update_checker()->do_update_check();
		$notification = sc_wpun()->update_checker()->last_notification();
		$this->assertNotNull( $notification );

		return $notification;
	}

	/**
	 * The item of a type from a check.
	 *
	 * @param string $type Item type.
	 * @return WPUN\Notification_Item
	 */
	private function item( $type ) {
		$items = $this->check()->get_items_of_type( $type );
		$this->assertCount( 1, $items, $type );

		return $items[0];
	}

	/**
	 * The three items from a check, by type.
	 *
	 * @return WPUN\Notification_Item[]
	 */
	private function items() {
		$notification = $this->check();

		return array(
			'core'   => $notification->get_items_of_type( 'core' )[0],
			'plugin' => $notification->get_items_of_type( 'plugin' )[0],
			'theme'  => $notification->get_items_of_type( 'theme' )[0],
		);
	}

	/**
	 * Off is the default and puts no download link on any item.
	 */
	public function test_off_by_default_adds_no_links() {
		$this->save_settings();
		$this->offer_downloads();

		$this->assertFalse( sc_wpun()->settings()->get()['download_links'] );
		foreach ( $this->items() as $type => $item ) {
			$this->assertArrayNotHasKey( 'download', $item->get_links(), $type );
		}
		$this->assertStringNotContainsString( 'Download', Notification_Renderer::plain_text( sc_wpun()->update_checker()->last_notification() ) );
	}

	/**
	 * Off does not run the filter.
	 */
	public function test_the_filter_does_not_run_when_off() {
		$this->save_settings();
		$this->offer_downloads();
		$calls = 0;
		add_filter(
			'sc_wpun_item_download_url',
			static function ( $url ) use ( &$calls ) {
				++$calls;
				return $url;
			}
		);

		$this->check();

		$this->assertSame( 0, $calls );
	}

	/**
	 * On adds the package address to plugin, theme and core items.
	 */
	public function test_on_adds_the_link_to_every_type() {
		$this->save_settings( array( 'download_links' => true ) );
		$this->offer_downloads();

		$items = $this->items();

		$this->assertSame( 'https://downloads.example.org/hello.zip', $items['plugin']->get_links()['download'] );
		$this->assertSame( 'https://downloads.example.org/theme.zip', $items['theme']->get_links()['download'] );
		$this->assertSame( 'https://downloads.example.org/wordpress.zip', $items['core']->get_links()['download'] );
	}

	/**
	 * Core falls back to the full package when the offer has no download address.
	 */
	public function test_core_falls_back_to_the_full_package() {
		$this->save_settings( array( 'download_links' => true ) );

		$this->set_updates( '99.0.0', array(), array(), array( 'packages' => (object) array( 'full' => 'https://downloads.example.org/full.zip' ) ) );
		$this->assertSame( 'https://downloads.example.org/full.zip', $this->item( 'core' )->get_links()['download'] );

		$this->set_updates( '99.0.0', array(), array(), array( 'packages' => array( 'full' => 'https://downloads.example.org/array.zip' ) ) );
		$this->assertSame( 'https://downloads.example.org/array.zip', $this->item( 'core' )->get_links()['download'] );

		$this->set_updates(
			'99.0.0',
			array(),
			array(),
			array(
				'download' => 'https://downloads.example.org/download.zip',
				'packages' => (object) array( 'full' => 'https://downloads.example.org/full.zip' ),
			)
		);
		$this->assertSame( 'https://downloads.example.org/download.zip', $this->item( 'core' )->get_links()['download'], 'The download address wins.' );

		$this->set_updates(
			'99.0.0',
			array(),
			array(),
			array(
				'download' => '',
				'packages' => (object) array( 'full' => 'https://downloads.example.org/full.zip' ),
			)
		);
		$this->assertSame( 'https://downloads.example.org/full.zip', $this->item( 'core' )->get_links()['download'], 'An empty download address falls back.' );
	}

	/**
	 * A missing, empty or unusable package gives no link and no warning.
	 */
	public function test_a_missing_or_unusable_package_gives_no_link() {
		$this->save_settings( array( 'download_links' => true ) );

		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ), array( get_stylesheet() => array( 'new_version' => '99.3' ) ) );
		foreach ( $this->items() as $type => $item ) {
			$this->assertArrayNotHasKey( 'download', $item->get_links(), 'No package: ' . $type );
		}

		$this->offer( '', '', array( 'download' => '' ) );
		foreach ( $this->items() as $type => $item ) {
			$this->assertArrayNotHasKey( 'download', $item->get_links(), 'Empty package: ' . $type );
		}

		$this->offer( array( 'https://example.org/a.zip' ), array( 'x' => 'y' ), array( 'download' => array( 'a' ) ) );
		foreach ( $this->items() as $type => $item ) {
			$this->assertArrayNotHasKey( 'download', $item->get_links(), 'Array package: ' . $type );
		}

		$this->offer( new stdClass(), new stdClass(), array( 'download' => new stdClass() ) );
		foreach ( $this->items() as $type => $item ) {
			$this->assertArrayNotHasKey( 'download', $item->get_links(), 'Object package: ' . $type );
		}

		$this->offer( '', '', array( 'packages' => 'not a list' ) );
		$this->assertArrayNotHasKey( 'download', $this->item( 'core' )->get_links() );
		$this->offer( '', '', array( 'packages' => (object) array( 'full' => array( 'x' ) ) ) );
		$this->assertArrayNotHasKey( 'download', $this->item( 'core' )->get_links() );
	}

	/**
	 * The labels in the three formats.
	 */
	public function test_the_labels() {
		$this->save_settings( array( 'download_links' => true ) );
		$this->set_updates( '99.0.0', array(), array(), array( 'download' => 'https://downloads.example.org/wordpress.zip' ) );
		$notification = $this->check();

		$this->assertStringContainsString( "  Download: https://downloads.example.org/wordpress.zip\n", Notification_Renderer::plain_text( $notification ) );
		$this->assertStringContainsString( '  - [WordPress download](https://downloads.example.org/wordpress.zip)', Notification_Renderer::markdown( $notification ) );
		$this->assertStringContainsString( '<li><a href="https://downloads.example.org/wordpress.zip">WordPress download</a></li>', Notification_Renderer::html( $notification ) );
	}

	/**
	 * An address that is not http or https stays on the item, but no format links it.
	 */
	public function test_a_non_http_address_is_not_linked() {
		$this->save_settings( array( 'download_links' => true ) );

		foreach ( array( 'ftp://example.org/x.zip', '/tmp/x.zip' ) as $package ) {
			$this->set_updates( '99.0.0', array(), array(), array( 'download' => $package ) );
			$notification = $this->check();

			$this->assertSame( $package, $notification->get_items_of_type( 'core' )[0]->get_links()['download'] );
			$this->assertStringNotContainsString( 'Download', Notification_Renderer::plain_text( $notification ), $package );
			$markdown = Notification_Renderer::markdown( $notification );
			$this->assertStringContainsString( "  - WordPress download\n", $markdown, $package );
			$this->assertStringNotContainsString( $package, $markdown, $package );
			$html = Notification_Renderer::html( $notification );
			$this->assertStringContainsString( '<li>WordPress download</li>', $html, $package );
			$this->assertStringNotContainsString( 'x.zip', $html, $package );
		}
	}

	/**
	 * A source of configuration turns the links on, whatever is stored.
	 */
	public function test_a_config_source_turns_the_links_on() {
		$this->save_settings( array( 'download_links' => false ) );
		$source = new Test_Config_Source( array( 'SC_WPUN_SETTING_DOWNLOAD_LINKS' => 'yes' ) );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $source ) {
				$sources[] = $source;
				return $sources;
			}
		);
		sc_wpun()->config()->flush();
		$this->offer_downloads();

		$this->assertTrue( sc_wpun()->settings()->get()['download_links'] );
		$this->assertFalse( sc_wpun()->settings()->stored()['download_links'] );
		$this->assertSame( 'https://downloads.example.org/hello.zip', $this->item( 'plugin' )->get_links()['download'] );
	}

	/**
	 * The filter gets the address, the item type and the key.
	 */
	public function test_the_filter_gets_the_type_and_key() {
		$this->save_settings( array( 'download_links' => true ) );
		$this->offer_downloads();
		$seen = array();
		add_filter(
			'sc_wpun_item_download_url',
			static function ( $url, $type, $key ) use ( &$seen ) {
				$seen[ $type ] = array( $url, $key );
				return $url;
			},
			10,
			3
		);

		$this->check();

		$this->assertSame(
			array(
				'core'   => array( 'https://downloads.example.org/wordpress.zip', 'core' ),
				'plugin' => array( 'https://downloads.example.org/hello.zip', 'hello.php' ),
				'theme'  => array( 'https://downloads.example.org/theme.zip', get_stylesheet() ),
			),
			$seen
		);
	}

	/**
	 * The filter can change an address or drop it with an empty string, and what is not a string counts as empty.
	 */
	public function test_the_filter_changes_or_drops_the_link() {
		$this->save_settings( array( 'download_links' => true ) );
		$this->offer_downloads();

		add_filter(
			'sc_wpun_item_download_url',
			static function ( $url, $type ) {
				if ( 'plugin' === $type ) {
					return '';
				}
				if ( 'theme' === $type ) {
					return array( 'x' );
				}
				return 'https://mirror.example.org/wordpress.zip';
			},
			10,
			2
		);

		$items = $this->items();

		$this->assertArrayNotHasKey( 'download', $items['plugin']->get_links() );
		$this->assertArrayNotHasKey( 'download', $items['theme']->get_links() );
		$this->assertSame( 'https://mirror.example.org/wordpress.zip', $items['core']->get_links()['download'] );
	}

	/**
	 * Off is the default, the form offers it off, and it is stored and accepted as a boolean.
	 */
	public function test_the_setting() {
		$settings = sc_wpun()->settings();

		$this->assertFalse( $settings->default_settings()['download_links'] );
		$this->assertFalse( $settings->schemas()['download_links']['default'] );
		$this->assertSame( 'boolean', $settings->schemas()['download_links']['type'] );

		$this->save_settings();
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings', array_diff_key( $this->global_settings(), array( 'download_links' => 1 ) ) );
		$this->assertFalse( $settings->stored()['download_links'], 'A missing key is off.' );

		add_filter(
			'sc_wpun_default_settings',
			static function ( $defaults ) {
				$defaults['download_links'] = true;
				return $defaults;
			}
		);
		$this->assertTrue( $settings->stored()['download_links'], 'The default filter applies to a missing key.' );
	}

	/**
	 * A posted value is validated as a boolean.
	 */
	public function test_a_posted_value_is_validated() {
		$page = sc_wpun()->settings_page();

		$this->assertTrue( $page->sc_wpun_settings_validate( array( 'download_links' => '1' ) )['download_links'] );
		$this->assertFalse( $page->sc_wpun_settings_validate( array( 'download_links' => '0' ) )['download_links'] );
		$this->assertSame( array(), get_settings_errors() );

		$valid = $page->sc_wpun_settings_validate( array( 'download_links' => 'banana' ) );
		$this->assertFalse( $valid['download_links'], 'The stored value is kept.' );
		$codes = wp_list_pluck( get_settings_errors(), 'code' );
		$this->assertContains( 'sc_wpun_settings_main_download_links_error', $codes );
	}

	/**
	 * The settings screen shows a checkbox with the warning, and the checkbox points to it.
	 */
	public function test_the_settings_screen_warns() {
		$this->save_settings();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$html = ob_get_clean();
		$doc  = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		$xpath = new DOMXPath( $doc );

		$box = $xpath->query( '//input[@type="checkbox"][@id="sc_wpun_settings_main_download_links"][@name="sc_wpun_settings[download_links]"]' );
		$this->assertSame( 1, $box->length );
		$this->assertFalse( $box->item( 0 )->hasAttribute( 'checked' ) );
		$this->assertSame( 'sc_wpun_settings_main_download_links-help', $box->item( 0 )->getAttribute( 'aria-describedby' ) );
		$help = $xpath->query( '//p[@id="sc_wpun_settings_main_download_links-help"]' );
		$this->assertSame( 1, $help->length );
		$this->assertStringContainsString( 'licence key or a private token', $help->item( 0 )->textContent );
		$this->assertSame( ' Include download links in notifications', $xpath->query( '//label[@for="sc_wpun_settings_main_download_links"]' )->item( 0 )->textContent );
	}
}
