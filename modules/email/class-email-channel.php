<?php
/**
 * The email channel.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Modules\Email;

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;
use WPUN\Notification_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Sends a notification by email with wp_mail().
 *
 * This is the reference example of a notification channel: copy it to write your own.
 * It only uses the public extension API (Channel, Channel_Instance, Notification,
 * Notification_Renderer and Delivery_Result) and is registered from its loader,
 * modules/email/email.php, on the `sc_wpun_register_channels` action.
 *
 * What a channel is expected to do, as shown here:
 *
 * - Describe its settings in get_fields() with the core field types, and check what is
 *   submitted in validate_settings(), returning a WP_Error naming the field at fault.
 * - Deliver in send() and always return a Delivery_Result. Do not throw, and keep
 *   secrets and recipients out of the result.
 * - Change global state (filters, listeners) only for the duration of the send, and put
 *   it back in a `finally` block.
 * - Handle test notifications (`$notification->is_test()`) with a message of its own.
 *
 * Any number of instances of the channel can exist, each with its own recipients.
 *
 * Filters, for back-compatibility with versions before 2.0.0:
 *
 * - `sc_wpun_email_subject` ( string $subject, Notification $notification, Channel_Instance $instance )
 * - `sc_wpun_email_content` ( string $body, Notification $notification, Channel_Instance $instance )
 *
 * Since 2.0.0 both get the extra arguments, and the content is no longer HTML-escaped
 * plain text: in plain text format it is not escaped at all, so `&` is sent as `&`.
 * In HTML format every value in it is escaped.
 *
 * @since 2.0.0
 */
class Email_Channel extends Channel {

	/**
	 * Channel type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'email';
	}

	/**
	 * Name shown to the site owner.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Email', 'wp-updates-notifier' );
	}

	/**
	 * One sentence about the channel.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Sends the notification to one or more email addresses.', 'wp-updates-notifier' );
	}

	/**
	 * The settings of an instance.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			'to'             => array(
				'type'        => 'email_list',
				'label'       => __( 'Send to', 'wp-updates-notifier' ),
				'description' => __( 'Separate multiple email addresses with a comma (,).', 'wp-updates-notifier' ),
				'required'    => true,
			),
			'from'           => array(
				'type'              => 'text',
				'label'             => __( 'From address', 'wp-updates-notifier' ),
				'description'       => __( 'Leave empty to use the WordPress default. Only set an address at a domain this site is allowed to send mail for.', 'wp-updates-notifier' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'reply_to'       => array(
				'type'              => 'text',
				'label'             => __( 'Reply-To address', 'wp-updates-notifier' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'format'         => array(
				'type'    => 'select',
				'label'   => __( 'Format', 'wp-updates-notifier' ),
				'default' => 'text',
				'options' => array(
					'text' => __( 'Plain text', 'wp-updates-notifier' ),
					'html' => __( 'HTML', 'wp-updates-notifier' ),
				),
			),
			'subject_prefix' => array(
				'type'              => 'text',
				'label'             => __( 'Subject prefix', 'wp-updates-notifier' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Whether the channel supports a feature.
	 *
	 * @param string $feature Feature name.
	 *
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return 'test_message' === $feature;
	}

	/**
	 * Clean and check the settings submitted for an instance.
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 *
	 * @return array<string, mixed>|\WP_Error The settings to store, or an error naming the field at fault.
	 */
	public function validate_settings( array $settings ) {
		$to = array();
		foreach ( explode( ',', $this->text( $settings, 'to' ) ) as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			$address = sanitize_email( $entry );
			if ( ! is_email( $address ) ) {
				return new \WP_Error( 'invalid_email', __( 'One or more email addresses to send to are invalid.', 'wp-updates-notifier' ), array( 'field' => 'to' ) );
			}
			$to[] = $address;
		}
		if ( array() === $to ) {
			return new \WP_Error( 'invalid_email', __( 'Enter at least one valid email address to send to.', 'wp-updates-notifier' ), array( 'field' => 'to' ) );
		}

		$clean = array( 'to' => implode( ',', $to ) );

		foreach ( array( 'from', 'reply_to' ) as $name ) {
			$address = trim( sanitize_text_field( $this->text( $settings, $name ) ) );
			if ( '' !== $address ) {
				$address = sanitize_email( $address );
				if ( ! is_email( $address ) ) {
					return new \WP_Error( 'invalid_email', __( 'The email address is invalid.', 'wp-updates-notifier' ), array( 'field' => $name ) );
				}
			}
			$clean[ $name ] = $address;
		}

		$format = $this->text( $settings, 'format', 'text' );
		if ( ! in_array( $format, array( 'text', 'html' ), true ) ) {
			return new \WP_Error( 'invalid_format', __( 'The format must be plain text or HTML.', 'wp-updates-notifier' ), array( 'field' => 'format' ) );
		}
		$clean['format'] = $format;

		$clean['subject_prefix'] = sanitize_text_field( $this->text( $settings, 'subject_prefix' ) );

		return $clean;
	}

	/**
	 * Send the notification to the instance's recipients.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The configured instance.
	 *
	 * @return Delivery_Result
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		$to = $this->clean_addresses( $this->text( $instance->get_settings(), 'to' ) );
		if ( array() === $to ) {
			return Delivery_Result::failure( __( 'No email address to send to is set.', 'wp-updates-notifier' ), 'not_configured' );
		}

		$settings = $instance->get_settings();
		$is_html  = 'html' === $this->text( $settings, 'format', 'text' );
		$site     = wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES );

		if ( $notification->is_test() ) {
			// translators: %s: Site name.
			$subject = sprintf( __( 'WP Updates Notifier: test message for %s', 'wp-updates-notifier' ), $site );
			// translators: 1: Site name. 2: Site address.
			$body = sprintf( __( 'This is a test message from WP Updates Notifier for %1$s (%2$s). Update notifications for this site will be sent to this address.', 'wp-updates-notifier' ), $site, $notification->get_site_url() );
			$body = $is_html ? '<p>' . esc_html( $body ) . '</p>' : $body;
		} else {
			// translators: %s: Site name.
			$subject = sprintf( __( 'WP Updates Notifier: updates available for %s', 'wp-updates-notifier' ), $site );
			$body    = $is_html ? Notification_Renderer::html( $notification ) : Notification_Renderer::plain_text( $notification );
		}

		$prefix = $this->text( $settings, 'subject_prefix' );
		if ( '' !== $prefix ) {
			$subject = $prefix . ' ' . $subject;
		}

		/**
		 * Filters the subject of the email.
		 *
		 * @since 1.0.0 Since 2.0.0 also receives the notification and the instance.
		 *
		 * @param string           $subject      Subject, including the prefix.
		 * @param Notification     $notification The notification.
		 * @param Channel_Instance $instance     The instance sending it.
		 */
		$subject = apply_filters( 'sc_wpun_email_subject', $subject, $notification, $instance );

		/**
		 * Filters the body of the email.
		 *
		 * @since 1.0.0 Since 2.0.0 also receives the notification and the instance, and is not HTML-escaped.
		 *
		 * @param string           $body         Body: plain text, or HTML if the instance is set to HTML.
		 * @param Notification     $notification The notification.
		 * @param Channel_Instance $instance     The instance sending it.
		 */
		$body = apply_filters( 'sc_wpun_email_content', $body, $notification, $instance );

		// No From is set unless asked for: the WordPress default is the address the host accepts mail from (#76).
		$headers = array();
		// Cleaned again here: settings can also arrive through filters or WP-CLI, and a line break would add a header.
		$from = sanitize_email( $this->text( $settings, 'from' ) );
		if ( '' !== $from ) {
			$headers[] = 'From: ' . __( 'WP Updates Notifier', 'wp-updates-notifier' ) . ' <' . $from . '>';
		}
		$reply_to = sanitize_email( $this->text( $settings, 'reply_to' ) );
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$error        = null;
		$content_type = static fn() => $is_html ? 'text/html' : 'text/plain';
		$capture      = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};

		// A site may set the content type for all mail, so this goes last to keep the format chosen here.
		add_filter( 'wp_mail_content_type', $content_type, PHP_INT_MAX );
		add_action( 'wp_mail_failed', $capture );
		try {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			$sent = wp_mail( $to, (string) $subject, (string) $body, $headers );
		} finally {
			remove_filter( 'wp_mail_content_type', $content_type, PHP_INT_MAX );
			remove_action( 'wp_mail_failed', $capture );
		}

		if ( $sent ) {
			return Delivery_Result::success();
		}

		$message = $error instanceof \WP_Error && '' !== $error->get_error_message() ? $error->get_error_message() : __( 'WordPress could not send the email.', 'wp-updates-notifier' );
		$context = $error instanceof \WP_Error ? array( 'error_code' => (string) $error->get_error_code() ) : array();

		return Delivery_Result::failure( $message, 'wp_mail_failed', null, null, $context );
	}

	/**
	 * A setting as a trimmed string.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $name     Setting name.
	 * @param string               $fallback Value if it is missing or not a string.
	 *
	 * @return string
	 */
	private function text( array $settings, string $name, string $fallback = '' ): string {
		return isset( $settings[ $name ] ) && is_scalar( $settings[ $name ] ) ? trim( (string) $settings[ $name ] ) : $fallback;
	}

	/**
	 * Split a comma-separated list of addresses, cleaning each and dropping empty entries.
	 *
	 * @param string $addresses_list Addresses separated by commas.
	 *
	 * @return string[]
	 */
	private function clean_addresses( string $addresses_list ): array {
		$addresses = array();
		foreach ( explode( ',', $addresses_list ) as $address ) {
			$address = sanitize_email( trim( $address ) );
			if ( '' !== $address ) {
				$addresses[] = $address;
			}
		}

		return $addresses;
	}
}
