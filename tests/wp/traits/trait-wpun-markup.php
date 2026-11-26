<?php
/**
 * Markup helpers: parse, capture and assert on HTML.
 *
 * @package wp-updates-notifier
 */

/**
 * Parsing and checking markup, with failure messages that stay short.
 */
trait WPUN_Markup {

	/**
	 * The longest piece of markup a failure message shows.
	 *
	 * @var int
	 */
	private static $markup_excerpt_length = 300;

	/**
	 * Parse markup for XPath queries. Invalid markup raises no warning.
	 *
	 * @param string $html Markup.
	 * @return DOMXPath
	 */
	protected function dom( string $html ): DOMXPath {
		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $doc );
	}

	/**
	 * The output of a callback. The output buffer is closed even when the callback throws.
	 *
	 * @param callable $callback Function that prints.
	 * @return string
	 * @throws \Throwable What the callback throws.
	 */
	protected function render( callable $callback ): string {
		$level = ob_get_level();
		ob_start();
		try {
			$callback();
			$output = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			throw $e;
		}

		return $output;
	}

	/**
	 * Assert that markup has a node (XPath needle) or a piece of text (any other needle).
	 *
	 * An XPath needle starts with `/`, `(` or `./`. A failure shows the message, the needle and the size of the
	 * page, never the page.
	 *
	 * @param string $needle  XPath or text.
	 * @param string $html    Markup.
	 * @param string $message Message.
	 */
	protected function assertMarkupHas( string $needle, string $html, string $message = '' ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion naming.
		$found = $this->markup_find( $needle, $html );

		$this->assertTrue(
			null !== $found,
			$this->markup_message( $message, 'The markup does not have ' . $needle, $html )
		);
	}

	/**
	 * Assert that markup has no node (XPath needle) and not a piece of text (any other needle).
	 *
	 * See assertMarkupHas(). A failure also shows the first match, cut short.
	 *
	 * @param string $needle  XPath or text.
	 * @param string $html    Markup.
	 * @param string $message Message.
	 */
	protected function assertMarkupLacks( string $needle, string $html, string $message = '' ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion naming.
		$found = $this->markup_find( $needle, $html );

		$this->assertTrue(
			null === $found,
			$this->markup_message( $message, 'The markup has ' . $needle . ', first match: ' . (string) $found, $html )
		);
	}

	/**
	 * The first match of a needle in markup.
	 *
	 * @param string $needle XPath or text.
	 * @param string $html   Markup.
	 * @return string|null A short single-line excerpt of the match, or null for none.
	 */
	private function markup_find( string $needle, string $html ): ?string {
		if ( 1 === preg_match( '#^(/|\(|\./)#', $needle ) ) {
			$nodes = $this->dom( $html )->query( $needle );
			if ( ! $nodes instanceof DOMNodeList || 0 === $nodes->length ) {
				return null;
			}
			$node = $nodes->item( 0 );

			return $this->markup_excerpt( (string) $node->ownerDocument->saveHTML( $node ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
		}

		$position = '' === $needle ? false : strpos( $html, $needle );
		if ( false === $position ) {
			return null;
		}

		return $this->markup_excerpt( substr( $html, max( 0, $position - 60 ), self::$markup_excerpt_length * 2 ) );
	}

	/**
	 * Cut markup to the excerpt length, on one line.
	 *
	 * @param string $markup Markup.
	 * @return string
	 */
	private function markup_excerpt( string $markup ): string {
		$line = trim( (string) preg_replace( '/\s+/', ' ', $markup ) );

		return mb_strlen( $line ) > self::$markup_excerpt_length ? mb_substr( $line, 0, self::$markup_excerpt_length ) . '...' : $line;
	}

	/**
	 * A failure message: the caller's message, what failed and the size of the page.
	 *
	 * @param string $message What the caller said.
	 * @param string $what    What failed.
	 * @param string $html    Markup.
	 * @return string
	 */
	private function markup_message( string $message, string $what, string $html ): string {
		return ( '' === $message ? '' : $message . "\n" ) . $what . ' (page: ' . strlen( $html ) . ' bytes)';
	}
}
