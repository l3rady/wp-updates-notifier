<?php
/**
 * Configuration from environment variables.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Reads values from environment variables. Only used when the site opts in by setting the
 * environment variable SC_WPUN_LOAD_FROM_ENV to 1, true, yes or on, or with the
 * `sc_wpun_load_from_env` filter.
 *
 * @since 2.0.0
 */
final class Env_Source implements Config_Source {

	/**
	 * The value of the variable, if it is set.
	 *
	 * @since 2.0.0
	 *
	 * @param string         $key     Variable name.
	 * @param Config_Context $context What is being asked for.
	 *
	 * @return string|null The value, or null if the variable is not set.
	 */
	public function get( string $key, Config_Context $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Part of the interface.
		$value = getenv( $key );
		if ( false !== $value ) {
			return $value;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A deployment setting, not user input; it is cast and validated like every value.
		return isset( $_ENV[ $key ] ) && is_string( $_ENV[ $key ] ) ? $_ENV[ $key ] : null;
	}

	/**
	 * Where the value comes from.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key Variable name.
	 *
	 * @return string
	 */
	public function get_label( string $key ): string {
		// translators: %s: Name of an environment variable. Describes where a configuration value comes from; shown in "Set by %s".
		return sprintf( __( 'environment variable %s', 'wp-updates-notifier' ), $key );
	}
}
