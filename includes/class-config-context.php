<?php
/**
 * What a configuration value is asked for.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * The setting or channel instance field a Config_Source is asked about, and the site.
 *
 * Immutable. Build one with setting() or channel_field().
 *
 * @since 2.0.0
 */
final class Config_Context {
	const SCOPE_SETTING = 'setting';
	const SCOPE_CHANNEL = 'channel';

	/**
	 * Scope.
	 *
	 * @var string
	 */
	private $scope;

	/**
	 * Setting name.
	 *
	 * @var string
	 */
	private $setting;

	/**
	 * Instance id.
	 *
	 * @var string
	 */
	private $instance_id;

	/**
	 * Field name.
	 *
	 * @var string
	 */
	private $field;

	/**
	 * Site id.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Use setting() or channel_field().
	 *
	 * @param string $scope       Scope.
	 * @param string $setting     Setting name.
	 * @param string $instance_id Instance id.
	 * @param string $field       Field name.
	 */
	private function __construct( string $scope, string $setting, string $instance_id, string $field ) {
		$this->scope       = $scope;
		$this->setting     = $setting;
		$this->instance_id = $instance_id;
		$this->field       = $field;
		$this->site_id     = get_current_blog_id();
	}

	/**
	 * A global setting.
	 *
	 * @since 2.0.0
	 *
	 * @param string $name Setting name, for example `frequency`.
	 *
	 * @return Config_Context
	 */
	public static function setting( string $name ): Config_Context {
		return new self( self::SCOPE_SETTING, $name, '', '' );
	}

	/**
	 * A field of a channel instance.
	 *
	 * @since 2.0.0
	 *
	 * @param string $instance_id Instance id.
	 * @param string $field       Field name, or `enabled`.
	 *
	 * @return Config_Context
	 */
	public static function channel_field( string $instance_id, string $field ): Config_Context {
		return new self( self::SCOPE_CHANNEL, '', $instance_id, $field );
	}

	/**
	 * Whether this is a `setting` or a `channel` field.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_scope(): string {
		return $this->scope;
	}

	/**
	 * The setting name; empty for a channel field.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_setting(): string {
		return $this->setting;
	}

	/**
	 * The instance id; empty for a setting.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_instance_id(): string {
		return $this->instance_id;
	}

	/**
	 * The field name; empty for a setting.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_field(): string {
		return $this->field;
	}

	/**
	 * The site the value is asked for, as it was when this object was made.
	 *
	 * @since 2.0.0
	 *
	 * @return int
	 */
	public function get_site_id(): int {
		return $this->site_id;
	}

	/**
	 * The name of the constant and environment variable for this value.
	 *
	 * `SC_WPUN_SETTING_<SETTING>` for a setting, `SC_WPUN_CHANNEL_<INSTANCE_ID>__<FIELD>` for a channel field.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_key(): string {
		if ( self::SCOPE_SETTING === $this->scope ) {
			return 'SC_WPUN_SETTING_' . strtoupper( $this->setting );
		}

		return 'SC_WPUN_CHANNEL_' . strtoupper( $this->instance_id ) . '__' . strtoupper( $this->field );
	}
}
