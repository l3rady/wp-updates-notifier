<?php
/**
 * Sending notifications.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Sends the notifications by email.
 *
 * @internal
 */
class Notifier {
	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Channel instance storage, where the email settings are kept.
	 *
	 * @var Channel_Store
	 */
	private $channels;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings.
	 * @param Channel_Store $channels Channel instance storage.
	 */
	public function __construct( Settings $settings, Channel_Store $channels ) {
		$this->settings = $settings;
		$this->channels = $channels;
	}

	/**
	 * A setting of the email instance, as a string.
	 *
	 * @param string $id  Instance id.
	 * @param string $key Setting name.
	 *
	 * @return string Empty if there is no such instance or setting.
	 */
	private function instance_setting( string $id, string $key ): string {
		$instance = $this->channels->get( $id );
		$value    = null === $instance ? null : $instance->get_setting( $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Sends email message.
	 *
	 * @param string $message Holds message to be sent in body of email.
	 *
	 * @return bool Whether the email contents were sent successfully.
	 */
	public function send_email_message( $message ) {
		// translators: %s: Site URL.
		$subject = sprintf( __( 'WP Updates Notifier: Updates Available @ %s', 'wp-updates-notifier' ), home_url() );
		add_filter( 'wp_mail_from', array( $this, 'sc_wpun_wp_mail_from' ) ); // add from filter.
		add_filter( 'wp_mail_from_name', array( $this, 'sc_wpun_wp_mail_from_name' ) ); // add from name filter.
		add_filter( 'wp_mail_content_type', array( $this, 'sc_wpun_wp_mail_content_type' ) ); // add content type filter.
		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$response = wp_mail( $this->instance_setting( 'email', 'to' ), apply_filters( 'sc_wpun_email_subject', $subject ), apply_filters( 'sc_wpun_email_content', esc_html( $message ) ) ); // send email.
		// phpcs:enable
		remove_filter( 'wp_mail_from', array( $this, 'sc_wpun_wp_mail_from' ) ); // remove from filter.
		remove_filter( 'wp_mail_from_name', array( $this, 'sc_wpun_wp_mail_from_name' ) ); // remove from name filter.
		remove_filter( 'wp_mail_content_type', array( $this, 'sc_wpun_wp_mail_content_type' ) ); // remove content type filter.

		return $response;
	}


	/**
	 * Get the from email address.
	 *
	 * @return String email address.
	 */
	public function sc_wpun_wp_mail_from() {
		return $this->instance_setting( 'email', 'from' );
	}

	/**
	 * Get the name to send email from.
	 *
	 * @return String From Name.
	 */
	public function sc_wpun_wp_mail_from_name() {
		return __( 'WP Updates Notifier', 'wp-updates-notifier' );
	}

	/**
	 * Email type.
	 *
	 * @return String email type.
	 */
	public function sc_wpun_wp_mail_content_type() {
		return 'text/plain';
	}

	/**
	 * Filter the background update notification email
	 *
	 * @param array<mixed> $email Array of email arguments that will be passed to wp_mail().
	 *
	 * @return array<mixed> Modified array containing the new email address.
	 */
	public function filter_auto_core_update_email( $email ) {
		$options     = $this->settings->get(); // Get settings.
		$notify_to   = $this->instance_setting( 'email', 'to' );
		$notify_from = $this->instance_setting( 'email', 'from' );

		if ( 0 !== $options['notify_automatic'] ) {
			if ( ! empty( $notify_to ) ) { // If an email address has been set, override the WordPress default.
				$email['to'] = $notify_to;
			}

			if ( ! empty( $notify_from ) ) { // If an email address has been set, override the WordPress default.
				$email['headers'] = $this->normalise_mail_headers( $email['headers'] ?? array() );

				if ( ! $this->has_mail_header( $email['headers'], 'From' ) ) {
					$email['headers'][] = 'From: ' . $this->sc_wpun_wp_mail_from_name() . ' <' . $notify_from . '>';
				}
			}
		}

		return $email;
	}

	/**
	 * Turn mail headers into a list of header lines, the way wp_mail() reads them.
	 *
	 * Core builds the automatic update emails with `headers` as an empty string, and
	 * other filters may pass a newline-separated string or an array.
	 *
	 * @param mixed $headers Headers as a string, an array or anything else.
	 *
	 * @return array<mixed> List of header lines (an array passed in is returned as it is).
	 */
	private function normalise_mail_headers( $headers ): array {
		if ( is_array( $headers ) ) {
			return $headers;
		}

		if ( ! is_string( $headers ) || '' === trim( $headers ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r\n", "\n", $headers ) ) ) ) );
	}

	/**
	 * Whether a list of header lines already contains the given header.
	 *
	 * @param array<mixed> $headers List of header lines.
	 * @param string       $name    Header name, for example "From".
	 *
	 * @return bool
	 */
	private function has_mail_header( array $headers, string $name ): bool {
		foreach ( $headers as $header ) {
			if ( is_string( $header ) && 0 === stripos( ltrim( $header ), $name . ':' ) ) {
				return true;
			}
		}

		return false;
	}
}
