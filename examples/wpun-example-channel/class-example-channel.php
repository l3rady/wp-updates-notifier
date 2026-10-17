<?php
/**
 * The example channel.
 *
 * @package wpun-example-channel
 */

namespace WPUN_Example_Channel;

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;
use WPUN\Notification_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Posts a notification as JSON to a chat webhook.
 *
 * This is a reference for people writing their own channel. It only uses the public extension API: Channel,
 * Channel_Instance, Notification, Notification_Renderer and Delivery_Result. It follows the rules every
 * channel module should:
 *
 * - Describe the settings in get_fields(). The plugin draws the form, checks what is submitted and stores it,
 *   so there is no form code and no validate_settings() here. A `secret` field is never shown again once saved.
 * - Deliver in send(), and always return a Delivery_Result. Do not throw. A failure is retried on the next run.
 * - Use the WordPress HTTP API with a short timeout, and wp_safe_remote_post() for an address the site owner typed in.
 * - Tell the plugin when the service asked to slow down (HTTP 429), with the number of seconds from `Retry-After`.
 * - Respect the limits of the service: send a summary ("...and 12 more") instead of a message that is too long.
 * - Never put a secret, such as the webhook address, in a result, a notice or a log. They are shown to
 *   administrators and written to the stored state.
 * - Let site code change the payload with filter_payload(), and check it is still what you can send.
 *
 * @since 1.0.0
 */
class Example_Channel extends Channel {

	/**
	 * The channel type: lower case letters, digits and underscores, unique among all channels.
	 */
	const TYPE = 'example';

	/**
	 * The longest message to send, in characters. Chat services refuse a message above their limit.
	 */
	const MAX_LENGTH = 4000;

	/**
	 * The most updates to list in a message, however short they are.
	 */
	const MAX_ITEMS = 50;

	/**
	 * Seconds to wait for the service before giving up. A slow service must not hold up the update check.
	 */
	const TIMEOUT = 10;

	/**
	 * Channel type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * Name shown to the site owner.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Example chat (developer demo)', 'wpun-example-channel' );
	}

	/**
	 * One sentence about the channel.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Posts the notification as JSON to a webhook address. Shows how to write a channel.', 'wpun-example-channel' );
	}

	/**
	 * The settings of an instance, as a field schema. The schema is documented on the Channel class.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			// A webhook address is a secret: anyone who has it can post to the chat. `secret` => true keeps the saved value
			// out of the form (it shows as saved, and leaving it empty keeps it), and `required` makes the form insist on one.
			// The `url` type refuses anything that is not an http or https address.
			'webhook_url' => array(
				'type'        => 'url',
				'label'       => __( 'Webhook address', 'wpun-example-channel' ),
				'description' => __( 'The address the chat service gave you to post messages to.', 'wpun-example-channel' ),
				'required'    => true,
				'secret'      => true,
			),
			// An ordinary optional field. The sanitiser keeps what the user typed free of tags and line breaks.
			'username'    => array(
				'type'              => 'text',
				'label'             => __( 'Name to post as', 'wpun-example-channel' ),
				'description'       => __( 'Optional. Leave empty to use the name set up in the chat service.', 'wpun-example-channel' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Whether the channel supports a feature.
	 *
	 * `test_message` adds a "Send test message" button for instances of this channel. send() then gets a
	 * notification whose is_test() is true.
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return 'test_message' === $feature;
	}

	/**
	 * Deliver the notification through one instance.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The configured instance: its settings are the fields above.
	 * @return Delivery_Result
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		$url = $instance->get_setting( 'webhook_url', '' );
		if ( ! is_string( $url ) || '' === $url ) {
			return Delivery_Result::failure( __( 'No webhook address is set.', 'wpun-example-channel' ), 'not_configured' );
		}

		$payload = array( 'text' => $this->message( $notification ) );
		$name    = $instance->get_setting( 'username', '' );
		if ( is_string( $name ) && '' !== $name ) {
			$payload['username'] = $name;
		}

		// Site code may still change what is sent, for example with `sc_wpun_channel_payload_example`.
		// A filter can return anything, so anything that is not an array is ignored.
		$filtered = $this->filter_payload( $payload, $notification, $instance );
		$payload  = is_array( $filtered ) ? $filtered : $payload;

		// wp_safe_remote_post() refuses addresses on the server's own network, which stops a webhook address
		// from being used to reach internal services.
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $payload ),
			)
		);

		return $this->result( $response );
	}

	/**
	 * The text to post.
	 *
	 * @param Notification $notification The notification.
	 * @return string
	 */
	private function message( Notification $notification ): string {
		$site = wp_specialchars_decode( $notification->get_site_name(), ENT_QUOTES );

		if ( $notification->is_test() ) {
			// translators: 1: Site name. 2: Site address.
			return sprintf( __( 'This is a test message from WP Updates Notifier for %1$s (%2$s).', 'wpun-example-channel' ), $site, $notification->get_site_url() );
		}

		// Start with as many updates as the item limit allows, and show fewer until the message fits.
		// with_items() returns a copy of the notification with only the items given, so the site name, the links
		// and the closing line stay. The plugin counts every item as delivered when send() succeeds, which is why
		// the message has to say how many were left out.
		$items = $notification->get_items();
		$shown = min( count( $items ), self::MAX_ITEMS );

		while ( true ) {
			$text = Notification_Renderer::plain_text( $notification->with_items( array_slice( $items, 0, $shown ) ) );
			$left = count( $items ) - $shown;
			if ( $left > 0 ) {
				$text .= "\n" . $this->more_line( $left );
			}

			if ( 0 === $shown || mb_strlen( $text ) <= self::MAX_LENGTH ) {
				return $text;
			}
			--$shown;
		}
	}

	/**
	 * The line that says how many updates were left out of the message.
	 *
	 * @param int $count How many.
	 * @return string
	 */
	private function more_line( int $count ): string {
		// translators: %d: Number of updates that are not listed in the message.
		return sprintf( _n( '…and %d more', '…and %d more', $count, 'wpun-example-channel' ), $count );
	}

	/**
	 * Turn the answer of the HTTP API into a Delivery_Result.
	 *
	 * The messages say what happened, never which address was used.
	 *
	 * @param array<string, mixed>|\WP_Error $response What wp_safe_remote_post() returned.
	 * @return Delivery_Result
	 */
	private function result( $response ): Delivery_Result {
		// The request did not complete: no connection, a timeout, or an address that is not allowed.
		// The error message is not used, because cURL puts the address in some of them. The code is safe.
		if ( is_wp_error( $response ) ) {
			return Delivery_Result::failure(
				__( 'The request to the webhook failed.', 'wpun-example-channel' ),
				'http_error',
				null,
				null,
				array( 'error_code' => (string) $response->get_error_code() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return Delivery_Result::success();
		}

		// 429 Too Many Requests: the service says how many seconds to wait. Pass it on: the plugin keeps it with
		// the result. Like any failure, the updates stay unsent and go out again on the next scheduled check.
		if ( 429 === $status ) {
			$wait = wp_remote_retrieve_header( $response, 'retry-after' );

			return Delivery_Result::failure(
				// translators: %d: HTTP status code.
				sprintf( __( 'The webhook is limiting requests (HTTP %d).', 'wpun-example-channel' ), $status ),
				'rate_limited',
				$status,
				is_string( $wait ) && ctype_digit( $wait ) ? (int) $wait : null
			);
		}

		return Delivery_Result::failure(
			// translators: %d: HTTP status code.
			sprintf( __( 'The webhook answered with HTTP %d.', 'wpun-example-channel' ), $status ),
			'bad_status',
			$status
		);
	}
}
