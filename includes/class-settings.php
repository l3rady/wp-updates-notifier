<?php
/**
 * The plugin settings.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * The plugin settings: their defaults, how they are read and written and how older ones are upgraded.
 *
 * @internal
 * @phpstan-type SettingsData array{frequency: string, notify_core: 'none'|'all'|'major', notify_plugins: 'none'|'all'|'active', notify_themes: 'none'|'all'|'active', hide_updates: bool}
 */
class Settings {
	const OPT_FIELD         = 'sc_wpun_settings';
	const OPT_VERSION_FIELD = 'sc_wpun_settings_ver';
	const OPT_VERSION       = '13.0';

	/**
	 * What the notify settings were stored as before settings version 10: 0 none, 1 all, 2 active only.
	 */
	const LEGACY_NOTIFY = array(
		'0' => 'none',
		'1' => 'all',
		'2' => 'active',
	);

	/**
	 * Run state storage, written when settings from before version 8 are upgraded.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Channel instance storage, written when settings from before version 9 are upgraded.
	 *
	 * @var Channel_Store
	 */
	private $channels;

	/**
	 * Resolver of values supplied from outside the database.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param State         $state    Run state storage.
	 * @param Channel_Store $channels Channel instance storage.
	 * @param Config        $config   Resolver of values supplied from outside the database.
	 */
	public function __construct( State $state, Channel_Store $channels, Config $config ) {
		$this->state    = $state;
		$this->channels = $channels;
		$this->config   = $config;
	}

	/**
	 * Check if this plugin settings are up to date. Firstly check the version in
	 * the DB. If they don't match then load in defaults but don't override values
	 * already set. Also this will remove obsolete settings that are not needed.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$current_ver = $this->read_option( self::OPT_VERSION_FIELD ); // Get current plugin version.
		if ( self::OPT_VERSION !== $current_ver ) { // is the version the same as this plugin?
			$options  = (array) get_option( self::OPT_FIELD ); // get current settings from DB.
			$defaults = $this->default_settings();

			// "manual" has meant "never check" since the external cron was removed in 1.5.1. Check daily instead.
			if ( isset( $options['frequency'] ) && 'manual' === $options['frequency'] ) {
				$options['frequency'] = 'daily';
			}

			// Settings version 10 stores the notify settings as none, all or active instead of 0, 1 and 2.
			foreach ( array( 'notify_plugins', 'notify_themes' ) as $name ) {
				if ( array_key_exists( $name, $options ) ) {
					$options[ $name ] = $this->notify_key( $name, $options[ $name ], $defaults[ $name ] );
				}
			}

			// Settings version 11 stores the on/off settings as true and false instead of 1 and 0.
			foreach ( array( 'hide_updates' ) as $name ) {
				if ( array_key_exists( $name, $options ) ) {
					$options[ $name ] = $this->bool_setting( $options, $name, $defaults[ $name ] );
				}
			}

			// Settings version 8 moved the run state (what was notified, when the last check ran) to its own option.
			if ( false === get_option( State::OPT_FIELD ) ) {
				$this->state->save(
					$this->state->normalise(
						array(
							'notified'        => $options['notified'] ?? array(),
							'last_check_time' => $options['last_check_time'] ?? false,
						)
					)
				);
			}

			// Settings version 9 moved the email and Slack settings to channel instances.
			if ( false === get_option( Channel_Store::OPT_FIELD ) ) {
				$this->migrate_channels( $options, intval( $current_ver ) );
			}

			// Intersect current options with defaults. Basically removing settings that are obsolete.
			$options = array_intersect_key( $options, $defaults );
			// Merge current settings with defaults. Basically adding any new settings with defaults that we dont have.
			$options = array_merge( $defaults, $options );
			$this->write_option( self::OPT_FIELD, $options ); // update settings.
			$this->write_option( self::OPT_VERSION_FIELD, self::OPT_VERSION ); // update settings version.
		}
	}

	/**
	 * Create the email and Slack instances from the settings of before version 9.
	 *
	 * @param array<mixed> $options     Stored settings, as they were before the upgrade.
	 * @param int          $current_ver The settings version being upgraded from (0 for a new install).
	 *
	 * @return void
	 */
	private function migrate_channels( array $options, int $current_ver ): void {
		$admin_email = (string) get_option( 'admin_email' );

		// Before settings version 7 there was email only, so the upgrade turned email notifications on.
		$email_on = $current_ver < 7 || $this->is_on( $options['email_notifications'] ?? 0 );

		// 1.x sent from the admin email unless another address was set; now From is only set when asked for (#76).
		$from = trim( $this->string_setting( $options, 'notify_from', '' ) );
		if ( 0 === strcasecmp( $from, trim( $admin_email ) ) ) {
			$from = '';
		}

		// Raw writes: these values come from the database, whatever is supplied from outside.
		$this->channels->save_raw(
			new Channel_Instance(
				'email',
				'email',
				'Email',
				$email_on,
				array(
					'to'             => $this->string_setting( $options, 'notify_to', $admin_email ),
					'from'           => $from,
					'reply_to'       => '',
					'format'         => 'text',
					'subject_prefix' => '',
				)
			)
		);

		$slack_on    = $this->is_on( $options['slack_notifications'] ?? 0 );
		$webhook_url = $this->string_setting( $options, 'slack_webhook_url', '' );

		// Slack is only carried over if it was set up.
		if ( $slack_on || '' !== $webhook_url ) {
			$this->channels->save_raw(
				new Channel_Instance(
					'slack',
					'slack',
					'Slack',
					$slack_on,
					array(
						'webhook_url' => $webhook_url,
						'channel'     => $this->string_setting( $options, 'slack_channel_override', '' ),
					)
				)
			);
		}
	}

	/**
	 * Whether a stored on/off value is on, the way the settings were read before version 9 (exactly 1).
	 *
	 * @param mixed $value Stored value, for example 1 or '1'.
	 *
	 * @return bool
	 */
	private function is_on( $value ): bool {
		return is_scalar( $value ) && 1 === absint( $value );
	}

	/**
	 * The settings a new install starts with.
	 *
	 * @return SettingsData
	 */
	public function default_settings(): array {
		$defaults = array(
			'frequency'      => 'hourly',
			'notify_core'    => 'all',
			'notify_plugins' => 'all',
			'notify_themes'  => 'all',
			'hide_updates'   => true,
		);

		/**
		 * Filters the settings a new install starts with, and the values used for settings that are missing.
		 *
		 * Only the keys below are used, and values are cast to the type shown. Anything else
		 * is ignored, as is a return value that is not an array. A notify setting that is not
		 * one of its keys falls back to the built-in default.
		 *
		 * Example, check daily by default:
		 *
		 *     add_filter(
		 *         'sc_wpun_default_settings',
		 *         function ( $defaults ) {
		 *             $defaults['frequency'] = 'daily';
		 *             return $defaults;
		 *         }
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, mixed> $defaults {
		 *     The default settings.
		 *
		 *     @type string $frequency        Name of a cron schedule.
		 *     @type string $notify_core      `none`, `all` or `major` (major releases only).
		 *     @type string $notify_plugins   `none`, `all` or `active` (active plugins only).
		 *     @type string $notify_themes    `none`, `all` or `active` (active themes only).
		 *     @type bool   $hide_updates     True to hide the WordPress update notice from users who cannot update.
		 * }
		 */
		$filtered = apply_filters( 'sc_wpun_default_settings', $defaults );
		if ( ! is_array( $filtered ) ) {
			return $defaults;
		}

		return array(
			'frequency'      => $this->string_setting( $filtered, 'frequency', $defaults['frequency'] ),
			'notify_core'    => $this->notify_key( 'notify_core', $filtered['notify_core'] ?? null, $defaults['notify_core'], false ),
			'notify_plugins' => $this->notify_key( 'notify_plugins', $filtered['notify_plugins'] ?? null, $defaults['notify_plugins'], false ),
			'notify_themes'  => $this->notify_key( 'notify_themes', $filtered['notify_themes'] ?? null, $defaults['notify_themes'], false ),
			'hide_updates'   => $this->bool_setting( $filtered, 'hide_updates', $defaults['hide_updates'] ),
		);
	}

	/**
	 * The effective settings: the stored ones, with the values constants, environment variables
	 * or other configuration sources supply laid over them.
	 *
	 * A supplied value that is not valid for its setting is reported and not used.
	 *
	 * @return SettingsData
	 */
	public function get(): array {
		$settings  = $this->stored();
		$schemas   = $this->schemas();
		$frequency = $this->config->resolve_typed( Config_Context::setting( 'frequency' ), $schemas['frequency'] );
		if ( null !== $frequency ) {
			$settings['frequency'] = (string) $frequency->get_value();
		}

		$core = $this->config->resolve_typed( Config_Context::setting( 'notify_core' ), $schemas['notify_core'] );
		if ( null !== $core ) {
			$settings['notify_core'] = $this->notify_key( 'notify_core', $core->get_value(), $settings['notify_core'], false );
		}

		$plugins = $this->config->resolve_typed( Config_Context::setting( 'notify_plugins' ), $schemas['notify_plugins'] );
		if ( null !== $plugins ) {
			$settings['notify_plugins'] = $this->notify_key( 'notify_plugins', $plugins->get_value(), $settings['notify_plugins'], false );
		}

		$themes = $this->config->resolve_typed( Config_Context::setting( 'notify_themes' ), $schemas['notify_themes'] );
		if ( null !== $themes ) {
			$settings['notify_themes'] = $this->notify_key( 'notify_themes', $themes->get_value(), $settings['notify_themes'], false );
		}

		$hide = $this->config->resolve_typed( Config_Context::setting( 'hide_updates' ), $schemas['hide_updates'] );
		if ( null !== $hide ) {
			$settings['hide_updates'] = (bool) $hide->get_value();
		}

		return $settings;
	}

	/**
	 * Whether the About tab is shown. A code-only setting: it is never stored, only supplied from outside the database.
	 *
	 * @return bool
	 */
	public function show_about(): bool {
		$schema = $this->schemas()['show_about'];
		$value  = $this->config->resolve_typed( Config_Context::setting( 'show_about' ), $schema );

		return null === $value ? (bool) $schema['default'] : (bool) $value->get_value();
	}

	/**
	 * The source of a setting that is supplied from outside the database.
	 *
	 * @param string $name Setting name, for example `frequency`.
	 *
	 * @return string|null Where it comes from, for example "constant SC_WPUN_SETTING_FREQUENCY"; null if the database is used.
	 */
	public function external_source( string $name ): ?string {
		$schemas = $this->schemas();
		if ( ! isset( $schemas[ $name ] ) ) {
			return null;
		}
		$external = $this->config->resolve_typed( Config_Context::setting( $name ), $schemas[ $name ] );

		return null === $external ? null : $external->get_source();
	}

	/**
	 * The field schema of each global setting, which is also what it accepts when supplied from outside the database.
	 *
	 * The settings screen is drawn from these. Schemas use the field types of channel fields (see Channel).
	 *
	 * A schema with `'storage' => false` is a code-only setting: it is read only through the configuration path
	 * (constant, environment variable, source or filter), never stored, never given a field on the settings screen,
	 * and a posted value is ignored. When nothing supplies a value, its `default` applies.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schemas(): array {
		$frequencies = array();
		foreach ( Frequencies::all() as $name => $frequency ) {
			$frequencies[ $name ] = $frequency['display'];
		}

		return array(
			'frequency'      => array(
				'type'    => 'enum',
				'label'   => __( 'Frequency to check', 'wp-updates-notifier' ),
				'options' => $frequencies,
			),
			'notify_core'    => $this->notify_schema( 'notify_core' ),
			'notify_plugins' => $this->notify_schema( 'notify_plugins' ),
			'notify_themes'  => $this->notify_schema( 'notify_themes' ),
			'hide_updates'   => array(
				'type'    => 'boolean',
				'label'   => __( 'Update notice', 'wp-updates-notifier' ),
				'labels'  => array(
					'on' => __( 'Hide the WordPress update notice from users who cannot update WordPress', 'wp-updates-notifier' ),
				),
				'default' => true,
			),
			'show_about'     => array(
				'type'    => 'boolean',
				'label'   => __( 'About tab', 'wp-updates-notifier' ),
				'default' => true,
				'storage' => false,
			),
		);
	}

	/**
	 * The schema of a notify setting, which accepts only its own keys.
	 *
	 * @param string $name `notify_core`, `notify_plugins` or `notify_themes`.
	 *
	 * @return array<string, mixed>
	 */
	private function notify_schema( string $name ): array {
		$none = __( 'No', 'wp-updates-notifier' );
		$all  = __( 'Yes', 'wp-updates-notifier' );

		if ( 'notify_core' === $name ) {
			return array(
				'type'    => 'enum',
				'label'   => __( 'Notify about WordPress updates?', 'wp-updates-notifier' ),
				'options' => array(
					'none'  => $none,
					'all'   => $all,
					'major' => __( 'Yes, but only major releases', 'wp-updates-notifier' ),
				),
				'default' => 'all',
			);
		}

		$plugins = 'notify_plugins' === $name;

		return array(
			'type'    => 'enum',
			'label'   => $plugins ? __( 'Notify about plugin updates?', 'wp-updates-notifier' ) : __( 'Notify about theme updates?', 'wp-updates-notifier' ),
			'options' => array(
				'none'   => $none,
				'all'    => $all,
				'active' => $plugins ? __( 'Yes, but only active plugins', 'wp-updates-notifier' ) : __( 'Yes, but only active themes', 'wp-updates-notifier' ),
			),
			'default' => 'all',
		);
	}

	/**
	 * A notify setting as one of its own keys.
	 *
	 * @param string $name     `notify_core`, `notify_plugins` or `notify_themes`.
	 * @param mixed  $value    The value.
	 * @param string $fallback Key to use when the value is not one.
	 * @param bool   $legacy   Whether 0, 1 and 2 (as stored before settings version 10) stand for none, all and active. Never for core.
	 *
	 * @return ($name is 'notify_core' ? 'none'|'all'|'major' : 'none'|'all'|'active')
	 */
	private function notify_key( string $name, $value, string $fallback, bool $legacy = true ): string {
		if ( $legacy && 'notify_core' !== $name && ( is_int( $value ) || is_string( $value ) ) && isset( self::LEGACY_NOTIFY[ (string) $value ] ) ) {
			$value = self::LEGACY_NOTIFY[ (string) $value ];
		}

		// Only the keys matter here, so the labels are not translated: this runs on plugins_loaded (settings upgrade),
		// before WordPress 6.7 allows translating.
		$keys   = 'notify_core' === $name ? array( 'none', 'all', 'major' ) : array( 'none', 'all', 'active' );
		$schema = array(
			'type'    => 'enum',
			'options' => array_combine( $keys, $keys ),
			'default' => in_array( $fallback, $keys, true ) ? $fallback : 'all',
		);

		/**
		 * Enum_Field only returns one of the schema's options.
		 *
		 * @var 'none'|'all'|'active'|'major' $key
		 */
		$key = Enum_Field::sanitize( $value, $schema )['value'];

		return $key;
	}

	/**
	 * The plugin's saved settings, with every value of the type the code expects and nothing from outside the database.
	 *
	 * Values can arrive as strings (imports, `wp option update`, filters), and missing
	 * keys get their defaults. The notify settings also accept 0, 1 and 2 as stored before settings
	 * version 10. The frequency is not checked against the schedules on offer: a saved schedule that is
	 * no longer offered is kept, so the settings screen can say so.
	 *
	 * @return SettingsData
	 */
	public function stored(): array {
		$stored   = $this->read_option( self::OPT_FIELD );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = $this->default_settings();

		return array(
			'frequency'      => $this->string_setting( $stored, 'frequency', $defaults['frequency'] ),
			'notify_core'    => $this->notify_key( 'notify_core', $stored['notify_core'] ?? null, $defaults['notify_core'] ),
			'notify_plugins' => $this->notify_key( 'notify_plugins', $stored['notify_plugins'] ?? null, $defaults['notify_plugins'] ),
			'notify_themes'  => $this->notify_key( 'notify_themes', $stored['notify_themes'] ?? null, $defaults['notify_themes'] ),
			'hide_updates'   => $this->bool_setting( $stored, 'hide_updates', $defaults['hide_updates'] ),
		);
	}

	/**
	 * A stored setting as a boolean, or its default.
	 *
	 * Accepts true and false and the 1 and 0 older versions stored, as numbers or strings.
	 *
	 * @param array<mixed> $stored   Stored settings.
	 * @param string       $key      Setting name.
	 * @param bool         $fallback Value when the setting is missing or unusable.
	 *
	 * @return bool
	 */
	private function bool_setting( array $stored, string $key, bool $fallback ): bool {
		if ( ! isset( $stored[ $key ] ) ) {
			return $fallback;
		}

		return Boolean_Field::sanitize( $stored[ $key ], array( 'default' => $fallback ) )['value'];
	}

	/**
	 * A stored setting as a string, or its default.
	 *
	 * @param array<mixed> $stored   Stored settings.
	 * @param string       $key      Setting name.
	 * @param string       $fallback Value when the setting is missing or unusable.
	 *
	 * @return string
	 */
	private function string_setting( array $stored, string $key, string $fallback ): string {
		return isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ? (string) $stored[ $key ] : $fallback;
	}

	/**
	 * Filter for when getting this plugin's settings.
	 *
	 * @param string $field Option field name of where we are getting plugin settings.
	 *
	 * @return mixed The stored value after the get filter.
	 */
	public function read_option( $field ) {
		/**
		 * Filters a setting option of the plugin after it is read.
		 *
		 * @since 1.4.0
		 *
		 * @param mixed  $value The stored value.
		 * @param string $field The name of the option.
		 */
		return apply_filters( 'sc_wpun_get_options_filter', get_option( $field ), $field );
	}

	/**
	 * Filter for when setting this plugin's settings.
	 *
	 * @param string $field Option field name of where we are saving plugin settings.
	 * @param mixed  $value The value you are saving.
	 *
	 * @return bool True if the option was updated.
	 */
	public function write_option( $field, $value ) {
		/**
		 * Filters a setting option of the plugin before it is stored.
		 *
		 * @since 1.4.0
		 *
		 * @param mixed  $value The value about to be stored.
		 * @param string $field The name of the option.
		 */
		$value = apply_filters( 'sc_wpun_put_options_filter', $value, $field );

		return update_option( $field, $value );
	}
}
