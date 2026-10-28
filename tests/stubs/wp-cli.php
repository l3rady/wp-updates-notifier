<?php
/**
 * The parts of WP-CLI the plugin calls, for PHPStan only.
 *
 * The php-stubs/wp-cli-stubs package requires WordPress stubs older than this project's, so it cannot be installed.
 *
 * @package wp-updates-notifier
 */

// phpcs:disable

namespace {
	class WP_CLI {
		/**
		 * @param string          $name     Command name.
		 * @param callable|string $callable Class name or callable.
		 * @param array<mixed>    $args     Arguments.
		 * @return bool
		 */
		public static function add_command( $name, $callable, $args = array() ) {}

		/**
		 * @param string $message Message.
		 * @return void
		 */
		public static function log( $message ) {}

		/**
		 * @param string $message Message.
		 * @return void
		 */
		public static function line( $message = '' ) {}

		/**
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( $message ) {}

		/**
		 * @param string $message Message.
		 * @return void
		 */
		public static function warning( $message ) {}

		/**
		 * @param string|\WP_Error $message Message.
		 * @param bool|int         $exit    Whether to exit, or the code.
		 * @return void
		 * @phpstan-return ($exit is false ? void : never)
		 */
		public static function error( $message, $exit = true ) {}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param string                    $format Format.
	 * @param array<int, array<mixed>>  $items  Items.
	 * @param string|string[]           $fields Fields.
	 * @return void
	 */
	function format_items( $format, $items, $fields ) {}

	/**
	 * @param array<string, mixed> $assoc_args Flags.
	 * @param string               $flag       Flag.
	 * @param mixed                $default    Default.
	 * @return mixed
	 */
	function get_flag_value( $assoc_args, $flag, $default = null ) {}
}
