<?php
/**
 * Markup of schema fields on the settings screen.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

use WPUN\Enum_Field;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Draws form controls from field schemas. Only the enum type is drawn so far.
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
	 *
	 * @return string
	 */
	public static function enum( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '' ): string {
		$field    = Enum_Field::normalise( $field );
		$multiple = true === $field['multiple'];
		$chosen   = array_map( 'strval', array_filter( is_array( $value ) ? $value : array( $value ), 'is_scalar' ) );
		$options  = array();
		foreach ( $field['options'] as $key => $label ) {
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
}
