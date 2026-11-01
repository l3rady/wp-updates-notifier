<?php
/**
 * Only http and https links get an anchor in the HTML of a notification.
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Notification_Renderer;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * Markdown and plain text already refuse other schemes. HTML does the same: the label is shown as text, with no anchor.
 *
 * @group security
 */
class Test_Hardening_Html_Links extends WPUN_Security_Test_Case {

	/**
	 * The HTML of a notification with one item and these links.
	 *
	 * @param array<string, string> $links Links by label.
	 * @return string
	 */
	private function html( array $links ): string {
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

		return Notification_Renderer::html( new Notification( 'My Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', array( $item ) ) );
	}

	/**
	 * Links with another scheme, or none, are shown as text without an anchor.
	 */
	public function test_links_that_are_not_http_have_no_anchor() {
		$html = $this->html(
			array(
				'details'   => 'javascript:alert(1)',
				'changelog' => 'ftp://example.org/changelog',
				'download'  => 'mailto:someone@example.org',
				'homepage'  => '/tmp/x.zip',
				'data'      => 'data:text/html,<script>alert(1)</script>',
			)
		);

		$this->assertStringContainsString( '<li>Plugin details</li>', $html );
		$this->assertStringContainsString( '<li>Plugin changelog</li>', $html );
		$this->assertStringContainsString( '<li>Plugin download</li>', $html );
		$this->assertStringContainsString( '<li>Plugin Homepage</li>', $html );
		$this->assertStringNotContainsString( 'ftp:', $html );
		$this->assertStringNotContainsString( 'mailto:', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'x.zip', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertSame( 1, substr_count( $html, '<a ' ), 'Only the link to the Updates screen.' );
	}

	/**
	 * An http or https link keeps its anchor, escaped for an attribute.
	 */
	public function test_an_http_link_keeps_its_anchor() {
		$html = $this->html(
			array(
				'details' => 'https://wordpress.org/plugins/plugin/?a=b&c=d',
				'upper'   => 'HTTP://example.org/y',
			)
		);

		$this->assertStringContainsString( '<li><a href="https://wordpress.org/plugins/plugin/?a=b&#038;c=d">Plugin details</a></li>', $html );
		$this->assertStringContainsString( '<a href="http://example.org/y">Plugin Upper</a>', $html );
	}
}
