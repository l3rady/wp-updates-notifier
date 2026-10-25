<?php
/**
 * Loaded by Infection itself (infection.json5, bootstrap), not by the tests: it reflects the plugin's classes while it
 * generates mutants, and a class such as Email_Channel needs its parent class, so the plugin's autoloader is registered.
 *
 * @package wp-updates-notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' ); // The plugin's files exit without it.
}

require_once __DIR__ . '/../includes/class-autoloader.php';
\WPUN\Autoloader::register();
