<?php
/**
 * The kinds of item a notification can carry.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * The registered item types: core, plugin and theme, and any an add-on registers.
 *
 * @since 2.0.0
 */
final class Item_Types {

	/**
	 * Every registered type with its label.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, string> Type => label.
	 */
	public static function all(): array {
		$builtin = array(
			'core'   => __( 'WordPress', 'wp-updates-notifier' ),
			'plugin' => __( 'Plugins', 'wp-updates-notifier' ),
			'theme'  => __( 'Themes', 'wp-updates-notifier' ),
		);

		/**
		 * Filters the item types, to register types of your own.
		 *
		 * A type is a key of lower case letters, digits and underscores, with a label. Invalid
		 * entries are dropped, and core, plugin and theme cannot be removed or renamed away.
		 *
		 * Example, register a type for PHP end-of-life warnings:
		 *
		 *     add_filter(
		 *         'sc_wpun_item_types',
		 *         function ( $types ) {
		 *             $types['php'] = __( 'PHP', 'my-addon' );
		 *             return $types;
		 *         }
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, string> $types Type => label.
		 */
		$filtered = apply_filters( 'sc_wpun_item_types', $builtin );

		$types = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $type => $label ) {
			if ( is_string( $type ) && 1 === preg_match( '/^[a-z0-9_]+$/D', $type ) && is_string( $label ) && '' !== $label && ! isset( $builtin[ $type ] ) ) {
				$types[ $type ] = $label;
			}
		}

		return $builtin + $types;
	}

	/**
	 * Whether a type is registered.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Item type.
	 *
	 * @return bool
	 */
	public static function exists( string $type ): bool {
		return isset( self::all()[ $type ] );
	}
}
