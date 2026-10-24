<?php
/**
 * Configuration from constants.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Reads values from PHP constants, usually defined in wp-config.php. Always on.
 *
 * @since 2.0.0
 */
final class Constant_Source implements Config_Source {

	/**
	 * The value of the constant, if it is defined.
	 *
	 * A constant can hold an array; it is returned as it is and reported as invalid by the plugin.
	 *
	 * @since 2.0.0
	 *
	 * @param string         $key     Constant name.
	 * @param Config_Context $context What is being asked for.
	 *
	 * @return mixed The value, or null if the constant is not defined.
	 */
	public function get( string $key, Config_Context $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Part of the interface.
		return defined( $key ) ? constant( $key ) : null;
	}

	/**
	 * Where the value comes from.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key Constant name.
	 *
	 * @return string
	 */
	public function get_label( string $key ): string {
		// translators: %s: Name of a PHP constant. Describes where a configuration value comes from; shown in "Set by %s".
		return sprintf( __( 'constant %s', 'wp-updates-notifier' ), $key );
	}
}
