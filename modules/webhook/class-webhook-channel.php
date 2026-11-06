<?php
/**
 * The webhook channel.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Modules\Webhook;

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;
use WPUN\Notification_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Posts a notification as signed JSON to any web address: Zapier, Make, n8n, Home Assistant or your own code.
 *
 * This is the reference for a channel that talks to a web service, next to the email module. Like it, it only uses the
 * public extension API (Channel, Channel_Instance, Notification, Notification_Item and Delivery_Result) and is registered from
 * its loader, modules/webhook/webhook.php, on the `sc_wpun_register_channels` action. It shows:
 *
 * - Secrets: the address (it often carries a token), the signing secret and the extra headers are never shown again once saved,
 *   and never reach a result, a notice or a log.
 * - Field groups and conditional fields: the rarely used settings sit in a collapsed group, and the header names apply only
 *   when "Custom names" is chosen.
 * - Checks that span several fields in validate_settings().
 * - Safe HTTP: wp_safe_remote_post(), a short timeout, no redirects, and HTTP 429 with `Retry-After`.
 * - Signing the exact bytes that are sent, following the Standard Webhooks specification.
 *
 * The body is a JSON document with the envelope `type`, `timestamp`, `schema_version` and `data`. `type` is `updates.available`,
 * `updates.reminder`, `updates.test` or `updates.resolved`. The JSON Schema is docs/developers/webhook-payload.schema.json.
 * Within `schema_version` 1 fields are added, never removed or changed.
 *
 * The headers `webhook-id`, `webhook-timestamp` and `webhook-signature` are those of Standard Webhooks
 * (https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md). The signature is
 * `v1,` and the base64 of the HMAC-SHA256, with the decoded `whsec_` secret as the key, of `{id}.{timestamp}.{body}`.
 * Without a secret the message is sent unsigned.
 *
 * Hooks, besides the payload filters of every channel (`sc_wpun_channel_payload` and `sc_wpun_channel_payload_webhook`):
 *
 * - `sc_wpun_webhook_headers` ( array $headers, Notification $notification, Channel_Instance $instance, string $body )
 * - `sc_wpun_webhook_resolved_sent` ( Delivery_Result $result, Notification_Item[] $items, Channel_Instance $instance )
 *
 * @since 3.0.0
 */
class Webhook_Channel extends Channel {

	/**
	 * Channel type.
	 */
	const TYPE = 'webhook';

	/**
	 * Version of the payload. Within a version fields are added, never removed or retyped.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Longest wait for the receiver, in seconds. The check delivers to every instance in turn, during a cron request.
	 */
	const MAX_TIMEOUT = 10;

	/**
	 * Wait for the receiver, in seconds, when none is set.
	 */
	const DEFAULT_TIMEOUT = 5;

	/**
	 * Most extra header lines.
	 */
	const MAX_HEADERS = 20;

	/**
	 * Longest name of a signature header.
	 */
	const MAX_NAME_LENGTH = 64;

	/**
	 * Longest excerpt of an answer to keep in a failure, in characters.
	 */
	const MAX_EXCERPT = 200;

	/**
	 * The standard names of the signature headers, by setting.
	 */
	const STANDARD_NAMES = array(
		'id_header'        => 'webhook-id',
		'timestamp_header' => 'webhook-timestamp',
		'signature_header' => 'webhook-signature',
	);

	/**
	 * Headers the module sets itself, in lower case. No setting may name them.
	 */
	const RESERVED_NAMES = array( 'content-type', 'content-length', 'host', 'user-agent' );

	/**
	 * Resolved items collected during an update check, by instance id: a list of [ item, how ] for each.
	 *
	 * @var array<string, array{0: Channel_Instance, 1: array<int, array{0: Notification_Item, 1: string}>}>
	 */
	private static $resolved = array();

	/**
	 * Channel type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * The API versions this channel needs: 1.1.0 added `https_only` to the `url` field type.
	 *
	 * @return string
	 */
	public function get_api_constraint(): string {
		return '^1.1';
	}

	/**
	 * Name shown to the site owner.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Webhook', 'wp-updates-notifier' );
	}

	/**
	 * One sentence about the channel.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Posts the notification as signed JSON to a web address, for Zapier, Make, n8n, Home Assistant or your own code.', 'wp-updates-notifier' );
	}

	/**
	 * The settings of an instance.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			// The address often carries a token, so it is a secret: it is never shown again, and changing it means typing it again.
			// `https_only` refuses http://. A site that needs plain http can remove the key with the `sc_wpun_channel_fields` filter.
			'url'              => array(
				'type'        => 'url',
				'label'       => __( 'Webhook address', 'wp-updates-notifier' ),
				'description' => __( 'The https:// address that receives the messages.', 'wp-updates-notifier' ),
				'required'    => true,
				'secret'      => true,
				'https_only'  => true,
			),
			// Optional. Without it the messages are sent unsigned, so the receiver cannot tell who sent them.
			'signing_secret'   => array(
				'type'              => 'secret',
				'label'             => __( 'Signing secret', 'wp-updates-notifier' ),
				'description'       => __( 'Optional. Signs every message, so the receiver can check it came from this site. It starts with whsec_ and is the same secret you give the receiver.', 'wp-updates-notifier' ),
				'validate_callback' => array( $this, 'check_signing_secret' ),
			),
			'send_resolved'    => array(
				'type'    => 'boolean',
				'label'   => __( 'Installed updates', 'wp-updates-notifier' ),
				'default' => true,
				'labels'  => array(
					'on' => __( 'Also send a message when a reported update is installed', 'wp-updates-notifier' ),
				),
			),
			'header_names'     => array(
				'group'       => 'advanced',
				'type'        => 'enum',
				'display'     => 'radio',
				'label'       => __( 'Signature headers', 'wp-updates-notifier' ),
				'description' => __( 'The standard names work with the Standard Webhooks libraries. Only the names change: the values and the signed content stay the same.', 'wp-updates-notifier' ),
				'default'     => 'standard',
				'options'     => array(
					'standard' => __( 'Standard (webhook-id, webhook-timestamp, webhook-signature)', 'wp-updates-notifier' ),
					'custom'   => __( 'Custom names', 'wp-updates-notifier' ),
				),
			),
			'id_header'        => array(
				'group'             => 'advanced',
				'type'              => 'text',
				'label'             => __( 'ID header', 'wp-updates-notifier' ),
				'default'           => self::STANDARD_NAMES['id_header'],
				'show_external'     => true,
				'applies_when'      => array( 'header_names' => 'custom' ),
				'validate_callback' => array( $this, 'check_header_name' ),
			),
			'timestamp_header' => array(
				'group'             => 'advanced',
				'type'              => 'text',
				'label'             => __( 'Timestamp header', 'wp-updates-notifier' ),
				'default'           => self::STANDARD_NAMES['timestamp_header'],
				'show_external'     => true,
				'applies_when'      => array( 'header_names' => 'custom' ),
				'validate_callback' => array( $this, 'check_header_name' ),
			),
			'signature_header' => array(
				'group'             => 'advanced',
				'type'              => 'text',
				'label'             => __( 'Signature header', 'wp-updates-notifier' ),
				'default'           => self::STANDARD_NAMES['signature_header'],
				'show_external'     => true,
				'applies_when'      => array( 'header_names' => 'custom' ),
				'validate_callback' => array( $this, 'check_header_name' ),
			),
			// These values are usually credentials, so the list is a secret: changing one header means typing the whole list again.
			'headers'          => array(
				'group'             => 'advanced',
				'type'              => 'textarea',
				'label'             => __( 'Extra headers', 'wp-updates-notifier' ),
				'description'       => __( 'Optional. One per line, written Name: value, for a receiver that wants an Authorization or API key header. Up to 20 lines.', 'wp-updates-notifier' ),
				'secret'            => true,
				'validate_callback' => array( $this, 'check_headers' ),
			),
			'timeout'          => array(
				'group'       => 'advanced',
				'type'        => 'number',
				'label'       => __( 'Timeout', 'wp-updates-notifier' ),
				'description' => __( 'Seconds to wait for the receiver, from 1 to 10.', 'wp-updates-notifier' ),
				'default'     => self::DEFAULT_TIMEOUT,
				'min'         => 1,
				'max'         => self::MAX_TIMEOUT,
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
			'advanced' => array(
				'label'       => __( 'Advanced', 'wp-updates-notifier' ),
				'description' => __( 'Most sites can leave these as they are.', 'wp-updates-notifier' ),
				'collapsed'   => true,
			),
		);
	}

	/**
	 * The panels of the settings form: a preview of the message.
	 *
	 * @param Channel_Instance $instance The instance being edited, or the draft of a new channel.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_form_panels( Channel_Instance $instance ): array {
		return array(
			'preview' => array(
				'label'       => __( 'Preview', 'wp-updates-notifier' ),
				'description' => __( 'The headers and body of a message from this channel with these settings, for a sample plugin update. The body is shown indented; the real one is on one line. Nothing is sent.', 'wp-updates-notifier' ),
				'content'     => $this->preview( $instance ),
				'collapsed'   => true,
			),
		);
	}

	/**
	 * Escaped markup of the preview: the headers and the body of a sample message, built the way deliver() builds one.
	 *
	 * Only the headers the module sets itself show their value. The signature, the extra headers and anything a filter added
	 * show "(hidden)", and neither the address nor the signing secret is ever printed.
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

		$notes  = array();
		$secret = $instance->get_setting( 'signing_secret', '' );
		$key    = null;
		if ( is_string( $secret ) && '' !== $secret ) {
			$key = self::decode_secret( $secret );
			if ( null === $key ) {
				$notes[] = __( 'The signing secret is not valid, so no message is sent until it is fixed.', 'wp-updates-notifier' );
			}
		} else {
			$notes[] = __( 'Not signed: no signing secret is set.', 'wp-updates-notifier' );
		}

		$extra      = array();
		$lines_text = $instance->get_setting( 'headers', '' );
		if ( is_string( $lines_text ) && '' !== $lines_text ) {
			$parsed = self::parse_headers( $lines_text );
			if ( null !== $parsed['error'] ) {
				$notes[] = __( 'The extra headers are not valid, so no message is sent until they are fixed. They are left out here.', 'wp-updates-notifier' );
			} else {
				$extra = $parsed['headers'];
			}
		}

		$items = array();
		foreach ( $notification->get_items() as $item ) {
			$items[] = self::item_data( $item );
		}
		$message = $this->build_message( 'updates.available', $notification, $instance, $items, $extra, $key );
		if ( null === $message ) {
			return '<p>' . esc_html__( 'The message could not be turned into JSON.', 'wp-updates-notifier' ) . '</p>';
		}

		$public = self::public_headers( $message['names'] );
		$lines  = array();
		foreach ( $message['headers'] as $name => $value ) {
			$lines[] = $name . ': ' . ( in_array( strtolower( $name ), $public, true ) ? $value : __( '(hidden)', 'wp-updates-notifier' ) );
		}
		$json = wp_json_encode( self::with_objects( $message['payload'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$html  = '<dl class="sc-wpun-preview">';
		$html .= '<dt>' . esc_html__( 'Headers', 'wp-updates-notifier' ) . '</dt><dd><pre>' . esc_html( implode( "\n", $lines ) ) . '</pre>';
		foreach ( $notes as $note ) {
			$html .= '<p>' . esc_html( $note ) . '</p>';
		}
		$html .= '</dd>';
		$html .= '<dt>' . esc_html__( 'Body', 'wp-updates-notifier' ) . '</dt><dd><pre>' . esc_html( is_string( $json ) ? $json : '' ) . '</pre></dd>';
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
	 * Check the signing secret: `whsec_` and base64 that decodes to 24 to 64 bytes. Empty is fine (no signing).
	 *
	 * @param mixed $value Clean value.
	 *
	 * @return true|\WP_Error
	 */
	public function check_signing_secret( $value ) {
		if ( is_string( $value ) && '' !== $value && null === self::decode_secret( $value ) ) {
			return new \WP_Error(
				'invalid_field',
				__( 'The signing secret must start with whsec_ followed by base64 text that decodes to 24 to 64 bytes. To make one, run this command and paste the result here and into the receiver:', 'wp-updates-notifier' )
				. ' printf \'whsec_%s\n\' "$(openssl rand -base64 32)"'
			);
		}

		return true;
	}

	/**
	 * Check the name of a signature header: a token of at most 64 characters, and not one the module sets itself.
	 *
	 * Empty is fine: the standard name is used.
	 *
	 * @param mixed $value Clean value.
	 *
	 * @return true|\WP_Error
	 */
	public function check_header_name( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return true;
		}
		if ( strlen( $value ) > self::MAX_NAME_LENGTH || ! self::is_token( $value ) ) {
			return new \WP_Error( 'invalid_field', __( 'A header name may only have letters, digits and these characters: ! # $ % & \' * + - . ^ _ ` | ~. It can be up to 64 characters long.', 'wp-updates-notifier' ) );
		}
		if ( in_array( strtolower( $value ), self::RESERVED_NAMES, true ) ) {
			return new \WP_Error( 'invalid_field', __( 'This header is set by the plugin and cannot be used here.', 'wp-updates-notifier' ) );
		}

		return true;
	}

	/**
	 * Check the extra headers line by line. An error names the line, never its value.
	 *
	 * @param mixed $value Clean value.
	 *
	 * @return true|\WP_Error
	 */
	public function check_headers( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return true;
		}

		$parsed = self::parse_headers( $value );
		if ( null === $parsed['error'] ) {
			return true;
		}

		$line = $parsed['error']['line'];
		switch ( $parsed['error']['code'] ) {
			case 'too_many':
				// translators: %d: Most lines.
				$message = sprintf( __( 'Use at most %d header lines.', 'wp-updates-notifier' ), self::MAX_HEADERS );
				break;
			case 'format':
				// translators: %d: Line number.
				$message = sprintf( __( 'Line %d must be written Name: value.', 'wp-updates-notifier' ), $line );
				break;
			case 'name':
				// translators: %d: Line number.
				$message = sprintf( __( 'The header name on line %d may only have letters, digits and these characters: ! # $ %% & \' * + - . ^ _ ` | ~.', 'wp-updates-notifier' ), $line );
				break;
			case 'reserved':
				// translators: %d: Line number.
				$message = sprintf( __( 'The header on line %d is set by the plugin and cannot be used here.', 'wp-updates-notifier' ), $line );
				break;
			case 'duplicate':
				// translators: %d: Line number.
				$message = sprintf( __( 'The header on line %d is already set on an earlier line.', 'wp-updates-notifier' ), $line );
				break;
			default:
				// translators: %d: Line number.
				$message = sprintf( __( 'Line %d needs a value without control characters.', 'wp-updates-notifier' ), $line );
		}

		return new \WP_Error( 'invalid_field', $message );
	}

	/**
	 * Clean and check the settings submitted for an instance.
	 *
	 * The default does the work for each field. This adds the checks that need several: the three signature header names
	 * differ, ignoring case, and none of them is also set in the extra headers. They apply only when the names are custom
	 * (see `applies_when`).
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 * @param array<string, mixed> $stored   The settings stored for the instance.
	 *
	 * @return array<string, mixed>|\WP_Error The settings to store, or an error naming the field at fault.
	 */
	public function validate_settings( array $settings, array $stored = array() ) {
		$clean = parent::validate_settings( $settings, $stored );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		// What the fields are worth: the clean value (a secret left empty has its stored value here), else the stored one.
		$view = $clean + $stored;
		if ( 'custom' !== ( $view['header_names'] ?? 'standard' ) ) {
			return $clean;
		}

		$extra = array();
		if ( isset( $view['headers'] ) && is_string( $view['headers'] ) ) {
			$extra = self::parse_headers( $view['headers'] )['names'];
		}

		$seen = array();
		foreach ( self::STANDARD_NAMES as $setting => $standard ) {
			$name = isset( $view[ $setting ] ) && is_string( $view[ $setting ] ) && '' !== trim( $view[ $setting ] ) ? trim( $view[ $setting ] ) : $standard;
			$key  = strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				return new \WP_Error( 'invalid_field', __( 'The three signature headers need different names.', 'wp-updates-notifier' ), array( 'field' => $setting ) );
			}
			if ( isset( $extra[ $key ] ) ) {
				// translators: %d: Line number of the extra headers.
				return new \WP_Error( 'invalid_field', sprintf( __( 'Line %d of the extra headers uses this name.', 'wp-updates-notifier' ), $extra[ $key ] ), array( 'field' => $setting ) );
			}
			$seen[ $key ] = true;
		}

		return $clean;
	}

	/**
	 * Send the notification to the instance's address.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The configured instance.
	 *
	 * @return Delivery_Result
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		if ( $notification->is_test() ) {
			$type = 'updates.test';
		} elseif ( $notification->is_reminder() ) {
			$type = 'updates.reminder';
		} else {
			$type = 'updates.available';
		}

		$items = array();
		foreach ( $notification->get_items() as $item ) {
			$items[] = self::item_data( $item );
		}

		return $this->deliver( $type, $notification, $instance, $items );
	}

	/**
	 * Keep a resolved item for the instance that reported it. Hooked to `sc_wpun_item_resolved`.
	 *
	 * Only instances of this channel are kept. The message goes out in send_resolved(), once the check has finished.
	 *
	 * @param Notification_Item $item     The item as it was reported.
	 * @param string            $how      `updated`, `removed` or `core`.
	 * @param Channel_Instance  $instance The instance that reported it.
	 *
	 * @return void
	 */
	public static function collect_resolved( Notification_Item $item, string $how, Channel_Instance $instance ): void {
		if ( self::TYPE !== $instance->get_type() ) {
			return;
		}

		self::$resolved[ $instance->get_id() ]    ??= array( $instance, array() );
		self::$resolved[ $instance->get_id() ][1][] = array( $item, $how );
	}

	/**
	 * Send one `updates.resolved` message per instance that collected items. Hooked to `sc_wpun_after_update_check`.
	 *
	 * Only instances that are on, have "Installed updates" ticked and are not waiting after a Retry-After or a deferred result
	 * get one. It is best effort: the check has forgotten the items already, so a failure is not retried. What was collected
	 * is always dropped, so nothing is sent late by a later check.
	 *
	 * @param mixed $notification The notification of the check, with every item that was waiting.
	 *
	 * @return void
	 */
	public static function send_resolved( $notification ): void {
		$collected      = self::$resolved;
		self::$resolved = array();
		if ( ! $notification instanceof Notification ) {
			return;
		}

		$channel = new self();
		foreach ( $collected as $id => $held ) {
			[$instance, $pairs] = $held;
			if ( ! $instance->is_enabled() || ! self::is_on( $instance->get_setting( 'send_resolved', true ) ) || null !== sc_wpun_instance_waiting_until( (string) $id ) ) {
				continue;
			}
			// The rules of the dispatcher: nothing goes out while the module is not registered, or a supplied value is not valid
			// (the stored value would then be used, which may be an address the site owner meant to replace).
			if ( array() !== sc_wpun_instance_problems( (string) $id ) ) {
				continue;
			}

			$items = array();
			$data  = array();
			foreach ( $pairs as [$item, $how] ) {
				$items[] = $item;
				$row     = self::item_data( $item );

				$row['resolution']  = $how;
				$row['notified_at'] = null === $item->get_notified_at() ? null : gmdate( 'c', $item->get_notified_at() );
				$data[]             = $row;
			}

			try {
				// The notification of the check, with the resolved items: site code that filters the payload or the headers sees the usual object.
				$result = $channel->deliver( 'updates.resolved', $notification->with_items( $items ), $instance, $data );
			} catch ( \Throwable $e ) {
				// A courtesy message must never break the update check. Only the class is kept: the text may hold the address.
				$result = Delivery_Result::failure( __( 'The message about installed updates could not be sent.', 'wp-updates-notifier' ), 'exception', null, null, array( 'exception' => get_class( $e ) ) );
			}

			/**
			 * Fires after the webhook channel tried to send an `updates.resolved` message, whether it worked or not.
			 *
			 * The message is best effort: the update check has forgotten the items by then, so a failure is not retried and
			 * is not shown on the Channels tab. Use this to log failures. It is not sent through the dispatcher, so
			 * `sc_wpun_pre_send`, `sc_wpun_notification_sent` and `sc_wpun_notification_failed` do not apply. It does not fire for
			 * an instance that is switched off, has "Installed updates" unticked, is waiting or has a problem (see
			 * `sc_wpun_instance_problems()`), nor in a dry run or a test send.
			 *
			 * The filters `sc_wpun_channel_payload` and `sc_wpun_webhook_headers` receive the notification of the check, copied
			 * with the resolved items.
			 *
			 * Example, log failures:
			 *
			 *     add_action(
			 *         'sc_wpun_webhook_resolved_sent',
			 *         function ( $result, $items, $instance ) {
			 *             if ( ! $result->is_success() ) {
			 *                 error_log( sprintf( 'Webhook %s: %s', $instance->get_id(), $result->get_message() ) );
			 *             }
			 *         },
			 *         10,
			 *         3
			 *     );
			 *
			 * @since 3.0.0
			 *
			 * @param Delivery_Result     $result   The result. It never holds the address, the signing secret or a header value.
			 * @param Notification_Item[] $items    The items that were installed or removed.
			 * @param Channel_Instance    $instance The webhook instance the message was sent through.
			 */
			do_action( 'sc_wpun_webhook_resolved_sent', $result, $items, $instance );
		}
	}

	/**
	 * Build, sign and post one message.
	 *
	 * The same path for every type of message, the resolved ones included.
	 *
	 * @param string                           $type         The message type, for example `updates.available`.
	 * @param Notification                     $notification What the filters see.
	 * @param Channel_Instance                 $instance     The instance.
	 * @param array<int, array<string, mixed>> $items The items of the message, as arrays.
	 *
	 * @return Delivery_Result
	 */
	private function deliver( string $type, Notification $notification, Channel_Instance $instance, array $items ): Delivery_Result {
		$url = $this->checked_url( $instance );
		if ( null === $url ) {
			return Delivery_Result::failure( __( 'No valid webhook address is set.', 'wp-updates-notifier' ), 'not_configured' );
		}

		// Settings can arrive by other ways than the form, so the secret and the headers are checked again before anything is signed or sent.
		$secret = $instance->get_setting( 'signing_secret', '' );
		$key    = null;
		if ( is_string( $secret ) && '' !== $secret ) {
			$key = self::decode_secret( $secret );
			if ( null === $key ) {
				return Delivery_Result::failure( __( 'The signing secret is not valid, so nothing was sent.', 'wp-updates-notifier' ), 'invalid_secret' );
			}
		}

		$extra      = array();
		$lines_text = $instance->get_setting( 'headers', '' );
		if ( is_string( $lines_text ) && '' !== $lines_text ) {
			$parsed = self::parse_headers( $lines_text );
			if ( null !== $parsed['error'] ) {
				return Delivery_Result::failure( __( 'The extra headers are not valid, so nothing was sent.', 'wp-updates-notifier' ), 'invalid_headers' );
			}
			$extra = $parsed['headers'];
		}

		$message = $this->build_message( $type, $notification, $instance, $items, $extra, $key );
		if ( null === $message ) {
			return Delivery_Result::failure( __( 'The message could not be turned into JSON, so nothing was sent.', 'wp-updates-notifier' ), 'encode_failed' );
		}
		$headers = $message['headers'];
		$names   = $message['names'];
		$body    = $message['body'];

		$response = $this->post( $url, $headers, $body, $this->timeout( $instance ) );

		// What a receiver may echo back: the secrets and the address, in every form we can think of. The header values are
		// those sent, so a credential added by the headers filter is removed too.
		$public  = self::public_headers( $names );
		$secrets = array( $url );
		foreach ( $headers as $name => $value ) {
			if ( ! in_array( strtolower( $name ), $public, true ) ) {
				$secrets[] = $value;
			}
		}
		if ( is_string( $secret ) ) {
			$secrets[] = $secret;
		}

		return $this->result( $response, $secrets );
	}

	/**
	 * The payload, the exact JSON body and the final headers of a message, as deliver() sends them. Sends nothing.
	 *
	 * @param string                $type         The message type, for example `updates.available`.
	 * @param Notification          $notification What is being delivered.
	 * @param Channel_Instance      $instance     The webhook instance.
	 * @param array<int, mixed>     $items        The items, as item_data() gives them.
	 * @param array<string, string> $extra        The extra headers, already parsed.
	 * @param string|null           $key          The decoded signing key, or null to leave the message unsigned.
	 *
	 * @return array{payload: mixed, body: string, headers: array<string, string>, names: array<string, string>}|null Null if the payload can not be encoded.
	 */
	private function build_message( string $type, Notification $notification, Channel_Instance $instance, array $items, array $extra, ?string $key ): ?array {
		// One time for the payload and the timestamp header, so they agree.
		$time     = $this->now();
		$payload  = array(
			'type'           => $type,
			'timestamp'      => gmdate( 'c', $time ),
			'schema_version' => self::SCHEMA_VERSION,
			'data'           => array(
				'site'         => array(
					'name'        => wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES ),
					'url'         => $notification->get_site_url(),
					'updates_url' => $notification->get_updates_url(),
					'network'     => sc_wpun_is_network(),
				),
				'notification' => array(
					'source'      => $notification->get_source(),
					'is_test'     => $notification->is_test(),
					'is_reminder' => $notification->is_reminder(),
				),
				'items'        => $items,
			),
		);
		$filtered = $this->filter_payload( $payload, $notification, $instance );
		$payload  = is_array( $filtered ) ? $filtered : $payload;

		// Encode once: these bytes are signed and these bytes are sent.
		$body = wp_json_encode( self::with_objects( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $body ) || '' === $body ) {
			return null;
		}

		$names = $this->header_names( $instance );
		$id    = $this->message_id();

		$headers                               = $extra + array(
			'Content-Type' => 'application/json',
			'User-Agent'   => 'WP-Updates-Notifier/' . self::plugin_version() . '; ' . home_url(),
		);
		$headers[ $names['id_header'] ]        = $id;
		$headers[ $names['timestamp_header'] ] = (string) $time;
		if ( null !== $key ) {
			$headers[ $names['signature_header'] ] = self::sign( $id, $time, $body, $key );
		}

		/**
		 * Filters the headers of a webhook message, after the signature headers are added.
		 *
		 * It runs last, on the final headers, just before the message is sent. The names of the headers are not part of what
		 * is signed, so renaming or adding headers is safe. The body cannot be changed here: use `sc_wpun_channel_payload_webhook`
		 * for that. An entry whose name is not a string, or whose value is not a string without a line break, is dropped, and a
		 * return value that is not an array is ignored.
		 *
		 * For an `updates.resolved` message `$notification` is the notification of the update check, copied with the items that
		 * were installed or removed.
		 *
		 * It also runs for the preview panel of the channel's form, with the notification source `preview`; nothing is sent.
		 *
		 * `$headers` and `$instance` hold secrets, such as the signature and the extra headers: don't log or dump them.
		 *
		 * Example, add a header:
		 *
		 *     add_filter(
		 *         'sc_wpun_webhook_headers',
		 *         function ( $headers, $notification, $instance, $body ) {
		 *             $headers['X-Site'] = 'production';
		 *             return $headers;
		 *         },
		 *         10,
		 *         4
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param array<string, string> $headers      The headers, by name.
		 * @param Notification          $notification What is being delivered.
		 * @param Channel_Instance      $instance     The webhook instance.
		 * @param string                $body         The exact JSON that is sent and signed.
		 */
		$filtered_headers = apply_filters( 'sc_wpun_webhook_headers', $headers, $notification, $instance, $body );
		if ( is_array( $filtered_headers ) ) {
			$headers = array();
			foreach ( $filtered_headers as $name => $value ) {
				if ( is_string( $name ) && is_string( $value ) && 1 !== preg_match( '/[\r\n]/', $name . $value ) ) {
					$headers[ $name ] = $value;
				}
			}
		}

		return array(
			'payload' => $payload,
			'body'    => $body,
			'headers' => $headers,
			'names'   => $names,
		);
	}

	/**
	 * The lower case names of the headers whose values are not secret: the ones the module sets itself.
	 *
	 * @param array<string, string> $names The names of the signature headers, from header_names().
	 *
	 * @return string[]
	 */
	private static function public_headers( array $names ): array {
		return array_map( 'strtolower', array( 'Content-Type', 'User-Agent', $names['id_header'], $names['timestamp_header'] ) );
	}

	/**
	 * Make the request. The only place a request is made.
	 *
	 * The function wp_safe_remote_post() refuses addresses on the server's own network, which stops a webhook address from being used to
	 * reach internal services, and ports other than 80, 443 and 8080. Redirects are not followed: a redirected POST would lose
	 * its body, and the signature would no longer match.
	 *
	 * @param string                $url     The address, checked by checked_url().
	 * @param array<string, string> $headers Headers.
	 * @param string                $body    The JSON.
	 * @param int                   $timeout Seconds.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function post( string $url, array $headers, string $body, int $timeout ) {
		return wp_safe_remote_post(
			$url,
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				// Only a short excerpt of the answer is kept, so a large one is not read in full.
				'limit_response_size' => 64 * KB_IN_BYTES,
				'headers'             => $headers,
				'body'                => $body,
			)
		);
	}

	/**
	 * Turn the answer of the HTTP API into a Delivery_Result.
	 *
	 * The messages say what happened, never the address, the secret or a header value.
	 *
	 * @param array<string, mixed>|\WP_Error $response What wp_safe_remote_post() returned.
	 * @param string[]                       $secrets  Texts to remove from what the receiver said.
	 *
	 * @return Delivery_Result
	 */
	private function result( $response, array $secrets ): Delivery_Result {
		$result = Delivery_Result::from_response( $response );
		$status = $result->get_status();

		// Success, a WP_Error and 429 are the helper's answer as it is.
		if ( null === $status || $result->is_success() || 429 === $status ) {
			return $result;
		}

		// A redirect is a failure: the POST would not be repeated with its body, and the signature would not match.
		if ( $status >= 300 && $status < 400 ) {
			return Delivery_Result::failure(
				// translators: %d: HTTP status code.
				sprintf( __( 'The webhook answered with a redirect (HTTP %d), which is not followed. Use the final address.', 'wp-updates-notifier' ), $status ),
				'redirected',
				$status
			);
		}

		// Same message and code as the helper's, plus what the receiver said.
		return Delivery_Result::failure(
			$result->get_message(),
			'bad_status',
			$status,
			null,
			array( 'body_excerpt' => self::excerpt( wp_remote_retrieve_body( $response ), $secrets ) )
		);
	}

	/**
	 * A short, plain text excerpt of what the receiver answered, for the site owner.
	 *
	 * Tags are removed, white space is collapsed and the length is capped. Secrets are removed first and again after each
	 * step that can change the text, in case the receiver echoed them back.
	 *
	 * @param string   $body    The answer.
	 * @param string[] $secrets The address, the signing secret and the values of the extra headers.
	 *
	 * @return string
	 */
	private static function excerpt( string $body, array $secrets ): string {
		$needles = array();
		foreach ( $secrets as $secret ) {
			$secret = (string) $secret;
			if ( '' === $secret ) {
				continue;
			}
			$needles[] = $secret;
			// The part that does the work: the token of "Bearer <token>", or the key of "whsec_<key>", which a receiver may print alone.
			$space = strpos( $secret, ' ' );
			if ( false !== $space && strlen( $secret ) - $space > 4 ) {
				$needles[] = substr( $secret, $space + 1 );
			}
			if ( 0 === strpos( $secret, 'whsec_' ) ) {
				$needles[] = substr( $secret, 6 );
			}
			// The address as the receiver may print it: JSON-escaped, or in parts. A bare "/" would hide every slash.
			$parts = wp_parse_url( $secret );
			if ( is_array( $parts ) && isset( $parts['host'] ) ) {
				$path  = $parts['path'] ?? '';
				$query = $parts['query'] ?? '';
				foreach ( array( $path . ( '' !== $query ? '?' . $query : '' ), $path, $query ) as $part ) {
					if ( strlen( $part ) > 1 ) {
						$needles[] = $part;
					}
				}
			}
		}
		$all = array();
		foreach ( $needles as $needle ) {
			$all[] = $needle;
			$all[] = str_replace( '/', '\\/', $needle );
			$all[] = htmlspecialchars( $needle, ENT_QUOTES );
			$all[] = rawurlencode( $needle );
		}
		$all = array_unique( array_filter( $all, static fn( $needle ) => strlen( $needle ) > 0 ) );
		// The longest first, so a short value inside a long one does not leave the rest behind.
		usort( $all, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );

		$redact = static fn( string $text ): string => str_ireplace( $all, '[removed]', $text );

		$text = wp_strip_all_tags( $redact( $body ) );
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $redact( $text ) ) );
		$text = $redact( $text );

		return mb_substr( $text, 0, self::MAX_EXCERPT );
	}

	/**
	 * The address of the instance, if it can be used: set, http(s) with a host, and https if the field says so.
	 *
	 * The settings screen and the supplied values are checked already; this covers values that arrive another way. The
	 * `https_only` key is read from the fields as they are now, so a site that turned it off with `sc_wpun_channel_fields`
	 * to allow http keeps working.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return string|null
	 */
	private function checked_url( Channel_Instance $instance ): ?string {
		$url = $instance->get_setting( 'url', '' );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || '' === $parts['host'] ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return null;
		}

		$fields = $this->get_form_fields();
		$https  = isset( $fields['url'] ) && is_array( $fields['url'] ) ? $fields['url']['https_only'] ?? false : false;
		if ( 'https' !== $scheme && true === $https ) {
			return null;
		}

		return $url;
	}

	/**
	 * The names of the signature headers in force: the standard ones, or the custom ones when the instance chose them.
	 *
	 * A custom name that is missing or not usable falls back to its standard name.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return array<string, string> By setting: `id_header`, `timestamp_header` and `signature_header`.
	 */
	private function header_names( Channel_Instance $instance ): array {
		$names = self::STANDARD_NAMES;
		if ( 'custom' !== $instance->get_setting( 'header_names', 'standard' ) ) {
			return $names;
		}

		$seen = array();
		foreach ( self::STANDARD_NAMES as $setting => $standard ) {
			$value = $instance->get_setting( $setting, '' );
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' !== $value && true === $this->check_header_name( $value ) && ! isset( $seen[ strtolower( $value ) ] ) ) {
				$names[ $setting ] = $value;
			}
			$seen[ strtolower( $names[ $setting ] ) ] = true;
		}

		return $names;
	}

	/**
	 * Seconds to wait for the receiver, within 1 and 10.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return int
	 */
	private function timeout( Channel_Instance $instance ): int {
		$timeout = $instance->get_setting( 'timeout', self::DEFAULT_TIMEOUT );

		return is_numeric( $timeout ) ? max( 1, min( self::MAX_TIMEOUT, (int) $timeout ) ) : self::DEFAULT_TIMEOUT;
	}

	/**
	 * The unique id of a message: `msg_` and a random id with no `.` in it. It is new for every message.
	 *
	 * Tests override it, to know what is signed.
	 *
	 * @return string
	 */
	protected function message_id(): string {
		return 'msg_' . str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * The time of the message, as a Unix time. Tests override it.
	 *
	 * @return int
	 */
	protected function now(): int {
		return time();
	}

	/**
	 * The Standard Webhooks signature of a message.
	 *
	 * @param string $id        The message id.
	 * @param int    $timestamp The Unix time in seconds.
	 * @param string $body      The exact body that is sent.
	 * @param string $key       The key: the decoded secret.
	 *
	 * @return string `v1,` and the base64 of HMAC-SHA256 over `{id}.{timestamp}.{body}`.
	 */
	public static function sign( string $id, int $timestamp, string $body, string $key ): string {
		return 'v1,' . base64_encode( hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $body, $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A signature, not obfuscation.
	}

	/**
	 * The key bytes of a `whsec_` secret.
	 *
	 * @param string $secret The secret: `whsec_` and base64.
	 *
	 * @return string|null The key, or null when the secret is not `whsec_` followed by base64 that decodes to 24 to 64 bytes.
	 */
	public static function decode_secret( string $secret ): ?string {
		if ( 0 !== strpos( $secret, 'whsec_' ) ) {
			return null;
		}

		$key = base64_decode( substr( $secret, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- A signing key, not obfuscation.

		return false !== $key && strlen( $key ) >= 24 && strlen( $key ) <= 64 ? $key : null;
	}

	/**
	 * Whether a text is an HTTP header name (an RFC 9110 token).
	 *
	 * @param string $name The text.
	 *
	 * @return bool
	 */
	private static function is_token( string $name ): bool {
		return 1 === preg_match( '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name );
	}

	/**
	 * Read the extra headers.
	 *
	 * @param string $lines_text One `Name: value` per line. Blank lines are skipped.
	 *
	 * @return array{headers: array<string, string>, names: array<string, int>, error: array{line: int, code: string}|null} The headers
	 *         by name, the line each name (in lower case) is on, and the first error with its 1-based line number.
	 */
	private static function parse_headers( string $lines_text ): array {
		$out = array(
			'headers' => array(),
			'names'   => array(),
			'error'   => null,
		);

		$count = 0;
		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $lines_text ) ) as $index => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$number = $index + 1;
			if ( ++$count > self::MAX_HEADERS ) {
				$out['error'] = array(
					'line' => $number,
					'code' => 'too_many',
				);
				return $out;
			}

			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				$out['error'] = array(
					'line' => $number,
					'code' => 'format',
				);
				return $out;
			}
			$name  = trim( substr( $line, 0, $colon ) );
			$value = trim( substr( $line, $colon + 1 ) );
			$key   = strtolower( $name );

			$code = null;
			if ( '' === $name || strlen( $name ) > self::MAX_NAME_LENGTH || ! self::is_token( $name ) ) {
				$code = 'name';
			} elseif ( in_array( $key, self::RESERVED_NAMES, true ) || in_array( $key, self::STANDARD_NAMES, true ) ) {
				$code = 'reserved';
			} elseif ( isset( $out['names'][ $key ] ) ) {
				$code = 'duplicate';
			} elseif ( '' === $value || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				$code = 'value';
			}
			if ( null !== $code ) {
				$out['error'] = array(
					'line' => $number,
					'code' => $code,
				);
				return $out;
			}

			$out['headers'][ $name ] = $value;
			$out['names'][ $key ]    = $number;
		}

		return $out;
	}

	/**
	 * An item as the array of the payload.
	 *
	 * @param Notification_Item $item The item.
	 *
	 * @return array<string, mixed>
	 */
	private static function item_data( Notification_Item $item ): array {
		return array(
			'type'            => $item->get_type(),
			'key'             => $item->get_key(),
			'name'            => $item->get_name(),
			'current_version' => $item->get_current_version(),
			'new_version'     => $item->get_new_version(),
			'links'           => $item->get_links(),
			'notes'           => $item->get_notes(),
			'flags'           => $item->get_flags(),
		);
	}

	/**
	 * Make the `links` and `flags` of the items JSON objects when they are empty, so they are `{}` and not `[]`.
	 *
	 * Done after the payload filters, which then work with plain arrays.
	 *
	 * @param array<mixed> $payload The payload.
	 *
	 * @return array<mixed>
	 */
	private static function with_objects( array $payload ): array {
		if ( isset( $payload['data']['items'] ) && is_array( $payload['data']['items'] ) ) {
			foreach ( $payload['data']['items'] as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				foreach ( array( 'links', 'flags' ) as $key ) {
					if ( isset( $item[ $key ] ) && array() === $item[ $key ] ) {
						$payload['data']['items'][ $index ][ $key ] = new \stdClass();
					}
				}
			}
		}

		return $payload;
	}

	/**
	 * Whether a setting is on. A missing setting is on; `false`, 0, '0' and '' are off.
	 *
	 * @param mixed $value The setting.
	 *
	 * @return bool
	 */
	private static function is_on( $value ): bool {
		return ! in_array( $value, array( false, 0, '0', '' ), true );
	}

	/**
	 * The version of the plugin, for the User-Agent.
	 *
	 * Read from the header of the main plugin file: the constant is not part of the public API.
	 *
	 * @return string
	 */
	private static function plugin_version(): string {
		static $version = null;
		if ( null === $version ) {
			$data    = get_file_data( dirname( __DIR__, 2 ) . '/wp-updates-notifier.php', array( 'Version' => 'Version' ) );
			$version = '' !== $data['Version'] ? $data['Version'] : '0';
		}

		return $version;
	}
}
