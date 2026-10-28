<?php
/**
 * A stand-in for WP-CLI, loaded only by the tests of the commands. It records instead of printing, and
 * `WP_CLI::error()` throws instead of exiting, so a test can see that a command would exit 1.
 *
 * @package wp-updates-notifier
 */

// phpcs:ignoreFile -- A stand-in for another project's global class and functions, in two namespaces.

namespace {
	/**
	 * What `WP_CLI::error()` throws.
	 */
	class Test_Cli_Error extends RuntimeException {
	}

	/**
	 * The WP_CLI class, recording everything.
	 */
	class WP_CLI {

		/**
		 * Lines printed, in order, as "type: text" (line has no type).
		 *
		 * @var string[]
		 */
		public static $output = array();

		/**
		 * Commands registered by name.
		 *
		 * @var array
		 */
		public static $commands = array();

		public static function reset() {
			self::$output   = array();
			self::$commands = array();
		}

		public static function add_command( $name, $callable, $args = array() ) {
			self::$commands[ $name ] = $callable;
		}

		public static function log( $message ) {
			self::$output[] = $message;
		}

		public static function line( $message = '' ) {
			self::$output[] = $message;
		}

		public static function success( $message ) {
			self::$output[] = 'Success: ' . $message;
		}

		public static function warning( $message ) {
			self::$output[] = 'Warning: ' . $message;
		}

		public static function error( $message, $exit = true ) {
			self::$output[] = 'Error: ' . $message;
			throw new Test_Cli_Error( $message );
		}

		/**
		 * Everything printed so far.
		 *
		 * @return string
		 */
		public static function text() {
			return implode( "\n", self::$output );
		}
	}
}

namespace WP_CLI\Utils {
	function get_flag_value( $assoc_args, $flag, $default = null ) {
		return $assoc_args[ $flag ] ?? $default;
	}

	/**
	 * Prints a table as tab separated lines, JSON as JSON.
	 */
	function format_items( $format, $items, $fields ) {
		$fields = is_array( $fields ) ? $fields : explode( ',', $fields );
		$rows   = array();
		foreach ( $items as $item ) {
			$row = array();
			foreach ( $fields as $field ) {
				$row[ $field ] = $item[ $field ] ?? '';
			}
			$rows[] = $row;
		}

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $rows ) );
			return;
		}

		\WP_CLI::line( implode( "\t", $fields ) );
		foreach ( $rows as $row ) {
			\WP_CLI::line( implode( "\t", array_map( 'strval', $row ) ) );
		}
	}
}
