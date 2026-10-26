<?php
/**
 * Only http and https links reach the plain text of a notification (#325).
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Notification_Renderer;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * Markdown and HTML already refuse other schemes. Plain text does the same, so a mail client that turns bare
 * URLs into links never offers a `javascript:` one.
 *
 * @group security
 */
class Test_Hardening_Plain_Text_Links extends WPUN_Security_Test_Case {

	/**
	 * The plain text of a notification with one item and these links.
	 *
	 * @param array<string, string> $links Links by label.
	 * @return string
	 */
	private function plain( array $links ): string {
		$item = new Notification_Item(
			array(
				'type'            => 'plugin',
				'key'             => 'plugin/plugin.php',
				'name'            => 'Plugin',
				'current_version' => '1.0',
				'new_version'     => '2.0',
				'links'           => $links,
			)
		);

		return Notification_Renderer::plain_text( new Notification( 'My Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', array( $item ) ) );
	}

	/**
	 * Links with another scheme are dropped.
	 */
	public function test_links_that_are_not_http_are_dropped() {
		$text = $this->plain(
			array(
				'details'   => 'javascript:alert(1)',
				'changelog' => 'ftp://example.org/changelog',
				'homepage'  => " \tJaVaScRiPt:alert(1)",
				'data'      => 'data:text/html,<script>alert(1)</script>',
				'relative'  => '//evil.example/x',
				'spaced'    => 'https://ok.example/ javascript:alert(1)',
			)
		);

		$this->assertStringNotContainsString( ' javascript', strtolower( $text ) );
		$this->assertStringContainsString( ': https://ok.example/%20javascript:alert(1)', $text, 'A space cannot start a second address.' );
		$this->assertStringNotContainsString( 'details:', strtolower( $text ) );
		$this->assertStringNotContainsString( 'homepage:', strtolower( $text ) );
		$this->assertStringNotContainsString( 'ftp:', $text );
		$this->assertStringNotContainsString( 'data:', $text );
		$this->assertStringNotContainsString( 'evil.example', $text );
		$this->assertStringContainsString( 'Plugin from version 1.0 to 2.0', $text );
	}

	/**
	 * An http or https link is kept, with spaces encoded so it stays one address.
	 */
	public function test_an_https_link_is_kept() {
		$text = $this->plain(
			array(
				'details' => 'https://wordpress.org/plugins/plugin/?a=b c',
				'http'    => 'http://example.org/x',
				'upper'   => 'HTTPS://example.org/y',
			)
		);

		$this->assertStringContainsString( ': https://wordpress.org/plugins/plugin/?a=b%20c', $text );
		$this->assertStringContainsString( ': https://example.org/y', $text );
		$this->assertStringContainsString( ': http://example.org/x', $text );
	}
}
