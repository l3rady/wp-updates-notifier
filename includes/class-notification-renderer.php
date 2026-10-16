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
	 * @since 2.0.0
	 *
	 * @param Notification $notification The notification.
	 *
	 * @return string
	 */
	public static function plain_text( Notification $notification ): string {
		$site = wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES );

		// translators: 1: Site name. 2: Site address.
		$lines = array( sprintf( __( 'There are updates available for %1$s (%2$s)', 'wp-updates-notifier' ), $site, $notification->get_site_url() ), '' );

		foreach ( $notification->get_items() as $item ) {
			// translators: 1: Name of the plugin, theme or WordPress. 2: Installed version. 3: New version.
			$lines[] = sprintf( __( '%1$s: %2$s → %3$s', 'wp-updates-notifier' ), $item->get_name(), $item->get_current_version(), $item->get_new_version() );
			foreach ( $item->get_links() as $label => $url ) {
				$lines[] = '  ' . self::link_label( (string) $label ) . ': ' . $url;
			}
			foreach ( $item->get_notes() as $note ) {
				$lines[] = '  ' . $note;
			}
			$lines[] = '';
		}

		// translators: %s: Address of the WordPress updates screen.
		$lines[] = sprintf( __( 'Please visit %s to update.', 'wp-updates-notifier' ), $notification->get_updates_url() );

		/**
		 * Filters the plain text of a notification, which channels use as the message.
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
		 */
		return (string) apply_filters( 'sc_wpun_message_text', implode( "\n", $lines ), $notification );
	}

	/**
	 * Markdown, with Markdown characters in names, versions and notes escaped.
	 *
	 * @since 2.0.0
	 *
	 * @param Notification $notification The notification.
	 *
	 * @return string
	 */
	public static function markdown( Notification $notification ): string {
		$site = wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES );

		// translators: 1: Site name. 2: Site address.
		$lines = array( '**' . sprintf( __( 'There are updates available for %1$s (%2$s)', 'wp-updates-notifier' ), self::escape_markdown( $site ), self::escape_markdown( $notification->get_site_url() ) ) . '**', '' );

		foreach ( $notification->get_items() as $item ) {
			// translators: 1: Name of the plugin, theme or WordPress. 2: Installed version. 3: New version.
			$lines[] = '- ' . sprintf( __( '%1$s: %2$s → %3$s', 'wp-updates-notifier' ), '**' . self::escape_markdown( $item->get_name() ) . '**', self::escape_markdown( $item->get_current_version() ), self::escape_markdown( $item->get_new_version() ) );
			foreach ( $item->get_links() as $label => $url ) {
				$lines[] = '  - [' . self::escape_markdown( self::link_label( (string) $label ) ) . '](' . str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), $url ) . ')';
			}
			foreach ( $item->get_notes() as $note ) {
				$lines[] = '  - ' . self::escape_markdown( $note );
			}
		}

		$lines[] = '';
		// translators: %s: Address of the WordPress updates screen.
		$lines[] = sprintf( __( 'Please visit %s to update.', 'wp-updates-notifier' ), '[' . self::escape_markdown( $notification->get_updates_url() ) . '](' . str_replace( array( ' ', '(', ')' ), array( '%20', '%28', '%29' ), $notification->get_updates_url() ) . ')' );

		return implode( "\n", $lines );
	}

	/**
	 * HTML, with every value escaped.
	 *
	 * @since 2.0.0
	 *
	 * @param Notification $notification The notification.
	 *
	 * @return string
	 */
	public static function html( Notification $notification ): string {
		$site = wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES );

		// translators: 1: Site name. 2: Site address.
		$html = '<p>' . esc_html( sprintf( __( 'There are updates available for %1$s (%2$s)', 'wp-updates-notifier' ), $site, $notification->get_site_url() ) ) . "</p>\n<ul>\n";

		foreach ( $notification->get_items() as $item ) {
			$html .= '<li>';
			// translators: 1: Name of the plugin, theme or WordPress. 2: Installed version. 3: New version.
			$html .= sprintf( esc_html__( '%1$s: %2$s → %3$s', 'wp-updates-notifier' ), '<strong>' . esc_html( $item->get_name() ) . '</strong>', esc_html( $item->get_current_version() ), esc_html( $item->get_new_version() ) );

			$details = '';
			foreach ( $item->get_links() as $label => $url ) {
				$details .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( self::link_label( (string) $label ) ) . '</a></li>';
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
		// translators: %s: Address of the WordPress updates screen.
		$html .= '<p>' . sprintf( esc_html__( 'Please visit %s to update.', 'wp-updates-notifier' ), '<a href="' . esc_url( $notification->get_updates_url() ) . '">' . esc_html( $notification->get_updates_url() ) . '</a>' ) . '</p>';

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
