<?php
/**
 * Constants WordPress defines at runtime that the stubs leave out.
 *
 * @package wp-updates-notifier
 */

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant, defined for static analysis only.
}
