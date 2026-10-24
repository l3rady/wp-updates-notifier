<?php
/**
 * Markup of schema fields on the settings screen.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

use WPUN\Boolean_Field;
use WPUN\Enum_Field;
use WPUN\Field_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Draws form controls from field schemas: the built-in types, and any type registered through the
 * `sc_wpun_field_types` filter.
 *
 * A control with an error is marked `aria-invalid` (the select, text-like input or number, or the fieldset of a
 * group of radio buttons or checkboxes) and linked to the error message by `aria-describedby`.
 *
 * @internal
 */
final class Field_Renderer {

	/**
	 * The markup of an enum field.
	 *
	 * Radio buttons and checkboxes sit in a fieldset whose legend is the field's label (visible to screen
	 * readers only, as the settings row already shows it), each with its own label. A field with several choices
	 * is preceded by a hidden empty input of the same name, so that choosing nothing is submitted too.
	 * Everything is escaped.
	 *
	 * @param array<string, mixed>    $field       Field schema of type `enum` or `select`.
	 * @param string                  $name        The `name` attribute of the input, without `[]`.
	 * @param string                  $id          Base of the `id` attributes: the fieldset or select gets it as it is.
	 * @param string|array<int,mixed> $value       The current choice, or the list of current choices.
	 * @param bool                    $disabled    Whether the inputs are disabled.
	 * @param string                  $described_by Id of the element that describes the field, if any.
	 * @param array<int|string,mixed> $extra_options Choices shown before the field's own options, key => label, for example a saved
	 *                                              value that is no longer offered. They do not change the default display.
	 * @param string                  $error        Error message of the field, if any: the control is marked invalid.
	 *
	 * @return string
	 */
	public static function enum( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', array $extra_options = array(), string $error = '' ): string {
		$field    = Enum_Field::normalise( $field );
		$multiple = true === $field['multiple'];
		$chosen   = array_map( 'strval', array_filter( is_array( $value ) ? $value : array( $value ), 'is_scalar' ) );
		$options  = array();
		foreach ( $extra_options + $field['options'] as $key => $label ) {
			$options[ (string) $key ] = is_scalar( $label ) ? (string) $label : (string) $key;
		}

		$input_name = $multiple ? $name . '[]' : $name;
		$disable    = $disabled ? ' disabled="disabled"' : '';
		$describe   = ( '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : '' ) . ( '' !== $error ? ' aria-invalid="true"' : '' );
		$required   = self::is_required( $field );
		$html       = '';

		// Nothing chosen submits nothing at all, so a list says so with an empty value.
		if ( $multiple ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value=""' . $disable . ' />';
		}

		if ( 'select' === $field['display'] ) {
			$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '"' . ( $multiple ? ' multiple="multiple"' : '' ) . $disable . $describe . ( $required ? ' aria-required="true"' : '' ) . '>';
			foreach ( $options as $key => $label ) {
				$html .= '<option value="' . esc_attr( $key ) . '"' . ( in_array( $key, $chosen, true ) ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
			}

			return $html . '</select>';
		}

		$type   = $multiple ? 'checkbox' : 'radio';
		$legend = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
		// A group of radio buttons can be required (`aria-required` is for a radio group, not a plain fieldset); a group of checkboxes has only the text.
		$group = ! $multiple && $required ? ' role="radiogroup" aria-required="true"' : '';
		$html .= '<fieldset id="' . esc_attr( $id ) . '"' . $group . $describe . '><legend class="screen-reader-text"><span>' . esc_html( $legend ) . ( $required ? self::required_marker() : '' ) . '</span></legend>';

		$index = 0;
		foreach ( $options as $key => $label ) {
			$input_id = $id . '_' . $index;
			$html    .= ( $index > 0 ? '<br />' : '' )
				. '<label for="' . esc_attr( $input_id ) . '"><input type="' . $type . '" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $key ) . '"'
				. ( in_array( $key, $chosen, true ) ? ' checked="checked"' : '' ) . $disable . ' /> ' . esc_html( $label ) . '</label>';
			++$index;
		}

		return $html . '</fieldset>';
	}

	/**
	 * The markup of a field, by its type.
	 *
	 * The type is looked up among the registered field types (see the `sc_wpun_field_types` filter), `checkbox` and
	 * `select` being aliases of `boolean` and `enum`. A type nothing handles draws nothing, with a notice to the
	 * developer. With an error, the message follows the control in a paragraph linked to it by `aria-describedby`.
	 *
	 * @param array<string, mixed>    $field         Field schema.
	 * @param string                  $name          The `name` attribute of the input, without `[]`.
	 * @param string                  $id            Base of the `id` attributes.
	 * @param mixed                   $value         The current value.
	 * @param bool                    $disabled      Whether the inputs are disabled.
	 * @param string                  $described_by  Id of the element that describes the field, if any.
	 * @param array<int|string,mixed> $extra_options Extra choices for an enum, see enum().
	 * @param string                  $error         Error message of the field, if any.
	 *
	 * @return string Empty for a type that nothing handles.
	 */
	public static function field( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', array $extra_options = array(), string $error = '' ): string {
		$handler = Field_Types::get( $field['type'] ?? null );
		if ( null === $handler ) {
			Field_Types::unknown( $field['type'] ?? null );

			return '';
		}

		if ( Field_Types::is_secret( $field ) ) {
			// A secret's renderer is only told whether a value is saved, never the value.
			$value = is_scalar( $value ) && '' !== (string) $value ? '1' : '';
		}

		$error_id = $id . '-error';
		if ( '' !== $error ) {
			$described_by = trim( $described_by . ' ' . $error_id );
		}

		if ( array( Field_Types::class, 'render_enum' ) === $handler['render'] ) {
			$html = self::enum( $field, $name, $id, Field_Types::enum_value( $value ), $disabled, $described_by, $extra_options, $error );
		} else {
			$drawn = call_user_func( $handler['render'], $field, $name, $id, $value, $disabled, $described_by, $error );
			$html  = is_scalar( $drawn ) ? (string) $drawn : '';
		}

		if ( '' !== $error ) {
			$html .= '<p class="sc-wpun-field-error" id="' . esc_attr( $error_id ) . '"><strong>' . esc_html__( 'Error:', 'wp-updates-notifier' ) . '</strong> ' . esc_html( $error ) . '</p>';
		}

		return $html;
	}

	/**
	 * The markup of a single-line text field.
	 *
	 * @param array<string, mixed> $field        Field schema; `secret` set draws a secret.
	 * @param string               $name         The `name` attribute.
	 * @param string               $id           The `id` attribute.
	 * @param mixed                $value        The current value.
	 * @param bool                 $disabled     Whether the input is disabled.
	 * @param string               $described_by Ids of the elements that describe the field, if any.
	 * @param string               $error        Error message of the field, if any: the input is marked invalid.
	 *
	 * @return string
	 */
	public static function text( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', string $error = '' ): string {
		return self::input( 'text', 'regular-text', $field, $name, $id, $value, $disabled, $described_by, $error );
	}

	/**
	 * The markup of a URL field.
	 *
	 * @param array<string, mixed> $field        Field schema; `secret` set draws a secret.
	 * @param string               $name         The `name` attribute.
	 * @param string               $id           The `id` attribute.
	 * @param mixed                $value        The current value.
	 * @param bool                 $disabled     Whether the input is disabled.
	 * @param string               $described_by Ids of the elements that describe the field, if any.
	 * @param string               $error        Error message of the field, if any: the input is marked invalid.
	 *
	 * @return string
	 */
	public static function url( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', string $error = '' ): string {
		return self::input( 'url', 'regular-text', $field, $name, $id, $value, $disabled, $described_by, $error );
	}

	/**
	 * The markup of a field for a list of email addresses: a text input, as `type="email"` cannot hold several.
	 *
	 * @param array<string, mixed> $field        Field schema; `secret` set draws a secret.
	 * @param string               $name         The `name` attribute.
	 * @param string               $id           The `id` attribute.
	 * @param mixed                $value        The current value.
	 * @param bool                 $disabled     Whether the input is disabled.
	 * @param string               $described_by Ids of the elements that describe the field, if any.
	 * @param string               $error        Error message of the field, if any: the input is marked invalid.
	 *
	 * @return string
	 */
	public static function email_list( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', string $error = '' ): string {
		return self::input( 'text', 'regular-text', $field, $name, $id, $value, $disabled, $described_by, $error );
	}

	/**
	 * The markup of a number field, with `min` and `max` attributes when the schema sets them.
	 *
	 * @param array<string, mixed> $field        Field schema.
	 * @param string               $name         The `name` attribute.
	 * @param string               $id           The `id` attribute.
	 * @param mixed                $value        The current value.
	 * @param bool                 $disabled     Whether the input is disabled.
	 * @param string               $described_by Ids of the elements that describe the field, if any.
	 * @param string               $error        Error message of the field, if any: the input is marked invalid.
	 *
	 * @return string
	 */
	public static function number( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', string $error = '' ): string {
		$extra = '';
		foreach ( array( 'min', 'max' ) as $key ) {
			$bound = Field_Types::bound( $field, $key );
			if ( null !== $bound ) {
				$extra .= ' ' . $key . '="' . $bound . '"';
			}
		}

		return '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '"' . $extra . self::common( $disabled, $described_by, $error, self::is_required( $field ) ) . ' />';
	}

	/**
	 * The markup of a multi-line text field. A secret is drawn as a password input.
	 *
	 * @param array<string, mixed> $field        Field schema; `secret` set draws a secret.
	 * @param string               $name         The `name` attribute.
	 * @param string               $id           The `id` attribute.
	 * @param mixed                $value        The current value.
	 * @param bool                 $disabled     Whether the input is disabled.
	 * @param string               $described_by Ids of the elements that describe the field, if any.
	 * @param string               $error        Error message of the field, if any: the input is marked invalid.
	 *
	 * @return string
	 */
	public static function textarea( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', string $error = '' ): string {
		if ( Field_Types::is_secret( $field ) ) {
			return self::secret( $field, $name, $id, $value, $disabled, $described_by, $error );
		}

		return '<textarea class="large-text" rows="5" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . self::common( $disabled, $described_by, $error, self::is_required( $field ) ) . '>'
			. esc_textarea( is_scalar( $value ) ? (string) $value : '' ) . '</textarea>';
	}

	/**
	 * The markup of a secret field.
	 *
	 * The saved value is never output: `$value` only tells whether one is saved. If so (and the field is not
	 * disabled) a checkbox named `<name>__clear` lets the user empty it; an empty submission keeps it. For a name
	 * like `opt[token]` the checkbox is named `opt[token__clear]`, so it arrives in the same array.
	 *
	 * @param array<string, mixed> $field        Field schema.
	 * @param string               $name         The `name` attribute.
	 * @param string               $id           The `id` attribute.
	 * @param mixed                $value        The saved value.
	 * @param bool                 $disabled     Whether the input is disabled.
	 * @param string               $described_by Ids of the elements that describe the field, if any.
	 * @param string               $error        Error message of the field, if any: the input is marked invalid.
	 *
	 * @return string
	 */
	public static function secret( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', string $error = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Same signature as the other types.
		$has_value = is_scalar( $value ) && '' !== (string) $value;
		$saved     = $has_value && ! $disabled;
		$label     = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
		if ( $saved ) {
			$described_by = trim( $id . '-saved ' . $described_by );
		}
		$html = '<input type="password" class="regular-text" autocomplete="off" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value=""' . self::common( $disabled, $described_by, $error, self::is_required( $field ) && ! $has_value ) . ' />';

		if ( $saved ) {
			$clear = ']' === substr( $name, -1 ) ? substr( $name, 0, -1 ) . '__clear]' : $name . '__clear';
			// The checkbox is named after the field, so several secrets in one form can be told apart.
			$clear_text = __( 'Clear the saved value', 'wp-updates-notifier' );
			if ( '' !== $label ) {
				// translators: %s: Label of the field, for example "Webhook URL".
				$clear_text = sprintf( __( 'Clear the saved value of %s', 'wp-updates-notifier' ), $label );
			}
			$html .= '<p class="description" id="' . esc_attr( $id . '-saved' ) . '">' . esc_html__( 'A value is saved. Leave this empty to keep it, or type a new value to replace it.', 'wp-updates-notifier' ) . '</p>'
				. '<label for="' . esc_attr( $id . '__clear' ) . '"><input type="checkbox" id="' . esc_attr( $id . '__clear' ) . '" name="' . esc_attr( $clear ) . '" value="1" /> '
				. esc_html( $clear_text ) . '</label>';
		}

		return $html;
	}

	/**
	 * An `<input>` of a text-like type, or a secret when the schema asks for one.
	 *
	 * @param string               $type         The input type.
	 * @param string               $class_name   CSS class.
	 * @param array<string, mixed> $field        Field schema.
	 * @param string               $name         The `name` attribute.
	 * @param string               $id           The `id` attribute.
	 * @param mixed                $value        The current value.
	 * @param bool                 $disabled     Whether the input is disabled.
	 * @param string               $described_by Ids of the elements that describe the field, if any.
	 * @param string               $error        Error message of the field, if any.
	 *
	 * @return string
	 */
	private static function input( string $type, string $class_name, array $field, string $name, string $id, $value, bool $disabled, string $described_by, string $error ): string {
		if ( Field_Types::is_secret( $field ) ) {
			return self::secret( $field, $name, $id, $value, $disabled, $described_by, $error );
		}

		return '<input type="' . $type . '" class="' . $class_name . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '"' . self::common( $disabled, $described_by, $error, self::is_required( $field ) ) . ' />';
	}

	/**
	 * Whether a field is marked as required in the form: `required` is true, and it is not a boolean, which is always valid.
	 *
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return bool
	 */
	public static function is_required( array $field ): bool {
		return true === ( $field['required'] ?? false ) && 'boolean' !== Field_Types::canonical( $field['type'] ?? null );
	}

	/**
	 * The marker after the label of a required field, as core has it on its user screens.
	 *
	 * @return string Escaped markup, with a leading space.
	 */
	public static function required_marker(): string {
		// translators: Shown after the label of a field that must be filled in.
		return ' <span class="description">' . esc_html__( '(required)', 'wp-updates-notifier' ) . '</span>';
	}

	/**
	 * The attributes every control can have.
	 *
	 * @param bool   $disabled     Whether disabled.
	 * @param string $described_by Ids of the elements that describe the field.
	 * @param string $error        Error message, if any.
	 * @param bool   $required     Whether the field is required.
	 *
	 * @return string
	 */
	private static function common( bool $disabled, string $described_by, string $error, bool $required = false ): string {
		return ( $disabled ? ' disabled="disabled"' : '' )
			. ( $required ? ' aria-required="true"' : '' )
			. ( '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : '' )
			. ( '' !== $error ? ' aria-invalid="true"' : '' );
	}

	/**
	 * The markup of a boolean field.
	 *
	 * A checkbox is preceded by a hidden input of the same name with the value 0, so that an unticked box
	 * submits "off" instead of nothing. Radio buttons sit in a fieldset whose legend is the field's label
	 * (visible to screen readers only), each with its own label. Everything is escaped.
	 *
	 * @param array<string, mixed> $field        Field schema of type `boolean` or `checkbox`.
	 * @param string               $name         The `name` attribute of the input.
	 * @param string               $id           Base of the `id` attributes: the fieldset or select gets it as it is.
	 * @param mixed                $value        The current value: on for true, 1 and '1'.
	 * @param bool                 $disabled     Whether the inputs are disabled.
	 * @param string               $described_by Id of the element that describes the field, if any.
	 * @param string               $error        Error message of the field, if any: the control is marked invalid.
	 *
	 * @return string
	 */
	public static function boolean( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', string $error = '' ): string {
		$field    = Boolean_Field::normalise( $field );
		$on       = true === Boolean_Field::sanitize( $value, $field )['value'];
		$disable  = $disabled ? ' disabled="disabled"' : '';
		$describe = ( '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : '' ) . ( '' !== $error ? ' aria-invalid="true"' : '' );
		$labels   = $field['labels'];

		if ( 'checkbox' === $field['display'] ) {
			return '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"' . $disable . ' />'
				. '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"'
				. ( $on ? ' checked="checked"' : '' ) . $disable . $describe . ' /> ' . esc_html( $labels['on'] ) . '</label>';
		}

		if ( 'select' === $field['display'] ) {
			return '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $disable . $describe . '>'
				. '<option value="1"' . ( $on ? ' selected="selected"' : '' ) . '>' . esc_html( $labels['on'] ) . '</option>'
				. '<option value="0"' . ( $on ? '' : ' selected="selected"' ) . '>' . esc_html( $labels['off'] ) . '</option>'
				. '</select>';
		}

		$legend = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
		$html   = '<fieldset id="' . esc_attr( $id ) . '"' . $describe . '><legend class="screen-reader-text"><span>' . esc_html( $legend ) . '</span></legend>';
		foreach ( array(
			array( '1', $labels['on'], $on ),
			array( '0', $labels['off'], ! $on ),
		) as $index => $choice ) {
			$input_id = $id . '_' . $index;
			$html    .= ( $index > 0 ? '<br />' : '' )
				. '<label for="' . esc_attr( $input_id ) . '"><input type="radio" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $name ) . '" value="' . $choice[0] . '"'
				. ( $choice[2] ? ' checked="checked"' : '' ) . $disable . ' /> ' . esc_html( $choice[1] ) . '</label>';
		}

		return $html . '</fieldset>';
	}
}
