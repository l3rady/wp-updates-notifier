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
 * - `type` (string): a value type, see below: `text`, `textarea`, `url`, `email_list`, `secret`, `number`, `boolean` or `enum`.
 *   `checkbox` and `select` are aliases of `boolean` and `enum`. `radio`, `select` and `multiselect` are displays, not types.
 * - `label` (string): label of the field.
 * - `description` (string): help text.
 * - `default` (mixed): value used when nothing is stored.
 * - `required` (bool): the field must have a value. A `false` boolean and a `0` number count as values.
 *   The form also marks the field: "(required)" after its label (not for a boolean, which is always valid) and `aria-required="true"` on its
 *   control. A custom field type's renderer gets the schema and can read `required` to do the same.
 * - `secret` (bool): the value is never shown again once saved, like a `secret`. Works for every type, including added ones, whose renderer then
 *   only gets `'1'` (a value is saved) or `''`.
 * - `show_external` (bool): show the value on the edit screen when it comes from outside the database (a constant, an environment variable or a
 *   source). By default such a value is not printed, only "Set by ...", for every type except `number`, `boolean` and `enum`, added types included.
 *   Don't use it for secrets.
 * - `options` (array): key => label, for `enum` and `select`.
 * - `multiple` (bool): for `enum`, true when any number of the options can be chosen. The value is then a list of keys.
 * - `display` (string): for `boolean`, `checkbox` (default), `radio` or `select`. For `enum`, `select`, `radio` or
 *   `checkboxes`: a single choice cannot use checkboxes and several choices cannot use radios, and the default is radio
 *   (single) or checkboxes (several) for up to five options, a select above that.
 * - `labels` (array): for `boolean`, the `on` and `off` texts. `on` is the checkbox text and the "on" choice, and defaults to the
 *   label for a checkbox and to Yes otherwise; `off` defaults to No.
 * - `min`, `max` (int): for a `number`, the smallest and largest value; for an `enum` with several choices, how many may be chosen.
 * - `sanitize_callback` (callable): receives the submitted value, returns the clean value.
 * - `validate_callback` (callable): receives the clean value, returns true or a WP_Error (false is an error too).
 *
 * The value types, with their displays:
 *
 * - `text`: a string, shown as a text input. Tags and line breaks are removed.
 * - `textarea`: a string, shown as a textarea.
 * - `url`: a string, shown as a URL input. Empty, or an http or https address with a host (no `javascript:`, `ftp:` or relative URLs).
 * - `email_list`: addresses separated by commas, shown as a text input. One bad address makes the field invalid. Stored joined with `,`.
 * - `secret`: a string, shown as a password input, trimmed and with control characters removed. The saved value is never output. Submitting nothing keeps the saved value, and a
 *   `<name>__clear` setting that is true (the "Clear" checkbox of the form) empties it. `'secret' => true` does the same for the other
 *   types.
 * - `number`: a whole number, shown as a number input, within `min` and `max`. An empty value is left out, so the stored value or the
 *   default applies.
 * - `boolean`: `true` or `false`, shown as a checkbox, radio buttons or a select.
 * - `enum`: a choice, or several, from `options`, shown as radio buttons, checkboxes or a select.
 *
 * Add-ons can add types, or replace these, with the `sc_wpun_field_types` filter.
 *
 * The default validate_settings() applies to every type: `sanitize_callback`, the sanitiser of the type, then `required` and
 * `validate_callback`. Every error is a WP_Error with the code `invalid_field` (or the code of the callback) and the data
 * `array( 'field' => $name )`.
 *
 * A `boolean` is always `true` or `false`; a submitted or stored `1`, `0`, `'1'`, `'0'` or `''` (off) is accepted and anything else
 * is invalid. `checkbox` is an alias of `boolean` with the checkbox display. Supplied from outside the database, `1`, `true`,
 * `yes` and `on` are on, and `0`, `false`, `no`, `off` and an empty value are off, in any case.
 *
 * Every channel has an `enabled` setting, which is not part of get_fields(). Channel::enabled_field() is its shared boolean
 * schema, used by the settings screen and for values supplied from outside the database.
 *
 * The fields in use are those of get_form_fields(): get_fields() passed through the `sc_wpun_channel_fields` filter.
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
	 * The fields of the settings form, validated and stored: get_fields() after the `sc_wpun_channel_fields` filter.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	final public function get_form_fields(): array {
		$fields = $this->get_fields();

		/**
		 * Filters the settings fields of a channel type.
		 *
		 * The fields are drawn on the add and edit forms, checked by the default Channel::validate_settings() and can be
		 * supplied from outside the database. Same schema as Channel::get_fields().
		 *
		 * A channel that overrides validate_settings() decides for itself what it keeps, so a field added to it here is
		 * drawn but may not be saved. The email channel is one: it keeps only its own fields. Changing the texts of
		 * existing fields is always safe.
		 *
		 * Example, change the help text of the email channel's recipients:
		 *
		 *     add_filter(
		 *         'sc_wpun_channel_fields',
		 *         function ( $fields, $type ) {
		 *             if ( 'email' === $type && isset( $fields['to'] ) ) {
		 *                 $fields['to']['description'] = 'Use the team mailing list.';
		 *             }
		 *             return $fields;
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, array<string, mixed>> $fields The field schemas by name.
		 * @param string                              $type   The channel type.
		 */
		$filtered = apply_filters( 'sc_wpun_channel_fields', $fields, $this->get_type() );

		return is_array( $filtered ) ? $filtered : $fields;
	}

	/**
	 * The field schema of the `enabled` setting every instance has, shared by all channels.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed>
	 */
	public static function enabled_field(): array {
		return array(
			'type'   => 'boolean',
			'label'  => __( 'Enabled', 'wp-updates-notifier' ),
			'labels' => array(
				'on' => __( 'Send notifications through this channel', 'wp-updates-notifier' ),
			),
		);
	}

	/**
	 * Clean and check the settings submitted for an instance.
	 *
	 * The default keeps the fields declared by get_fields(), runs each field's
	 * `sanitize_callback` and then checks a `boolean` field is an on or off value (anything else returns a
	 * WP_Error naming the field) and an `enum` field against its options: a single
	 * choice that is not an option, or a list with too few or too many choices, returns a
	 * WP_Error naming the field; unknown keys in a list are dropped. The other types are checked
	 * as described in the class description, and `required` and `validate_callback` are enforced.
	 * A field missing from the submission stays missing, unless it is required and nothing is stored.
	 * A secret keeps its stored value when nothing is submitted for it, and is emptied by a true
	 * `<name>__clear` setting, which is never part of the result. A field of a type nothing handles is dropped.
	 * A channel can override it to check more, and return a WP_Error whose error data names the field
	 * at fault. The fields are those of get_form_fields(), so a field added by the `sc_wpun_channel_fields`
	 * filter is checked too; a channel that overrides this method decides for itself.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $settings Submitted settings.
	 * @param array<string, mixed> $stored   The settings currently stored for the instance, to keep a secret.
	 *
	 * @return array<string, mixed>|\WP_Error The settings to store.
	 */
	public function validate_settings( array $settings, array $stored = array() ) {
		return Field_Types::validate( $this->get_form_fields(), $settings, $stored );
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
	 * The versions of the extension API this module needs, as a constraint.
	 *
	 * The registry refuses to register the module when the API version of the site (sc_wpun_api_version())
	 * does not satisfy it, and tells the site owner. An empty string, the default, means no check.
	 * Composer's syntax applies, for example `'^1.0'` (any 1.x from 1.0 on) or `'>=1.0 <3.0'`.
	 *
	 * @since 2.0.0
	 *
	 * @return string The constraint, or an empty string to skip the check.
	 */
	public function get_api_constraint(): string {
		return '';
	}

	/**
	 * Markup of the settings fields of an instance, for a channel that draws its own.
	 *
	 * It replaces only the module fields part of the add and edit form. The inputs must be named `settings[<field>]`.
	 * The core form still draws the label, the enabled switch, the routing, the nonce and the buttons, and saves
	 * the result, through validate_settings(). The instance is the one being edited, with the values just submitted
	 * after a failed save. The markup is output as it is, so it must be escaped. The instance holds the saved
	 * values of secrets: never output them. Errors of a failed save are listed above the form, as plain text.
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
		 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
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
		 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
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
