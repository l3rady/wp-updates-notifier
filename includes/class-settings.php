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
 * @phpstan-type SettingsData array{frequency: string, notify_plugins: int, notify_themes: int, notify_automatic: int, hide_updates: int}
 */
class Settings {
	const OPT_FIELD         = 'sc_wpun_settings';
	const OPT_VERSION_FIELD = 'sc_wpun_settings_ver';
	const OPT_VERSION       = '9.0';

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
			'frequency'        => 'hourly',
			'notify_plugins'   => 1,
			'notify_themes'    => 1,
			'notify_automatic' => 1,
			'hide_updates'     => 1,
		);

		/**
		 * Filters the settings a new install starts with, and the values used for settings that are missing.
		 *
		 * Only the keys below are used, and values are cast to the type shown. Anything else
		 * is ignored, as is a return value that is not an array.
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
		 *     @type int    $notify_plugins   0 none, 1 all plugins, 2 active plugins only.
		 *     @type int    $notify_themes    0 none, 1 all themes, 2 active themes only.
		 *     @type int    $notify_automatic 1 to send WordPress's automatic core update emails to the notification address.
		 *     @type int    $hide_updates     1 to hide the WordPress update notice from users who cannot update.
		 * }
		 */
		$filtered = apply_filters( 'sc_wpun_default_settings', $defaults );
		if ( ! is_array( $filtered ) ) {
			return $defaults;
		}

		return array(
			'frequency'        => $this->string_setting( $filtered, 'frequency', $defaults['frequency'] ),
			'notify_plugins'   => $this->int_setting( $filtered, 'notify_plugins', $defaults['notify_plugins'] ),
			'notify_themes'    => $this->int_setting( $filtered, 'notify_themes', $defaults['notify_themes'] ),
			'notify_automatic' => $this->int_setting( $filtered, 'notify_automatic', $defaults['notify_automatic'] ),
			'hide_updates'     => $this->int_setting( $filtered, 'hide_updates', $defaults['hide_updates'] ),
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

		$plugins = $this->config->resolve_typed( Config_Context::setting( 'notify_plugins' ), $schemas['notify_plugins'] );
		if ( null !== $plugins ) {
			$settings['notify_plugins'] = (int) $plugins->get_value();
		}

		$themes = $this->config->resolve_typed( Config_Context::setting( 'notify_themes' ), $schemas['notify_themes'] );
		if ( null !== $themes ) {
			$settings['notify_themes'] = (int) $themes->get_value();
		}

		$automatic = $this->config->resolve_typed( Config_Context::setting( 'notify_automatic' ), $schemas['notify_automatic'] );
		if ( null !== $automatic ) {
			$settings['notify_automatic'] = (int) $automatic->get_value();
		}

		$hide = $this->config->resolve_typed( Config_Context::setting( 'hide_updates' ), $schemas['hide_updates'] );
		if ( null !== $hide ) {
			$settings['hide_updates'] = (int) $hide->get_value();
		}

		return $settings;
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
	 * What each global setting accepts when it is supplied from outside the database.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		return array(
			'frequency'        => array(
				'type'    => 'select',
				'options' => Frequencies::all(),
			),
			'notify_plugins'   => array(
				'type' => 'number',
				'min'  => 0,
				'max'  => 2,
			),
			'notify_themes'    => array(
				'type' => 'number',
				'min'  => 0,
				'max'  => 2,
			),
			'notify_automatic' => array( 'type' => 'checkbox' ),
			'hide_updates'     => array( 'type' => 'checkbox' ),
		);
	}

	/**
	 * The plugin's saved settings, with every value of the type the code expects and nothing from outside the database.
	 *
	 * Values can arrive as strings (imports, `wp option update`, filters), and missing
	 * keys get their defaults.
	 *
	 * @return SettingsData
	 */
	public function stored(): array {
		$stored   = $this->read_option( self::OPT_FIELD );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = $this->default_settings();

		return array(
			'frequency'        => $this->string_setting( $stored, 'frequency', $defaults['frequency'] ),
			'notify_plugins'   => $this->int_setting( $stored, 'notify_plugins', $defaults['notify_plugins'] ),
			'notify_themes'    => $this->int_setting( $stored, 'notify_themes', $defaults['notify_themes'] ),
			'notify_automatic' => $this->int_setting( $stored, 'notify_automatic', $defaults['notify_automatic'] ),
			'hide_updates'     => $this->int_setting( $stored, 'hide_updates', $defaults['hide_updates'] ),
		);
	}

	/**
	 * A stored setting as a non-negative integer, or its default.
	 *
	 * @param array<mixed> $stored   Stored settings.
	 * @param string       $key      Setting name.
	 * @param int          $fallback Value when the setting is missing or unusable.
	 *
	 * @return int
	 */
	private function int_setting( array $stored, string $key, int $fallback ): int {
		return isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ? absint( $stored[ $key ] ) : $fallback;
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
