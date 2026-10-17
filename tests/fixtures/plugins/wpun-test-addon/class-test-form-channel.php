<?php
/**
 * Channels for the add and edit forms, for tests.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;

/**
 * A channel with a custom field type, a secret, a required text and a text with a description.
 */
class Test_Form_Channel extends Channel {

	/**
	 * Channel type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'fixture';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Fixture';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'A channel for tests.';
	}

	/**
	 * Fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			'colour' => array(
				'type'  => 'test_colour',
				'label' => 'Colour',
			),
			'token'  => array(
				'type'  => 'secret',
				'label' => 'Token',
			),
			'name'   => array(
				'type'     => 'text',
				'label'    => 'Name',
				'required' => true,
			),
			'note'   => array(
				'type'        => 'text',
				'label'       => 'Note',
				'description' => 'Shown to nobody.',
			),
		);
	}

	/**
	 * Supports a test message.
	 *
	 * @param string $feature Feature.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return 'test_message' === $feature;
	}

	/**
	 * Succeed.
	 *
	 * @param Notification     $notification Notification.
	 * @param Channel_Instance $instance     Instance.
	 * @return Delivery_Result
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		return Delivery_Result::success();
	}

	/**
	 * Register the `test_colour` field type: a colour input that accepts #rrggbb.
	 *
	 * @return void
	 */
	public static function register_colour_type() {
		add_filter(
			'sc_wpun_field_types',
			static function ( $types ) {
				$types['test_colour'] = array(
					'render'   => static function ( $field, $name, $id, $value, $disabled = false, $described_by = '', $error = '' ) {
						return '<input type="color" class="test-colour" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '"'
							. ( $disabled ? ' disabled="disabled"' : '' )
							. ( '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : '' )
							. ( '' !== $error ? ' aria-invalid="true"' : '' ) . ' />';
					},
					'sanitize' => static function ( $value ) {
						if ( is_string( $value ) && 1 === preg_match( '/^#[0-9a-f]{6}$/i', $value ) ) {
							return strtolower( $value );
						}

						return new WP_Error( 'invalid_colour', 'Enter a colour like #00aa00.' );
					},
				);
				return $types;
			}
		);
	}
}

/**
 * The same channel, drawing its settings itself.
 */
class Test_Custom_Render_Channel extends Test_Form_Channel {

	/**
	 * Channel type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'fixture_custom';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Fixture custom';
	}

	/**
	 * Own markup.
	 *
	 * @param Channel_Instance $instance Instance.
	 * @return string|null
	 */
	public function render_settings( Channel_Instance $instance ): ?string {
		return '<p class="test-custom-render">Custom <input type="text" name="settings[name]" value="' . esc_attr( (string) $instance->get_setting( 'name', '' ) ) . '" /></p>';
	}
}
