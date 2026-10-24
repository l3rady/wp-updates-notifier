<?php
/**
 * Resolving configuration from outside the database.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Finds the value a constant, environment variable or add-on source supplies for a setting or
 * channel field, casts it to the type the field expects, and keeps a list of the values it could not use.
 *
 * The order is: the `sc_wpun_config_value` filter, then the sources (constants, environment
 * variables if the site opted in, then sources added with `sc_wpun_config_sources`). When none supplies a
 * value the database is used, which is for the caller to do.
 *
 * Nothing here ever logs or reports a value, only where it came from.
 *
 * @internal
 */
final class Config {

	/**
	 * Sources, built on first use.
	 *
	 * @var Config_Source[]|null
	 */
	private $sources = null;

	/**
	 * Results by context key and site.
	 *
	 * @var array<string, Config_Value|null>
	 */
	private $cache = array();

	/**
	 * Counts the flushes.
	 *
	 * @var int
	 */
	private $generation = 0;

	/**
	 * Values that could not be used.
	 *
	 * @var array<string, array{key: string, source: string, message: string}>
	 */
	private $problems = array();

	/**
	 * The value supplied from outside the database, as the source gave it.
	 *
	 * @param Config_Context $context What to resolve.
	 *
	 * @return Config_Value|null Null when nothing outside the database supplies one.
	 */
	public function resolve( Config_Context $context ): ?Config_Value {
		$cache_key = 'raw|' . $context->get_site_id() . '|' . $context->get_key();
		if ( array_key_exists( $cache_key, $this->cache ) ) {
			return $this->cache[ $cache_key ];
		}

		$key    = $context->get_key();
		$value  = null;
		$source = null;

		foreach ( $this->sources() as $candidate ) {
			try {
				$found = $candidate->get( $key, $context );
			} catch ( \Throwable $e ) {
				// Only the class of the exception: its message could hold a secret.
				$this->add_problem( $key, $this->label( $candidate, $key ), sprintf( 'The source failed with %s.', get_class( $e ) ) );
				continue;
			}
			if ( null !== $found ) {
				$value  = $found;
				$source = $this->label( $candidate, $key );
				break;
			}
		}

		/**
		 * Filters the value of a setting or channel field, the last word before the database is used.
		 *
		 * Receives what the configuration sources supplied, or null if none did. Return a value
		 * to override it, or null to use the saved value from the database. Values are scalars
		 * and are cast to the type of the setting or field. Do not log the value: it can be a secret.
		 *
		 * Example, take the check frequency from a site option of your own:
		 *
		 *     add_filter(
		 *         'sc_wpun_config_value',
		 *         function ( $value, $context, $source ) {
		 *             if ( 'frequency' === $context->get_setting() ) {
		 *                 return get_site_option( 'my_frequency', $value );
		 *             }
		 *             return $value;
		 *         },
		 *         10,
		 *         3
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param string|bool|int|float|null $value   The value the sources supplied, or null.
		 * @param Config_Context             $context What is asked for, and the site.
		 * @param string|null                $source  Where the value came from, or null.
		 */
		$final = apply_filters( 'sc_wpun_config_value', $value, $context, $source );

		$result = null;
		if ( null !== $final ) {
			if ( $final !== $value ) {
				// translators: Describes where a configuration value comes from; shown in "Set by %s". Keep sc_wpun_config_value as is.
				$source = __( 'the sc_wpun_config_value filter', 'wp-updates-notifier' );
			}
			$result = new Config_Value( $final, (string) $source, $key );
		}

		$this->cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * The external value cast to the type of the field, or null to use the database.
	 *
	 * A value that cannot be used is recorded as a problem and not used.
	 *
	 * @param Config_Context       $context What to resolve.
	 * @param array<string, mixed> $field   Field schema: `type` (boolean, checkbox, number, enum, select, or text-like) and, for
	 *                                      `enum` and `select`, `options` (and for `enum` `multiple`, `min` and `max`, see
	 *                                      Enum_Field); for `number`, optional `min` and `max`.
	 *
	 * @return Config_Value|null
	 */
	public function resolve_typed( Config_Context $context, array $field ): ?Config_Value {
		$cache_key = 'typed|' . $context->get_site_id() . '|' . $context->get_key();
		if ( array_key_exists( $cache_key, $this->cache ) ) {
			return $this->cache[ $cache_key ];
		}

		$raw    = $this->resolve( $context );
		$result = null;
		if ( null !== $raw ) {
			$cast = $this->cast( $raw->get_value(), $field );
			if ( $cast['ok'] ) {
				$result = new Config_Value( $cast['value'], $raw->get_source(), $raw->get_key() );
			} else {
				// A setting with no storage has no saved value to fall back to.
				$message = isset( $field['storage'] ) && false === $field['storage']
					? __( 'The value is not valid for this setting, so the default is used.', 'wp-updates-notifier' )
					: __( 'The value is not valid for this setting, so the saved one is used.', 'wp-updates-notifier' );
				$this->add_problem( $raw->get_key(), $raw->get_source(), $message );
			}
		}

		$this->cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Resolve a field that must not silently fall back to the database.
	 *
	 * @param Config_Context       $context What to resolve.
	 * @param array<string, mixed> $field   Field schema, see resolve_typed().
	 *
	 * @return array{ok: bool, value: Config_Value|null} `ok` is false when a value is supplied but cannot be used
	 *                                                   (a problem is recorded); `value` is null when nothing is supplied.
	 */
	public function resolve_field( Config_Context $context, array $field ): array {
		$raw = $this->resolve( $context );
		if ( null === $raw ) {
			return array(
				'ok'    => true,
				'value' => null,
			);
		}

		$cast = $this->cast( $raw->get_value(), $field );
		if ( ! $cast['ok'] ) {
			$this->add_problem( $raw->get_key(), $raw->get_source(), __( 'The value is not valid for this field.', 'wp-updates-notifier' ) );

			return array(
				'ok'    => false,
				'value' => null,
			);
		}

		return array(
			'ok'    => true,
			'value' => new Config_Value( $cast['value'], $raw->get_source(), $raw->get_key() ),
		);
	}

	/**
	 * Record a value that cannot be used. The message must not contain the value.
	 *
	 * @param string $key     The key.
	 * @param string $source  Where it came from.
	 * @param string $message Why it was not used.
	 *
	 * @return void
	 */
	public function report_problem( string $key, string $source, string $message ): void {
		$this->add_problem( $key, $source, $message );
	}

	/**
	 * Changes every time the cache is flushed, so holders of derived data know to drop it.
	 *
	 * @return int
	 */
	public function generation(): int {
		return $this->generation;
	}

	/**
	 * Cast a raw value to the type of a field.
	 *
	 * @param mixed                $raw   The value a source supplied.
	 * @param array<string, mixed> $field Field schema, see resolve_typed().
	 *
	 * @return array{ok: bool, value: mixed}
	 */
	public function cast( $raw, array $field ): array {
		$bad  = array(
			'ok'    => false,
			'value' => null,
		);
		$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';

		if ( ! is_scalar( $raw ) ) {
			return $bad; // Arrays, objects and null are never usable.
		}

		switch ( $type ) {
			case 'boolean':
			case 'checkbox':
				return Boolean_Field::cast_external( $raw, $field );

			case 'number':
				if ( is_bool( $raw ) || ! is_numeric( $raw ) ) {
					return $bad;
				}
				$number = (int) $raw;
				if ( ( isset( $field['min'] ) && $number < $field['min'] ) || ( isset( $field['max'] ) && $number > $field['max'] ) ) {
					return $bad;
				}
				return array(
					'ok'    => true,
					'value' => $number,
				);

			case 'enum':
			case 'select':
				return Enum_Field::cast_external( $raw, $field );

			default:
				if ( is_bool( $raw ) ) {
					return $bad;
				}
				return array(
					'ok'    => true,
					'value' => (string) $raw,
				);
		}
	}

	/**
	 * Values that could not be used so far in this request.
	 *
	 * @return array<int, array{key: string, source: string, message: string}> Never contains a value.
	 */
	public function problems(): array {
		return array_values( $this->problems );
	}

	/**
	 * Forget everything cached and recorded, for tests and when the site changes.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->sources  = null;
		$this->cache    = array();
		$this->problems = array();
		++$this->generation;
	}

	/**
	 * Whether the environment variable SC_WPUN_LOAD_FROM_ENV switches reading environment variables on.
	 *
	 * Read from the environment itself, so the switch lives where the values do. A value that is not
	 * a recognised on or off value is reported and counts as off.
	 *
	 * @return bool
	 */
	private function env_opt_in(): bool {
		$key   = 'SC_WPUN_LOAD_FROM_ENV';
		$value = ( new Env_Source() )->get( $key, Config_Context::setting( 'load_from_env' ) );
		if ( null === $value ) {
			return false;
		}

		$cast = $this->cast( $value, array( 'type' => 'boolean' ) );
		if ( ! $cast['ok'] ) {
			$this->add_problem( $key, ( new Env_Source() )->get_label( $key ), __( 'The value is not a recognised on or off value (1, true, yes, on, 0, false, no, off), so environment variables are not read.', 'wp-updates-notifier' ) );
			return false;
		}

		return true === $cast['value'];
	}

	/**
	 * The sources in order, built once.
	 *
	 * @return Config_Source[]
	 */
	private function sources(): array {
		if ( null !== $this->sources ) {
			return $this->sources;
		}

		$sources = array( new Constant_Source() );

		$load_from_env = $this->env_opt_in();

		/**
		 * Filters whether configuration is also read from environment variables.
		 *
		 * Off unless the environment variable SC_WPUN_LOAD_FROM_ENV is set to an "on" value (1, true,
		 * yes or on), so variables a host happens to set never change the plugin's behaviour by surprise.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $load_from_env Whether to read environment variables.
		 */
		if ( true === apply_filters( 'sc_wpun_load_from_env', $load_from_env ) ) {
			$sources[] = new Env_Source();
		}

		/**
		 * Filters the configuration sources, in the order they are asked.
		 *
		 * The first source to return a value wins. By default constants come first, then environment
		 * variables if enabled. Add your own to read from a secrets service, or reorder the list.
		 * Entries that are not Config_Source objects are dropped.
		 *
		 * Example, ask a source of your own after the constants:
		 *
		 *     add_filter(
		 *         'sc_wpun_config_sources',
		 *         function ( $sources ) {
		 *             $sources[] = new My_Vault_Source();
		 *             return $sources;
		 *         }
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param Config_Source[] $sources The sources.
		 */
		$filtered = apply_filters( 'sc_wpun_config_sources', $sources );

		$this->sources = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $source ) {
			if ( $source instanceof Config_Source ) {
				$this->sources[] = $source;
			} else {
				_doing_it_wrong( 'sc_wpun_config_sources', esc_html__( 'Every entry must implement WPUN\\Config_Source.', 'wp-updates-notifier' ), '2.0.0' );
			}
		}

		return $this->sources;
	}

	/**
	 * A source's description of a key, whatever the source does.
	 *
	 * @param Config_Source $source The source.
	 * @param string        $key    The key.
	 *
	 * @return string
	 */
	private function label( Config_Source $source, string $key ): string {
		try {
			return $source->get_label( $key );
		} catch ( \Throwable $e ) {
			// translators: Describes where a configuration value comes from; shown in "Set by %s".
			return __( 'a configuration source', 'wp-updates-notifier' );
		}
	}

	/**
	 * Record a value that could not be used.
	 *
	 * @param string $key     The key.
	 * @param string $source  Where it came from.
	 * @param string $message Why it was not used. Never contains the value.
	 *
	 * @return void
	 */
	private function add_problem( string $key, string $source, string $message ): void {
		$this->problems[ $key . '|' . $source . '|' . $message ] = array(
			'key'     => $key,
			'source'  => $source,
			'message' => $message,
		);
	}
}
