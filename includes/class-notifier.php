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
 * Handles the deprecated email sending.
 *
 * @internal
 */
class Notifier {
	/**
	 * Channel instance storage, where the email settings are kept.
	 *
	 * @var Channel_Store
	 */
	private $channels;

	/**
	 * Constructor.
	 *
	 * @param Channel_Store $channels Channel instance storage.
	 */
	public function __construct( Channel_Store $channels ) {
		$this->channels = $channels;
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
}
