<?php
/**
 * The plugin class autoloader.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Loads the plugin's own classes from the includes directory when they are first used.
 *
 * A class in the WPUN namespace maps to a file named after it: underscores become dashes and
 * the name is lowercase. Namespaces below WPUN become directories, so WPUN\Admin\Settings_Page
 * is includes/admin/class-settings-page.php. Abstract classes and interfaces carry an
 * abstract-class- or interface- prefix instead of class-.
 *
 * @internal
 */
final class Autoloader {
	const NAMESPACE_PREFIX = 'WPUN\\';

	/**
	 * Start loading the plugin's classes on demand.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load the file that declares a class, if it is one of ours.
	 *
	 * @param string $class_name Fully qualified class, abstract class or interface name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		$path = self::path_for( $class_name );

		if ( null !== $path ) {
			require_once $path;
		}
	}

	/**
	 * The file that declares a class.
	 *
	 * @param string $class_name Fully qualified class, abstract class or interface name.
	 * @return string|null Absolute path, or null if the class is not ours or has no file.
	 */
	public static function path_for( string $class_name ): ?string {
		$class_name = ltrim( $class_name, '\\' );

		if ( 0 !== strpos( $class_name, self::NAMESPACE_PREFIX ) ) {
			return null;
		}

		$parts = explode( '\\', substr( $class_name, strlen( self::NAMESPACE_PREFIX ) ) );

		// Only plain identifiers, so a name such as `WPUN\..\x` can never reach a file outside includes/.
		foreach ( $parts as $part ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $part ) ) {
				return null;
			}
		}

		$name = strtolower( str_replace( '_', '-', (string) array_pop( $parts ) ) );

		$dir = __DIR__ . '/';

		foreach ( $parts as $part ) {
			$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}

		foreach ( array( 'class-', 'abstract-class-', 'interface-' ) as $prefix ) {
			$file = $dir . $prefix . $name . '.php';

			if ( is_file( $file ) ) {
				return $file;
			}
		}

		return null;
	}
}
