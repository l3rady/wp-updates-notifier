<?php
/**
 * Turning a notification into text.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Renders a Notification as plain text, Markdown or HTML, for channels to send.
 *
 * Each format escapes for itself: plain text is not escaped at all, Markdown
 * escapes Markdown characters and HTML escapes every value.
 *
 * @since 2.0.0
 */
final class Notification_Renderer {
	/**
	 * Plain text, not escaped.
	 *
	 * Lengths are counted in characters (mb_strlen()). Some services count bytes or UTF-16 code units instead, so a
	 * module for one of them should leave a margin or measure the result itself.
	 *
	 * When a limit is given and the notification is too big, as few items as needed are left out and a line
	 * "…and N more" says how many (see the `sc_wpun_more_items_text` filter). The line sits before the closing line.
	 * If even a message without any item is longer than `$max_length`, that message is returned anyway, never an
	 * empty string, so the result can then exceed `$max_length`. Nothing is added when no item is left out.
	 *
	 * @since 2.0.0
	 *
	 * @param Notification $notification The notification.
	 * @param int          $max_length   The longest text to return, in characters, after the `sc_wpun_message_text` filter. 0 or less for no limit.
	 * @param int          $max_items    The most items to list. 0 or less for no limit.
	 *
	 * @return string
	 */
	public static function plain_text( Notification $notification, int $max_length = 0, int $max_items = 0 ): string {
		return self::fit(
			$notification,
			$max_length,
			$max_items,
			static fn( Notification $shown, int $hidden ): string => self::render_plain_text( $shown, $hidden, $notification )
		);
	}

	/**
	 * Markdown, with Markdown characters in names, versions and notes escaped.
	 *
	 * The target is CommonMark, where a backslash escapes a character. A service with its own dialect, for example
	 * Slack mrkdwn, which ignores backslashes and needs `&`, `<` and `>` turned into entities, must escape for that
	 * dialect itself. Links whose URL is not http or https are written as plain text without a link.
	 *
	 * The limits work as on plain_text(): lengths are in characters, a limit of 0 or less is no limit, and a line
	 * "…and N more" (escaped for Markdown) follows the list when items are left out.
	 *
	 * @since 2.0.0
	 *
	 * @param Notification $notification The notification.
	 * @param int          $max_length   The longest text to return, in characters. 0 or less for no limit.
	 * @param int          $max_items    The most items to list. 0 or less for no limit.
	 *
	 * @return string
	 */
	public static function markdown( Notification $notification, int $max_length = 0, int $max_items = 0 ): string {
		return self::fit(
			$notification,
			$max_length,
			$max_items,
			static fn( Notification $shown, int $hidden ): string => self::render_markdown( $shown, $hidden, $notification )
		);
	}

	/**
	 * HTML, with every value escaped.
	 *
	 * The limits work as on plain_text(): lengths are in characters of the HTML, a limit of 0 or less is no limit,
	 * and a paragraph "…and N more" (escaped) follows the list when items are left out.
	 *
	 * @since 2.0.0
	 *
	 * @param Notification $notification The notification.
	 * @param int          $max_length   The longest HTML to return, in characters. 0 or less for no limit.
	 * @param int          $max_items    The most items to list. 0 or less for no limit.
	 *
	 * @return string
	 */
	public static function html( Notification $notification, int $max_length = 0, int $max_items = 0 ): string {
		return self::fit(
			$notification,
			$max_length,
			$max_items,
			static fn( Notification $shown, int $hidden ): string => self::render_html( $shown, $hidden, $notification )
		);
	}

	/**
	 * Render with as many items as the limits allow.
	 *
	 * @param Notification                        $notification The full notification.
	 * @param int                                 $max_length   Longest text in characters, 0 or less for no limit.
	 * @param int                                 $max_items    Most items, 0 or less for no limit.
	 * @param callable(Notification, int): string $render       Renders a notification and the number of items left out.
	 *
	 * @return string
	 */
	private static function fit( Notification $notification, int $max_length, int $max_items, callable $render ): string {
		$items = $notification->get_items();
		$total = count( $items );
		$top   = $max_items > 0 ? min( $total, $max_items ) : $total;

		$attempt = static function ( int $count ) use ( $notification, $items, $total, $render ): string {
			$shown = $count < $total ? $notification->with_items( array_slice( $items, 0, $count ) ) : $notification;
			return $render( $shown, $total - $count );
		};

		$text = $attempt( $top );
		if ( $max_length <= 0 || mb_strlen( $text ) <= $max_length ) {
			return $text;
		}

		// Leaving one item out adds the "more" line, which can be longer than the item, so the full render is
		// checked first and the search only covers fewer items. The largest count that fits is found by bisection.
		$best = $attempt( 0 );
		$low  = 0;
		$high = $top - 1;
		while ( $low <= $high ) {
			$mid = intdiv( $low + $high, 2 );
			$try = $attempt( $mid );
			if ( mb_strlen( $try ) <= $max_length ) {
				$best = $try;
				$low  = $mid + 1;
			} else {
				$high = $mid - 1;
			}
		}

		return $best;
	}

	/**
	 * The line that says how many items were left out, as plain text.
	 *
	 * @param int          $hidden       How many items were left out.
	 * @param Notification $notification The notification, in full.
	 *
	 * @return string
	 */
	private static function more_text( int $hidden, Notification $notification ): string {
		// translators: %d: Number of updates that are not listed in the message.
		$text = sprintf( _n( '…and %d more', '…and %d more', $hidden, 'wp-updates-notifier' ), $hidden );

		/**
		 * Filters the line that says how many items were left out of a shortened message.
		 *
		 * Return plain text: it is escaped for the format (Markdown, HTML) where needed. The line only exists
		 * when at least one item was left out.
		 *
		 * Example, say it in another way:
		 *
		 *     add_filter(
		 *         'sc_wpun_more_items_text',
		 *         function ( $text, $hidden ) {
		 *             return sprintf( '(%d more not shown)', $hidden );
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param string       $text         The line, for example "…and 3 more".
		 * @param int          $hidden       How many items were left out.
		 * @param Notification $notification The notification, with all its items.
		 */
		return (string) apply_filters( 'sc_wpun_more_items_text', $text, $hidden, $notification );
	}

	/**
	 * The opening line, as a format with the site name and address, for a reminder or a new update.
	 *
	 * @param Notification $notification The notification.
	 *
	 * @return string A sprintf() format with %1$s (site name) and %2$s (site address).
	 */
	private static function intro( Notification $notification ): string {
		if ( $notification->is_reminder() ) {
			// translators: 1: Site name. 2: Site address.
			return __( 'Reminder: these updates are still waiting for %1$s (%2$s)', 'wp-updates-notifier' );
		}

		// translators: 1: Site name. 2: Site address.
		return __( 'There are updates available for %1$s (%2$s)', 'wp-updates-notifier' );
	}

	/**
	 * Render plain text.
	 *
	 * @param Notification $notification The notification with the items to show.
	 * @param int          $hidden       How many items were left out.
	 * @param Notification $full         The notification with all its items.
	 *
	 * @return string
	 */
	private static function render_plain_text( Notification $notification, int $hidden, Notification $full ): string {
		$site = wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES );

		$lines = array( sprintf( self::intro( $notification ), $site, $notification->get_site_url() ), '' );

		foreach ( $notification->get_items() as $item ) {
			// translators: 1: Name of the plugin, theme or WordPress. 2: Installed version. 3: New version.
			$lines[] = sprintf( __( '%1$s from version %2$s to %3$s', 'wp-updates-notifier' ), $item->get_name(), $item->get_current_version(), $item->get_new_version() );
			foreach ( $item->get_links() as $label => $url ) {
				$url = self::web_url( $url );
				if ( '' !== $url ) {
					$lines[] = '  ' . self::link_label( (string) $label ) . ': ' . $url;
				}
			}
			foreach ( $item->get_notes() as $note ) {
				$lines[] = '  ' . $note;
			}
			$lines[] = '';
		}

		if ( $hidden > 0 ) {
			$lines[] = self::more_text( $hidden, $full );
			$lines[] = '';
		}

		// translators: %s: Address of the WordPress updates screen.
		$lines[] = sprintf( __( 'Please visit %s to update.', 'wp-updates-notifier' ), $notification->get_updates_url() );

		/**
		 * Filters the plain text of a notification, which channels use as the message.
		 *
		 * When a length limit is given to the renderer, the filter may run more than once for one message, and the
		 * length is measured on what the filter returns. `$notification` is then a copy with only the items shown
		 * when the message was shortened.
		 *
		 * Example, add a line at the end:
		 *
		 *     add_filter(
		 *         'sc_wpun_message_text',
		 *         function ( $text, $notification ) {
		 *             return $text . "\n\n-- Sent by my agency";
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param string       $text         The plain text.
		 * @param Notification $notification The notification it was made from.
		 * @param int          $hidden       How many items were left out of the text, 0 when none.
		 */
		return (string) apply_filters( 'sc_wpun_message_text', implode( "\n", $lines ), $notification, $hidden );
	}

	/**
	 * Render Markdown.
	 *
	 * @param Notification $notification The notification with the items to show.
	 * @param int          $hidden       How many items were left out.
	 * @param Notification $full         The notification with all its items.
	 *
	 * @return string
	 */
	private static function render_markdown( Notification $notification, int $hidden, Notification $full ): string {
		$site = wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES );

		$lines = array( '**' . sprintf( self::intro( $notification ), self::escape_markdown( $site ), self::escape_markdown( $notification->get_site_url() ) ) . '**', '' );

		foreach ( $notification->get_items() as $item ) {
			// translators: 1: Name of the plugin, theme or WordPress. 2: Installed version. 3: New version.
			$lines[] = '- ' . sprintf( __( '%1$s from version %2$s to %3$s', 'wp-updates-notifier' ), '**' . self::escape_markdown( $item->get_name() ) . '**', self::escape_markdown( $item->get_current_version() ), self::escape_markdown( $item->get_new_version() ) );
			foreach ( $item->get_links() as $label => $url ) {
				$link_url = self::markdown_url( $url );
				$text     = self::escape_markdown( self::item_link_label( (string) $label, $item->get_name() ) );
				$lines[]  = '  - ' . ( '' === $link_url ? $text : '[' . $text . '](' . $link_url . ')' );
			}
			foreach ( $item->get_notes() as $note ) {
				$lines[] = '  - ' . self::escape_markdown( $note );
			}
		}

		$lines[] = '';
		if ( $hidden > 0 ) {
			$lines[] = self::escape_markdown( self::more_text( $hidden, $full ) );
			$lines[] = '';
		}
		$updates_url  = self::markdown_url( $notification->get_updates_url() );
		$updates_text = self::escape_markdown( $notification->get_updates_url() );
		// translators: %s: Address of the WordPress updates screen.
		$lines[] = sprintf( __( 'Please visit %s to update.', 'wp-updates-notifier' ), '' === $updates_url ? $updates_text : '[' . $updates_text . '](' . $updates_url . ')' );

		return implode( "\n", $lines );
	}

	/**
	 * Render HTML.
	 *
	 * @param Notification $notification The notification with the items to show.
	 * @param int          $hidden       How many items were left out.
	 * @param Notification $full         The notification with all its items.
	 *
	 * @return string
	 */
	private static function render_html( Notification $notification, int $hidden, Notification $full ): string {
		$site = wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES );

		$html = '<p>' . esc_html( sprintf( self::intro( $notification ), $site, $notification->get_site_url() ) ) . "</p>\n<ul>\n";

		foreach ( $notification->get_items() as $item ) {
			$html .= '<li>';
			// translators: 1: Name of the plugin, theme or WordPress. 2: Installed version. 3: New version.
			$html .= sprintf( esc_html__( '%1$s from version %2$s to %3$s', 'wp-updates-notifier' ), '<strong>' . esc_html( $item->get_name() ) . '</strong>', esc_html( $item->get_current_version() ), esc_html( $item->get_new_version() ) );

			$details = '';
			foreach ( $item->get_links() as $label => $url ) {
				$details .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( self::item_link_label( (string) $label, $item->get_name() ) ) . '</a></li>';
			}
			foreach ( $item->get_notes() as $note ) {
				$details .= '<li>' . esc_html( $note ) . '</li>';
			}
			if ( '' !== $details ) {
				$html .= '<ul>' . $details . '</ul>';
			}

			$html .= "</li>\n";
		}

		$html .= "</ul>\n";
		if ( $hidden > 0 ) {
			$html .= '<p>' . esc_html( self::more_text( $hidden, $full ) ) . "</p>\n";
		}
		// The link says where it goes, not the address: a screen reader would spell the URL out.
		$html .= '<p><a href="' . esc_url( $notification->get_updates_url() ) . '">' . esc_html__( 'Go to the Updates screen to update.', 'wp-updates-notifier' ) . '</a></p>';

		return $html;
	}

	/**
	 * The text shown for a link: its label, with a translation for the usual ones.
	 *
	 * @param string $label Label of the link, for example "details".
	 *
	 * @return string
	 */
	private static function link_label( string $label ): string {
		switch ( $label ) {
			case 'details':
				return __( 'Details', 'wp-updates-notifier' );
			case 'changelog':
				return __( 'Changelog', 'wp-updates-notifier' );
			default:
				return ucfirst( str_replace( '_', ' ', $label ) );
		}
	}

	/**
	 * The text of a link that names the item it is about, for Markdown and HTML, where links are listed out of context.
	 *
	 * @param string $label Label of the link, for example "details".
	 * @param string $name  Name of the plugin, theme or WordPress the link is about.
	 *
	 * @return string
	 */
	private static function item_link_label( string $label, string $name ): string {
		switch ( $label ) {
			case 'details':
				// translators: %s: Name of the plugin, theme or WordPress.
				return sprintf( __( '%s details', 'wp-updates-notifier' ), $name );
			case 'changelog':
				// translators: %s: Name of the plugin, theme or WordPress.
				return sprintf( __( '%s changelog', 'wp-updates-notifier' ), $name );
			default:
				return $name . ' ' . self::link_label( $label );
		}
	}

	/**
	 * A safe http or https URL as esc_url_raw() cleans it, or an empty string when the URL is not one.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private static function web_url( string $url ): string {
		$url    = trim( $url );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		// esc_url_raw() adds http:// to a URL without a scheme, so the scheme is checked first.
		return in_array( $scheme, array( 'http', 'https' ), true ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
	}

	/**
	 * A URL for the target of a Markdown link, or an empty string when it is not a safe http or https URL.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private static function markdown_url( string $url ): string {
		$url = trim( $url );
		if ( '' === self::web_url( $url ) ) {
			return '';
		}

		return str_replace( array( ' ', '(', ')', '<', '>' ), array( '%20', '%28', '%29', '%3C', '%3E' ), $url );
	}

	/**
	 * Escape the characters Markdown treats as formatting.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private static function escape_markdown( string $text ): string {
		return (string) preg_replace( '/([\\\\`*_{}\[\]()#+\-.!|<>~&])/', '\\\\$1', $text );
	}
}
