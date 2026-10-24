<?php
/**
 * Storage of the configured channels.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Stores the channel instances of the site, in the order they were added.
 *
 * The methods all() and get() return the effective instances: the stored ones plus those defined in code
 * (the `sc_wpun_channels` filter and the SC_WPUN_CHANNELS constant), with field values supplied
 * from outside the database laid over them and checked against the channel's schema. save()
 * never writes a value supplied from outside, and instances defined in code cannot be saved or
 * deleted. The module of an instance does not need to be registered: instances of a type that
 * is not available right now (an add-on that is deactivated) are kept untouched. Part of the
 * extension API.
 *
 * @since 2.0.0
 */
class Channel_Store {
	const OPT_FIELD = 'sc_wpun_channels';

	/**
	 * Resolver of values supplied from outside the database.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Registered channels, to know the fields of an instance.
	 *
	 * @var Channel_Registry
	 */
	private $registry;

	/**
	 * Instances defined in code, with the Config generation they were built in.
	 *
	 * @var array<string, Channel_Instance>|null
	 */
	private $code_defined = null;

	/**
	 * The Config generation the code-defined instances belong to.
	 *
	 * @var int
	 */
	private $code_generation = -1;

	/**
	 * Field names already complained about.
	 *
	 * @var array<string, true>
	 */
	private $warned = array();

	/**
	 * Constructor.
	 *
	 * @param Config           $config   Resolver of values supplied from outside the database.
	 * @param Channel_Registry $registry Registered channels.
	 */
	public function __construct( Config $config, Channel_Registry $registry ) {
		$this->config   = $config;
		$this->registry = $registry;
	}

	/**
	 * Every instance that can be read, keyed by id, in stored order, as it is in effect.
	 *
	 * That is the stored instances with the instances defined in code added (a code-defined instance
	 * replaces a stored one with the same id), and with the values constants, environment variables or
	 * other configuration sources supply laid over their settings and `enabled` switch.
	 * Stored entries that cannot be turned into an instance are skipped.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, Channel_Instance>
	 */
	public function all(): array {
		$instances = array();

		foreach ( $this->raw_all() as $id => $raw ) {
			$instances[ $id ] = $this->overlay( $raw )[0];
		}

		return $instances;
	}

	/**
	 * One instance, as it is in effect.
	 *
	 * @since 2.0.0
	 *
	 * @param string $id Identifier.
	 *
	 * @return Channel_Instance|null Null if there is no usable instance with this id.
	 */
	public function get( string $id ): ?Channel_Instance {
		$raw = $this->raw( $id );

		return null === $raw ? null : $this->overlay( $raw )[0];
	}

	/**
	 * The instances as stored, with no outside values laid over them and none defined in code.
	 *
	 * @internal
	 *
	 * @return array<string, Channel_Instance>
	 */
	public function stored_all(): array {
		$instances = array();

		foreach ( $this->read() as $id => $entry ) {
			$instance = $this->hydrate( (string) $id, $entry );
			if ( null !== $instance ) {
				$instances[ $instance->get_id() ] = $instance;
			}
		}

		return $instances;
	}

	/**
	 * Whether there is a usable instance with this id.
	 *
	 * @since 2.0.0
	 *
	 * @param string $id Identifier.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		return null !== $this->raw( $id );
	}

	/**
	 * Whether an instance is defined in code, and so cannot be changed or deleted here.
	 *
	 * @since 2.0.0
	 *
	 * @param string $id Identifier.
	 *
	 * @return bool
	 */
	public function is_code_defined( string $id ): bool {
		return isset( $this->code_defined()[ $id ] );
	}

	/**
	 * The constant names of the supplied values that make an instance misconfigured: a value
	 * that cannot be used, or that the channel refuses.
	 *
	 * @internal
	 *
	 * @param string $id Identifier.
	 *
	 * @return string[] Empty when the instance is fine, or there is none.
	 */
	public function problems( string $id ): array {
		$raw = $this->raw( $id );

		return null === $raw ? array() : $this->overlay( $raw )[1];
	}

	/**
	 * Where the value of a field comes from, if it is supplied from outside the database.
	 *
	 * @internal
	 *
	 * @param string $id    Identifier.
	 * @param string $field Field name, or `enabled`.
	 *
	 * @return string|null For example "constant SC_WPUN_CHANNEL_EMAIL__TO"; null if the database is used.
	 */
	public function external_source( string $id, string $field ): ?string {
		if ( ! $this->is_valid_name( $field ) ) {
			return null;
		}
		$value = $this->config->resolve( Config_Context::channel_field( $id, $field ) );

		return null === $value ? null : $value->get_source();
	}

	/**
	 * Add an instance, or replace the one with the same id (which keeps its position).
	 *
	 * Values that are supplied from outside the database are never written: for those fields
	 * (and the `enabled` switch) the previously stored value is kept. An instance defined in
	 * code cannot be saved.
	 *
	 * @since 2.0.0
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return bool False if nothing was written because the instance is defined in code.
	 */
	public function save( Channel_Instance $instance ): bool {
		$id = $instance->get_id();
		if ( $this->is_code_defined( $id ) ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'An instance defined in code cannot be saved.', 'wp-updates-notifier' ), '2.0.0' );
			return false;
		}

		$stored   = $this->read();
		$previous = isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) ? $stored[ $id ] : array();
		$settings = $instance->get_settings();
		$enabled  = $instance->is_enabled();

		foreach ( $this->external_fields( $id, $instance->get_type() ) as $field ) {
			if ( 'enabled' === $field ) {
				$enabled = ! empty( $previous['enabled'] );
			} elseif ( isset( $previous['settings'] ) && is_array( $previous['settings'] ) && array_key_exists( $field, $previous['settings'] ) ) {
				$settings[ $field ] = $previous['settings'][ $field ];
			} else {
				unset( $settings[ $field ] );
			}
		}

		$stored[ $id ] = array(
			'type'     => $instance->get_type(),
			'label'    => $instance->get_label(),
			'enabled'  => $enabled,
			'settings' => $settings,
			'routing'  => $instance->get_routing(),
		);

		$this->write( $stored );

		return true;
	}

	/**
	 * Write an instance exactly as given, with no outside values filtered out and no check for code-defined ids.
	 *
	 * Only for the settings upgrade, which moves values that come from the database. A copy stored under
	 * the id of a code-defined instance is harmless, and becomes the active one if the definition is removed.
	 *
	 * @internal
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return void
	 */
	public function save_raw( Channel_Instance $instance ): void {
		$stored                        = $this->read();
		$stored[ $instance->get_id() ] = array(
			'type'     => $instance->get_type(),
			'label'    => $instance->get_label(),
			'enabled'  => $instance->is_enabled(),
			'settings' => $instance->get_settings(),
			'routing'  => $instance->get_routing(),
		);

		$this->write( $stored );
	}

	/**
	 * Remove an instance. An instance defined in code cannot be deleted.
	 *
	 * @since 2.0.0
	 *
	 * @param string $id Identifier.
	 *
	 * @return bool False if there was no stored instance with this id, or it is defined in code.
	 */
	public function delete( string $id ): bool {
		if ( $this->is_code_defined( $id ) ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'An instance defined in code cannot be deleted.', 'wp-updates-notifier' ), '2.0.0' );
			return false;
		}

		$stored = $this->read();

		if ( ! array_key_exists( $id, $stored ) ) {
			return false;
		}

		$instance = $this->get( $id );
		unset( $stored[ $id ] );
		$this->write( $stored );

		if ( null !== $instance ) {
			/**
			 * Fires after a channel instance was deleted.
			 *
			 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
			 *
			 * @since 2.0.0
			 *
			 * @param string           $id       Identifier of the deleted instance.
			 * @param Channel_Instance $instance The deleted instance.
			 */
			do_action( 'sc_wpun_channel_deleted', $id, $instance );
		}

		return true;
	}

	/**
	 * Stored and code-defined instances, before any outside value is laid over them.
	 *
	 * @return array<string, Channel_Instance>
	 */
	private function raw_all(): array {
		$instances = $this->stored_all();

		foreach ( $this->code_defined() as $id => $instance ) {
			$instances[ $id ] = $instance; // Replaces a stored one in place, or is added at the end.
		}

		return $instances;
	}

	/**
	 * One instance before any outside value is laid over it.
	 *
	 * @param string $id Identifier.
	 *
	 * @return Channel_Instance|null
	 */
	private function raw( string $id ): ?Channel_Instance {
		$code = $this->code_defined();
		if ( isset( $code[ $id ] ) ) {
			return $code[ $id ];
		}

		$stored = $this->read();

		return array_key_exists( $id, $stored ) ? $this->hydrate( $id, $stored[ $id ] ) : null;
	}

	/**
	 * Whether a name can be part of a constant name: lower case letters and digits, single underscores between.
	 *
	 * @param string $name Instance id or field name.
	 *
	 * @return bool
	 */
	private function is_valid_name( string $name ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(?:_[a-z0-9]+)*$/D', $name );
	}

	/**
	 * The fields of a channel that can be supplied from outside, by name.
	 *
	 * Names that cannot be part of a constant name and the reserved name `enabled` are skipped, with a
	 * notice to the developer. An instance of a type with no registered channel only has `enabled`.
	 *
	 * @param string $type Channel type.
	 *
	 * @return array<string, array<string, mixed>> Field schema by name.
	 */
	private function external_schemas( string $type ): array {
		$channel = $this->registry->get( $type );
		$schemas = array();

		foreach ( null === $channel ? array() : $channel->get_form_fields() as $name => $field ) {
			$name = (string) $name;
			if ( 'enabled' === $name || ! $this->is_valid_name( $name ) ) {
				if ( ! isset( $this->warned[ $type . '|' . $name ] ) ) {
					$this->warned[ $type . '|' . $name ] = true;
					_doing_it_wrong(
						__METHOD__,
						esc_html(
							// translators: 1: Channel type. 2: Field name.
							sprintf( __( 'The field "%2$s" of the "%1$s" channel cannot be supplied from outside the database: the name "enabled" is reserved, and names may only contain a-z, 0-9 and single underscores.', 'wp-updates-notifier' ), $type, $name )
						),
						'2.0.0'
					);
				}
				continue;
			}
			$schemas[ $name ] = $field;
		}

		return $schemas;
	}

	/**
	 * The names of the fields of an instance that are supplied from outside the database, `enabled` included.
	 *
	 * @param string $id   Identifier.
	 * @param string $type Channel type.
	 *
	 * @return string[]
	 */
	private function external_fields( string $id, string $type ): array {
		$fields = array();
		$names  = array_merge( array( 'enabled' ), array_keys( $this->external_schemas( $type ) ) );

		foreach ( $names as $name ) {
			if ( null !== $this->config->resolve( Config_Context::channel_field( $id, (string) $name ) ) ) {
				$fields[] = (string) $name;
			}
		}

		return $fields;
	}

	/**
	 * An instance with the outside values laid over it, and the keys that make it misconfigured.
	 *
	 * A value that cannot be used (it fails the cast, the field's sanitizer, or the channel's check) is not
	 * put into the instance: the stored value stays, or the field is left out if nothing is stored, and the
	 * instance is listed as misconfigured so that nothing is delivered through it. Add-on code can throw;
	 * that is reported by the exception class only. `validate_settings()` returns only its first error, so an
	 * invalid supplied value can go unreported while a stored field fails first.
	 *
	 * @param Channel_Instance $raw The instance as stored or defined in code.
	 *
	 * @return array{0: Channel_Instance, 1: string[]}
	 */
	private function overlay( Channel_Instance $raw ): array {
		$id       = $raw->get_id();
		$settings = $raw->get_settings();
		$enabled  = $raw->is_enabled();
		$bad      = array();
		$external = array();

		$switch = $this->config->resolve_field( Config_Context::channel_field( $id, 'enabled' ), Channel::enabled_field() );
		if ( ! $switch['ok'] ) {
			$bad[] = Config_Context::channel_field( $id, 'enabled' )->get_key();
		} elseif ( null !== $switch['value'] ) {
			$enabled = (bool) $switch['value']->get_value();
		}

		foreach ( $this->external_schemas( $raw->get_type() ) as $name => $schema ) {
			$context = Config_Context::channel_field( $id, $name );
			$found   = $this->config->resolve_field( $context, $schema );
			if ( ! $found['ok'] ) {
				$bad[] = $context->get_key();
			} elseif ( null !== $found['value'] ) {
				$value = $found['value']->get_value();
				if ( isset( $schema['sanitize_callback'] ) && is_callable( $schema['sanitize_callback'] ) ) {
					try {
						$value = call_user_func( $schema['sanitize_callback'], $value );
					} catch ( \Throwable $e ) {
						$this->report_throw( $found['value'], $e );
						$bad[] = $context->get_key();
						continue;
					}
				}
				$settings[ $name ] = $value;
				$external[ $name ] = $found['value'];
			}
		}

		$channel = $this->registry->get( $raw->get_type() );
		if ( null !== $channel && array() !== $external ) {
			$blame = array();
			try {
				// No stored settings: keeping a saved secret on an empty value is for forms, and an empty supplied secret must win.
				$checked = $channel->validate_settings( $settings );
				if ( $checked instanceof \WP_Error ) {
					$data  = $checked->get_error_data();
					$field = is_array( $data ) && isset( $data['field'] ) && is_string( $data['field'] ) ? $data['field'] : null;
					// Blame the field the channel names, or every supplied field if it names none. A bad stored value is not ours to report.
					$blame = null === $field ? array_keys( $external ) : ( isset( $external[ $field ] ) ? array( $field ) : array() );
					foreach ( $blame as $name ) {
						$this->config->report_problem( $external[ $name ]->get_key(), $external[ $name ]->get_source(), __( 'The value is not valid for this channel.', 'wp-updates-notifier' ) );
					}
				} elseif ( is_array( $checked ) ) {
					foreach ( array_keys( $external ) as $name ) {
						if ( array_key_exists( $name, $checked ) ) {
							$settings[ $name ] = $checked[ $name ];
						}
					}
				}
			} catch ( \Throwable $e ) {
				$blame = array_keys( $external );
				foreach ( $blame as $name ) {
					$this->report_throw( $external[ $name ], $e );
				}
			}

			$original = $raw->get_settings();
			foreach ( $blame as $name ) {
				$bad[] = $external[ $name ]->get_key();
				if ( array_key_exists( $name, $original ) ) {
					$settings[ $name ] = $original[ $name ];
				} else {
					unset( $settings[ $name ] );
				}
			}
		}

		return array(
			$raw->with_enabled( $enabled )->with_settings( $settings ),
			array_values( array_unique( $bad ) ),
		);
	}

	/**
	 * Record that add-on code threw while checking a supplied value. Only the exception class is kept.
	 *
	 * @param Config_Value $value The supplied value.
	 * @param \Throwable   $e     What was thrown.
	 *
	 * @return void
	 */
	private function report_throw( Config_Value $value, \Throwable $e ): void {
		$this->config->report_problem(
			$value->get_key(),
			$value->get_source(),
			// translators: %s: Class name of an exception.
			sprintf( __( 'The value could not be checked: %s was thrown.', 'wp-updates-notifier' ), get_class( $e ) )
		);
	}

	/**
	 * The instances defined in code: the `SC_WPUN_CHANNELS` constant (JSON), then the `sc_wpun_channels` filter.
	 *
	 * Invalid entries are reported as configuration problems and skipped. Built once per Config generation.
	 *
	 * @return array<string, Channel_Instance>
	 */
	private function code_defined(): array {
		if ( null !== $this->code_defined && $this->config->generation() === $this->code_generation ) {
			return $this->code_defined;
		}
		$this->code_generation = $this->config->generation();
		$this->code_defined    = array();

		// translators: Describes where a configuration value comes from; shown in "Set by %s". Keep SC_WPUN_CHANNELS as is.
		$json_source = __( 'constant SC_WPUN_CHANNELS', 'wp-updates-notifier' );
		$entries     = array();
		$constant    = null;
		if ( defined( 'SC_WPUN_CHANNELS' ) ) {
			$constant = constant( 'SC_WPUN_CHANNELS' );
			if ( ! is_string( $constant ) ) {
				$this->config->report_problem( 'SC_WPUN_CHANNELS', $json_source, __( 'The constant must be a JSON string.', 'wp-updates-notifier' ) );
				$constant = null;
			}
		}

		/**
		 * Filters the JSON text of the `SC_WPUN_CHANNELS` constant, which defines channel instances in code.
		 *
		 * Null when the constant is not defined. A filter can supply the JSON itself, for example from a secrets
		 * service. The text is a JSON array of objects with the keys `id`, `type`, `label`, `enabled`, `settings`
		 * and `routing`.
		 *
		 * @since 2.0.0
		 *
		 * @param string|null $json The JSON text, or null.
		 */
		$json = apply_filters( 'sc_wpun_channels_json', $constant );
		if ( null !== $json && ! is_string( $json ) ) {
			$this->config->report_problem( 'SC_WPUN_CHANNELS', $json_source, __( 'The value must be a JSON string.', 'wp-updates-notifier' ) );
		} elseif ( is_string( $json ) && '' !== trim( $json ) ) {
			$decoded   = json_decode( $json, true );
			$json_fail = json_last_error() !== JSON_ERROR_NONE;
			if ( $json_fail || ! is_array( $decoded ) ) {
				// The JSON error message names the kind of error, not any of the content.
				$this->config->report_problem( 'SC_WPUN_CHANNELS', $json_source, $json_fail ? json_last_error_msg() : __( 'The JSON must be a list of channels.', 'wp-updates-notifier' ) );
			} else {
				$seen = array();
				foreach ( array_values( $decoded ) as $position => $entry ) {
					$instance = $this->entry_to_instance( $entry, 'SC_WPUN_CHANNELS', $json_source, $position, $seen );
					if ( null !== $instance ) {
						$entries[] = $instance;
					}
				}
			}
		}

		/**
		 * Filters the channel instances defined in code.
		 *
		 * Starts with the instances of the `SC_WPUN_CHANNELS` constant. Entries are Channel_Instance objects or
		 * arrays with the keys `id`, `type`, `label`, `enabled`, `settings` and `routing`. An instance defined here
		 * replaces a stored one with the same id, is read-only in the admin and works like any other for delivery.
		 * Invalid entries are reported and skipped.
		 *
		 * Example:
		 *
		 *     add_filter(
		 *         'sc_wpun_channels',
		 *         function ( $channels ) {
		 *             $channels[] = array(
		 *                 'id'       => 'ops_email',
		 *                 'type'     => 'email',
		 *                 'label'    => 'Operations',
		 *                 'enabled'  => true,
		 *                 'settings' => array( 'to' => 'ops@example.org' ),
		 *             );
		 *             return $channels;
		 *         }
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param array<int|string, Channel_Instance|array<string, mixed>> $channels The instances.
		 */
		$filtered = apply_filters( 'sc_wpun_channels', $entries );
		if ( is_array( $filtered ) ) {
			// translators: Describes where a configuration value comes from; shown in "Set by %s". Keep sc_wpun_channels as is.
			$filter_source = __( 'the sc_wpun_channels filter', 'wp-updates-notifier' );
			$seen          = array();
			foreach ( array_values( $filtered ) as $position => $entry ) {
				$instance = $this->entry_to_instance( $entry, 'sc_wpun_channels', $filter_source, $position, $seen );
				if ( null !== $instance ) {
					$this->code_defined[ $instance->get_id() ] = $instance;
				}
			}
		}

		return $this->code_defined;
	}

	/**
	 * An instance from an entry of the code-defined list, or null with a problem recorded.
	 *
	 * @param mixed               $entry    A Channel_Instance or an array.
	 * @param string              $key      What to name in the problem.
	 * @param string              $source   Where the entry comes from.
	 * @param int                 $position Position in the list, for the message.
	 * @param array<string, true> $seen Ids already taken in this list; the first one wins.
	 *
	 * @return Channel_Instance|null
	 */
	private function entry_to_instance( $entry, string $key, string $source, int $position, array &$seen ): ?Channel_Instance {
		$instance = $this->build_entry( $entry, $key, $source, $position );
		if ( null === $instance ) {
			return null;
		}
		if ( isset( $seen[ $instance->get_id() ] ) ) {
			// translators: %d: Position of the entry in the list, starting at 1.
			$this->config->report_problem( $key, $source, sprintf( __( 'Channel %d repeats the id of an earlier channel and was skipped.', 'wp-updates-notifier' ), $position + 1 ) );
			return null;
		}
		$seen[ $instance->get_id() ] = true;

		return $instance;
	}

	/**
	 * Build an instance from an entry, or null with a problem recorded.
	 *
	 * @param mixed  $entry    A Channel_Instance or an array.
	 * @param string $key      What to name in the problem.
	 * @param string $source   Where the entry comes from.
	 * @param int    $position Position in the list, for the message.
	 *
	 * @return Channel_Instance|null
	 */
	private function build_entry( $entry, string $key, string $source, int $position ): ?Channel_Instance {
		if ( $entry instanceof Channel_Instance ) {
			return $entry;
		}

		// translators: %d: Position of the entry in the list, starting at 1.
		$where = sprintf( __( 'Channel %d is not valid and was skipped.', 'wp-updates-notifier' ), $position + 1 );
		if ( ! is_array( $entry ) || ! isset( $entry['id'], $entry['type'] ) || ! is_string( $entry['id'] ) || ! is_string( $entry['type'] ) ) {
			$this->config->report_problem( $key, $source, $where );
			return null;
		}

		$enabled = array(
			'ok'    => true,
			'value' => true,
		);
		if ( array_key_exists( 'enabled', $entry ) ) {
			$enabled = $this->config->cast( $entry['enabled'], Channel::enabled_field() );
		}
		if ( ! $enabled['ok'] ) {
			$this->config->report_problem( $key, $source, $where );
			return null;
		}

		try {
			return new Channel_Instance(
				$entry['id'],
				$entry['type'],
				isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? (string) $entry['label'] : $entry['id'],
				(bool) $enabled['value'],
				isset( $entry['settings'] ) && is_array( $entry['settings'] ) ? $entry['settings'] : array(),
				isset( $entry['routing'] ) && is_array( $entry['routing'] ) ? $entry['routing'] : array()
			);
		} catch ( InvalidArgumentException $e ) {
			$this->config->report_problem( $key, $source, $where );
			return null;
		}
	}

	/**
	 * The stored entries as they are, after the get filter.
	 *
	 * @return array<int|string, mixed>
	 */
	private function read(): array {
		/** This filter is documented in includes/class-settings.php */
		$stored = apply_filters( 'sc_wpun_get_options_filter', get_option( self::OPT_FIELD ), self::OPT_FIELD );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Store the entries, creating the option without autoload if it is missing.
	 *
	 * @param array<int|string, mixed> $stored Entries.
	 *
	 * @return void
	 */
	private function write( array $stored ): void {
		/** This filter is documented in includes/class-settings.php */
		$stored = apply_filters( 'sc_wpun_put_options_filter', $stored, self::OPT_FIELD );

		if ( false === get_option( self::OPT_FIELD ) ) {
			add_option( self::OPT_FIELD, $stored, '', false );
		} else {
			update_option( self::OPT_FIELD, $stored );
		}
	}

	/**
	 * Turn a stored entry into an instance.
	 *
	 * @param string $id    Identifier.
	 * @param mixed  $entry Stored entry.
	 *
	 * @return Channel_Instance|null Null if the entry is not usable.
	 */
	private function hydrate( string $id, $entry ): ?Channel_Instance {
		if ( ! is_array( $entry ) || ! isset( $entry['type'] ) || ! is_string( $entry['type'] ) ) {
			return null;
		}

		try {
			return new Channel_Instance(
				$id,
				$entry['type'],
				isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? (string) $entry['label'] : '',
				! empty( $entry['enabled'] ),
				isset( $entry['settings'] ) && is_array( $entry['settings'] ) ? $entry['settings'] : array(),
				isset( $entry['routing'] ) && is_array( $entry['routing'] ) ? $entry['routing'] : array()
			);
		} catch ( InvalidArgumentException $e ) {
			return null;
		}
	}
}
