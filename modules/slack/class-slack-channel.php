<?php
/**
 * The Slack channel.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Modules\Slack;

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Posts a notification to a Slack incoming webhook.
 *
 * A channel that talks to a web service, like the webhook module, and it only uses the public extension API. It has:
 *
 * - A secret: the webhook address carries the token, so it is never shown again once saved and never reaches a result,
 *   a notice or a log. Not even the error of the HTTP API is passed on, as it can contain the address.
 * - A host allowlist, checked when the settings are saved and again before every request, as an address can reach the
 *   channel without being saved through the form (an upgrade from 1.x, a constant or an environment variable).
 * - A collapsed field group for the settings only some webhooks use.
 * - Safe HTTP: wp_safe_remote_post(), a short timeout, no retries, and HTTP 429 with `Retry-After`.
 * - Slack's answers: a webhook answers with a short word (`no_service`, `channel_not_found`) that is turned into a message
 *   the site owner can act on.
 *
 * Settings, which are permanent keys in the database:
 *
 * - `webhook_url`: the address Slack gave for the webhook. Only https, and only on the hosts of the allowlist.
 * - `channel`, `username`, `icon`: optional, in the collapsed group "legacy". Only legacy webhooks honour them. A webhook
 *   of a Slack app always posts as the app, to the channel chosen when the webhook was created. `icon` is `:emoji_name:`
 *   (sent as `icon_emoji`) or an https address of an image (sent as `icon_url`).
 *
 * The payload is the message of Slack_Message (`text`, `blocks`, `unfurl_links` and `unfurl_media`) plus `channel`,
 * `username` and `icon_emoji` or `icon_url`, each only when set. The `sc_wpun_channel_payload` and
 * `sc_wpun_channel_payload_slack` filters can change it; what comes back must still be an array with `blocks` or `text`.
 *
 * Hooks:
 *
 * - `sc_wpun_slack_allowed_hosts` ( string[] $hosts ): the host names a webhook address may have, see allowed_hosts().
 *
 * Besides the settings the instance has its label, switch and routing, which WP Updates Notifier adds to every channel.
 * A site that was upgraded from WP Updates Notifier 1.x has an instance with the id and type `slack` and the settings
 * `webhook_url` and `channel`: it is paused until this module is registered, and then delivers without being set up again.
 */
class Slack_Channel extends Channel {

	/**
	 * Channel type. It is stored with every instance, so it never changes.
	 */
	const TYPE = 'slack';

	/**
	 * Longest wait for Slack, in seconds. The check delivers to every instance in turn, during a cron request.
	 */
	const TIMEOUT = 10;

	/**
	 * What Slack answers when it refuses a message, and what to do about it.
	 *
	 * Slack answers a webhook with a short word in the body. The keys are those words. The messages are in
	 * error_message(), where they can be translated.
	 */
	const KNOWN_ERRORS = array(
		'invalid_payload',
		'action_prohibited',
		'posting_to_general_channel_denied',
		'channel_not_found',
		'user_not_found',
		'channel_is_archived',
		'no_service',
		'no_service_id',
		'no_active_hooks',
		'no_team',
		'team_disabled',
		'invalid_token',
		'too_many_attachments',
		'no_text',
	);

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
		return __( 'Slack', 'wp-updates-notifier' );
	}

	/**
	 * One sentence about the channel.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Posts the notification to a Slack channel through an incoming webhook.', 'wp-updates-notifier' );
	}

	/**
	 * The settings of an instance.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			// The address carries the token, so it is a secret. `https_only` refuses http://, and the callback the other hosts.
			'webhook_url' => array(
				'type'              => 'url',
				'label'             => __( 'Webhook address', 'wp-updates-notifier' ),
				'description'       => __( 'The address of an incoming webhook. In Slack, open api.slack.com/apps, create or choose an app, turn on Incoming Webhooks and add a webhook to a channel. Then copy its address, which starts with https://hooks.slack.com/services/.', 'wp-updates-notifier' ),
				'required'          => true,
				'secret'            => true,
				'https_only'        => true,
				'validate_callback' => array( $this, 'check_webhook_url' ),
			),
			'channel'     => array(
				'group'       => 'legacy',
				'type'        => 'text',
				'label'       => __( 'Channel', 'wp-updates-notifier' ),
				'description' => __( 'Optional. A channel such as #ops, or @name for a direct message, to post to instead of the channel of the webhook.', 'wp-updates-notifier' ),
			),
			'username'    => array(
				'group'       => 'legacy',
				'type'        => 'text',
				'label'       => __( 'Name to post as', 'wp-updates-notifier' ),
				'description' => __( 'Optional. The name the messages come from.', 'wp-updates-notifier' ),
			),
			'icon'        => array(
				'group'             => 'legacy',
				'type'              => 'text',
				'label'             => __( 'Icon', 'wp-updates-notifier' ),
				'description'       => __( 'Optional. An emoji such as :robot_face:, or the https:// address of an image.', 'wp-updates-notifier' ),
				'validate_callback' => array( $this, 'check_icon' ),
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
			'legacy' => array(
				'label'       => __( 'Name, icon and channel', 'wp-updates-notifier' ),
				'description' => __( 'Only legacy webhooks honour these. Webhooks of Slack apps always post as the app, to the channel chosen when the webhook was created.', 'wp-updates-notifier' ),
				'collapsed'   => true,
			),
		);
	}

	/**
	 * Whether the channel supports a feature: it sends test messages.
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return 'test_message' === $feature;
	}

	/**
	 * The host names a webhook address may have.
	 *
	 * By default `hooks.slack.com` and `hooks.slack-gov.com` (GovSlack). The filter `sc_wpun_slack_allowed_hosts` changes
	 * the list, for example for a Slack-compatible service or a proxy of your own. A host is allowed when it is the same as
	 * one in the list, ignoring case; `*.example.org` is not a pattern. The port and any user name in the address do not count.
	 *
	 * @return string[] Lower case host names.
	 */
	public static function allowed_hosts(): array {
		/**
		 * Filters the host names a Slack webhook address may have.
		 *
		 * The list is checked when the settings are saved and again before every request.
		 * A host is allowed when it is the same as one in the list, ignoring case; `*.example.org` is not a pattern.
		 * The port and any user name in the address do not count. Only https addresses are accepted.
		 *
		 * Example, also allow a proxy:
		 *
		 *     add_filter(
		 *         'sc_wpun_slack_allowed_hosts',
		 *         function ( $hosts ) {
		 *             $hosts[] = 'slack-proxy.example.org';
		 *             return $hosts;
		 *         }
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param string[] $hosts Host names, by default `hooks.slack.com` and `hooks.slack-gov.com`.
		 */
		$hosts = apply_filters( 'sc_wpun_slack_allowed_hosts', array( 'hooks.slack.com', 'hooks.slack-gov.com' ) );

		$clean = array();
		foreach ( is_array( $hosts ) ? $hosts : array() as $host ) {
			if ( is_string( $host ) && '' !== trim( $host ) ) {
				$clean[] = strtolower( trim( $host ) );
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whether an address can be used: https, with a host of the allowlist.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function is_allowed_url( string $url ): bool {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return false;
		}

		return 'https' === strtolower( $parts['scheme'] ) && in_array( strtolower( $parts['host'] ), self::allowed_hosts(), true );
	}

	/**
	 * Check that the host of the webhook address is on the allowlist. The message never repeats the address.
	 *
	 * @param mixed $value Clean value.
	 * @return true|\WP_Error
	 */
	public function check_webhook_url( $value ) {
		if ( ! is_string( $value ) || '' === $value || self::is_allowed_url( $value ) ) {
			return true;
		}

		return new \WP_Error(
			'invalid_field',
			sprintf(
				// translators: %s: Host names such as hooks.slack.com, separated by commas.
				__( 'This is not the address of a Slack webhook. It must be an https:// address on one of these hosts: %s.', 'wp-updates-notifier' ),
				implode( ', ', self::allowed_hosts() )
			)
		);
	}

	/**
	 * Check the icon: empty, an emoji written :name:, or an https address.
	 *
	 * @param mixed $value Clean value.
	 * @return true|\WP_Error
	 */
	public function check_icon( $value ) {
		if ( ! is_string( $value ) || '' === $value || null !== $this->icon_payload( $value ) ) {
			return true;
		}

		return new \WP_Error( 'invalid_field', __( 'The icon must be an emoji written like :robot_face:, or an https:// address.', 'wp-updates-notifier' ) );
	}

	/**
	 * The payload entry for an icon: `icon_emoji` for :emoji_name:, `icon_url` for an https address, null for anything else.
	 *
	 * @param string $icon The icon setting.
	 * @return array<string, string>|null
	 */
	private function icon_payload( string $icon ): ?array {
		$icon = trim( $icon );
		if ( 1 === preg_match( '/^:[a-z0-9_+\-]+(::skin-tone-[2-6])?:$/i', $icon ) ) {
			return array( 'icon_emoji' => $icon );
		}

		$parts = wp_parse_url( $icon );
		if ( is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( $parts['scheme'] ) && '' !== $parts['host'] ) {
			return array( 'icon_url' => esc_url_raw( $icon, array( 'https' ) ) );
		}

		return null;
	}

	/**
	 * Post the notification to the webhook.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The configured instance.
	 * @return Delivery_Result
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		$url = $instance->get_setting( 'webhook_url', '' );
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return Delivery_Result::failure( __( 'No Slack webhook address is set.', 'wp-updates-notifier' ), 'not_configured' );
		}

		// Checked again here: an address from 1.x, a constant or an environment variable never went through the form.
		if ( ! self::is_allowed_url( $url ) ) {
			return Delivery_Result::failure(
				__( 'The Slack webhook address is not usable: it must be an https:// address on an allowed host such as hooks.slack.com.', 'wp-updates-notifier' ),
				'invalid_url'
			);
		}

		$payload = $this->filter_payload( $this->payload( $notification, $instance ), $notification, $instance );
		$body    = is_array( $payload ) && ( ! empty( $payload['blocks'] ) || ( isset( $payload['text'] ) && is_string( $payload['text'] ) && '' !== $payload['text'] ) ) ? wp_json_encode( $payload ) : false;
		if ( false === $body ) {
			return Delivery_Result::failure( __( 'A filter changed the Slack message into something that cannot be sent. It must be an array with blocks or text.', 'wp-updates-notifier' ), 'bad_payload' );
		}

		$response = wp_safe_remote_post(
			trim( $url ),
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => 64 * KB_IN_BYTES,
				'headers'             => array( 'Content-Type' => 'application/json' ),
				'body'                => $body,
			)
		);

		return $this->result( $response );
	}

	/**
	 * The payload: the message, and the name, icon and channel when they are set.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The instance.
	 * @return array<string, mixed>
	 */
	private function payload( Notification $notification, Channel_Instance $instance ): array {
		$payload = Slack_Message::build( $notification );

		foreach ( array( 'channel', 'username' ) as $key ) {
			$value = $instance->get_setting( $key, '' );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$payload[ $key ] = trim( $value );
			}
		}

		$icon = $instance->get_setting( 'icon', '' );
		if ( is_string( $icon ) && '' !== trim( $icon ) ) {
			return array_merge( $payload, $this->icon_payload( $icon ) ?? array() );
		}

		return $payload;
	}

	/**
	 * Turn the answer of the HTTP API into a Delivery_Result.
	 *
	 * Delivery_Result::from_response() does the work: a request that did not complete, a 2xx answer, 429 with `Retry-After`
	 * and any other status. Only one answer is Slack's own: a refusal with a known error word in the body, which becomes
	 * a message the site owner can act on. The messages say what happened, never the address or what the HTTP API said about it.
	 *
	 * @param array<string, mixed>|\WP_Error $response What wp_safe_remote_post() returned.
	 * @return Delivery_Result
	 */
	private function result( $response ): Delivery_Result {
		$result = Delivery_Result::from_response( $response );
		if ( $result->is_success() ) {
			return Delivery_Result::success( __( 'The message was posted to Slack.', 'wp-updates-notifier' ) );
		}
		if ( is_wp_error( $response ) ) {
			// The code of the error comes from whoever raised it, so it is only kept when it looks like a code.
			$code = $result->get_context()['error_code'] ?? '';

			return 1 === preg_match( '/^[a-z0-9_]{1,64}$/i', (string) $code ) ? $result : Delivery_Result::failure( $result->get_message(), $result->get_code() );
		}
		if ( 'bad_status' !== $result->get_code() ) {
			return $result;
		}

		// Slack answers with a short word such as `no_service`. Anything else, such as an HTML page, is not repeated.
		$word = trim( wp_remote_retrieve_body( $response ) );
		if ( 1 === preg_match( '/^[a-z0-9_]{1,64}$/i', $word ) && in_array( $word, self::KNOWN_ERRORS, true ) ) {
			return Delivery_Result::failure(
				$this->error_message( $word ),
				$word,
				$result->get_status(),
				null,
				array( 'error_code' => $word )
			);
		}

		return $result;
	}

	/**
	 * What to tell the site owner about an error word of Slack.
	 *
	 * @param string $code One of KNOWN_ERRORS.
	 * @return string
	 */
	private function error_message( string $code ): string {
		switch ( $code ) {
			case 'invalid_payload':
				return __( 'Slack could not read the message (invalid_payload). If a filter changes the Slack message, check it.', 'wp-updates-notifier' );
			case 'action_prohibited':
				return __( 'A Slack administrator has blocked this webhook from posting (action_prohibited). Ask a workspace administrator to allow it.', 'wp-updates-notifier' );
			case 'posting_to_general_channel_denied':
				return __( 'Only administrators may post to the #general channel of this workspace (posting_to_general_channel_denied). Choose another channel.', 'wp-updates-notifier' );
			case 'channel_not_found':
				return __( 'Slack cannot find the channel (channel_not_found). Check the channel setting, or clear it to post to the channel of the webhook.', 'wp-updates-notifier' );
			case 'user_not_found':
				return __( 'Slack cannot find the person set as the channel (user_not_found). Check the channel setting, or clear it.', 'wp-updates-notifier' );
			case 'channel_is_archived':
				return __( 'The channel is archived (channel_is_archived). Unarchive it, or create a webhook for another channel.', 'wp-updates-notifier' );
			case 'no_service':
				return __( 'Slack no longer knows this webhook (no_service). It was probably revoked or its app was removed. Create a new webhook and save its address.', 'wp-updates-notifier' );
			case 'no_service_id':
				return __( 'The webhook address is not complete (no_service_id). Copy it again from Slack.', 'wp-updates-notifier' );
			case 'no_active_hooks':
				return __( 'Incoming webhooks are turned off for the Slack app (no_active_hooks). Turn them on in the settings of the app.', 'wp-updates-notifier' );
			case 'no_team':
				return __( 'Slack cannot find the workspace of this webhook (no_team). Create a new webhook and save its address.', 'wp-updates-notifier' );
			case 'team_disabled':
				return __( 'The Slack workspace of this webhook has been disabled (team_disabled).', 'wp-updates-notifier' );
			case 'invalid_token':
				return __( 'Slack does not accept the token in the webhook address (invalid_token). Create a new webhook and save its address.', 'wp-updates-notifier' );
			case 'too_many_attachments':
				return __( 'The message has too many attachments (too_many_attachments). If a filter adds attachments, check it.', 'wp-updates-notifier' );
			default:
				return __( 'Slack found no text to post (no_text). If a filter changes the Slack message, check it.', 'wp-updates-notifier' );
		}
	}
}
