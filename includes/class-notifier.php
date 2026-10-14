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
 * Sends the notifications by email and to Slack.
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
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Sends email message.
	 *
	 * @param string $message Holds message to be sent in body of email.
	 *
	 * @return bool Whether the email contents were sent successfully.
	 */
	public function send_email_message( $message ) {
		$settings = $this->settings->get(); // get settings.

		// translators: %s: Site URL.
		$subject = sprintf( __( 'WP Updates Notifier: Updates Available @ %s', 'wp-updates-notifier' ), home_url() );
		add_filter( 'wp_mail_from', array( $this, 'sc_wpun_wp_mail_from' ) ); // add from filter.
		add_filter( 'wp_mail_from_name', array( $this, 'sc_wpun_wp_mail_from_name' ) ); // add from name filter.
		add_filter( 'wp_mail_content_type', array( $this, 'sc_wpun_wp_mail_content_type' ) ); // add content type filter.
		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$response = wp_mail( $settings['notify_to'], apply_filters( 'sc_wpun_email_subject', $subject ), apply_filters( 'sc_wpun_email_content', esc_html( $message ) ) ); // send email.
		// phpcs:enable
		remove_filter( 'wp_mail_from', array( $this, 'sc_wpun_wp_mail_from' ) ); // remove from filter.
		remove_filter( 'wp_mail_from_name', array( $this, 'sc_wpun_wp_mail_from_name' ) ); // remove from name filter.
		remove_filter( 'wp_mail_content_type', array( $this, 'sc_wpun_wp_mail_content_type' ) ); // remove content type filter.

		return $response;
	}


	/**
	 * Sends slack post.
	 *
	 * @param string $message Holds message to be posted to slack.
	 *
	 * @return bool Success or failure.
	 */
	public function send_slack_message( $message ) {
		$settings = $this->settings->get(); // get settings.

		$payload = array(
			'username'   => __( 'WP Updates Notifier', 'wp-updates-notifier' ),
			'icon_emoji' => ':robot_face:',
			'text'       => esc_html( $message ),
		);

		if ( ! empty( $settings['slack_channel_override'] ) && '' !== $settings['slack_channel_override'] ) {
			$payload['channel'] = $settings['slack_channel_override'];
		}

		$response = wp_remote_post(
			$settings['slack_webhook_url'],
			array(
				'method' => 'POST',
				'body'   => array(
					'payload' => wp_json_encode( $payload ),
				),
			)
		);

		return is_wp_error( $response );
	}

	/**
	 * Get the from email address.
	 *
	 * @return String email address.
	 */
	public function sc_wpun_wp_mail_from() {
		$settings = $this->settings->get();
		return $settings['notify_from'];
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
		$options = $this->settings->get(); // Get settings.

		if ( 0 !== $options['notify_automatic'] ) {
			if ( ! empty( $options['notify_to'] ) ) { // If an email address has been set, override the WordPress default.
				$email['to'] = $options['notify_to'];
			}

			if ( ! empty( $options['notify_from'] ) ) { // If an email address has been set, override the WordPress default.
				$email['headers'] = $this->normalise_mail_headers( $email['headers'] ?? array() );

				if ( ! $this->has_mail_header( $email['headers'], 'From' ) ) {
					$email['headers'][] = 'From: ' . $this->sc_wpun_wp_mail_from_name() . ' <' . $options['notify_from'] . '>';
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
