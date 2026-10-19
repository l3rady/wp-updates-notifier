<?php
/**
 * Base class of the security test group (#220): hostile payloads and markup checks.
 *
 * @package wp-updates-notifier
 */

/**
 * Helpers shared by the tests in tests/wp/security.
 */
abstract class WPUN_Security_Test_Case extends WPUN_Test_Case {

	/**
	 * Present in every payload, so a test can tell the payload from the page's own markup.
	 */
	const MARKER = 'wpun_xss';

	/**
	 * Hostile values for names, versions, URLs, labels and settings: markup, attribute breakouts, script URLs,
	 * entities and a right-to-left override. Single lines, as plugin headers need.
	 *
	 * @return array<string, string>
	 */
	protected static function payloads(): array {
		return array(
			'script'        => '<script>alert("wpun_xss")</script>',
			'img'           => '"><img src=x onerror=alert(\'wpun_xss\')>',
			'svg'           => '<svg/onload=alert(\'wpun_xss\')>',
			'attribute'     => '" autofocus onfocus="alert(\'wpun_xss\')" x="',
			'single_quote'  => '\' onmouseover=\'alert("wpun_xss")\' x=\'',
			'javascript'    => 'javascript:alert(\'wpun_xss\')',
			'javascript_ws' => " \tJaVa\tScRiPt:alert('wpun_xss')",
			'data_url'      => 'data:text/html,<script>alert("wpun_xss")</script>',
			'entities'      => '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &#60;b&#62; wpun_xss',
			'rtl_override'  => "Plugin \u{202E}gpj.exe wpun_xss",
		);
	}

	/**
	 * Payloads as data sets.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function payloads_provider() {
		$sets = array();
		foreach ( self::payloads() as $name => $payload ) {
			$sets[ $name ] = array( $payload );
		}

		return $sets;
	}

	/**
	 * Parse markup.
	 *
	 * @param string $html Markup.
	 * @return DOMXPath
	 */
	protected function parse( string $html ): DOMXPath {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return new DOMXPath( $doc );
	}

	/**
	 * Assert that no payload became live markup: no script, image, SVG or frame element that carries the marker,
	 * no event handler attribute, and no link or source that runs script.
	 *
	 * @param string $html    Markup, a page or a mail.
	 * @param string $context What the markup is, for the failure message.
	 */
	protected function assert_no_live_markup( string $html, string $context ): void {
		$xpath = $this->parse( $html );

		foreach ( $xpath->query( '//*' ) as $element ) {
			$name = strtolower( $element->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			if ( in_array( $name, array( 'script', 'img', 'svg', 'iframe', 'object', 'embed', 'style', 'link' ), true ) ) {
				$source = $element->ownerDocument->saveHTML( $element ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
				$this->assertStringNotContainsString( self::MARKER, (string) $source, "$context: a <$name> element carries a payload." );
				$this->assertNotSame( 'img', $name, "$context: an <img> element was created from a payload." );
			}

			foreach ( $element->attributes as $attribute ) {
				$attr  = strtolower( $attribute->name );
				$value = (string) $attribute->value;
				$this->assertStringStartsNotWith( 'on', $attr, "$context: event handler attribute $attr on <$name>." );
				if ( in_array( $attr, array( 'href', 'src', 'action', 'formaction', 'xlink:href', 'data' ), true ) ) {
					$clean = strtolower( preg_replace( '/[\x00-\x20\x7f]+/', '', $value ) );
					$this->assertDoesNotMatchRegularExpression( '/^(javascript|data|vbscript):/', $clean, "$context: unsafe $attr on <$name>." );
				}
			}
		}

		// The payload's own characters never reach the markup as markup.
		$this->assertStringNotContainsString( '<script>alert("wpun_xss")', $html, "$context: raw script payload." );
		$this->assertStringNotContainsString( '<img src=x onerror', $html, "$context: raw image payload." );
		$this->assertStringNotContainsString( '<svg/onload', $html, "$context: raw SVG payload." );
		$this->assertStringNotContainsString( '"><img', $html, "$context: attribute breakout." );
		$this->assertStringNotContainsString( 'href="javascript', strtolower( $html ), "$context: javascript: link." );
		$this->assertStringNotContainsString( 'href="data:', strtolower( $html ), "$context: data: link." );
	}

	/**
	 * The output of a callback.
	 *
	 * @param callable $render Function that prints.
	 * @return string
	 */
	protected function capture( callable $render ): string {
		ob_start();
		$render();

		return (string) ob_get_clean();
	}

	/**
	 * A plain administrator who is not a super admin, with the user set as the current user.
	 *
	 * @return int User id.
	 */
	protected function become_administrator(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );

		return $id;
	}
}
