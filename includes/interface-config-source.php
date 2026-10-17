<?php
/**
 * A place configuration can come from.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * A source of configuration values outside the database: constants, environment
 * variables, or a secrets service an add-on connects to.
 *
 * Register sources with the `sc_wpun_config_sources` filter. They are asked in order and the
 * first to return a value wins. A source returns scalars (a string, bool, int or float); the
 * plugin casts the value to the type of the setting or field and reports a value it cannot use.
 *
 * @since 2.0.0
 */
interface Config_Source {

	/**
	 * The value for a setting or channel field, or null if this source has none.
	 *
	 * Do not log the value. A source may throw; the plugin reports the failure and asks the next source.
	 *
	 * @since 2.0.0
	 *
	 * @param string         $key     The constant or environment variable name, for example `SC_WPUN_SETTING_FREQUENCY`.
	 * @param Config_Context $context What is being asked for, and for which site.
	 *
	 * @return string|bool|int|float|null The value, or null if there is none.
	 */
	public function get( string $key, Config_Context $context );

	/**
	 * Where the value for a key comes from, in words, for the settings screen and Site Health.
	 *
	 * For example "constant SC_WPUN_SETTING_FREQUENCY" or "AWS Secrets Manager". It must never contain the value.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key The constant or environment variable name.
	 *
	 * @return string
	 */
	public function get_label( string $key ): string;
}
