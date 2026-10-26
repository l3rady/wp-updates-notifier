<?php
/**
 * A Markdown link target is the cleaned URL, not the raw one (HN1 of the 2.0.0 security audit).
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Notification_Renderer;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * The target of a Markdown link is built from the URL as esc_url_raw() cleans it, so characters that could end
 * the target or the link early are gone before the remaining ones are escaped.
 *
 * @group security
 */
class Test_Hardening_Markdown_Links extends WPUN_Security_Test_Case {

	/**
	 * The Markdown of a notification with one item and this link.
	 *
	 * @param string $url Link.
	 * @return string
	 */
	private function markdown( string $url ): string {
		$item = new Notification_Item(
			array(
				'type'            => 'plugin',
				'key'             => 'plugin/plugin.php',
				'name'            => 'Plugin',
				'current_version' => '1.0',
				'new_version'     => '2.0',
				'links'           => array( 'details' => $url ),
			)
		);

		return Notification_Renderer::markdown( new Notification( 'My Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', array( $item ) ) );
	}

	/**
	 * The target of the link to the item, or an empty string when the item has no link.
	 *
	 * @param string $markdown Markdown.
	 * @return string
	 */
	private function target( string $markdown ): string {
		return 1 === preg_match( '/^  - \[[^\n]*\]\((.*)\)$/m', $markdown, $match ) ? $match[1] : '';
	}

	/**
	 * Characters that are not valid in a URL are removed from the target.
	 */
	public function test_characters_that_break_out_of_the_target_are_removed() {
		foreach ( array(
			'backslash' => 'https://example.org/a\\b',
			'quote'     => 'https://example.org/a"b',
			'backtick'  => 'https://example.org/a`b',
		) as $case => $url ) {
			$target = $this->target( $this->markdown( $url ) );
			$this->assertSame( 'https://example.org/ab', $target, $case );
		}
	}

	/**
	 * Parentheses, spaces and angle brackets are percent-encoded, so the target ends where it should.
	 */
	public function test_parentheses_are_encoded() {
		$target = $this->target( $this->markdown( 'https://example.org/a(b)c d' ) );

		$this->assertSame( 'https://example.org/a%28b%29c%20d', $target );
	}

	/**
	 * Another scheme gives plain text, with no link.
	 */
	public function test_a_scheme_that_is_not_http_gives_no_link() {
		foreach ( array( 'javascript:alert(1)', 'ftp://example.org/x', 'data:text/html,x' ) as $url ) {
			$markdown = $this->markdown( $url );

			$this->assertSame( '', $this->target( $markdown ), $url );
			$this->assertStringNotContainsString( '](', explode( "\n", $markdown )[2] . explode( "\n", $markdown )[3], $url );
		}
	}
}
