<?php
/**
 * The boolean field type: an on or off value.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Normalises, casts and sanitises fields of type `boolean` (and its alias `checkbox`).
 *
 * Schema keys, besides `type` and `label`:
 *
 * - `display` (string): `checkbox` (default), `radio` or `select`. `checkbox` as the type is an alias of
 *   `boolean` with the checkbox display.
 * - `labels` (array): `on` and `off` texts. `on` is the checkbox text, and the "on" choice of a radio or
 *   select; it defaults to the field label for a checkbox and to Yes otherwise. `off` is the "off" choice of a
 *   radio or select and defaults to No.
 * - `default` (bool): used when nothing valid is available. Without one, the default is false.
 *
 * The value is always `true` or `false`.
 *
 * @internal
 */
final class Boolean_Field {

	/**
	 * Displays a boolean field can have.
	 */
	const DISPLAYS = array( 'checkbox', 'radio', 'select' );

	/**
	 * Fields already complained about.
	 *
	 * @var array<string, true>
	 */
	private static $warned = array();

	/**
	 * Whether a field schema is a boolean, including the `checkbox` alias.
	 *
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return bool
	 */
	public static function is_boolean( array $field ): bool {
		return isset( $field['type'] ) && in_array( $field['type'], array( 'boolean', 'checkbox' ), true );
	}

	/**
	 * Forget which fields were complained about, for tests.
	 *
	 * @return void
	 */
	public static function forget_warnings(): void {
		self::$warned = array();
	}

	/**
	 * The schema with `type` boolean, and `display` and `labels` always set to usable values.
	 *
	 * A display that is not known falls back to `checkbox`, with a notice to the developer (once per field).
	 *
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function normalise( array $field ): array {
		$display = 'checkbox';

		if ( isset( $field['display'] ) ) {
			if ( is_string( $field['display'] ) && in_array( $field['display'], self::DISPLAYS, true ) ) {
				$display = $field['display'];
			} else {
				$shown = is_string( $field['display'] ) ? $field['display'] : gettype( $field['display'] );
				$id    = md5( (string) wp_json_encode( array( $shown, $field['label'] ?? null ) ) );
				if ( ! isset( self::$warned[ $id ] ) ) {
					self::$warned[ $id ] = true;
					_doing_it_wrong(
						__METHOD__,
						esc_html(
							sprintf(
								// translators: 1: The display asked for. 2: The display used instead.
								__( 'The display "%1$s" cannot show an on or off value, so "%2$s" is used.', 'wp-updates-notifier' ),
								$shown,
								$display
							)
						),
						'2.0.0'
					);
				}
			}
		}

		$labels = isset( $field['labels'] ) && is_array( $field['labels'] ) ? $field['labels'] : array();
		$on     = $labels['on'] ?? null;
		$off    = $labels['off'] ?? null;
		if ( ! is_string( $on ) ) {
			$label = $field['label'] ?? null;
			$on    = 'checkbox' === $display && is_string( $label ) ? $label : __( 'Yes', 'wp-updates-notifier' );
		}
		if ( ! is_string( $off ) ) {
			$off = __( 'No', 'wp-updates-notifier' );
		}

		$field['type']    = 'boolean';
		$field['display'] = $display;
		$field['labels']  = array(
			'on'  => $on,
			'off' => $off,
		);

		return $field;
	}

	/**
	 * The value used when nothing valid is available.
	 *
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return bool The field's own default when it is valid, else false.
	 */
	public static function default_value( array $field ): bool {
		if ( array_key_exists( 'default', $field ) ) {
			$checked = self::check( $field['default'] );
			if ( $checked['ok'] ) {
				return $checked['value'];
			}
		}

		return false;
	}

	/**
	 * Cast a value supplied from outside the database (a constant, an environment variable or a source).
	 *
	 * Accepts 1, true, yes and on, and 0, false, no, off and an empty string, in any case and trimmed.
	 * Arrays and anything else are not usable.
	 *
	 * @param mixed                $raw   The value.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array{ok: bool, value: mixed}
	 */
	public static function cast_external( $raw, array $field ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same signature as the other field types.
		if ( is_string( $raw ) ) {
			$raw = strtolower( trim( $raw ) );
		}

		if ( in_array( $raw, array( true, 1, '1', 'true', 'yes', 'on' ), true ) ) {
			return array(
				'ok'    => true,
				'value' => true,
			);
		}

		if ( in_array( $raw, array( false, 0, '0', 'false', 'no', 'off', '' ), true ) ) {
			return array(
				'ok'    => true,
				'value' => false,
			);
		}

		return array(
			'ok'    => false,
			'value' => null,
		);
	}

	/**
	 * Clean a submitted or stored value.
	 *
	 * Accepts true and false, 1 and 0, '1' and '0', and '' (off). Anything else is not ok and the default is
	 * returned. A checkbox form submits the hidden '0' and then '1' when ticked, which PHP receives as '1'.
	 *
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array{ok: bool, value: bool}
	 */
	public static function sanitize( $value, array $field ): array {
		$checked = self::check( $value );

		if ( ! $checked['ok'] ) {
			return array(
				'ok'    => false,
				'value' => self::default_value( $field ),
			);
		}

		return $checked;
	}

	/**
	 * Check a value.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array{ok: bool, value: bool} The value is the cleaned one when ok.
	 */
	private static function check( $value ): array {
		if ( in_array( $value, array( true, 1, '1' ), true ) ) {
			return array(
				'ok'    => true,
				'value' => true,
			);
		}

		if ( in_array( $value, array( false, 0, '0', '' ), true ) ) {
			return array(
				'ok'    => true,
				'value' => false,
			);
		}

		return array(
			'ok'    => false,
			'value' => false,
		);
	}
}
