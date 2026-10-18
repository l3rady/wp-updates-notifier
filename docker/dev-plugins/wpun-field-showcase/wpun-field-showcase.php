<?php
/**
 * Plugin Name:       WP Updates Notifier Field Showcase
 * Description:       Development only. Adds a notification channel that shows every settings field combination, to look at and try by hand. It sends nothing.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Requires Plugins:  wp-updates-notifier
 * Requires WPUN API: ^1.0
 * License:           GPL-2.0-or-later
 * Text Domain:       wpun-field-showcase
 *
 * This lives under docker/ and is mounted into the local site only. It is never in the plugin zip. It registers through
 * the public extension API only, like a third-party add-on: `sc_wpun_register_channels` and `sc_wpun_field_types`.
 *
 * Two modules are added: `field_showcase` (every field type and display) and `field_showcase_custom` (draws its own
 * settings with render_settings()).
 *
 * Values from outside the database
 * --------------------------------
 * A constant named SC_WPUN_CHANNEL_<INSTANCE_ID>__<FIELD> (both upper case) makes a field of an instance read-only, shown as
 * "Set by constant ...". For an instance with the id `showcase`:
 *
 *     wp config set SC_WPUN_CHANNEL_SHOWCASE__TEXT_REQUIRED 'Set from wp-config' --type=constant
 *     wp config set SC_WPUN_CHANNEL_SHOWCASE__NUMBER_RANGE 7 --raw --type=constant
 *     wp config set SC_WPUN_CHANNEL_SHOWCASE__BOOL_DEFAULT_ON false --raw --type=constant
 *     wp config set SC_WPUN_CHANNEL_SHOWCASE__SECRET_REQUIRED 'a-secret-value' --type=constant
 *
 * The last one is a secret: the form shows that a value is set, never the value. Remove a constant again with
 * `wp config delete SC_WPUN_CHANNEL_SHOWCASE__TEXT_REQUIRED --type=constant`.
 *
 * @package wpun-field-showcase
 */

namespace WPUN_Field_Showcase;

use WPUN\Channel_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * The versions of the WP Updates Notifier extension API this plugin works with.
 */
const REQUIRED_API = '^1.0';

/**
 * Wire the showcase, if the installed plugin offers the API it was written for.
 *
 * It takes no parameters on purpose: WordPress passes an empty string to callbacks of `plugins_loaded`.
 *
 * @return void
 */
function boot(): void {
	if ( ! function_exists( 'sc_wpun_api_satisfies' ) || ! \sc_wpun_api_satisfies( REQUIRED_API ) ) {
		return;
	}

	require_once __DIR__ . '/class-showcase-channel.php';
	require_once __DIR__ . '/class-showcase-custom-channel.php';

	add_filter( 'sc_wpun_field_types', __NAMESPACE__ . '\\add_field_types' );
	add_action(
		'sc_wpun_register_channels',
		static function ( Channel_Registry $registry ) {
			$registry->register( new Showcase_Channel() );
			$registry->register( new Showcase_Custom_Channel() );
		}
	);
}

/**
 * Add the colour field type: a colour input that accepts #rrggbb.
 *
 * @param mixed $types Field types by name.
 * @return mixed
 */
function add_field_types( $types ) {
	if ( ! is_array( $types ) ) {
		return $types;
	}

	$types['showcase_colour'] = array(
		'render'   => static function ( $field, $name, $id, $value, $disabled = false, $described_by = '', $error = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Fixed signature.
			$colour = is_string( $value ) && '' !== $value ? $value : '#000000';

			return '<input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $colour ) . '"'
				. ( $disabled ? ' disabled="disabled"' : '' )
				. ( '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : '' )
				. ( '' !== $error ? ' aria-invalid="true"' : '' ) . ' />';
		},
		'sanitize' => static function ( $value ) {
			if ( is_string( $value ) && 1 === preg_match( '/^#[0-9a-f]{6}$/i', $value ) ) {
				return strtolower( $value );
			}

			return new \WP_Error( 'invalid_colour', __( 'Enter a colour like #00aa00.', 'wpun-field-showcase' ) );
		},
	);

	return $types;
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );
