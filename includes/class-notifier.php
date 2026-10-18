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
 * Handles the automatic update emails and the deprecated email sending.
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
	 * The email instance whose addresses the automatic update emails use: the `email` instance when it is an enabled
	 * email channel, otherwise the first enabled email instance.
	 *
	 * @return Channel_Instance|null Null if no email instance is enabled.
	 */
	private function email_instance(): ?Channel_Instance {
		$preferred = $this->channels->get( 'email' );
		if ( null !== $preferred && 'email' === $preferred->get_type() && $preferred->is_enabled() ) {
			return $preferred;
		}

		foreach ( $this->channels->all() as $instance ) {
			if ( 'email' === $instance->get_type() && $instance->is_enabled() ) {
				return $instance;
			}
		}

		return null;
	}

	/**
	 * A setting of the email instance from email_instance(), as a string.
	 *
	 * @param string $key Setting name.
	 *
	 * @return string Empty if no email instance is enabled or it has no such setting.
	 */
	private function instance_setting( string $key ): string {
		$instance = $this->email_instance();
		$value    = null === $instance ? null : $instance->get_setting( $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Send a message the way versions before 2.0.0 did: plain text to every enabled email instance.
	 *
	 * Only for the deprecated SC_WP_Updates_Notifier::send_email_message(); updates go out
	 * through the email channel.
	 *
	 * @param string $message Body of the email.
	 *
	 * @return bool Whether an email was sent to any instance.
	 */
	public function send_legacy_message( $message ) {
		// translators: %s: Site name.
		$subject = sprintf( __( 'WP Updates Notifier: updates available for %s', 'wp-updates-notifier' ), wp_specialchars_decode( get_bloginfo(), ENT_QUOTES ) );
		$sent    = false;

		foreach ( $this->channels->all() as $instance ) {
			$to = $instance->get_setting( 'to' );
			if ( 'email' !== $instance->get_type() || ! $instance->is_enabled() || ! is_string( $to ) || '' === trim( $to ) ) {
				continue;
			}
			/** This filter is documented in modules/email/class-email-channel.php */
			$mail_subject = apply_filters( 'sc_wpun_email_subject', $subject );
			/** This filter is documented in modules/email/class-email-channel.php */
			$mail_body = apply_filters( 'sc_wpun_email_content', $message );
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			if ( wp_mail( $to, $mail_subject, $mail_body ) ) {
				$sent = true;
			}
		}

		return $sent;
	}

	/**
	 * The name auto update emails are sent from.
	 *
	 * @return string
	 */
	public function mail_from_name() {
		return __( 'WP Updates Notifier', 'wp-updates-notifier' );
	}

	/**
	 * Get the email address configured to send from, for the deprecated facade method.
	 *
	 * @return string
	 */
	public function mail_from() {
		return $this->instance_setting( 'from' );
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
		$notify_to   = $this->instance_setting( 'to' );
		$notify_from = sanitize_email( $this->instance_setting( 'from' ) );

		if ( true === $options['notify_automatic'] ) {
			if ( ! empty( $notify_to ) ) { // If an email address has been set, override the WordPress default.
				$email['to'] = $notify_to;
			}

			if ( ! empty( $notify_from ) ) { // If an email address has been set, override the WordPress default.
				$email['headers'] = $this->normalise_mail_headers( $email['headers'] ?? array() );

				if ( ! $this->has_mail_header( $email['headers'], 'From' ) ) {
					$email['headers'][] = 'From: ' . $this->mail_from_name() . ' <' . $notify_from . '>';
				}
			}

			$notify_reply_to = sanitize_email( $this->instance_setting( 'reply_to' ) );
			if ( ! empty( $notify_reply_to ) ) {
				$email['headers'] = $this->normalise_mail_headers( $email['headers'] ?? array() );

				if ( ! $this->has_mail_header( $email['headers'], 'Reply-To' ) ) {
					$email['headers'][] = 'Reply-To: ' . $notify_reply_to;
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
