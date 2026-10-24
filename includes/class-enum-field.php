<?php
/**
 * The enum field type: a choice from a fixed set of options.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Normalises, casts and sanitises fields of type `enum` (and its alias `select`).
 *
 * Schema keys, besides `type` and `label`:
 *
 * - `options` (array): key => label. Keys are what is stored; they are always compared as strings.
 * - `multiple` (bool): false (default) for one choice, true for a list of choices.
 * - `display` (string): `select`, `radio` or `checkboxes`. Single choice cannot use checkboxes and
 *   several choices cannot use radios. The default is radio (single) or checkboxes (several) for up to
 *   five options and a select above that. `select` as the type means a single choice shown as a select.
 * - `default` (string|string[]): used when nothing valid is available. Without one, a single choice
 *   defaults to its first option and a list to empty.
 * - `min`, `max` (int): how many choices a list must hold.
 *
 * A list is kept in the order of the options, so the same choices are always stored the same way.
 *
 * @internal
 */
final class Enum_Field {

	/**
	 * Most options a radio or checkbox list is the default display for.
	 */
	const MAX_INLINE_OPTIONS = 5;

	/**
	 * Fields already complained about.
	 *
	 * @var array<string, true>
	 */
	private static $warned = array();

	/**
	 * Whether a field schema is an enum, including the `select` alias.
	 *
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return bool
	 */
	public static function is_enum( array $field ): bool {
		return isset( $field['type'] ) && in_array( $field['type'], array( 'enum', 'select' ), true );
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
	 * The schema with `type` enum and `multiple`, `display` and `options` always set to usable values.
	 *
	 * A display that does not fit the field falls back to the default display, with a notice to the
	 * developer (once per field).
	 *
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function normalise( array $field ): array {
		$is_select = isset( $field['type'] ) && 'select' === $field['type'];
		$options   = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		$multiple  = isset( $field['multiple'] ) && true === $field['multiple'];
		$allowed   = $multiple ? array( 'select', 'checkboxes' ) : array( 'select', 'radio' );
		$default   = $is_select || count( $options ) > self::MAX_INLINE_OPTIONS ? 'select' : ( $multiple ? 'checkboxes' : 'radio' );
		$display   = $default;

		if ( isset( $field['display'] ) ) {
			if ( is_string( $field['display'] ) && in_array( $field['display'], $allowed, true ) ) {
				$display = $field['display'];
			} else {
				$shown = is_string( $field['display'] ) ? $field['display'] : gettype( $field['display'] );
				$id    = md5( (string) wp_json_encode( array( $multiple, $shown, array_keys( $options ) ) ) );
				if ( ! isset( self::$warned[ $id ] ) ) {
					self::$warned[ $id ] = true;
					_doing_it_wrong(
						__METHOD__,
						esc_html(
							$multiple
								// translators: 1: The display asked for. 2: The display used instead.
								? sprintf( __( 'The display "%1$s" cannot show several choices, so "%2$s" is used.', 'wp-updates-notifier' ), $shown, $default )
								// translators: 1: The display asked for. 2: The display used instead.
								: sprintf( __( 'The display "%1$s" cannot show a single choice, so "%2$s" is used.', 'wp-updates-notifier' ), $shown, $default )
						),
						'2.0.0'
					);
				}
			}
		}

		$field['type']     = 'enum';
		$field['options']  = $options;
		$field['multiple'] = $multiple;
		$field['display']  = $display;

		return $field;
	}

	/**
	 * The value used when nothing valid is available.
	 *
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return string|string[] A key, or a list of keys for a field with several choices.
	 */
	public static function default_value( array $field ) {
		$field = self::normalise( $field );

		if ( array_key_exists( 'default', $field ) ) {
			$checked = self::check( $field['default'], $field );
			if ( $checked['ok'] ) {
				return $checked['value'];
			}
		}

		if ( $field['multiple'] ) {
			return array();
		}

		$keys = self::keys( $field );

		return $keys[0] ?? '';
	}

	/**
	 * Cast a value supplied from outside the database (a constant, an environment variable or a source).
	 *
	 * A single choice is the key. Several choices are a comma-separated string. Anything unknown makes
	 * the whole value unusable: a typo must never widen a list of choices into "everything".
	 *
	 * @param mixed                $raw   The value.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array{ok: bool, value: mixed}
	 */
	public static function cast_external( $raw, array $field ): array {
		$bad   = array(
			'ok'    => false,
			'value' => null,
		);
		$field = self::normalise( $field );

		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return $bad;
		}

		$checked = self::check( $raw, $field );
		if ( ! $checked['ok'] || array() !== $checked['unknown'] ) {
			return $bad;
		}

		return array(
			'ok'    => true,
			'value' => $checked['value'],
		);
	}

	/**
	 * Clean a submitted or stored value.
	 *
	 * A single choice that is not an option is not ok and the default is returned. A list accepts an array
	 * (or a comma-separated string); unknown keys are dropped and named in `unknown`, and a list with too few
	 * or too many choices is not ok and the default is returned. `''` and an empty list mean no choices, so a
	 * form can submit an empty value to say that nothing is chosen.
	 *
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array{ok: bool, value: mixed, unknown: string[]}
	 */
	public static function sanitize( $value, array $field ): array {
		$field   = self::normalise( $field );
		$checked = self::check( $value, $field );

		if ( ! $checked['ok'] ) {
			return array(
				'ok'      => false,
				'value'   => self::default_value( $field ),
				'unknown' => $checked['unknown'],
			);
		}

		return $checked;
	}

	/**
	 * The option keys, as strings.
	 *
	 * @param array<string, mixed> $field Normalised field schema.
	 *
	 * @return string[]
	 */
	public static function keys( array $field ): array {
		return isset( $field['options'] ) && is_array( $field['options'] ) ? array_map( 'strval', array_keys( $field['options'] ) ) : array();
	}

	/**
	 * Check a value against the options.
	 *
	 * @param mixed                $value Value.
	 * @param array<string, mixed> $field Normalised field schema.
	 *
	 * @return array{ok: bool, value: mixed, unknown: string[]} The value is the cleaned one when ok.
	 */
	private static function check( $value, array $field ): array {
		$keys = self::keys( $field );

		if ( empty( $field['multiple'] ) ) {
			if ( ( is_string( $value ) || is_int( $value ) ) && in_array( (string) $value, $keys, true ) ) {
				return array(
					'ok'      => true,
					'value'   => (string) $value,
					'unknown' => array(),
				);
			}

			return array(
				'ok'      => false,
				'value'   => null,
				'unknown' => array(),
			);
		}

		$given = array();
		if ( is_string( $value ) ) {
			$given = explode( ',', $value );
		} elseif ( is_int( $value ) ) {
			$given = array( (string) $value );
		} elseif ( is_array( $value ) ) {
			// Anything in the list that is not a string or a number is dropped without being named.
			$given = array_filter( $value, static fn( $item ): bool => is_string( $item ) || is_int( $item ) );
		} elseif ( null !== $value ) {
			return array(
				'ok'      => false,
				'value'   => null,
				'unknown' => array(),
			);
		}

		$chosen  = array();
		$unknown = array();
		foreach ( $given as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			if ( in_array( $item, $keys, true ) ) {
				$chosen[ $item ] = true;
			} elseif ( ! in_array( $item, $unknown, true ) ) {
				$unknown[] = $item;
			}
		}

		$list  = array_values( array_intersect( $keys, array_keys( $chosen ) ) );
		$count = count( $list );
		$ok    = ( ! isset( $field['min'] ) || $count >= (int) $field['min'] ) && ( ! isset( $field['max'] ) || $count <= (int) $field['max'] );

		return array(
			'ok'      => $ok,
			'value'   => $list,
			'unknown' => $unknown,
		);
	}
}
