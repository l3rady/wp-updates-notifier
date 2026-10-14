<?php
/**
 * The message the plugin has always sent.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Builds the text of the email and Slack message from a Notification, exactly as before the channel API.
 *
 * The wording, the tabs and the HTML entities (#65) are deliberate: the email and
 * Slack channels will switch to Notification_Renderer when they become modules.
 *
 * @internal
 */
class Legacy_Message {
	/**
	 * Build the message.
	 *
	 * @param Notification $notification The notification.
	 *
	 * @return string
	 */
	public static function build( Notification $notification ): string {
		$body = '';

		foreach ( $notification->get_items() as $item ) {
			$body .= self::item( $item );
		}

		$message = __( 'There are updates available for your WordPress site:', 'wp-updates-notifier' ) . ' ' . esc_html( $notification->get_site_name() ) . ' @ ' . esc_url( $notification->get_site_url() ) . "\n" . $body . "\n";
		// translators: %s: URL of the WordPress updates screen.
		$message .= sprintf( __( 'Please visit %s to update.', 'wp-updates-notifier' ), $notification->get_updates_url() );

		return $message;
	}

	/**
	 * The lines of one item. Items of types other than core, plugin and theme have no legacy wording.
	 *
	 * @param Notification_Item $item The item.
	 *
	 * @return string
	 */
	private static function item( Notification_Item $item ): string {
		$links = $item->get_links();

		switch ( $item->get_type() ) {
			case 'core':
				// translators: 1: Installed WordPress version. 2: New WordPress version.
				return "\n" . sprintf( __( 'WP-Core: WordPress is out of date. Please update from version %1$s to %2$s', 'wp-updates-notifier' ), $item->get_current_version(), $item->get_new_version() ) . "\n";

			case 'plugin':
				// translators: 1: Plugin name. 2: Installed plugin version. 3: New plugin version.
				$text = "\n" . sprintf( __( 'Plugin: %1$s is out of date. Please update from version %2$s to %3$s', 'wp-updates-notifier' ), $item->get_name(), $item->get_current_version(), $item->get_new_version() ) . "\n";
				if ( isset( $links['details'] ) ) {
					// translators: %s: URL of the plugin page.
					$text .= "\t" . sprintf( __( 'Details: %s', 'wp-updates-notifier' ), $links['details'] ) . "\n";
				}
				if ( isset( $links['changelog'] ) ) {
					// translators: %s: URL of the changelog of the plugin.
					$text .= "\t" . sprintf( __( 'Changelog: %s', 'wp-updates-notifier' ), $links['changelog'] ) . "\n";
				}
				foreach ( $item->get_notes() as $note ) {
					$text .= "\t" . $note . "\n";
				}
				return $text;

			case 'theme':
				// translators: 1: Theme name. 2: Installed theme version. 3: New theme version.
				$text = "\n" . sprintf( __( 'Theme: %1$s is out of date. Please update from version %2$s to %3$s', 'wp-updates-notifier' ), $item->get_name(), $item->get_current_version(), $item->get_new_version() ) . "\n";
				if ( isset( $links['details'] ) ) {
					// translators: %s: URL of the theme page.
					$text .= "\t" . sprintf( __( 'Details: %s', 'wp-updates-notifier' ), $links['details'] ) . "\n";
				}
				return $text;

			default:
				return '';
		}
	}
}
