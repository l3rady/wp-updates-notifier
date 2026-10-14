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
 * @phpstan-type SettingsData array{frequency: string, email_notifications: int, notify_to: string, notify_from: string, slack_notifications: int, slack_webhook_url: string, slack_channel_override: string, notify_plugins: int, notify_themes: int, notify_automatic: int, hide_updates: int}
 */
class Settings {
	const OPT_FIELD         = 'sc_wpun_settings';
	const OPT_VERSION_FIELD = 'sc_wpun_settings_ver';
	const OPT_VERSION       = '8.1';

	/**
	 * Run state storage, written when settings from before version 8 are upgraded.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Constructor.
	 *
	 * @param State $state Run state storage.
	 */
	public function __construct( State $state ) {
		$this->state = $state;
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

			// If we are upgrading from settings before settings version 7, turn on email notifications by default.
			if ( intval( $current_ver ) < 7 ) {
				$defaults['email_notifications'] = 1;
			}

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

			// Intersect current options with defaults. Basically removing settings that are obsolete.
			$options = array_intersect_key( $options, $defaults );
			// Merge current settings with defaults. Basically adding any new settings with defaults that we dont have.
			$options = array_merge( $defaults, $options );
			$this->write_option( self::OPT_FIELD, $options ); // update settings.
			$this->write_option( self::OPT_VERSION_FIELD, self::OPT_VERSION ); // update settings version.
		}
	}

	/**
	 * The settings a new install starts with.
	 *
	 * @return SettingsData
	 */
	public function default_settings(): array {
		return array(
			'frequency'              => 'hourly',
			'email_notifications'    => 0,
			'notify_to'              => (string) get_option( 'admin_email' ),
			'notify_from'            => (string) get_option( 'admin_email' ),
			'slack_notifications'    => 0,
			'slack_webhook_url'      => '',
			'slack_channel_override' => '',
			'notify_plugins'         => 1,
			'notify_themes'          => 1,
			'notify_automatic'       => 1,
			'hide_updates'           => 1,
		);
	}

	/**
	 * The plugin's settings, filtered and with every value of the type the code expects.
	 *
	 * Values can arrive as strings (imports, `wp option update`, filters), and missing
	 * keys get their defaults.
	 *
	 * @return SettingsData
	 */
	public function get(): array {
		$stored   = $this->read_option( self::OPT_FIELD );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = $this->default_settings();

		return array(
			'frequency'              => $this->string_setting( $stored, 'frequency', $defaults['frequency'] ),
			'email_notifications'    => $this->int_setting( $stored, 'email_notifications', $defaults['email_notifications'] ),
			'notify_to'              => $this->string_setting( $stored, 'notify_to', $defaults['notify_to'] ),
			'notify_from'            => $this->string_setting( $stored, 'notify_from', $defaults['notify_from'] ),
			'slack_notifications'    => $this->int_setting( $stored, 'slack_notifications', $defaults['slack_notifications'] ),
			'slack_webhook_url'      => $this->string_setting( $stored, 'slack_webhook_url', $defaults['slack_webhook_url'] ),
			'slack_channel_override' => $this->string_setting( $stored, 'slack_channel_override', $defaults['slack_channel_override'] ),
			'notify_plugins'         => $this->int_setting( $stored, 'notify_plugins', $defaults['notify_plugins'] ),
			'notify_themes'          => $this->int_setting( $stored, 'notify_themes', $defaults['notify_themes'] ),
			'notify_automatic'       => $this->int_setting( $stored, 'notify_automatic', $defaults['notify_automatic'] ),
			'hide_updates'           => $this->int_setting( $stored, 'hide_updates', $defaults['hide_updates'] ),
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
		return update_option( $field, apply_filters( 'sc_wpun_put_options_filter', $value, $field ) );
	}
}
