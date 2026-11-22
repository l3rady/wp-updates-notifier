<?php
/**
 * The plain-text helpers of Notification_Renderer that channels with their own format use.
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification;
use WPUN\Notification_Renderer;

/**
 * Notification_Renderer::web_url(), item_link_text() and more_items_text().
 */
class Test_Renderer_Public_Helpers extends WPUN_Test_Case {

	/**
	 * A web address is kept, cleaned as esc_url_raw() does.
	 */
	public function test_web_url_keeps_an_http_or_https_address() {
		$this->assertSame( 'https://example.org/a?b=1&c=2', Notification_Renderer::web_url( 'https://example.org/a?b=1&c=2' ) );
		$this->assertSame( 'http://example.org/a', Notification_Renderer::web_url( '  http://example.org/a ' ) );
	}

	/**
	 * Anything that is not an explicit http or https address gives an empty string.
	 *
	 * @dataProvider data_unsafe_urls
	 *
	 * @param string $url The address.
	 */
	public function test_web_url_refuses_other_addresses( $url ) {
		$this->assertSame( '', Notification_Renderer::web_url( $url ) );
	}

	/**
	 * Addresses that are not web addresses.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_unsafe_urls() {
		return array(
			'javascript'      => array( 'javascript:alert(1)' ),
			'data'            => array( 'data:text/html;base64,AAAA' ),
			'scheme-relative' => array( '//example.org/a' ),
			'empty'           => array( '' ),
		);
	}

	/**
	 * The link text names the item; an unknown label is made readable.
	 */
	public function test_item_link_text() {
		$this->assertSame( 'Akismet details', Notification_Renderer::item_link_text( 'details', 'Akismet' ) );
		$this->assertSame( 'Akismet changelog', Notification_Renderer::item_link_text( 'changelog', 'Akismet' ) );
		$this->assertSame( 'Akismet download', Notification_Renderer::item_link_text( 'download', 'Akismet' ) );
		$this->assertSame( 'Mute Akismet in WordPress', Notification_Renderer::item_link_text( 'mute', 'Akismet' ) );
		$this->assertSame( 'Akismet Extra one', Notification_Renderer::item_link_text( 'extra_one', 'Akismet' ) );
	}

	/**
	 * The "more" line says how many, and the filter applies to it once with its three arguments.
	 */
	public function test_more_items_text_applies_the_filter() {
		$notification = new Notification( 'Site', 'https://example.org', 'https://example.org/u', array() );
		$this->assertSame( '…and 3 more', Notification_Renderer::more_items_text( 3, $notification ) );

		$calls = array();
		add_filter(
			'sc_wpun_more_items_text',
			function ( $text, $hidden, $seen ) use ( &$calls ) {
				$calls[] = array( $text, $hidden, $seen );

				return '(' . $hidden . ' not shown)';
			},
			10,
			3
		);

		$this->assertSame( '(2 not shown)', Notification_Renderer::more_items_text( 2, $notification ) );
		$this->assertSame( array( array( '…and 2 more', 2, $notification ) ), $calls );
	}
}
