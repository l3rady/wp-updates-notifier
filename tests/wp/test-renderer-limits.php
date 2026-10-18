<?php
/**
 * Length-limited rendering of a notification.
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Notification_Renderer;

/**
 * The max_length and max_items parameters of Notification_Renderer.
 */
class Test_Renderer_Limits extends WPUN_Test_Case {

	/**
	 * The formats the renderer has.
	 *
	 * @var string[]
	 */
	private $formats = array( 'plain_text', 'markdown', 'html' );

	/**
	 * A notification with items named "<prefix>1", "<prefix>2" and so on.
	 *
	 * @param int    $count  Number of items.
	 * @param string $prefix Start of every name.
	 * @return Notification
	 */
	private function notification( $count, $prefix = 'Plugin ' ) {
		$items = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$items[] = new Notification_Item(
				array(
					'type'            => 'plugin',
					'key'             => 'plugin-' . $i . '/plugin-' . $i . '.php',
					'name'            => $prefix . $i,
					'current_version' => '1.0',
					'new_version'     => '2.0',
				)
			);
		}

		return new Notification( 'My Site', 'https://example.org', 'https://example.org/wp-admin/update-core.php', $items );
	}

	/**
	 * Render in a format.
	 *
	 * @param string       $format       plain_text, markdown or html.
	 * @param Notification $notification The notification.
	 * @param int          $max_length   Length limit.
	 * @param int          $max_items    Item limit.
	 * @return string
	 */
	private function render( $format, Notification $notification, $max_length = 0, $max_items = 0 ) {
		return call_user_func( array( Notification_Renderer::class, $format ), $notification, $max_length, $max_items );
	}

	/**
	 * How many items a text lists.
	 *
	 * @param string $text The rendered text.
	 * @return int
	 */
	private function shown( $text ) {
		return substr_count( $text, '→' );
	}

	/**
	 * Limits that are not reached change nothing.
	 */
	public function test_under_the_limits_the_output_is_the_same() {
		foreach ( $this->formats as $format ) {
			$notification = $this->notification( 5 );
			$plain        = $this->render( $format, $notification );

			$this->assertSame( $plain, $this->render( $format, $notification, 10000, 50 ), $format );
			$this->assertSame( $plain, $this->render( $format, $notification, 0, 5 ), $format . ': exactly the item limit' );
			$this->assertSame( $plain, $this->render( $format, $notification, mb_strlen( $plain ), 0 ), $format . ': exactly the length limit' );
			$this->assertSame( $plain, $this->render( $format, $notification, -1, -1 ), $format . ': negative is no limit' );
			$this->assertStringNotContainsString( '…and', $plain, $format );
		}
	}

	/**
	 * Over the item limit only: the first items, a line with the rest, before the closing line.
	 */
	public function test_the_item_limit_summarises_the_rest() {
		foreach ( $this->formats as $format ) {
			$text = $this->render( $format, $this->notification( 10 ), 0, 3 );

			$this->assertSame( 3, $this->shown( $text ), $format );
			$this->assertStringContainsString( 'Plugin 3', $text, $format );
			$this->assertStringNotContainsString( 'Plugin 4', $text, $format );
			$this->assertStringContainsString( '…and 7 more', $text, $format );
		}

		$plain = $this->render( 'plain_text', $this->notification( 10 ), 0, 3 );
		$this->assertStringContainsString( "\n\n…and 7 more\n\nPlease visit ", $plain );

		$markdown = $this->render( 'markdown', $this->notification( 10 ), 0, 3 );
		$this->assertStringContainsString( "\n\n…and 7 more\n\nPlease visit ", $markdown );
		$this->assertStringNotContainsString( "\n…and 7 more", str_replace( "\n\n…and", '', $markdown ), 'Not directly under the list.' );

		$html = $this->render( 'html', $this->notification( 10 ), 0, 3 );
		$this->assertStringContainsString( "</ul>\n<p>…and 7 more</p>\n<p>Please visit ", $html );
	}

	/**
	 * Over the length limit only: the most items that fit, and the right count in the line.
	 */
	public function test_the_length_limit_keeps_the_most_items_that_fit() {
		foreach ( $this->formats as $format ) {
			$notification = $this->notification( 9 );
			$limit        = mb_strlen( $this->render( $format, $notification, 0, 4 ) );

			$text = $this->render( $format, $notification, $limit );

			$this->assertLessThanOrEqual( $limit, mb_strlen( $text ), $format );
			$this->assertSame( 4, $this->shown( $text ), $format );
			$this->assertStringContainsString( '…and 5 more', $text, $format );
			$this->assertGreaterThan( $limit, mb_strlen( $this->render( $format, $notification, 0, 5 ) ), $format . ': one more would not fit' );
		}
	}

	/**
	 * Every limit gives the largest count that fits, however long the line is compared with an item.
	 */
	public function test_every_length_limit_gives_the_largest_count_that_fits() {
		$notification = $this->notification( 12 );
		foreach ( $this->formats as $format ) {
			$full = mb_strlen( $this->render( $format, $notification ) );
			$base = mb_strlen( $this->render( $format, $this->notification( 0 ) ) );

			for ( $limit = $base; $limit <= $full; $limit += 7 ) {
				$text  = $this->render( $format, $notification, $limit );
				$shown = $this->shown( $text );

				if ( $shown > 0 || mb_strlen( $text ) <= $limit ) {
					$this->assertLessThanOrEqual( $limit, mb_strlen( $text ), "$format $limit" );
				}
				if ( $shown < 12 && $shown > 0 ) {
					$this->assertGreaterThan( $limit, mb_strlen( $this->render( $format, $notification, 0, $shown + 1 ) ), "$format $limit: one more would not fit" );
				}
			}
		}
	}

	/**
	 * A limit that not even zero items fit in still gives the header, the line and the closing line.
	 */
	public function test_a_tiny_limit_returns_the_message_without_items() {
		foreach ( $this->formats as $format ) {
			$text = $this->render( $format, $this->notification( 4 ), 1 );

			$this->assertNotSame( '', $text, $format );
			$this->assertSame( 0, $this->shown( $text ), $format );
			$this->assertStringContainsString( 'There are updates available for My Site', $text, $format );
			$this->assertStringContainsString( '…and 4 more', $text, $format );
			$this->assertStringContainsString( 'Please visit', $text, $format );
		}
	}

	/**
	 * No line when nothing is left out, even for a notification without items.
	 */
	public function test_no_line_when_nothing_is_left_out() {
		foreach ( $this->formats as $format ) {
			$empty = $this->notification( 0 );

			$this->assertStringNotContainsString( 'more', $this->render( $format, $empty, 10, 1 ), $format );
			$this->assertSame( $this->render( $format, $empty ), $this->render( $format, $empty, 10, 1 ), $format );
			$this->assertStringNotContainsString( '…and 0 more', $this->render( $format, $this->notification( 2 ), 0, 5 ), $format );
		}
	}

	/**
	 * Characters are counted, not bytes.
	 */
	public function test_length_is_counted_in_characters() {
		foreach ( $this->formats as $format ) {
			$notification = $this->notification( 9, 'Ünïcödé 插件 🚀 ' );
			$limit        = mb_strlen( $this->render( $format, $notification, 0, 4 ) );

			$text = $this->render( $format, $notification, $limit );

			$this->assertLessThanOrEqual( $limit, mb_strlen( $text ), $format );
			$this->assertGreaterThan( $limit, strlen( $text ), $format . ': more bytes than the limit, so bytes were not counted' );
			$this->assertSame( 4, $this->shown( $text ), $format );
			$this->assertStringContainsString( '…and 5 more', $text, $format );
		}
	}

	/**
	 * The line is made with _n(), with the number left out and the plugin's text domain.
	 */
	public function test_the_line_is_plural_aware() {
		$calls = array();
		add_filter(
			'ngettext',
			static function ( $translation, $single, $plural, $number, $domain ) use ( &$calls ) {
				if ( '…and %d more' === $single ) {
					$calls[] = array( $number, $domain );
				}
				return $translation;
			},
			10,
			5
		);

		$this->render( 'plain_text', $this->notification( 5 ), 0, 4 );
		$this->render( 'plain_text', $this->notification( 5 ), 0, 2 );

		$this->assertSame(
			array(
				array( 1, 'wp-updates-notifier' ),
				array( 3, 'wp-updates-notifier' ),
			),
			$calls
		);
	}

	/**
	 * Site code can change the line, and the result is escaped for the format.
	 */
	public function test_the_more_items_text_filter() {
		$args = array();
		add_filter(
			'sc_wpun_more_items_text',
			static function ( $text, $hidden, $notification ) use ( &$args ) {
				$args[] = array( $text, $hidden, count( $notification->get_items() ) );
				return '*<b>' . $hidden . ' hidden';
			},
			10,
			3
		);
		$notification = $this->notification( 6 );

		$this->assertStringContainsString( "\n\n*<b>4 hidden\n\nPlease visit", $this->render( 'plain_text', $notification, 0, 2 ) );
		$this->assertStringContainsString( "\n\n\\*\\<b\\>4 hidden\n\nPlease visit", $this->render( 'markdown', $notification, 0, 2 ) );
		$this->assertStringContainsString( '<p>*&lt;b&gt;4 hidden</p>', $this->render( 'html', $notification, 0, 2 ) );
		$this->assertSame( array( '…and 4 more', 4, 6 ), $args[0], 'Gets the default text, the count and the whole notification.' );
	}

	/**
	 * The message filter still applies, gets the number left out, and counts towards the length.
	 */
	public function test_the_message_text_filter_applies_and_is_measured() {
		$hidden = array();
		$suffix = "\n" . str_repeat( 'x', 100 );
		add_filter(
			'sc_wpun_message_text',
			static function ( $text, $notification, $left ) use ( &$hidden, $suffix ) {
				$hidden[] = array( $left, count( $notification->get_items() ) );
				return $text . $suffix;
			},
			10,
			3
		);
		$notification = $this->notification( 9 );

		$whole = Notification_Renderer::plain_text( $notification );
		$this->assertStringEndsWith( $suffix, $whole );
		$this->assertSame( array( array( 0, 9 ) ), $hidden );

		$limit  = mb_strlen( $whole );
		$hidden = array();
		$text   = Notification_Renderer::plain_text( $notification, $limit - 1 );

		$this->assertStringEndsWith( $suffix, $text );
		$this->assertLessThanOrEqual( $limit - 1, mb_strlen( $text ) );
		$this->assertSame( 8, $this->shown( $text ), 'Measured after the filter: the suffix is part of the length.' );
		$this->assertContains( array( 1, 8 ), $hidden );

		remove_all_filters( 'sc_wpun_message_text' );
		$without = Notification_Renderer::plain_text( $notification, $limit - 1 );
		$this->assertSame( 9, $this->shown( $without ), 'Without the suffix everything fits in that length.' );
	}
}
