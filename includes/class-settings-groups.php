<?php
/**
 * Settings groups registered by add-ons.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * The settings groups add-ons register: their fields, where the values are stored and what the effective values are.
 *
 * A group is registered from the `sc_wpun_register_settings` action, which fires the first time a group is needed
 * (after `sc_wpun_register_channels`). Its values are resolved through the same path as the plugin's own settings:
 * constants, environment variables and other sources first, then the stored value, then the default.
 *
 * @internal
 */
final class Settings_Groups {
	const OPT_PREFIX = 'sc_wpun_settings_';

	/**
	 * Ids that would collide with the plugin's own options, tabs and hooks, apart from the names of its settings.
	 */
	const RESERVED = array( 'general', 'channels', 'muted', 'about', 'ver', 'main', 'tab', 'tabs', 'validate', 'group', 'show_about' );

	/**
	 * Tabs the plugin draws itself.
	 */
	const OWN_TABS = array( 'channels', 'muted', 'about' );

	/**
	 * Resolver of values supplied from outside the database.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * The plugin's own settings, for the group that is `''`.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Registered channels, which register before the groups.
	 *
	 * @var Channel_Registry
	 */
	private $registry;

	/**
	 * Where the options are stored.
	 *
	 * @var Network_Context
	 */
	private $context;

	/**
	 * Registered groups by id: the arguments with their defaults, and the field schemas as `fields`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $groups = array();

	/**
	 * Whether the registration action has fired.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Whether the registration action is running.
	 *
	 * @var bool
	 */
	private $registering = false;

	/**
	 * Effective values by group and field, for one Config generation.
	 *
	 * @var array<string, array<string, array{value: mixed, source: string|null, origin: string}>>
	 */
	private $cache = array();

	/**
	 * The Config generation the cache belongs to.
	 *
	 * @var int
	 */
	private $generation = -1;

	/**
	 * Constructor. Nothing is loaded until a group is first needed.
	 *
	 * @param Config               $config   Resolver of values supplied from outside the database.
	 * @param Channel_Registry     $registry Registered channels.
	 * @param Settings             $settings The plugin's own settings.
	 * @param Network_Context|null $context  Where the options are stored; defaults to the current site setup.
	 */
	public function __construct( Config $config, Channel_Registry $registry, Settings $settings, ?Network_Context $context = null ) {
		$this->config   = $config;
		$this->registry = $registry;
		$this->settings = $settings;
		$this->context  = $context ?? new Network_Context();
	}

	/**
	 * Whether a name is one of the plugin's own settings.
	 *
	 * @param string $name Setting name.
	 *
	 * @return bool
	 */
	public static function is_plugin_setting( string $name ): bool {
		return isset( Settings::GLOBAL_SETTINGS[ $name ] ) || 'show_about' === $name;
	}

	/**
	 * Register a group. Only from the `sc_wpun_register_settings` action.
	 *
	 * @param string                              $group  Group id.
	 * @param array<string, mixed>                $args   See sc_wpun_register_settings_group().
	 * @param array<string, array<string, mixed>> $fields Field schemas by name.
	 *
	 * @return bool False, with a notice to the developer, when anything is not valid.
	 */
	public function register( string $group, array $args, array $fields ): bool {
		$problem = $this->registration_problem( $group, $args, $fields );
		if ( null !== $problem ) {
			_doing_it_wrong( 'sc_wpun_register_settings_group', esc_html( $problem ), '3.0.0' );
			return false;
		}

		$this->groups[ $group ] = array(
			'label'             => $args['label'],
			'description'       => (string) ( $args['description'] ?? '' ),
			'tab'               => (string) ( $args['tab'] ?? 'general' ),
			'tab_label'         => (string) ( $args['tab_label'] ?? '' ),
			'capability'        => (string) ( $args['capability'] ?? '' ),
			'validate_callback' => $args['validate_callback'] ?? null,
			'field_groups'      => $args['field_groups'] ?? array(),
			'autoload'          => true === ( $args['autoload'] ?? false ),
			'fields'            => $fields,
		);

		foreach ( $fields as $schema ) {
			if ( isset( $schema['group'] ) && ! isset( $this->groups[ $group ]['field_groups'][ $schema['group'] ] ) ) {
				Field_Types::unknown_group( $schema['group'] );
			}
		}

		return true;
	}

	/**
	 * The registered groups: the arguments with their defaults, and the field schemas as `fields`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$this->load();

		return $this->groups;
	}

	/**
	 * The effective value of a field and where it comes from. The group `''` is the plugin's own settings.
	 *
	 * @param string $group  Group id.
	 * @param string $name   Field name.
	 * @param string $caller The public function to blame when the group or field is unknown.
	 *
	 * @return array{value: mixed, source: string|null, origin: string}|null Null, with a notice to the developer, when unknown.
	 *                                                                         `source` is null when the database or the default is used.
	 */
	public function entry( string $group, string $name, string $caller ): ?array {
		if ( '' === $group ) {
			$entry = self::is_plugin_setting( $name ) ? $this->plugin_entry( $name ) : null;
		} else {
			$this->load();
			$entry = isset( $this->groups[ $group ] ) ? ( $this->resolved( $group )[ $name ] ?? null ) : null;
		}
		if ( null === $entry ) {
			$this->complain_unknown( $caller, $group, $name );
		}

		return $entry;
	}

	/**
	 * A setting of the plugin itself.
	 *
	 * @param string $name Setting name.
	 *
	 * @return array{value: mixed, source: string|null, origin: string}
	 */
	private function plugin_entry( string $name ): array {
		return $this->row( 'show_about' === $name ? $this->settings->show_about() : $this->settings->get()[ $name ], $this->settings->external_source( $name ), 'database' );
	}

	/**
	 * Tell the developer a group or field is not known.
	 *
	 * @param string $caller The public function to blame.
	 * @param string $group  Group id.
	 * @param string $name   Field name.
	 *
	 * @return void
	 */
	private function complain_unknown( string $caller, string $group, string $name ): void {
		_doing_it_wrong(
			esc_html( $caller ),
			esc_html(
				// translators: 1: Settings group id. 2: Field name.
				sprintf( __( 'No setting "%2$s" is registered in the settings group "%1$s".', 'wp-updates-notifier' ), $group, $name )
			),
			'3.0.0'
		);
	}

	/**
	 * Resolve every field of every group, so the values that cannot be used are recorded for the reports.
	 *
	 * @return void
	 */
	public function resolve_all(): void {
		foreach ( array_keys( $this->all() ) as $group ) {
			$this->resolved( $group );
		}
	}

	/**
	 * The values stored for a group.
	 *
	 * @param string $group Group id.
	 *
	 * @return array<string, mixed>
	 */
	public function stored( string $group ): array {
		$option = self::OPT_PREFIX . $group;

		/** This filter is documented in includes/class-settings.php */
		$stored = apply_filters( 'sc_wpun_get_options_filter', $this->context->get_option( $option ), $option );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Store the values of a group.
	 *
	 * Keeps the declared fields that are stored at all (not code-only ones). A field whose value is supplied from outside the
	 * database keeps the value that was stored before: what is supplied is never written.
	 *
	 * @param string               $group  Group id.
	 * @param array<string, mixed> $values The values to store, already checked.
	 *
	 * @return bool True if the stored value changed. False for a group that is not registered.
	 */
	public function save( string $group, array $values ): bool {
		$this->load();
		if ( ! isset( $this->groups[ $group ] ) ) {
			return false;
		}

		$old     = $this->stored( $group );
		$current = $this->resolved( $group );
		$new     = array();
		foreach ( $this->groups[ $group ]['fields'] as $name => $schema ) {
			$name = (string) $name;
			if ( false === ( $schema['storage'] ?? true ) ) {
				continue;
			}
			$source = null !== $current[ $name ]['source'];
			if ( $source && array_key_exists( $name, $old ) ) {
				$new[ $name ] = $old[ $name ];
			} elseif ( ! $source && array_key_exists( $name, $values ) ) {
				$new[ $name ] = $values[ $name ];
			}
		}

		$option = self::OPT_PREFIX . $group;

		/** This filter is documented in includes/class-settings.php */
		$new = apply_filters( 'sc_wpun_put_options_filter', $new, $option );

		$this->cache = array();

		return $this->context->update_option( $option, $new, $this->groups[ $group ]['autoload'] );
	}

	/**
	 * Remove the stored values of a group. The group need not be registered, its id must be valid.
	 *
	 * @param string $group Group id.
	 *
	 * @return void
	 */
	public function delete( string $group ): void {
		if ( null !== $this->id_problem( $group ) ) {
			return;
		}

		$this->context->delete_option( self::OPT_PREFIX . $group );
		$this->cache = array();
	}

	/**
	 * Let add-ons register their groups, once.
	 *
	 * @return void
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		// Channels register first, so a group can rely on them.
		$this->registry->all();

		$this->registering = true;
		try {
			/**
			 * Fires when settings groups are first needed, for add-ons to register theirs.
			 *
			 * Call `sc_wpun_register_settings_group()` from here. It fires after `sc_wpun_register_channels`, once per request,
			 * and not before something needs a group, such as the settings screen or `sc_wpun_setting()`.
			 *
			 * Example, a group with a switch that can also be set in `wp-config.php`:
			 *
			 *     add_action(
			 *         'sc_wpun_register_settings',
			 *         function () {
			 *             sc_wpun_register_settings_group(
			 *                 'acme',
			 *                 array( 'label' => 'Acme' ),
			 *                 array(
			 *                     'enabled' => array(
			 *                         'type'    => 'boolean',
			 *                         'label'   => 'Enabled',
			 *                         'default' => true,
			 *                     ),
			 *                 )
			 *             );
			 *         }
			 *     );
			 *
			 * @since 3.0.0
			 */
			do_action( 'sc_wpun_register_settings' );
		} finally {
			$this->registering = false;
		}
	}

	/**
	 * Why a registration is refused.
	 *
	 * @param string               $group  Group id.
	 * @param array<string, mixed> $args   Arguments.
	 * @param array<mixed>         $fields Field schemas.
	 *
	 * @return string|null Null when it is fine.
	 */
	private function registration_problem( string $group, array $args, array $fields ): ?string {
		if ( ! $this->registering ) {
			return __( 'Settings groups can only be registered from the sc_wpun_register_settings action.', 'wp-updates-notifier' );
		}

		return $this->id_problem( $group )
			?? $this->duplicate_problem( $group )
			?? $this->args_problem( $args )
			?? $this->fields_problem( $fields );
	}

	/**
	 * Why a group id cannot be used.
	 *
	 * @param string $group Group id.
	 *
	 * @return string|null Null when it is fine.
	 */
	private function id_problem( string $group ): ?string {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{1,30}$/D', $group ) || false !== strpos( $group, '__' ) ) {
			return __( 'A settings group id has 2 to 31 characters: a-z, 0-9 and single underscores, starting with a letter.', 'wp-updates-notifier' );
		}
		if ( self::is_plugin_setting( $group ) || in_array( $group, self::RESERVED, true ) ) {
			// translators: %s: Settings group id.
			return sprintf( __( 'The settings group id "%s" is reserved. Use an id of your own, such as the name of your add-on.', 'wp-updates-notifier' ), $group );
		}

		return null;
	}

	/**
	 * Why a group that is already registered cannot be registered again.
	 *
	 * @param string $group Group id.
	 *
	 * @return string|null Null when it is fine.
	 */
	private function duplicate_problem( string $group ): ?string {
		// translators: %s: Settings group id.
		return isset( $this->groups[ $group ] ) ? sprintf( __( 'The settings group "%s" is already registered.', 'wp-updates-notifier' ), $group ) : null;
	}

	/**
	 * Why the arguments of a group cannot be used.
	 *
	 * @param array<string, mixed> $args Arguments.
	 *
	 * @return string|null Null when it is fine.
	 */
	private function args_problem( array $args ): ?string {
		$bad = $this->bad_argument( $args );

		// translators: %s: Name of an argument.
		return null === $bad ? null : sprintf( __( 'The "%s" argument of a settings group is missing or not valid.', 'wp-updates-notifier' ), $bad );
	}

	/**
	 * The first argument of a group that is not valid.
	 *
	 * @param array<string, mixed> $args Arguments.
	 *
	 * @return string|null Its name.
	 */
	private function bad_argument( array $args ): ?string {
		if ( ! isset( $args['label'] ) || ! is_string( $args['label'] ) || '' === trim( $args['label'] ) ) {
			return 'label';
		}
		foreach ( array( 'description', 'tab_label', 'capability' ) as $key ) {
			if ( isset( $args[ $key ] ) && ! is_string( $args[ $key ] ) ) {
				return $key;
			}
		}
		if ( isset( $args['tab'] ) && ( ! is_string( $args['tab'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_-]*$/D', $args['tab'] ) || in_array( $args['tab'], self::OWN_TABS, true ) ) ) {
			return 'tab';
		}
		if ( isset( $args['validate_callback'] ) && ! is_callable( $args['validate_callback'] ) ) {
			return 'validate_callback';
		}
		if ( isset( $args['autoload'] ) && ! is_bool( $args['autoload'] ) ) {
			return 'autoload';
		}
		if ( isset( $args['field_groups'] ) && ! $this->are_field_groups( $args['field_groups'] ) ) {
			return 'field_groups';
		}

		return null;
	}

	/**
	 * Whether a value is a list of field groups: arrays by id, each with a label.
	 *
	 * @param mixed $groups The `field_groups` argument.
	 *
	 * @return bool
	 */
	private function are_field_groups( $groups ): bool {
		if ( ! is_array( $groups ) ) {
			return false;
		}
		foreach ( $groups as $id => $group ) {
			if ( '' === (string) $id || ! is_array( $group ) || ! isset( $group['label'] ) || ! is_string( $group['label'] ) || '' === $group['label'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Why the field schemas of a group cannot be used.
	 *
	 * @param array<mixed> $fields Field schemas by name.
	 *
	 * @return string|null Null when it is fine.
	 */
	private function fields_problem( array $fields ): ?string {
		if ( array() === $fields ) {
			return __( 'A settings group needs at least one field.', 'wp-updates-notifier' );
		}

		foreach ( $fields as $name => $schema ) {
			$name = (string) $name;
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/D', $name ) || false !== strpos( $name, '__' ) ) {
				// translators: %s: Field name.
				return sprintf( __( 'The field name "%s" is not valid: use a-z, 0-9 and single underscores, starting with a letter.', 'wp-updates-notifier' ), $name );
			}
			if ( null !== $this->bad_field( $name, $schema ) ) {
				// translators: %s: Field name.
				return sprintf( __( 'The schema of the field "%s" is not valid: it needs a known type, and a valid on_invalid, constant_first and storage.', 'wp-updates-notifier' ), $name );
			}
		}

		return null;
	}

	/**
	 * Why a field schema cannot be used.
	 *
	 * @param string $name   Field name.
	 * @param mixed  $schema The schema.
	 *
	 * @return string|null The key that is not valid.
	 */
	private function bad_field( string $name, $schema ): ?string {
		if ( ! is_array( $schema ) || null === Field_Types::get( $schema['type'] ?? null ) ) {
			return 'type';
		}
		foreach ( array( 'constant_first', 'storage' ) as $key ) {
			if ( isset( $schema[ $key ] ) && ! is_bool( $schema[ $key ] ) ) {
				return $key;
			}
		}
		if ( array_key_exists( 'on_invalid', $schema ) && ! $this->clean( $schema['on_invalid'], $name, $schema )['ok'] ) {
			return 'on_invalid';
		}

		return null;
	}

	/**
	 * The effective values of the fields of a registered group, resolved once per Config generation.
	 *
	 * @param string $group Group id.
	 *
	 * @return array<string, array{value: mixed, source: string|null, origin: string}>
	 */
	private function resolved( string $group ): array {
		if ( $this->generation !== $this->config->generation() ) {
			$this->cache      = array();
			$this->generation = $this->config->generation();
		}

		if ( ! isset( $this->cache[ $group ] ) ) {
			$stored = $this->stored( $group );
			$rows   = array();
			foreach ( $this->groups[ $group ]['fields'] as $name => $schema ) {
				$rows[ (string) $name ] = $this->resolve_field( $group, (string) $name, $schema, $stored );
			}
			$this->cache[ $group ] = $rows;
		}

		return $this->cache[ $group ];
	}

	/**
	 * The effective value of one field.
	 *
	 * A supplied value that can be used wins. One that cannot is recorded as a problem and replaced by `on_invalid` if the
	 * schema has one, else by the stored value, else by the default.
	 *
	 * @param string               $group  Group id.
	 * @param string               $name   Field name.
	 * @param array<string, mixed> $schema Field schema.
	 * @param array<string, mixed> $stored The values stored for the group.
	 *
	 * @return array{value: mixed, source: string|null, origin: string}
	 */
	private function resolve_field( string $group, string $name, array $schema, array $stored ): array {
		$supplied = $this->supplied( Config_Context::setting( $name, $group ), $schema );
		if ( null !== $supplied ) {
			$cast = $this->config->cast( $supplied->get_value(), $schema );
			if ( $cast['ok'] ) {
				return $this->row( $cast['value'], $supplied->get_source() );
			}

			$has_fallback = array_key_exists( 'on_invalid', $schema );
			$this->config->report_problem( $supplied->get_key(), $supplied->get_source(), $this->invalid_message( $schema, $has_fallback ) );
			$fallback = $has_fallback ? $this->clean( $schema['on_invalid'], $name, $schema ) : null;
			if ( null !== $fallback && $fallback['ok'] ) {
				return $this->row( $fallback['value'], $supplied->get_source() );
			}
		}

		$kept = false === ( $schema['storage'] ?? true ) || ! array_key_exists( $name, $stored ) ? null : $this->clean( $stored[ $name ], $name, $schema );

		return null !== $kept && $kept['ok'] ? $this->row( $kept['value'], null, 'database' ) : $this->row( $this->default_value( $schema ), null, 'default' );
	}

	/**
	 * A value supplied from outside the database.
	 *
	 * With `constant_first` a constant that is defined is read before anything else and is final: the sources and the
	 * `sc_wpun_config_value` filter are not asked.
	 *
	 * @param Config_Context       $context What to resolve.
	 * @param array<string, mixed> $schema  Field schema.
	 *
	 * @return Config_Value|null
	 */
	private function supplied( Config_Context $context, array $schema ): ?Config_Value {
		$key = $context->get_key();
		if ( true === ( $schema['constant_first'] ?? false ) && defined( $key ) ) {
			return new Config_Value( constant( $key ), ( new Constant_Source() )->get_label( $key ), $key );
		}

		return $this->config->resolve( $context );
	}

	/**
	 * Why a supplied value is not used. Never says the value.
	 *
	 * @param array<string, mixed> $schema       Field schema.
	 * @param bool                 $has_fallback Whether the schema has an `on_invalid`.
	 *
	 * @return string
	 */
	private function invalid_message( array $schema, bool $has_fallback ): string {
		if ( $has_fallback ) {
			return __( 'The value is not valid for this setting, so the value the add-on chose for this case is used.', 'wp-updates-notifier' );
		}

		return false === ( $schema['storage'] ?? true )
			? __( 'The value is not valid for this setting, so the default is used.', 'wp-updates-notifier' )
			: __( 'The value is not valid for this setting, so the saved one is used.', 'wp-updates-notifier' );
	}

	/**
	 * A value the way the type of the field would store it.
	 *
	 * @param mixed                $value  The value.
	 * @param string               $name   Field name.
	 * @param array<string, mixed> $schema Field schema.
	 *
	 * @return array{ok: bool, value: mixed}
	 */
	private function clean( $value, string $name, array $schema ): array {
		$handler = Field_Types::get( $schema['type'] ?? null );
		$clean   = null === $handler ? new \WP_Error( 'unknown_type' ) : call_user_func( $handler['sanitize'], $value, $schema + array( 'name' => $name ) );

		return array(
			'ok'    => ! $clean instanceof \WP_Error,
			'value' => $clean,
		);
	}

	/**
	 * The value used when nothing else is: the `default`, else what is empty for the type.
	 *
	 * @param array<string, mixed> $schema Field schema.
	 *
	 * @return mixed
	 */
	private function default_value( array $schema ) {
		if ( array_key_exists( 'default', $schema ) ) {
			return $schema['default'];
		}

		switch ( Field_Types::canonical( $schema['type'] ?? null ) ) {
			case 'boolean':
				return false;
			case 'number':
				return 0;
			case 'enum':
				return true === ( $schema['multiple'] ?? false ) ? array() : '';
			default:
				return '';
		}
	}

	/**
	 * An effective value with where it comes from.
	 *
	 * @param mixed       $value  The value.
	 * @param string|null $source What supplied it, or null.
	 * @param string      $origin Where else it comes from when nothing supplies it: `database` or `default`.
	 *
	 * @return array{value: mixed, source: string|null, origin: string}
	 */
	private function row( $value, ?string $source, string $origin = '' ): array {
		return array(
			'value'  => $value,
			'source' => $source,
			'origin' => $source ?? $origin,
		);
	}
}
