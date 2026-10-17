<?php
/**
 * Markup of schema fields on the settings screen.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

use WPUN\Boolean_Field;
use WPUN\Enum_Field;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Draws form controls from field schemas. The enum and boolean types are drawn so far.
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
	 *
	 * @return string
	 */
	public static function enum( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', array $extra_options = array() ): string {
		$field    = Enum_Field::normalise( $field );
		$multiple = true === $field['multiple'];
		$chosen   = array_map( 'strval', array_filter( is_array( $value ) ? $value : array( $value ), 'is_scalar' ) );
		$options  = array();
		foreach ( $extra_options + $field['options'] as $key => $label ) {
			$options[ (string) $key ] = is_scalar( $label ) ? (string) $label : (string) $key;
		}

		$input_name = $multiple ? $name . '[]' : $name;
		$disable    = $disabled ? ' disabled="disabled"' : '';
		$describe   = '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : '';
		$html       = '';

		// Nothing chosen submits nothing at all, so a list says so with an empty value.
		if ( $multiple ) {
			$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value=""' . $disable . ' />';
		}

		if ( 'select' === $field['display'] ) {
			$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $input_name ) . '"' . ( $multiple ? ' multiple="multiple"' : '' ) . $disable . $describe . '>';
			foreach ( $options as $key => $label ) {
				$html .= '<option value="' . esc_attr( $key ) . '"' . ( in_array( $key, $chosen, true ) ? ' selected="selected"' : '' ) . '>' . esc_html( $label ) . '</option>';
			}

			return $html . '</select>';
		}

		$type   = $multiple ? 'checkbox' : 'radio';
		$legend = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
		$html  .= '<fieldset id="' . esc_attr( $id ) . '"' . $describe . '><legend class="screen-reader-text"><span>' . esc_html( $legend ) . '</span></legend>';

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
	 * The markup of a field of type `enum`, `select`, `boolean` or `checkbox`, by its type.
	 *
	 * @param array<string, mixed>    $field        Field schema.
	 * @param string                  $name         The `name` attribute of the input, without `[]`.
	 * @param string                  $id           Base of the `id` attributes.
	 * @param mixed                   $value        The current value.
	 * @param bool                    $disabled     Whether the inputs are disabled.
	 * @param string                  $described_by Id of the element that describes the field, if any.
	 * @param array<int|string,mixed> $extra_options Extra choices for an enum, see enum().
	 *
	 * @return string Empty for a type that is not drawn here.
	 */
	public static function field( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', array $extra_options = array() ): string {
		if ( Enum_Field::is_enum( $field ) ) {
			return self::enum( $field, $name, $id, is_array( $value ) ? array_values( $value ) : ( is_scalar( $value ) ? (string) $value : '' ), $disabled, $described_by, $extra_options );
		}

		if ( Boolean_Field::is_boolean( $field ) ) {
			return self::boolean( $field, $name, $id, $value, $disabled, $described_by );
		}

		return '';
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
	 *
	 * @return string
	 */
	public static function boolean( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '' ): string {
		$field    = Boolean_Field::normalise( $field );
		$on       = true === Boolean_Field::sanitize( $value, $field )['value'];
		$disable  = $disabled ? ' disabled="disabled"' : '';
		$describe = '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : '';
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
