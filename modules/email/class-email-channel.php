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
use WPUN\Notification_Item;
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
				'group'         => 'recipients',
				'type'          => 'email_list',
				'label'         => __( 'Send to', 'wp-updates-notifier' ),
				'description'   => __( 'Separate multiple email addresses with a comma (,).', 'wp-updates-notifier' ),
				'required'      => true,
				'show_external' => true,
			),
			'from'           => array(
				'group'             => 'message',
				'type'              => 'text',
				'label'             => __( 'From address', 'wp-updates-notifier' ),
				'description'       => __( 'Leave empty to use the WordPress default. Only set an address at a domain this site is allowed to send mail for.', 'wp-updates-notifier' ),
				'show_external'     => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'reply_to'       => array(
				'group'             => 'message',
				'type'              => 'text',
				'label'             => __( 'Reply-To address', 'wp-updates-notifier' ),
				'show_external'     => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'format'         => array(
				'group'             => 'message',
				'type'              => 'enum',
				'label'             => __( 'Format', 'wp-updates-notifier' ),
				'default'           => 'text',
				'sanitize_callback' => 'sanitize_text_field',
				'options'           => array(
					'text' => __( 'Plain text', 'wp-updates-notifier' ),
					'html' => __( 'HTML', 'wp-updates-notifier' ),
				),
			),
			'subject_prefix' => array(
				'group'             => 'message',
				'type'              => 'text',
				'label'             => __( 'Subject prefix', 'wp-updates-notifier' ),
				'show_external'     => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * The groups of the settings form.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_field_groups(): array {
		return array(
			'recipients' => array(
				'label' => __( 'Recipients', 'wp-updates-notifier' ),
			),
			'message'    => array(
				'label' => __( 'Message', 'wp-updates-notifier' ),
			),
		);
	}

	/**
	 * The panels of the settings form: a preview of the email.
	 *
	 * @param Channel_Instance $instance The instance being edited, or the draft of a new channel.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_form_panels( Channel_Instance $instance ): array {
		return array(
			'preview' => array(
				'label'       => __( 'Preview', 'wp-updates-notifier' ),
				'description' => __( 'What a notification from this channel looks like with these settings, for a sample plugin update. Nothing is sent.', 'wp-updates-notifier' ),
				'content'     => $this->preview( $instance ),
				'collapsed'   => true,
			),
		);
	}

	/**
	 * Escaped markup of the preview: the recipients, the subject and the body for a sample notification.
	 *
	 * An HTML body is shown as source text, never as markup, as the content filter returns it unescaped.
	 *
	 * @param Channel_Instance $instance The instance to preview.
	 *
	 * @return string
	 */
	private function preview( Channel_Instance $instance ): string {
		$notification = new Notification(
			get_bloginfo( 'name' ),
			home_url( '/' ),
			self_admin_url( 'update-core.php' ),
			array(
				new Notification_Item(
					array(
						'type'            => 'plugin',
						'key'             => 'example-plugin/example-plugin.php',
						'name'            => __( 'Example plugin', 'wp-updates-notifier' ),
						'current_version' => '1.0.0',
						'new_version'     => '1.1.0',
					)
				),
			),
			array( 'source' => 'preview' )
		);
		$composed     = $this->compose( $notification, $instance );
		$to           = $this->clean_addresses( $this->text( $instance->get_settings(), 'to' ) );

		$html  = '<dl class="sc-wpun-preview">';
		$html .= '<dt>' . esc_html__( 'To', 'wp-updates-notifier' ) . '</dt><dd>' . ( array() === $to ? esc_html__( 'No recipients set yet.', 'wp-updates-notifier' ) : esc_html( implode( ', ', $to ) ) ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Subject', 'wp-updates-notifier' ) . '</dt><dd>' . esc_html( $composed['subject'] ) . '</dd>';
		$html .= '<dt>' . ( $composed['is_html'] ? esc_html__( 'Body (HTML source)', 'wp-updates-notifier' ) : esc_html__( 'Body', 'wp-updates-notifier' ) ) . '</dt><dd><pre>' . esc_html( $composed['body'] ) . '</pre></dd>';
		$html .= '</dl>';

		return $html;
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
	 * The fields are checked by the default, from their schema (so a field added with the `sc_wpun_channel_fields` filter is too);
	 * the From and Reply-To addresses, which may be empty, are then checked as addresses.
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 * @param array<string, mixed> $stored   The settings stored for the instance.
	 *
	 * @return array<string, mixed>|\WP_Error The settings to store, or an error naming the field at fault.
	 */
	public function validate_settings( array $settings, array $stored = array() ) {
		$clean = parent::validate_settings( $settings, $stored );
		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}

		foreach ( array( 'from', 'reply_to' ) as $name ) {
			if ( ! isset( $clean[ $name ] ) || '' === $clean[ $name ] ) {
				continue;
			}
			$address = sanitize_email( (string) $clean[ $name ] );
			if ( ! is_email( $address ) ) {
				return new \WP_Error( 'invalid_email', __( 'The email address is invalid.', 'wp-updates-notifier' ), array( 'field' => $name ) );
			}
			$clean[ $name ] = $address;
		}

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
		$composed = $this->compose( $notification, $instance );
		$is_html  = $composed['is_html'];
		$subject  = $composed['subject'];
		$body     = $composed['body'];
		if ( $is_html ) {
			$body = $this->document( (string) $body, (string) $subject );
		}

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

		// Site code may still change the whole mail; anything of the wrong shape is ignored.
		$payload = $this->checked_payload(
			$this->filter_payload(
				array(
					'to'      => $to,
					'subject' => (string) $subject,
					'message' => (string) $body,
					'headers' => $headers,
				),
				$notification,
				$instance
			),
			$to,
			(string) $subject,
			(string) $body,
			$headers
		);

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
			$sent = wp_mail( $payload['to'], $payload['subject'], $payload['message'], $payload['headers'] );
		} finally {
			remove_filter( 'wp_mail_content_type', $content_type, PHP_INT_MAX );
			remove_action( 'wp_mail_failed', $capture );
		}

		if ( $sent ) {
			return Delivery_Result::success();
		}

		if ( ! $error instanceof \WP_Error ) {
			return Delivery_Result::failure( __( 'WordPress could not send the email.', 'wp-updates-notifier' ), 'wp_mail_failed' );
		}

		// The mailer's own text can name the mail server, a user name or a recipient, and results are shown in the admin, so only
		// the code is kept. The full error still goes to core's `wp_mail_failed` action, where mail logging plugins pick it up.
		// translators: %s: Error code, for example "wp_mail_failed".
		$message = sprintf( __( 'The email could not be sent (%s).', 'wp-updates-notifier' ), (string) $error->get_error_code() );
		$context = array( 'error_code' => (string) $error->get_error_code() );

		return Delivery_Result::failure( $message, 'wp_mail_failed', null, null, $context );
	}

	/**
	 * The subject and body an instance sends for a notification, after the subject prefix and the content filters.
	 *
	 * An HTML body is a fragment: send() wraps it in a document.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The configured instance.
	 *
	 * @return array{subject: string, body: string, is_html: bool}
	 */
	private function compose( Notification $notification, Channel_Instance $instance ): array {
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
			if ( $notification->is_reminder() ) {
				// translators: %s: Site name.
				$subject = sprintf( __( 'WP Updates Notifier: reminder of updates waiting for %s', 'wp-updates-notifier' ), $site );
			} else {
				// translators: %s: Site name.
				$subject = sprintf( __( 'WP Updates Notifier: updates available for %s', 'wp-updates-notifier' ), $site );
			}
			$shown = $this->with_mute_links( $notification, $instance );
			$body  = $is_html ? Notification_Renderer::html( $shown ) : Notification_Renderer::plain_text( $shown );
		}

		$prefix = $this->text( $settings, 'subject_prefix' );
		if ( '' !== $prefix ) {
			$subject = $prefix . ' ' . $subject;
		}

		/**
		 * Filters the subject of the email.
		 *
		 * It also runs for the preview panel of the add and edit form, for a sample notification whose source is `preview`; nothing is sent then, and on the form of a new channel the instance is a draft with an empty id.
		 *
		 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
		 *
		 * @since 1.4.3 Since 2.0.0 also receives the notification and the instance.
		 *
		 * @param string           $subject      Subject, including the prefix.
		 * @param Notification     $notification The notification.
		 * @param Channel_Instance $instance     The instance sending it.
		 */
		$subject = apply_filters( 'sc_wpun_email_subject', $subject, $notification, $instance );

		/**
		 * Filters the body of the email.
		 *
		 * It also runs for the preview panel of the add and edit form, for a sample notification whose source is `preview`; nothing is sent then, and on the form of a new channel the instance is a draft with an empty id.
		 *
		 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
		 *
		 * @since 1.4.3 Since 2.0.0 also receives the notification and the instance, and is not HTML-escaped.
		 *
		 * @param string           $body         Body: plain text, or HTML if the instance is set to HTML. An HTML body is a fragment: it is wrapped in a document with the language, direction and title after this filter.
		 * @param Notification     $notification The notification.
		 * @param Channel_Instance $instance     The instance sending it.
		 */
		$body = apply_filters( 'sc_wpun_email_content', $body, $notification, $instance );

		return array(
			'subject' => (string) $subject,
			'body'    => (string) $body,
			'is_html' => $is_html,
		);
	}

	/**
	 * A copy of the notification whose plugins and themes carry a "Mute in WordPress" link, or the notification itself when the links are off.
	 *
	 * The link opens the Muted tab with the form filled in (see sc_wpun_mute_url()); core can't be muted.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The configured instance.
	 *
	 * @return Notification
	 */
	private function with_mute_links( Notification $notification, Channel_Instance $instance ): Notification {
		/**
		 * Filters whether the email links each plugin and theme to the Muted tab.
		 *
		 * The "Mute in WordPress" link opens the form of the Muted tab filled in; nothing is muted until the user presses the button, and it needs the permission of the settings screen. Return false for an instance whose recipients can't log in to the site, such as a client.
		 *
		 * It also runs for the preview panel of the add and edit form, for a sample notification whose source is `preview`; nothing is sent then, and on the form of a new channel the instance is a draft with an empty id.
		 *
		 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
		 *
		 *     add_filter(
		 *         'sc_wpun_email_mute_links',
		 *         static fn( $show, $notification, $instance ) => 'client' === $instance->get_id() ? false : $show,
		 *         10,
		 *         3
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param bool             $show         Whether to add the links. Default true.
		 * @param Notification     $notification The notification.
		 * @param Channel_Instance $instance     The instance sending it.
		 */
		if ( ! (bool) apply_filters( 'sc_wpun_email_mute_links', true, $notification, $instance ) ) {
			return $notification;
		}

		$items = array();
		foreach ( $notification->get_items() as $item ) {
			if ( in_array( $item->get_type(), array( 'plugin', 'theme' ), true ) ) {
				$item = new Notification_Item(
					array(
						'type'            => $item->get_type(),
						'key'             => $item->get_key(),
						'name'            => $item->get_name(),
						'current_version' => $item->get_current_version(),
						'new_version'     => $item->get_new_version(),
						'links'           => array_merge( $item->get_links(), array( 'mute' => sc_wpun_mute_url( $item->get_type(), $item->get_key() ) ) ),
						'notes'           => $item->get_notes(),
						'notified_at'     => $item->get_notified_at(),
						'flags'           => $item->get_flags(),
					)
				);
			}
			$items[] = $item;
		}

		return $notification->with_items( $items );
	}

	/**
	 * The filtered payload, with any key of the wrong type replaced by the original value.
	 *
	 * Header lines must be strings without line breaks, so a filter cannot inject headers.
	 *
	 * @param mixed    $payload  What the filters returned.
	 * @param string[] $to       Original recipients.
	 * @param string   $subject  Original subject.
	 * @param string   $message  Original message.
	 * @param string[] $headers  Original header lines.
	 *
	 * @return array{to: string[], subject: string, message: string, headers: string[]}
	 */
	private function checked_payload( $payload, array $to, string $subject, string $message, array $headers ): array {
		$payload = is_array( $payload ) ? $payload : array();

		$recipients = array();
		if ( isset( $payload['to'] ) && is_array( $payload['to'] ) ) {
			foreach ( $payload['to'] as $address ) {
				if ( is_string( $address ) && '' !== trim( $address ) && 1 !== preg_match( '/[\r\n]/', $address ) ) {
					$recipients[] = trim( $address );
				}
			}
		}

		$lines = array();
		if ( isset( $payload['headers'] ) && is_array( $payload['headers'] ) ) {
			foreach ( $payload['headers'] as $line ) {
				if ( is_string( $line ) && 1 !== preg_match( '/[\r\n]/', $line ) ) {
					$lines[] = $line;
				}
			}
		} else {
			$lines = $headers;
		}

		return array(
			'to'      => array() !== $recipients ? $recipients : $to,
			'subject' => isset( $payload['subject'] ) && is_string( $payload['subject'] ) ? $payload['subject'] : $subject,
			'message' => isset( $payload['message'] ) && is_string( $payload['message'] ) ? $payload['message'] : $message,
			'headers' => $lines,
		);
	}

	/**
	 * Wrap an HTML body in a minimal document, so the language and direction of the message are declared.
	 *
	 * No styling: the content stays plain semantic HTML. The language is the locale in effect when the message is built.
	 *
	 * @param string $body    The HTML fragment.
	 * @param string $subject The subject, used as the title.
	 *
	 * @return string
	 */
	private function document( string $body, string $subject ): string {
		// The charset the mail goes out with: wp_mail() takes it from the site.
		$charset = get_bloginfo( 'charset' );

		return '<!DOCTYPE html>' . "\n"
			. '<html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '" dir="' . ( is_rtl() ? 'rtl' : 'ltr' ) . '">' . "\n"
			. '<head><meta charset="' . esc_attr( $charset ) . '"><title>' . esc_html( $subject ) . '</title></head>' . "\n"
			. '<body>' . "\n" . $body . "\n</body>\n</html>\n";
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
