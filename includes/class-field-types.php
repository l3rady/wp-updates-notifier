<?php
/**
 * The registry of field types and the value types built into the plugin.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use WPUN\Admin\Field_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Knows how to draw and sanitise every field type, and runs the shared rules of a channel's settings.
 *
 * The types are `text`, `textarea`, `url`, `email_list`, `secret`, `number`, `boolean` and `enum`, and add-ons can
 * add their own or replace these through the `sc_wpun_field_types` filter. `checkbox` is an alias of `boolean` and
 * `select` of `enum`: they are resolved before the lookup, so replacing `boolean` also replaces `checkbox`.
 *
 * @internal
 */
final class Field_Types {

	/**
	 * Types whose externally supplied value is always shown on the edit form: the ones that cannot hold a secret.
	 * The value of every other type, built in or added, is withheld unless the schema sets `show_external`.
	 */
	const EXTERNAL_SHOWN = array( 'number', 'boolean', 'enum' );

	/**
	 * Types and problems already complained about.
	 *
	 * @var array<string, true>
	 */
	private static $warned = array();

	/**
	 * Forget what was complained about, for tests.
	 *
	 * @return void
	 */
	public static function forget_warnings(): void {
		self::$warned = array();
	}

	/**
	 * The type a schema's type stands for: `checkbox` is `boolean` and `select` is `enum`.
	 *
	 * @param mixed $type The type of a field schema.
	 *
	 * @return string Empty when the type is not a string.
	 */
	public static function canonical( $type ): string {
		if ( ! is_string( $type ) ) {
			return '';
		}

		if ( 'checkbox' === $type ) {
			return 'boolean';
		}

		return 'select' === $type ? 'enum' : $type;
	}

	/**
	 * Every field type, built in and added.
	 *
	 * The filter runs on every call, so nothing is cached between lookups.
	 *
	 * @return array<string, array{render: callable, sanitize: callable}>
	 */
	public static function all(): array {
		$builtin = self::builtin();

		/**
		 * Filters the field types the settings screen can draw and settings can be checked with.
		 *
		 * The array is keyed by type. Each entry is an array with a `render` and a `sanitize` callable. The built-in
		 * types (`text`, `textarea`, `url`, `email_list`, `secret`, `number`, `boolean` and `enum`) are in it in the
		 * same shape, so an add-on can replace one. `checkbox` and `select` are aliases of `boolean` and `enum`.
		 * An entry that is not an array of two callables is dropped, with a notice to the developer.
		 *
		 * `render( $field, $name, $id, $value, $disabled, $described_by, $error )` returns the escaped markup of the
		 * control. `$field` is the field schema, `$name` the `name` attribute, `$id` the `id`, `$value` the current
		 * value, `$disabled` whether the control is read-only and `$described_by` the ids of the elements that
		 * describe it. `$error` is the message of an error to show, or an empty string; the error text itself is
		 * drawn by the caller, so the control only needs to be marked invalid.
		 *
		 * For a field with `secret` set, `$value` is `'1'` when a value is saved and `''` when not: the real value is
		 * never passed to the callable.
		 *
		 * `sanitize( $value, $field )` returns the clean value, or a WP_Error when the value is not valid. `$field`
		 * is the field schema, with its `name` added. The error is reported against the field.
		 *
		 * Example, add a colour type:
		 *
		 *     add_filter(
		 *         'sc_wpun_field_types',
		 *         function ( $types ) {
		 *             $types['colour'] = array(
		 *                 'render'   => function ( $field, $name, $id, $value ) {
		 *                     return '<input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
		 *                 },
		 *                 'sanitize' => function ( $value ) {
		 *                     return sanitize_hex_color( $value ) ? sanitize_hex_color( $value ) : new WP_Error( 'invalid_colour', 'Enter a colour.' );
		 *                 },
		 *             );
		 *             return $types;
		 *         }
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, array{render: callable, sanitize: callable}> $types Field types by name.
		 */
		$filtered = apply_filters( 'sc_wpun_field_types', $builtin );
		if ( ! is_array( $filtered ) ) {
			return $builtin;
		}

		$types = array();
		foreach ( $filtered as $type => $handler ) {
			$type = (string) $type;
			if ( is_array( $handler ) && isset( $handler['render'], $handler['sanitize'] ) && is_callable( $handler['render'] ) && is_callable( $handler['sanitize'] ) ) {
				$types[ $type ] = array(
					'render'   => $handler['render'],
					'sanitize' => $handler['sanitize'],
				);
			} else {
				self::complain(
					'malformed:' . $type,
					__METHOD__,
					sprintf(
						// translators: %s: Field type.
						__( 'The field type "%s" must be an array with a callable "render" and a callable "sanitize", so it is ignored.', 'wp-updates-notifier' ),
						$type
					)
				);
				if ( isset( $builtin[ $type ] ) ) {
					$types[ $type ] = $builtin[ $type ];
				}
			}
		}

		return $types;
	}

	/**
	 * The handler of a type, aliases resolved.
	 *
	 * @param mixed $type The type of a field schema.
	 *
	 * @return array{render: callable, sanitize: callable}|null Null when no handler is registered.
	 */
	public static function get( $type ): ?array {
		$type = self::canonical( $type );

		return '' === $type ? null : ( self::all()[ $type ] ?? null );
	}

	/**
	 * Tell the developer a field has a type nothing handles (once per type).
	 *
	 * @param mixed $type The type of a field schema.
	 *
	 * @return void
	 */
	public static function unknown( $type ): void {
		$shown = is_string( $type ) ? $type : gettype( $type );
		self::complain(
			'unknown:' . $shown,
			__METHOD__,
			sprintf(
				// translators: %s: Field type.
				__( 'No field type "%s" is registered, so the field is ignored. Register it with the sc_wpun_field_types filter.', 'wp-updates-notifier' ),
				$shown
			)
		);
	}

	/**
	 * Whether a field's value is kept secret: a `secret` type, or any known type with `secret` set.
	 *
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return bool
	 */
	public static function is_secret( array $field ): bool {
		$type = self::canonical( $field['type'] ?? null );

		return 'secret' === $type || true === ( $field['secret'] ?? false );
	}

	/**
	 * Whether the edit form blanks a field's value that comes from outside the database.
	 *
	 * True unless the type is a number, boolean or enum, or the schema sets `show_external`. A secret is not
	 * blanked here: the field renderer already tells its control only whether a value is saved.
	 *
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return bool
	 */
	public static function hides_external( array $field ): bool {
		return true !== ( $field['show_external'] ?? false ) && ! self::is_secret( $field ) && ! in_array( self::canonical( $field['type'] ?? null ), self::EXTERNAL_SHOWN, true );
	}

	/**
	 * Check and clean the settings submitted for an instance against a field schema.
	 *
	 * Keeps only the declared fields. For each it runs `sanitize_callback`, then the sanitiser of the type, then
	 * enforces `required` and runs `validate_callback`. A field missing from the submission stays missing, unless
	 * it is required and nothing is stored, or it is a secret whose stored value is kept. Fields of an unknown
	 * type are dropped.
	 *
	 * @param array<string, array<string, mixed>> $fields   Field schemas by name.
	 * @param array<string, mixed>                $settings Submitted settings.
	 * @param array<string, mixed>                $stored   The settings stored for the instance.
	 *
	 * @return array<string, mixed>|\WP_Error The settings to store.
	 */
	public static function validate( array $fields, array $settings, array $stored ) {
		$clean = array();

		foreach ( $fields as $name => $field ) {
			$name  = (string) $name;
			$field = (array) $field;

			$handler = self::get( $field['type'] ?? null );
			if ( null === $handler ) {
				self::unknown( $field['type'] ?? null );
				continue;
			}

			$required = true === ( $field['required'] ?? false );
			$schema   = $field + array( 'name' => $name );
			$present  = array_key_exists( $name, $settings );
			$value    = $present ? $settings[ $name ] : null;

			if ( self::is_secret( $field ) ) {
				$clear = array_key_exists( $name . '__clear', $settings ) && in_array( $settings[ $name . '__clear' ], array( true, 1, '1' ), true );
				if ( $clear ) {
					if ( $required ) {
						return self::required_error( $name, $field );
					}
					$clean[ $name ] = '';
					continue;
				}
				if ( self::is_empty( $value ) ) {
					if ( array_key_exists( $name, $stored ) ) {
						// Nothing new was typed: the saved value stays as it is.
						$value = $stored[ $name ];
						if ( $required && self::is_empty( $value ) ) {
							return self::required_error( $name, $field );
						}
						$clean[ $name ] = $value;
						continue;
					}
					if ( $required ) {
						return self::required_error( $name, $field );
					}
					continue;
				}
			} elseif ( ! $present ) {
				if ( $required && ( ! array_key_exists( $name, $stored ) || self::is_empty( $stored[ $name ] ) ) ) {
					return self::required_error( $name, $field );
				}
				continue;
			}

			$number_empty = 'number' === self::canonical( $field['type'] ?? null ) && ( '' === $value || null === $value );
			if ( $number_empty ) {
				// An empty number is no number: it is left out, so the stored value or the default applies.
				if ( $required ) {
					return self::required_error( $name, $field );
				}
				continue;
			}

			if ( isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] ) ) {
				$value = call_user_func( $field['sanitize_callback'], $value );
			}

			// An empty submission of a required field says so, rather than that it is not a valid choice. An empty boolean is off.
			if ( $required && self::is_empty( $value ) && 'boolean' !== self::canonical( $field['type'] ?? null ) ) {
				return self::required_error( $name, $field );
			}

			$value = call_user_func( $handler['sanitize'], $value, $schema );
			if ( $value instanceof \WP_Error ) {
				return self::with_field( $value, $name );
			}

			if ( $required && self::is_empty( $value ) ) {
				return self::required_error( $name, $field );
			}

			if ( isset( $field['validate_callback'] ) && is_callable( $field['validate_callback'] ) ) {
				$verdict = call_user_func( $field['validate_callback'], $value );
				if ( $verdict instanceof \WP_Error ) {
					return self::with_field( $verdict, $name );
				}
				if ( false === $verdict ) {
					return self::invalid_error( $name, $field );
				}
			}

			$clean[ $name ] = $value;
		}

		return $clean;
	}

	/**
	 * Clean a text value: a string or a number, with tags and line breaks removed.
	 *
	 * @param mixed                $value Value.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array{ok: bool, value: mixed, message?: string}
	 */
	public static function text( $value, array $field ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same signature as the other types.
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return self::bad();
		}

		return self::good( sanitize_text_field( (string) $value ) );
	}

	/**
	 * Clean a secret: a string or a number, trimmed, with control characters removed.
	 *
	 * Nothing else is touched: tokens and passwords can hold `<`, `%` and other characters that
	 * sanitize_text_field() would remove, and the value is never output.
	 *
	 * @param mixed                $value Value.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array{ok: bool, value: mixed, message?: string}
	 */
	public static function secret( $value, array $field ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same signature as the other types.
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return self::bad();
		}

		return self::good( trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ) ) );
	}

	/**
	 * Clean a multi-line text value.
	 *
	 * @param mixed                $value Value.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array{ok: bool, value: mixed, message?: string}
	 */
	public static function textarea( $value, array $field ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same signature as the other types.
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return self::bad();
		}

		return self::good( sanitize_textarea_field( (string) $value ) );
	}

	/**
	 * Clean a URL: an empty one, or an http or https one with a host.
	 *
	 * No DNS lookup is made.
	 *
	 * @param mixed                $value Value.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array{ok: bool, value: mixed, message?: string}
	 */
	public static function url( $value, array $field ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same signature as the other types.
		if ( ! is_string( $value ) ) {
			return self::bad();
		}

		$value = trim( $value );
		if ( '' === $value ) {
			return self::good( '' );
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || '' === $parts['host'] || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return self::bad( __( 'Enter a full web address that starts with http:// or https://.', 'wp-updates-notifier' ) );
		}

		$clean = esc_url_raw( $value, array( 'http', 'https' ) );

		return '' === $clean ? self::bad( __( 'Enter a full web address that starts with http:// or https://.', 'wp-updates-notifier' ) ) : self::good( $clean );
	}

	/**
	 * Clean a list of email addresses: a comma-separated string, or a list of strings.
	 *
	 * @param mixed                $value Value.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array{ok: bool, value: mixed, message?: string} The value is the addresses joined with commas.
	 */
	public static function email_list( $value, array $field ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Same signature as the other types.
		$entries = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $part ) {
			if ( ! is_string( $part ) ) {
				return self::bad();
			}
			$entries = array_merge( $entries, explode( ',', $part ) );
		}

		$addresses = array();
		foreach ( $entries as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			$address = sanitize_email( $entry );
			if ( ! is_email( $address ) ) {
				return self::bad( __( 'One or more email addresses are not valid.', 'wp-updates-notifier' ) );
			}
			$addresses[] = $address;
		}

		return self::good( implode( ',', $addresses ) );
	}

	/**
	 * Clean a whole number within the field's `min` and `max`.
	 *
	 * @param mixed                $value Value: an integer, or a string holding one.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return array{ok: bool, value: mixed, message?: string}
	 */
	public static function number( $value, array $field ): array {
		if ( is_string( $value ) ) {
			$value = filter_var( trim( $value ), FILTER_VALIDATE_INT );
		}
		if ( ! is_int( $value ) ) {
			return self::bad( __( 'Enter a whole number.', 'wp-updates-notifier' ) );
		}

		$min = self::bound( $field, 'min' );
		$max = self::bound( $field, 'max' );
		if ( null !== $min && $value < $min ) {
			return self::bad(
				sprintf(
					// translators: %d: Smallest value.
					__( 'Enter a number that is at least %d.', 'wp-updates-notifier' ),
					$min
				)
			);
		}
		if ( null !== $max && $value > $max ) {
			return self::bad(
				sprintf(
					// translators: %d: Largest value.
					__( 'Enter a number that is at most %d.', 'wp-updates-notifier' ),
					$max
				)
			);
		}

		return self::good( $value );
	}

	/**
	 * A `min` or `max` of a schema.
	 *
	 * @param array<string, mixed> $field Field schema.
	 * @param string               $key   `min` or `max`.
	 *
	 * @return int|null Null when not set to a whole number.
	 */
	public static function bound( array $field, string $key ): ?int {
		if ( ! isset( $field[ $key ] ) || is_bool( $field[ $key ] ) ) {
			return null;
		}
		$bound = is_string( $field[ $key ] ) ? filter_var( trim( $field[ $key ] ), FILTER_VALIDATE_INT ) : $field[ $key ];

		return is_int( $bound ) ? $bound : null;
	}

	/**
	 * Draw an enum with the built-in markup.
	 *
	 * @param array<string, mixed> $field        Field schema.
	 * @param string               $name         Input name.
	 * @param string               $id           Input id.
	 * @param mixed                $value        Current value.
	 * @param bool                 $disabled     Whether read-only.
	 * @param string               $described_by Ids of describing elements.
	 * @param string               $error        Error message, if any.
	 *
	 * @return string
	 */
	public static function render_enum( array $field, string $name, string $id, $value, bool $disabled = false, string $described_by = '', string $error = '' ): string {
		return Field_Renderer::enum( $field, $name, $id, self::enum_value( $value ), $disabled, $described_by, array(), $error );
	}

	/**
	 * The value of an enum as the renderer takes it: a list, or a string.
	 *
	 * @param mixed $value Current value.
	 *
	 * @return string|array<int,mixed>
	 */
	public static function enum_value( $value ) {
		return is_array( $value ) ? array_values( $value ) : ( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * The built-in types, in the shape of the `sc_wpun_field_types` filter.
	 *
	 * @return array<string, array{render: callable, sanitize: callable}>
	 */
	private static function builtin(): array {
		$types = array();

		foreach ( array( 'text', 'textarea', 'url', 'email_list', 'secret', 'number' ) as $type ) {
			$types[ $type ] = array(
				'render'   => array( Field_Renderer::class, 'secret' === $type ? 'secret' : $type ),
				'sanitize' => static function ( $value, $field ) use ( $type ) {
					$result = call_user_func( array( self::class, $type ), $value, $field );

					return self::to_value( $result, $field );
				},
			);
		}

		$types['boolean'] = array(
			'render'   => array( Field_Renderer::class, 'boolean' ),
			'sanitize' => static function ( $value, $field ) {
				$checked = Boolean_Field::sanitize( $value, $field );

				return $checked['ok'] ? $checked['value'] : self::invalid_error( (string) ( $field['name'] ?? '' ), $field );
			},
		);

		$types['enum'] = array(
			'render'   => array( self::class, 'render_enum' ),
			'sanitize' => static function ( $value, $field ) {
				$checked = Enum_Field::sanitize( $value, $field );
				if ( $checked['ok'] ) {
					return $checked['value'];
				}

				return new \WP_Error(
					'invalid_field',
					sprintf(
						// translators: %s: Name of the field.
						__( 'The choice for "%s" is not valid.', 'wp-updates-notifier' ),
						self::label( (string) ( $field['name'] ?? '' ), $field )
					)
				);
			},
		);

		return $types;
	}

	/**
	 * The value of a result, or a WP_Error for one that is not ok.
	 *
	 * @param array{ok: bool, value: mixed, message?: string} $result Result of a sanitiser.
	 * @param array<string, mixed>                            $field  Field schema.
	 *
	 * @return mixed|\WP_Error
	 */
	private static function to_value( array $result, array $field ) {
		if ( $result['ok'] ) {
			return $result['value'];
		}

		if ( isset( $result['message'] ) ) {
			return new \WP_Error( 'invalid_field', $result['message'] );
		}

		return self::invalid_error( (string) ( $field['name'] ?? '' ), $field );
	}

	/**
	 * A result that is ok.
	 *
	 * @param mixed $value The clean value.
	 *
	 * @return array{ok: bool, value: mixed}
	 */
	private static function good( $value ): array {
		return array(
			'ok'    => true,
			'value' => $value,
		);
	}

	/**
	 * A result that is not ok.
	 *
	 * @param string $message Why, if more than "not valid" can be said.
	 *
	 * @return array{ok: bool, value: mixed, message?: string}
	 */
	private static function bad( string $message = '' ): array {
		$result = array(
			'ok'    => false,
			'value' => null,
		);
		if ( '' !== $message ) {
			$result['message'] = $message;
		}

		return $result;
	}

	/**
	 * Whether a value counts as empty for `required`: an empty string, null or an empty list. False and 0 do not.
	 *
	 * @param mixed $value Value.
	 *
	 * @return bool
	 */
	private static function is_empty( $value ): bool {
		return '' === $value || null === $value || array() === $value;
	}

	/**
	 * The label of a field, or its name.
	 *
	 * @param string               $name  Name.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return string
	 */
	private static function label( string $name, array $field ): string {
		return isset( $field['label'] ) && is_string( $field['label'] ) && '' !== $field['label'] ? $field['label'] : $name;
	}

	/**
	 * The error of a required field without a value.
	 *
	 * @param string               $name  Name.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return \WP_Error
	 */
	private static function required_error( string $name, array $field ): \WP_Error {
		return new \WP_Error(
			'invalid_field',
			sprintf(
				// translators: %s: Label of the field.
				__( '%s is required.', 'wp-updates-notifier' ),
				self::label( $name, $field )
			),
			array( 'field' => $name )
		);
	}

	/**
	 * The error of a value that is not valid.
	 *
	 * @param string               $name  Name.
	 * @param array<string, mixed> $field Field schema.
	 *
	 * @return \WP_Error
	 */
	private static function invalid_error( string $name, array $field ): \WP_Error {
		return new \WP_Error(
			'invalid_field',
			sprintf(
				// translators: %s: Name of the field.
				__( 'The value for "%s" is not valid.', 'wp-updates-notifier' ),
				self::label( $name, $field )
			),
			array( 'field' => $name )
		);
	}

	/**
	 * The same error with the field named in its data.
	 *
	 * @param \WP_Error $error Error.
	 * @param string    $name  Name of the field.
	 *
	 * @return \WP_Error
	 */
	private static function with_field( \WP_Error $error, string $name ): \WP_Error {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = null === $data ? array() : array( 'data' => $data );
		}
		$data['field'] = $name;

		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * Tell the developer something is wrong, once per key.
	 *
	 * @param string $key     What it is about.
	 * @param string $method  The method to report.
	 * @param string $message Message.
	 *
	 * @return void
	 */
	private static function complain( string $key, string $method, string $message ): void {
		if ( isset( self::$warned[ $key ] ) ) {
			return;
		}
		self::$warned[ $key ] = true;
		_doing_it_wrong( esc_html( $method ), esc_html( $message ), '2.0.0' );
	}
}
