<?php
/**
 * The markup of the settings screen (#72, #163).
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties are camelCase.

use WPUN\Channel_Instance;

/**
 * Valid HTML, accessible names, escaping.
 */
class Test_Settings_Markup extends WPUN_Test_Case {

	/**
	 * The parser errors of the last page.
	 *
	 * @var LibXMLError[]
	 */
	private $errors = array();

	/**
	 * The General tab as an administrator, parsed as a whole page.
	 *
	 * @return DOMXPath
	 */
	private function page(): DOMXPath {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		$html = (string) ob_get_clean();

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		libxml_clear_errors();
		$doc->loadHTML( '<!DOCTYPE html><html><head><title>t</title></head><body>' . $html . '</body></html>' );
		$this->errors = libxml_get_errors();
		libxml_clear_errors();

		return new DOMXPath( $doc );
	}

	/**
	 * The whole page parses without errors (libxml does not know HTML5 elements such as nav).
	 */
	public function test_the_page_is_valid_html() {
		$this->page();

		$errors = array_filter(
			$this->errors,
			static function ( $error ) {
				return 801 !== $error->code;
			}
		);
		$this->assertSame( array(), array_map( static fn( $error ) => trim( $error->message ), array_values( $errors ) ) );
	}

	/**
	 * Ids are unique.
	 */
	public function test_ids_are_unique() {
		$xpath = $this->page();

		$ids = array();
		foreach ( $xpath->query( '//*[@id]' ) as $node ) {
			$ids[] = $node->getAttribute( 'id' );
		}
		$this->assertSame( array(), array_keys( array_filter( array_count_values( $ids ), static fn( $count ) => $count > 1 ) ) );
	}

	/**
	 * Every control has an accessible name, and radios and checkboxes sit in a fieldset with a legend.
	 */
	public function test_every_control_is_named() {
		$xpath = $this->page();

		$controls = $xpath->query( '//input[not(@type="hidden" or @type="submit")] | //select | //textarea' );
		$this->assertGreaterThan( 5, $controls->length );
		foreach ( $controls as $control ) {
			$desc = $control->getAttribute( 'name' ) . ' ' . $control->getAttribute( 'id' );
			$type = $control->getAttribute( 'type' );
			if ( 'radio' === $type || 'checkbox' === $type ) {
				// The option's own label.
				$labelled = $xpath->query( 'ancestor::label', $control )->length > 0 || ( '' !== $control->getAttribute( 'id' ) && $xpath->query( '//label[@for="' . $control->getAttribute( 'id' ) . '"]' )->length > 0 );
				$this->assertTrue( $labelled, "Option $desc has a label." );
				if ( 'radio' === $type ) {
					$legend = $xpath->query( 'ancestor::fieldset/legend', $control );
					$this->assertGreaterThan( 0, $legend->length, "Radio $desc is in a fieldset with a legend." );
					$this->assertNotSame( '', trim( $legend->item( 0 )->textContent ), "Legend of $desc." );
				}
				continue;
			}
			$id       = $control->getAttribute( 'id' );
			$labelled = $xpath->query( 'ancestor::label', $control )->length > 0
				|| ( '' !== $id && $xpath->query( '//label[@for="' . $id . '"]' )->length > 0 )
				|| '' !== $control->getAttribute( 'aria-label' );
			$this->assertTrue( $labelled, "Control $desc has an accessible name." );
		}
	}

	/**
	 * The email text inputs are text inputs labelled by their row.
	 */
	public function test_email_inputs_are_labelled() {
		$xpath = $this->page();

		foreach ( array( 'notify_to', 'notify_from', 'notify_reply_to' ) as $name ) {
			$id = 'sc_wpun_settings_email_notifications_' . $name;
			$this->assertSame( 1, $xpath->query( '//input[@type="text"][@id="' . $id . '"]' )->length, $name );
			$this->assertSame( 1, $xpath->query( '//label[@for="' . $id . '"]' )->length, $name );
		}
	}

	/**
	 * Values with quotes and angle brackets come out as the same string once parsed.
	 */
	public function test_values_are_escaped() {
		$raw = '"><b>x</b> a&b';
		sc_wpun()->channel_store()->save( ( new Channel_Instance( 'email', 'email', 'Email', false ) )->with_settings( array( 'to' => $raw, 'from' => $raw ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$xpath = $this->page();

		foreach ( array( 'notify_to', 'notify_from' ) as $name ) {
			$input = $xpath->query( '//input[@id="sc_wpun_settings_email_notifications_' . $name . '"]' );
			$this->assertSame( 1, $input->length, $name );
			$this->assertSame( $raw, $input->item( 0 )->getAttribute( 'value' ), $name );
		}
		$this->assertSame( 0, $xpath->query( '//b' )->length );
	}

	/**
	 * Buttons are real buttons, with unique ids, and there are no spacer paragraphs.
	 */
	public function test_buttons_and_no_spacers() {
		$xpath = $this->page();

		$save = $xpath->query( '//form[contains(@action,"options.php")]//input[@type="submit"]' );
		$this->assertSame( 1, $save->length );
		$this->assertSame( 'Save settings', $save->item( 0 )->getAttribute( 'value' ) );
		$this->assertStringContainsString( 'button-primary', $save->item( 0 )->getAttribute( 'class' ) );

		$test = $xpath->query( '//form[contains(@action,"admin-post.php")]//input[@type="submit"]' );
		$this->assertSame( 1, $test->length );
		$this->assertSame( 'Send test email', $test->item( 0 )->getAttribute( 'value' ) );
		$this->assertNotSame( $save->item( 0 )->getAttribute( 'id' ), $test->item( 0 )->getAttribute( 'id' ) );
		$this->assertSame( 1, $xpath->query( '//form[contains(@action,"admin-post.php")]//*[contains(@class,"description")]' )->length );

		foreach ( $xpath->query( '//p' ) as $paragraph ) {
			$empty = '' === trim( str_replace( "\xC2\xA0", ' ', $paragraph->textContent ) ) && 0 === $paragraph->getElementsByTagName( '*' )->length;
			$this->assertFalse( $empty, 'No empty or &nbsp; spacer paragraphs.' );
		}
	}

	/**
	 * The test form has its own nonce field, so `_wpnonce` is not repeated.
	 */
	public function test_the_test_form_has_its_own_nonce() {
		$xpath = $this->page();

		$this->assertSame( 1, $xpath->query( '//input[@name="_wpnonce"]' )->length );
		$this->assertSame( 1, $xpath->query( '//form[contains(@action,"admin-post.php")]//input[@name="sc_wpun_test_nonce"]' )->length );
	}

	/**
	 * The settings link on the plugins screen points at the settings page and is escaped.
	 */
	public function test_the_plugin_action_link() {
		$links = sc_wpun()->settings_page()->plugin_action_links( array( 'other' ) );
		$this->assertCount( 2, $links );
		$this->assertSame( 'other', $links[1] );

		$doc = new DOMDocument();
		$doc->loadHTML( '<html><body>' . $links[0] . '</body></html>' );
		$link = $doc->getElementsByTagName( 'a' )->item( 0 );
		$this->assertSame( admin_url( 'options-general.php?page=wp-updates-notifier' ), $link->getAttribute( 'href' ) );
		$this->assertSame( 'Settings', $link->textContent );

		// Whatever the address holds cannot break out of the attribute.
		add_filter(
			'admin_url',
			static function ( $url ) {
				return $url . '&x="><b>';
			}
		);
		$html = sc_wpun()->settings_page()->plugin_action_links( array() )[0];
		$this->assertStringNotContainsString( '"><b>', $html );
	}

	/**
	 * The facade call passes the file: another plugin's links are left alone.
	 */
	public function test_links_of_another_plugin_are_unchanged() {
		$this->assertSame( array( 'x' ), sc_wpun()->settings_page()->plugin_action_links( array( 'x' ), 'other/other.php' ) );
		$this->assertCount( 2, sc_wpun()->settings_page()->plugin_action_links( array( 'x' ), plugin_basename( SC_WPUN_FILE ) ) );
	}
}
