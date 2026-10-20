<?php
/**
 * The settings screen is drawn from the setting schemas (#161).
 *
 * @package wp-updates-notifier
 */

/**
 * Which control each global setting gets.
 */
class Test_Settings_Screen extends WPUN_Test_Case {

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
	 * The settings page, parsed.
	 *
	 * @return DOMXPath
	 */
	private function page() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$html = ob_get_clean();

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );

		return new DOMXPath( $doc );
	}

	/**
	 * Offer more schedules through the filter.
	 */
	private function offer_more_frequencies() {
		// phpcs:disable WordPress.WP.CronInterval.ChangeDetected -- Test schedules of any interval, on purpose.
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				foreach ( array( 'two_days' => 2, 'three_days' => 3, 'four_days' => 4 ) as $name => $days ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					$schedules[ $name ] = array(
						'interval' => $days * DAY_IN_SECONDS,
						'display'  => $name,
					);
				}
				return $schedules;
			}
		);
		// phpcs:enable WordPress.WP.CronInterval.ChangeDetected
		add_filter(
			'sc_wpun_frequencies',
			static function ( $names ) {
				return array_merge( $names, array( 'two_days', 'three_days', 'four_days' ) );
			}
		);
	}

	/**
	 * The default schedules and the notify settings are radios in a fieldset with a legend.
	 */
	public function test_frequency_and_notify_settings_are_radios() {
		$this->save_settings( array( 'frequency' => 'daily' ) );
		$xpath = $this->page();

		foreach ( array( 'frequency' => 4, 'notify_plugins' => 3, 'notify_themes' => 3 ) as $name => $count ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$id = 'sc_wpun_settings_main_' . $name;
			$this->assertSame( 1, $xpath->query( '//fieldset[@id="' . $id . '"]/legend' )->length, $name );
			$this->assertSame( $count, $xpath->query( '//fieldset[@id="' . $id . '"]//input[@type="radio"][@name="sc_wpun_settings[' . $name . ']"]' )->length, $name );
			$this->assertSame( 0, $xpath->query( '//select[@name="sc_wpun_settings[' . $name . ']"]' )->length, $name );
			$this->assertSame( 1, $xpath->query( '//fieldset[@id="' . $id . '"]//input[@checked]' )->length, $name );
		}
		$this->assertSame( 1, $xpath->query( '//input[@type="radio"][@name="sc_wpun_settings[frequency]"][@value="daily"][@checked]' )->length );
	}

	/**
	 * The core setting is drawn on the General tab before the plugin and theme settings, with its own three choices.
	 */
	public function test_the_core_setting_is_drawn_first() {
		$this->save_settings( array( 'notify_core' => 'major' ) );
		$xpath = $this->page();

		$id = 'sc_wpun_settings_main_notify_core';
		$this->assertSame( 3, $xpath->query( '//fieldset[@id="' . $id . '"]//input[@type="radio"][@name="sc_wpun_settings[notify_core]"]' )->length );
		$this->assertSame( 1, $xpath->query( '//fieldset[@id="' . $id . '"]//input[@value="major"][@checked]' )->length );
		$this->assertSame( 0, $xpath->query( '//fieldset[@id="' . $id . '"]//input[@value="active"]' )->length );
		$this->assertSame( 1, $xpath->query( '//th[contains(., "Notify about WordPress updates?")]' )->length );
		$this->assertSame( 1, $xpath->query( '//tr[.//fieldset[@id="' . $id . '"]]/following-sibling::tr[.//fieldset[@id="sc_wpun_settings_main_notify_plugins"]]' )->length, 'Before the plugin setting.' );
		$this->assertSame( 0, $xpath->query( '//tr[.//fieldset[@id="sc_wpun_settings_main_notify_plugins"]]/following-sibling::tr[.//fieldset[@id="' . $id . '"]]' )->length );
	}

	/**
	 * More than five schedules make frequency a select again, labelled by its row.
	 */
	public function test_frequency_is_a_select_with_more_than_five_schedules() {
		$this->offer_more_frequencies();
		$this->save_settings( array( 'frequency' => 'two_days' ) );
		$xpath = $this->page();

		$this->assertSame( 7, $xpath->query( '//select[@id="sc_wpun_settings_main_frequency"][@name="sc_wpun_settings[frequency]"]/option' )->length );
		$this->assertSame( 1, $xpath->query( '//select[@id="sc_wpun_settings_main_frequency"]/option[@value="two_days"][@selected]' )->length );
		$this->assertSame( 1, $xpath->query( '//th/label[@for="sc_wpun_settings_main_frequency"]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@name="sc_wpun_settings[frequency]"]' )->length );
		$this->assertSame( 0, $xpath->query( '//th/label[@for="sc_wpun_settings_main_notify_plugins"]' )->length, 'Radios have a legend instead.' );
	}

	/**
	 * The on or off values are single checkboxes with descriptive text and a hidden 0 before them.
	 */
	public function test_on_off_values_are_single_checkboxes() {
		$this->save_settings( array( 'hide_updates' => true ) );
		$xpath = $this->page();

		$expected = array(
			'sc_wpun_settings_main_hide_updates' => array( 'hide_updates', true, 'Hide the WordPress update notice from users who cannot update WordPress' ),
		);
		foreach ( $expected as $id => list( $name, $on, $text ) ) {
			$box = $xpath->query( '//input[@type="checkbox"][@id="' . $id . '"][@name="sc_wpun_settings[' . $name . ']"]' );
			$this->assertSame( 1, $box->length, $name );
			$this->assertSame( $on, $box->item( 0 )->hasAttribute( 'checked' ), $name );
			$this->assertSame( 1, $xpath->query( '//input[@type="hidden"][@name="sc_wpun_settings[' . $name . ']"][@value="0"]/following-sibling::*[1][self::label][@for="' . $id . '"]' )->length, $name );
			$this->assertSame( 1, $xpath->query( '//input[@type="hidden"][@name="sc_wpun_settings[' . $name . ']"][@value="0"]' )->length, $name );
			$this->assertSame( ' ' . $text, $xpath->query( '//label[@for="' . $id . '"]' )->item( 0 )->textContent, $name ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$this->assertSame( 0, $xpath->query( '//select[@name="sc_wpun_settings[' . $name . ']"]' )->length, $name );
		}
		$this->assertSame( 0, $xpath->query( '//label[normalize-space(.)="Yes"][not(ancestor::fieldset)]' )->length, 'No checkbox is labelled Yes.' );
	}

	/**
	 * A saved schedule that is not offered any more is an extra, checked choice and the field stays radios.
	 */
	public function test_a_stale_frequency_is_an_extra_checked_choice() {
		$this->save_settings( array( 'frequency' => 'schedule_from_a_removed_plugin' ) );
		$xpath = $this->page();

		$radios = $xpath->query( '//fieldset[@id="sc_wpun_settings_main_frequency"]//input[@type="radio"]' );
		$this->assertSame( 5, $radios->length, 'Four schedules and the saved one.' );
		$this->assertSame( 'schedule_from_a_removed_plugin', $radios->item( 0 )->getAttribute( 'value' ) );
		$this->assertTrue( $radios->item( 0 )->hasAttribute( 'checked' ) );
		$this->assertSame( 1, $xpath->query( '//fieldset[@id="sc_wpun_settings_main_frequency"]//input[@checked]' )->length );
		$this->assertSame( 1, $xpath->query( '//label[contains(.,"schedule_from_a_removed_plugin (no longer available)")]' )->length );
		$this->assertSame( 1, $xpath->query( '//p[contains(@class,"sc-wpun-frequency-unavailable")][@id="sc_wpun_settings_main_frequency-unavailable"]' )->length );
		$this->assertSame( 'sc_wpun_settings_main_frequency-unavailable', $xpath->query( '//fieldset[@id="sc_wpun_settings_main_frequency"]' )->item( 0 )->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 0, $xpath->query( '//select[@name="sc_wpun_settings[frequency]"]' )->length );
	}

	/**
	 * A value supplied from outside the database disables its inputs, the hidden one included.
	 */
	public function test_external_values_disable_the_inputs() {
		$this->save_settings();
		$source = new Test_Config_Source(
			array(
				'SC_WPUN_SETTING_HIDE_UPDATES' => 'off',
				'SC_WPUN_SETTING_FREQUENCY'    => 'daily',
			)
		);
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $source ) {
				$sources[] = $source;
				return $sources;
			}
		);
		sc_wpun()->config()->flush();
		$xpath = $this->page();

		$this->assertSame( 2, $xpath->query( '//input[@name="sc_wpun_settings[hide_updates]"][@disabled]' )->length, 'Checkbox and hidden input.' );
		$this->assertSame( 0, $xpath->query( '//input[@name="sc_wpun_settings[hide_updates]"][@checked]' )->length );
		$this->assertSame( 4, $xpath->query( '//input[@name="sc_wpun_settings[frequency]"][@disabled]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@name="sc_wpun_settings[notify_plugins]"][@disabled]' )->length );

		$box = $xpath->query( '//input[@type="checkbox"][@name="sc_wpun_settings[hide_updates]"]' )->item( 0 );
		$this->assertSame( 'sc_wpun_settings_main_hide_updates-external', $box->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 1, $xpath->query( '//span[@id="sc_wpun_settings_main_hide_updates-external"][contains(@class,"sc-wpun-external")]' )->length );
		$fieldset = $xpath->query( '//fieldset[@id="sc_wpun_settings_main_frequency"]' )->item( 0 );
		$this->assertSame( 'sc_wpun_settings_main_frequency-external', $fieldset->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 1, $xpath->query( '//span[@id="sc_wpun_settings_main_frequency-external"]' )->length );
		$this->assertSame( '', $xpath->query( '//fieldset[@id="sc_wpun_settings_main_notify_plugins"]' )->item( 0 )->getAttribute( 'aria-describedby' ) );
	}

	/**
	 * No global setting is required, so no row of the General tab carries the marker.
	 */
	public function test_no_general_row_is_marked_required() {
		$this->save_settings();
		$xpath = $this->page();

		$this->assertSame( 0, $xpath->query( '//th[contains(.,"(required)")]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@aria-required]' )->length );
	}

	/**
	 * The removed Automatic updates setting has no row on the page.
	 */
	public function test_there_is_no_automatic_updates_row() {
		$this->save_settings();
		$xpath = $this->page();

		$this->assertSame( 0, $xpath->query( '//*[contains(@name,"notify_automatic") or contains(@id,"notify_automatic")]' )->length );
		$this->assertSame( 0, $xpath->query( '//th[contains(.,"Automatic updates")]' )->length );
	}

	/**
	 * The page checks the capability itself, not only through core's menu check (#233).
	 */
	public function test_the_page_refuses_users_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message, $title, $args ) {
					throw new WPDieException( 'status ' . absint( $args['response'] ) );
				};
			}
		);

		ob_start();
		try {
			sc_wpun()->settings_page()->settings_page();
			$this->fail( 'The page was drawn for an editor.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 'status 403', $e->getMessage() );
		} finally {
			$html = (string) ob_get_clean();
		}

		$this->assertStringNotContainsString( 'nav-tab', $html );
	}
}
