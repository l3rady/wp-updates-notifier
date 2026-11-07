<?php
/**
 * The Slack message: Block Kit layout and Slack text escaping.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Modules\Slack;

use WPUN\Item_Types;
use WPUN\Notification;
use WPUN\Notification_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Turns a Notification into the message Slack shows: Block Kit blocks plus a plain-text fallback.
 *
 * This is the bundled layout, and the only one for now. It is a class of its own, with no knowledge of HTTP or settings,
 * so that another layout can replace it without touching the channel.
 *
 * The message is this array (the channel adds `channel`, `username` and the icon, see Slack_Channel):
 *
 *     array(
 *         'text'         => string, // Plain-text fallback, shown in phone notifications. Up to 3000 characters.
 *         'blocks'       => array,  // Up to 50 blocks, see below.
 *         'unfurl_links' => false,
 *         'unfurl_media' => false,
 *     )
 *
 * The blocks, in order:
 *
 * 1. `header`: the title (up to 150 characters).
 * 2. One or more `section` blocks per item type (WordPress, Plugins, Themes and any type an add-on registered) in the
 *    order of the types. Each starts with the type in bold, and has one entry per item: "Akismet from version 5.6 to
 *    5.7.2", with the item's links and notes on the lines below. No section text is longer than 3000 characters; a type
 *    with many items continues in the next section. A test message has one section with a sentence instead.
 * 3. When items were left out, a `section` with the line "…and N more" and a link to the updates screen.
 * 4. A `context` block with a link to the updates screen and one to the site.
 *
 * Slack accepts 50 blocks in a message, and at most MAX_ITEMS items are listed, so a long list is summarised: as many
 * items as fit are listed and the "…and N more" line says how many were left out.
 *
 * Slack escaping: text is mrkdwn, in which `&`, `<` and `>` are control characters. They are replaced by `&amp;`, `&lt;`
 * and `&gt;`, and nothing else is: a backslash is not an escape character in Slack. Links are written `<url|label>`.
 *
 * @since 3.0.0
 *
 * @internal
 */
final class Slack_Message {

	/**
	 * Most blocks Slack accepts in a message.
	 */
	const MAX_BLOCKS = 50;

	/**
	 * Longest text of a section block, in characters.
	 */
	const MAX_SECTION = 3000;

	/**
	 * Longest text of a header block, in characters.
	 */
	const MAX_HEADER = 150;

	/**
	 * Longest fallback text, in characters.
	 */
	const MAX_FALLBACK = 3000;

	/**
	 * Most items listed in one message. The rest is summarised as "…and N more".
	 */
	const MAX_ITEMS = 50;

	/**
	 * Longest entry for one item, in characters. Links and notes that would make it longer are left out.
	 */
	const MAX_ENTRY = 2000;

	/**
	 * Longest name of an item before it is cut, in characters.
	 */
	const MAX_NAME = 100;

	/**
	 * Longest version before it is cut, in characters.
	 */
	const MAX_VERSION = 40;

	/**
	 * Longest note of an item before it is cut, in characters.
	 */
	const MAX_NOTE = 300;

	/**
	 * Longest web address in a link, in characters. A link with a longer one is written as its text only.
	 */
	const MAX_URL = 1000;

	/**
	 * Build the message.
	 *
	 * @param Notification $notification What to deliver.
	 * @return array<string, mixed>
	 */
	public static function build( Notification $notification ): array {
		$site   = self::single_line( wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES ) );
		$title  = self::title( $notification, $site );
		$blocks = array(
			array(
				'type' => 'header',
				'text' => array(
					'type'  => 'plain_text',
					'text'  => self::escape_to_fit( $title, self::MAX_HEADER ),
					'emoji' => false,
				),
			),
		);

		$hidden = 0;
		if ( $notification->is_test() ) {
			$blocks[] = self::section(
				sprintf(
					// translators: 1: Site name. 2: Site address.
					__( 'This is a test message from WP Updates Notifier for %1$s (%2$s). Update notifications for this site will be posted here.', 'wp-updates-notifier' ),
					self::escape( $site ),
					self::escape( $notification->get_site_url() )
				)
			);
		} else {
			$layout = self::layout_items( $notification );
			$hidden = $layout['hidden'];
			foreach ( $layout['sections'] as $text ) {
				$blocks[] = self::section( $text );
			}
		}

		$updates = self::link( $notification->get_updates_url(), __( 'Open the Updates screen', 'wp-updates-notifier' ) );

		if ( $hidden > 0 ) {
			$blocks[] = self::section( self::escape_to_fit( self::more_text( $hidden, $notification ), 500 ) . "\n" . $updates );
		}

		// A test message has nothing to update, so it links only to the site.
		$site_link = self::link( $notification->get_site_url(), self::cut( $site, self::MAX_NAME ) );
		$blocks[]  = array(
			'type'     => 'context',
			'elements' => array(
				array(
					'type' => 'mrkdwn',
					'text' => $notification->is_test() ? $site_link : $updates . '  |  ' . $site_link,
				),
			),
		);

		return array(
			'text'         => self::fallback( $notification, $title ),
			'blocks'       => $blocks,
			'unfurl_links' => false,
			'unfurl_media' => false,
		);
	}

	/**
	 * Replace the characters Slack treats as control characters in mrkdwn and plain text: `&`, `<` and `>`.
	 *
	 * The ampersand goes first, so the entities that are added are not escaped again. Nothing else is touched: a
	 * backslash is not an escape character in Slack.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function escape( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}

	/**
	 * A Slack link, `<url|label>`, or just the label when the address is not an http or https one.
	 *
	 * In the address `|`, `<`, `>` and spaces are encoded and `&` is escaped, so that no address can end the link early.
	 *
	 * @param string $url   Address.
	 * @param string $label Text of the link, not escaped.
	 * @return string
	 */
	public static function link( string $url, string $label ): string {
		$url    = trim( $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$safe   = in_array( $scheme, array( 'http', 'https' ), true ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
		$text   = self::escape( '' === trim( $label ) ? $url : $label );
		if ( '' === $safe || mb_strlen( $safe ) > self::MAX_URL ) {
			return $text;
		}

		return '<' . str_replace( array( '&', '|', '<', '>', ' ' ), array( '&amp;', '%7C', '%3C', '%3E', '%20' ), $safe ) . '|' . $text . '>';
	}

	/**
	 * Escape text and cut it with an ellipsis so that the escaped text is at most $max characters.
	 *
	 * The text is cut before it is escaped, so an entity such as `&amp;` is never cut in two.
	 *
	 * @param string $text Text, not escaped.
	 * @param int    $max  Most characters of the result.
	 * @return string
	 */
	public static function escape_to_fit( string $text, int $max ): string {
		$escaped = self::escape( $text );
		if ( mb_strlen( $escaped ) <= $max ) {
			return $escaped;
		}

		// Start where the proportion says it will fit, then shorten until it does.
		$length = max( 0, (int) floor( mb_strlen( $text ) * ( $max - 1 ) / mb_strlen( $escaped ) ) );
		while ( $length > 0 ) {
			$candidate = self::escape( rtrim( mb_substr( $text, 0, $length ) ) . '…' );
			if ( mb_strlen( $candidate ) <= $max ) {
				return $candidate;
			}
			--$length;
		}

		return '…';
	}

	/**
	 * The title: the header, and the start of the fallback text.
	 *
	 * @param Notification $notification What to deliver.
	 * @param string       $site         Site name.
	 * @return string Not escaped.
	 */
	private static function title( Notification $notification, string $site ): string {
		if ( $notification->is_test() ) {
			return __( 'Test message from WP Updates Notifier', 'wp-updates-notifier' );
		}
		if ( $notification->is_reminder() ) {
			// translators: %s: Site name.
			return sprintf( __( 'Reminder: these updates are still waiting for %s', 'wp-updates-notifier' ), $site );
		}

		// translators: %s: Site name.
		return sprintf( __( 'There are updates available for %s', 'wp-updates-notifier' ), $site );
	}

	/**
	 * The plain text Slack shows where it cannot show blocks, such as a phone notification.
	 *
	 * The title and the names of the items with their new versions, cut to the limit.
	 *
	 * @param Notification $notification What to deliver.
	 * @param string       $title        The title.
	 * @return string Escaped.
	 */
	private static function fallback( Notification $notification, string $title ): string {
		$names = array();
		foreach ( $notification->get_items() as $item ) {
			$names[] = trim( $item->get_name() . ' ' . $item->get_new_version() );
		}

		return self::escape_to_fit( array() === $names ? $title : $title . ': ' . implode( ', ', $names ), self::MAX_FALLBACK );
	}

	/**
	 * A section block with mrkdwn text.
	 *
	 * @param string $text Escaped text.
	 * @return array<string, mixed>
	 */
	private static function section( string $text ): array {
		return array(
			'type' => 'section',
			'text' => array(
				'type' => 'mrkdwn',
				'text' => $text,
			),
		);
	}

	/**
	 * The "…and N more" line, which the `sc_wpun_more_items_text` filter can change.
	 *
	 * @param int          $hidden       How many items were left out.
	 * @param Notification $notification The notification, with all its items.
	 * @return string Not escaped.
	 */
	private static function more_text( int $hidden, Notification $notification ): string {
		// translators: %d: Number of updates that are not listed in the message.
		$line = sprintf( _n( '…and %d more', '…and %d more', $hidden, 'wp-updates-notifier' ), $hidden );

		/** This filter is documented in includes/class-notification-renderer.php */
		return (string) apply_filters( 'sc_wpun_more_items_text', $line, $hidden, $notification );
	}

	/**
	 * Lay the items out as section texts, as many as fit in the limits.
	 *
	 * @param Notification $notification What to deliver.
	 * @return array{sections: string[], hidden: int} The texts of the section blocks, and how many items were left out.
	 */
	private static function layout_items( Notification $notification ): array {
		$labels = Item_Types::all();
		$by     = array();
		foreach ( $notification->get_items() as $item ) {
			$by[ $item->get_type() ][] = $item;
		}

		$total = count( $notification->get_items() );
		$limit = min( $total, self::MAX_ITEMS );
		$left  = $limit;
		$types = array();

		// The registered types in their order, then any other type an item has.
		foreach ( array_unique( array_merge( array_keys( $labels ), array_keys( $by ) ) ) as $type ) {
			if ( $left <= 0 ) {
				break;
			}
			if ( empty( $by[ $type ] ) ) {
				continue;
			}

			$items   = array_slice( $by[ $type ], 0, $left );
			$label   = $labels[ $type ] ?? ucfirst( str_replace( '_', ' ', (string) $type ) );
			$types[] = array(
				'heading' => '*' . self::escape_to_fit( $label, 100 ) . '*',
				'entries' => array_map(
					static fn( Notification_Item $item ): string => self::entry( $item ),
					$items
				),
			);
			$left   -= count( $items );
		}

		// The header and the closing block are always there, and the "more" section is when something is left out.
		$budget = self::MAX_BLOCKS - 2;
		$result = self::pack( $types, $limit, $budget );
		if ( $result['listed'] < $total ) {
			$result = self::pack( $types, $limit, $budget - 1 );
		}

		return array(
			'sections' => $result['sections'],
			'hidden'   => $total - $result['listed'],
		);
	}

	/**
	 * Fill section texts with entries in order: a new section for each type, and when the limit of a section is reached.
	 *
	 * @param array<int, array{heading: string, entries: string[]}> $types  The types in order, each with its entries.
	 * @param int                                                   $limit  Most entries.
	 * @param int                                                   $budget Most sections.
	 * @return array{sections: string[], listed: int}
	 */
	private static function pack( array $types, int $limit, int $budget ): array {
		$sections = array();
		$listed   = 0;
		foreach ( $types as $type ) {
			if ( $listed >= $limit || count( $sections ) >= $budget ) {
				break;
			}

			$sections[] = $type['heading'];
			$last       = count( $sections ) - 1;
			foreach ( $type['entries'] as $entry ) {
				if ( $listed >= $limit ) {
					break 2;
				}

				if ( mb_strlen( $sections[ $last ] ) + 2 + mb_strlen( $entry ) <= self::MAX_SECTION ) {
					$sections[ $last ] .= "\n\n" . $entry;
				} elseif ( count( $sections ) < $budget ) {
					$sections[] = $entry;
					++$last;
				} else {
					break 2;
				}
				++$listed;
			}
		}

		return array(
			'sections' => $sections,
			'listed'   => $listed,
		);
	}

	/**
	 * The entry of one item: a line "Akismet from version 5.6 to 5.7.2", then a line of links and a line for each note.
	 *
	 * Written for a screen reader as much as for the eye: words, no arrows, and links named after the item. The name and
	 * the versions are cut when they are very long, and a link or note that would make the entry too long is left out.
	 *
	 * @param Notification_Item $item The item.
	 * @return string Escaped.
	 */
	private static function entry( Notification_Item $item ): string {
		$name = self::cut( '' === $item->get_name() ? $item->get_key() : $item->get_name(), self::MAX_NAME );
		$line = sprintf(
			// translators: 1: Name of the plugin, theme or WordPress. 2: Installed version. 3: New version.
			__( '%1$s from version %2$s to %3$s', 'wp-updates-notifier' ),
			'*' . self::escape( $name ) . '*',
			self::escape( self::cut( $item->get_current_version(), self::MAX_VERSION ) ),
			self::escape( self::cut( $item->get_new_version(), self::MAX_VERSION ) )
		);

		$links = array();
		$size  = mb_strlen( $line );
		foreach ( $item->get_links() as $label => $url ) {
			$text = self::link( $url, self::link_text( (string) $label, $name ) );
			// Only real links are listed: an address that is not a web address comes back as plain text.
			if ( '<' === substr( $text, 0, 1 ) && $size + 3 + mb_strlen( $text ) <= self::MAX_ENTRY ) {
				$links[] = $text;
				$size   += 3 + mb_strlen( $text );
			}
		}
		if ( array() !== $links ) {
			$line .= "\n" . implode( ' | ', $links );
		}

		foreach ( $item->get_notes() as $note ) {
			$text = self::escape( self::cut( $note, self::MAX_NOTE ) );
			if ( '' !== $text && $size + 1 + mb_strlen( $text ) <= self::MAX_ENTRY ) {
				$line .= "\n" . $text;
				$size += 1 + mb_strlen( $text );
			}
		}

		return $line;
	}

	/**
	 * The text of a link, named after the item: "Akismet details".
	 *
	 * @param string $label Label of the link, such as `details`, `changelog` or `download`.
	 * @param string $name  Name of the item.
	 * @return string Not escaped.
	 */
	private static function link_text( string $label, string $name ): string {
		$formats = array(
			// translators: %s: Name of the plugin, theme or WordPress.
			'details'   => __( '%s details', 'wp-updates-notifier' ),
			// translators: %s: Name of the plugin, theme or WordPress.
			'changelog' => __( '%s changelog', 'wp-updates-notifier' ),
			// translators: %s: Name of the plugin, theme or WordPress.
			'download'  => __( '%s download', 'wp-updates-notifier' ),
		);

		return isset( $formats[ $label ] ) ? sprintf( $formats[ $label ], $name ) : $name . ' ' . ucfirst( str_replace( '_', ' ', $label ) );
	}

	/**
	 * Cut text to a number of characters, with an ellipsis when something was cut.
	 *
	 * @param string $text Text, not escaped.
	 * @param int    $max  Most characters.
	 * @return string
	 */
	private static function cut( string $text, int $max ): string {
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/**
	 * Replace every run of control characters by one space and trim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function single_line( string $text ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
	}
}
