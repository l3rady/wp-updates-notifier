<?php
/**
 * Base class of notification channels.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * A way of delivering a notification: email, Slack, a webhook or anything an add-on provides.
 *
 * A channel is registered once, by type, through the `sc_wpun_register_channels`
 * action or sc_wpun_register_channel(). The site owner configures any number of
 * Channel_Instance objects of that type; send() is called once per instance.
 *
 * Field schema returned by get_fields(): an array keyed by the setting's name,
 * each entry an array with these keys, all optional except `type`:
 *
 * - `type` (string): text, textarea, url, email_list, secret, boolean, checkbox, enum, select or number.
 * - `label` (string): label of the field.
 * - `description` (string): help text.
 * - `default` (mixed): value used when nothing is stored.
 * - `required` (bool): the field must have a value.
 * - `secret` (bool): the value is never shown again once saved.
 * - `options` (array): key => label, for `enum` and `select`.
 * - `multiple` (bool): for `enum`, true when any number of the options can be chosen. The value is then a list of keys.
 * - `display` (string): for `boolean`, `checkbox` (default), `radio` or `select`. For `enum`, `select`, `radio` or
 *   `checkboxes`: a single choice cannot use checkboxes and several choices cannot use radios, and the default is radio
 *   (single) or checkboxes (several) for up to five options, a select above that.
 * - `labels` (array): for `boolean`, the `on` and `off` texts. `on` is the checkbox text and the "on" choice, and defaults to the
 *   label for a checkbox and to Yes otherwise; `off` defaults to No.
 * - `min`, `max` (int): for an `enum` with several choices, how many may be chosen.
 * - `sanitize_callback` (callable): receives the submitted value, returns the clean value.
 * - `validate_callback` (callable): receives the clean value, returns true or a WP_Error.
 *
 * A `boolean` is always `true` or `false`; a submitted or stored `1`, `0`, `'1'`, `'0'` or `''` (off) is accepted and anything else
 * is invalid. `checkbox` is an alias of `boolean` with the checkbox display. Supplied from outside the database, `1`, `true`,
 * `yes` and `on` are on, and `0`, `false`, `no`, `off` and an empty value are off, in any case.
 *
 * An `enum` stores the key of the option, never its position, so options can be added, removed or reordered.
 * Supplied from outside the database (constants, environment variables), a single choice is the key and a list is
 * comma-separated, for example `core,plugin`; one unknown key makes the whole value unusable. `select` is an alias
 * of a single-choice `enum` shown as a select.
 *
 * @since 2.0.0
 */
abstract class Channel {
	/**
	 * Unique identifier of the channel: lower case letters, digits and underscores.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	abstract public function get_type(): string;

	/**
	 * Name shown to the site owner.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	abstract public function get_label(): string;

	/**
	 * Deliver a notification through one configured instance.
	 *
	 * Return a Delivery_Result. An exception, or any other return value, is treated as a failure.
	 *
	 * @since 2.0.0
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The configured instance to deliver through.
	 *
	 * @return Delivery_Result
	 */
	abstract public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result;

	/**
	 * One sentence about the channel.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * The settings of an instance, described by the field schema in the class description.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array();
	}

	/**
	 * Clean and check the settings submitted for an instance.
	 *
	 * The default keeps the fields declared by get_fields(), runs each field's
	 * `sanitize_callback` and then checks a `boolean` field is an on or off value (anything else returns a
	 * WP_Error naming the field) and an `enum` field against its options: a single
	 * choice that is not an option, or a list with too few or too many choices, returns a
	 * WP_Error naming the field; unknown keys in a list are dropped. A channel can
	 * override it to check more, and return a WP_Error whose error data names the field
	 * at fault.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 *
	 * @return array<string, mixed>|\WP_Error The settings to store.
	 */
	public function validate_settings( array $settings ) {
		$clean = array();

		foreach ( $this->get_fields() as $name => $field ) {
			if ( ! array_key_exists( $name, $settings ) ) {
				continue;
			}
			$value = $settings[ $name ];
			if ( isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] ) ) {
				$value = call_user_func( $field['sanitize_callback'], $value );
			}
			if ( Boolean_Field::is_boolean( $field ) ) {
				$checked = Boolean_Field::sanitize( $value, $field );
				if ( ! $checked['ok'] ) {
					return new \WP_Error(
						'invalid_field',
						sprintf(
							// translators: %s: Name of the field.
							__( 'The value for "%s" is not valid.', 'wp-updates-notifier' ),
							isset( $field['label'] ) && is_string( $field['label'] ) && '' !== $field['label'] ? $field['label'] : $name
						),
						array( 'field' => $name )
					);
				}
				$value = $checked['value'];
			} elseif ( Enum_Field::is_enum( $field ) ) {
				$checked = Enum_Field::sanitize( $value, $field );
				if ( ! $checked['ok'] ) {
					return new \WP_Error(
						'invalid_field',
						sprintf(
							// translators: %s: Name of the field.
							__( 'The choice for "%s" is not valid.', 'wp-updates-notifier' ),
							isset( $field['label'] ) && is_string( $field['label'] ) && '' !== $field['label'] ? $field['label'] : $name
						),
						array( 'field' => $name )
					);
				}
				$value = $checked['value'];
			}
			$clean[ $name ] = $value;
		}

		return $clean;
	}

	/**
	 * Whether the channel supports a feature.
	 *
	 * @since 2.0.0
	 *
	 * @param string $feature Feature name.
	 *
	 * @return bool
	 */
	public function supports( string $feature ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Overridable default.
		return false;
	}

	/**
	 * Markup of the settings form of an instance, for a channel that draws its own.
	 *
	 * @since 2.0.0
	 *
	 * @param Channel_Instance $instance The instance being edited.
	 *
	 * @return string|null The markup, or null to get the generic form built from get_fields().
	 */
	public function render_settings( Channel_Instance $instance ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Overridable default.
		return null;
	}

	/**
	 * Let site code change the formatted payload just before the channel sends it.
	 *
	 * A channel calls this with whatever it is about to send (an array for an HTTP body, say)
	 * and sends what comes back. It must check that the value still has the shape it needs,
	 * because a filter can return anything.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed            $payload      What the channel is about to send.
	 * @param Notification     $notification What is being delivered.
	 * @param Channel_Instance $instance     The instance it is delivered through.
	 *
	 * @return mixed The payload to send.
	 */
	protected function filter_payload( $payload, Notification $notification, Channel_Instance $instance ) {
		/**
		 * Filters the payload of any channel just before it is sent.
		 *
		 * The shape of the payload is up to the channel; see its documentation. The email
		 * channel uses `array( 'to' => string[], 'subject' => string, 'message' => string, 'headers' => string[] )`.
		 *
		 * Example, add a recipient to every email:
		 *
		 *     add_filter(
		 *         'sc_wpun_channel_payload',
		 *         function ( $payload, $notification, $instance ) {
		 *             if ( 'email' === $instance->get_type() ) {
		 *                 $payload['to'][] = 'archive@example.org';
		 *             }
		 *             return $payload;
		 *         },
		 *         10,
		 *         3
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param mixed            $payload      The payload.
		 * @param Notification     $notification What is being delivered.
		 * @param Channel_Instance $instance     The instance it is delivered through.
		 */
		$payload = apply_filters( 'sc_wpun_channel_payload', $payload, $notification, $instance );

		/**
		 * Filters the payload of the channels of one type just before it is sent.
		 *
		 * The dynamic part, `$type`, is the channel type, for example `email`. The arguments are the
		 * same as for `sc_wpun_channel_payload`.
		 *
		 * @since 2.0.0
		 *
		 * @param mixed            $payload      The payload.
		 * @param Notification     $notification What is being delivered.
		 * @param Channel_Instance $instance     The instance it is delivered through.
		 */
		return apply_filters( "sc_wpun_channel_payload_{$this->get_type()}", $payload, $notification, $instance );
	}
}
