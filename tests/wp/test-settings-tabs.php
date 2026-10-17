<?php
/**
 * The tabs of the settings screen (#163).
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties are camelCase.

/**
 * Tab bar, the current tab, and the hooks that add tabs.
 */
class Test_Settings_Tabs extends WPUN_Test_Case {

	/**
	 * The request URI before the test.
	 *
	 * @var string|null
	 */
	private $request_uri;

	/**
	 * Remember what tests change.
	 */
	public function set_up() {
		parent::set_up();
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null;
	}

	/**
	 * Put back what tests changed.
	 */
	public function tear_down() {
		unset( $_GET['tab'] );
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		parent::tear_down();
	}

	/**
	 * The settings page HTML, as an administrator.
	 *
	 * @param int $user_id User to view it as. Default a new administrator.
	 *
	 * @return string
	 */
	private function html( int $user_id = 0 ): string {
		wp_set_current_user( $user_id ? $user_id : self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();

		return (string) ob_get_clean();
	}

	/**
	 * The page, parsed.
	 *
	 * @param string $html Page HTML.
	 *
	 * @return DOMXPath
	 */
	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return new DOMXPath( $doc );
	}

	/**
	 * Add a tab through the hooks.
	 *
	 * @param string $slug  Slug.
	 * @param string $label Label.
	 */
	private function add_tab( string $slug, string $label ) {
		add_filter(
			'sc_wpun_settings_tabs',
			static function ( $tabs ) use ( $slug, $label ) {
				$tabs[ $slug ] = $label;
				return $tabs;
			}
		);
		add_action(
			"sc_wpun_settings_tab_{$slug}",
			static function () use ( $slug ) {
				echo '<p class="drawn-' . esc_attr( $slug ) . '">Drawn</p>';
			}
		);
	}

	/**
	 * The slugs of the tabs in the bar.
	 *
	 * @param DOMXPath $xpath Page.
	 *
	 * @return string[]
	 */
	private function tab_slugs( DOMXPath $xpath ): array {
		$slugs = array();
		foreach ( $xpath->query( '//nav[@class="nav-tab-wrapper"]/a' ) as $link ) {
			parse_str( (string) wp_parse_url( $link->getAttribute( 'href' ), PHP_URL_QUERY ), $query );
			$slugs[] = $query['tab'];
		}

		return $slugs;
	}

	/**
	 * With nothing added, General and Channels are the tabs, General is current and draws the settings.
	 */
	public function test_general_is_the_default_tab() {
		$xpath = $this->xpath( $this->html() );

		$this->assertSame( 1, $xpath->query( '//div[@class="wrap"]/h1' )->length );
		$this->assertSame( 1, $xpath->query( '//nav[@class="nav-tab-wrapper"][@aria-label]' )->length );
		$this->assertSame( array( 'general', 'channels' ), $this->tab_slugs( $xpath ) );
		$active = $xpath->query( '//a[contains(@class,"nav-tab-active")]' );
		$this->assertSame( 1, $active->length );
		$this->assertSame( 'page', $active->item( 0 )->getAttribute( 'aria-current' ) );
		$this->assertStringContainsString( 'options-general.php?page=wp-updates-notifier&tab=general', $active->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 1, $xpath->query( '//form[contains(@action,"options.php")]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[contains(@class,"sc-wpun-last-check")]' )->length );
	}

	/**
	 * The Channels tab is listed, can be chosen and draws the channel list instead of the settings form.
	 */
	public function test_channels_is_listed_and_drawn() {
		$_GET['tab'] = 'channels';
		$xpath       = $this->xpath( $this->html() );

		$this->assertSame( array( 'general', 'channels' ), $this->tab_slugs( $xpath ) );
		$this->assertSame( 1, $xpath->query( '//table[contains(@class,"sc-wpun-channels")]' )->length );
		$this->assertSame( 0, $xpath->query( '//form[contains(@action,"options.php")]' )->length );
	}

	/**
	 * A tab added through the filter and the action is listed, drawn when chosen, and the only current one.
	 */
	public function test_an_added_tab_is_drawn_and_marked_current() {
		$this->add_tab( 'mine', 'Mine' );
		$_GET['tab'] = 'mine';
		$xpath       = $this->xpath( $this->html() );

		$this->assertSame( array( 'general', 'channels', 'mine' ), $this->tab_slugs( $xpath ) );
		$this->assertSame( 1, $xpath->query( '//p[@class="drawn-mine"]' )->length );
		$this->assertSame( 0, $xpath->query( '//form[contains(@action,"options.php")]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[@aria-current]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[contains(@class,"nav-tab-active")]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[contains(@class,"nav-tab-active")][@aria-current="page"][contains(@href,"tab=mine")]' )->length );
	}

	/**
	 * An unknown slug shows General.
	 */
	public function test_an_unknown_tab_shows_general() {
		$this->add_tab( 'mine', 'Mine' );
		$_GET['tab'] = 'nope';
		$xpath       = $this->xpath( $this->html() );

		$this->assertSame( 1, $xpath->query( '//a[contains(@class,"nav-tab-active")][contains(@href,"tab=general")]' )->length );
		$this->assertSame( 0, $xpath->query( '//p[@class="drawn-mine"]' )->length );
	}

	/**
	 * A tab in the filter that nothing draws is not listed.
	 */
	public function test_a_tab_nothing_draws_is_not_listed() {
		add_filter(
			'sc_wpun_settings_tabs',
			static function ( $tabs ) {
				$tabs['empty'] = 'Empty';
				return $tabs;
			}
		);

		$this->assertSame( array( 'general', 'channels' ), $this->tab_slugs( $this->xpath( $this->html() ) ) );
	}

	/**
	 * Labels are escaped.
	 */
	public function test_labels_are_escaped() {
		$this->add_tab( 'mine', 'A "quoted" <b>label</b>' );
		$html = $this->html();

		$this->assertStringNotContainsString( '<b>label</b>', $html );
		$this->assertSame( 'A "quoted" <b>label</b>', $this->xpath( $html )->query( '//a[contains(@href,"tab=mine")]' )->item( 0 )->textContent );
	}

	/**
	 * Invalid filter results are ignored.
	 */
	public function test_invalid_filter_output_is_ignored() {
		add_filter( 'sc_wpun_settings_tabs', '__return_false' );
		$this->assertSame( array( 'general' ), array_keys( sc_wpun()->settings_page()->tabs() ) );
		remove_all_filters( 'sc_wpun_settings_tabs' );

		$this->add_tab( 'Bad Slug', 'Bad' );
		$this->add_tab( 'good', 'Good' );
		add_filter(
			'sc_wpun_settings_tabs',
			static function ( $tabs ) {
				$tabs[0]       = 'Numeric';
				$tabs['array'] = array( 'x' );
				return $tabs;
			}
		);
		add_action( 'sc_wpun_settings_tab_array', '__return_true' );
		add_action( 'sc_wpun_settings_tab_0', '__return_true' );

		$this->assertSame( array( 'general', 'good' ), array_keys( sc_wpun()->settings_page()->tabs() ) );
	}

	/**
	 * General can not be removed, and stays first.
	 */
	public function test_general_cannot_be_removed() {
		add_filter( 'sc_wpun_settings_tabs', '__return_empty_array' );
		$this->add_tab( 'mine', 'Mine' );

		$this->assertSame( array( 'general', 'mine' ), array_keys( sc_wpun()->settings_page()->tabs() ) );
	}

	/**
	 * Notices show on every tab, above the tab bar.
	 */
	public function test_notices_show_above_the_tabs_on_other_tabs() {
		$this->add_tab( 'mine', 'Mine' );
		$_GET['tab'] = 'mine';
		$admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		sc_wpun()->notices()->add( true, 'Done.' );
		$xpath = $this->xpath( $this->html( $admin ) );

		$this->assertSame( 1, $xpath->query( '//div[contains(@class,"sc-wpun-notice")]/following-sibling::nav' )->length );
		$this->assertSame( 0, $xpath->query( '//nav/following-sibling::div[contains(@class,"sc-wpun-notice")]' )->length );
	}

	/**
	 * Saving goes back to the General tab: options.php redirects to the referer the form carries.
	 */
	public function test_saving_returns_to_the_general_tab() {
		$_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php?page=wp-updates-notifier&tab=general';
		$xpath                  = $this->xpath( $this->html() );

		$referer = $xpath->query( '//form[contains(@action,"options.php")]//input[@name="_wp_http_referer"]' );
		$this->assertSame( 1, $referer->length );
		$this->assertStringContainsString( 'tab=general', $referer->item( 0 )->getAttribute( 'value' ) );
	}
}
