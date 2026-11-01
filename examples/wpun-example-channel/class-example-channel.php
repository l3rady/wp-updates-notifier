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
 *   Notification_Renderer does it for you when you pass it the limits.
 * - Never put a secret, such as the webhook address, in a result, a notice or a log. They are shown to
 *   administrators and written to the stored state.
 * - Declare the API version you need in get_api_constraint(), so the plugin refuses the channel on a site that is too old.
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
	 * The API versions this channel needs. The registry refuses the channel, and tells the site owner, if the site's API is older.
	 *
	 * REQUIRED_API is defined in the main plugin file, which is loaded before this class is used.
	 *
	 * @return string
	 */
	public function get_api_constraint(): string {
		return REQUIRED_API;
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
			// `group` draws it inside the group declared by get_field_groups(). A field can also say when it is used, for example
			// `'applies_when' => array( 'mode' => 'app' )`: it then gets a note, and `required` is enforced only when the condition holds.
			'username'    => array(
				'group'             => 'appearance',
				'type'              => 'text',
				'label'             => __( 'Name to post as', 'wpun-example-channel' ),
				'description'       => __( 'Optional. Leave empty to use the name set up in the chat service.', 'wpun-example-channel' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * The groups the fields can be split in. A long form is easier to read as a few labelled groups.
	 * A group is a fieldset with a legend, and `'collapsed' => true` would draw it closed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_field_groups(): array {
		return array(
			'appearance' => array(
				'label' => __( 'Appearance', 'wpun-example-channel' ),
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

		// The renderer lists as many updates as the limits allow and says how many were left out ("...and 12 more").
		// The plugin counts every item as delivered when send() succeeds, which is why the message has to say so.
		return Notification_Renderer::plain_text( $notification, self::MAX_LENGTH, self::MAX_ITEMS );
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

		// 429 Too Many Requests: the service says how long to wait. Pass it on: the plugin then skips this
		// channel until then (at most 7 days). The updates stay unsent and go out on the first check after.
		if ( 429 === $status ) {
			return Delivery_Result::failure(
				// translators: %d: HTTP status code.
				sprintf( __( 'The webhook is limiting requests (HTTP %d).', 'wpun-example-channel' ), $status ),
				'rate_limited',
				$status,
				Delivery_Result::retry_after_from_response( $response )
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
