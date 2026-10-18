<?php
/**
 * The field showcase channel that draws its own settings.
 *
 * @package wpun-field-showcase
 */

namespace WPUN_Field_Showcase;

use WPUN\Channel_Instance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Shows the custom render path: render_settings() returns the markup of the settings section.
 *
 * The fields are still declared in get_fields(), so the plugin checks and stores them. Only the markup is the module's own.
 */
class Showcase_Custom_Channel extends Showcase_Channel {

	/**
	 * Channel type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'field_showcase_custom';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Field showcase: custom render (dev only)', 'wpun-field-showcase' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Draws its own settings with render_settings(), for developers. It sends nothing.', 'wpun-field-showcase' );
	}

	/**
	 * Two fields only.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			'greeting' => array(
				'type'     => 'text',
				'label'    => __( 'Greeting', 'wpun-field-showcase' ),
				'required' => true,
			),
			'loud'     => array(
				'type'  => 'boolean',
				'label' => __( 'Loud', 'wpun-field-showcase' ),
			),
		);
	}

	/**
	 * The module's own markup for the settings section.
	 *
	 * @param Channel_Instance $instance The instance being edited.
	 * @return string|null
	 */
	public function render_settings( Channel_Instance $instance ): ?string {
		$greeting = $instance->get_setting( 'greeting', '' );
		$loud     = true === $instance->get_setting( 'loud', false );

		return '<div class="wpun-field-showcase-custom"><p><label>' . esc_html__( 'Greeting', 'wpun-field-showcase' )
			. ' <input type="text" class="regular-text" name="settings[greeting]" value="' . esc_attr( is_scalar( $greeting ) ? (string) $greeting : '' ) . '" /></label></p>'
			. '<p><label><input type="checkbox" name="settings[loud]" value="1"' . checked( $loud, true, false ) . ' /> '
			. esc_html__( 'Loud', 'wpun-field-showcase' ) . '</label></p></div>';
	}
}
