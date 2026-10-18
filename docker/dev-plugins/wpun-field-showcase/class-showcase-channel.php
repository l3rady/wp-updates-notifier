<?php
/**
 * The field showcase channel.
 *
 * @package wpun-field-showcase
 */

namespace WPUN_Field_Showcase;

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Shows every field type and display. Delivers nothing.
 *
 * The schema has no groups, so each label starts with the type and says what the field shows, and the order groups them.
 */
class Showcase_Channel extends Channel {

	/**
	 * Channel type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'field_showcase';
	}

	/**
	 * The API versions this channel needs.
	 *
	 * @return string
	 */
	public function get_api_constraint(): string {
		return REQUIRED_API;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Field showcase (dev only)', 'wpun-field-showcase' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Shows every settings field type and display, for developers. It sends nothing.', 'wpun-field-showcase' );
	}

	/**
	 * Whether the channel supports a feature.
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return 'test_message' === $feature;
	}

	/**
	 * Options for the enum fields.
	 *
	 * @param int $count How many.
	 * @return array<string, string>
	 */
	private static function options( int $count ): array {
		$options = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			// translators: %d: Option number.
			$options[ 'option_' . $i ] = sprintf( __( 'Option %d', 'wpun-field-showcase' ), $i );
		}

		return $options;
	}

	/**
	 * Refuse the value "fail", so the error round trip can be seen on demand.
	 *
	 * @param mixed $value The clean value.
	 * @return true|\WP_Error
	 */
	public static function refuse_fail( $value ) {
		if ( 'fail' === $value ) {
			return new \WP_Error( 'showcase_fail', __( 'The value "fail" is always refused.', 'wpun-field-showcase' ) );
		}

		return true;
	}

	/**
	 * Upper case the submitted text.
	 *
	 * @param mixed $value The submitted value.
	 * @return string
	 */
	public static function shout( $value ): string {
		return strtoupper( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
	}

	/**
	 * The settings, one or more of each type and display.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			// Text.
			'text_plain'            => array(
				'type'  => 'text',
				'label' => __( 'Text, plain', 'wpun-field-showcase' ),
			),
			'text_required'         => array(
				'type'     => 'text',
				'label'    => __( 'Text, required', 'wpun-field-showcase' ),
				'required' => true,
			),
			'text_description'      => array(
				'type'        => 'text',
				'label'       => __( 'Text, with a description', 'wpun-field-showcase' ),
				'description' => __( 'This is the help text of the field. It is read out with the field.', 'wpun-field-showcase' ),
			),
			'text_default'          => array(
				'type'    => 'text',
				'label'   => __( 'Text, with a default', 'wpun-field-showcase' ),
				'default' => 'Default text',
			),
			'text_secret'           => array(
				'type'   => 'text',
				'label'  => __( 'Text, secret flag', 'wpun-field-showcase' ),
				'secret' => true,
			),
			// Textarea.
			'textarea_plain'        => array(
				'type'  => 'textarea',
				'label' => __( 'Textarea, plain', 'wpun-field-showcase' ),
			),
			'textarea_secret'       => array(
				'type'   => 'textarea',
				'label'  => __( 'Textarea, secret flag', 'wpun-field-showcase' ),
				'secret' => true,
			),
			// URL.
			'url_plain'             => array(
				'type'  => 'url',
				'label' => __( 'URL, plain', 'wpun-field-showcase' ),
			),
			'url_required'          => array(
				'type'     => 'url',
				'label'    => __( 'URL, required', 'wpun-field-showcase' ),
				'required' => true,
			),
			// Email list.
			'email_list_plain'      => array(
				'type'  => 'email_list',
				'label' => __( 'Email list, plain', 'wpun-field-showcase' ),
			),
			'email_list_required'   => array(
				'type'     => 'email_list',
				'label'    => __( 'Email list, required', 'wpun-field-showcase' ),
				'required' => true,
			),
			// Secret.
			'secret_plain'          => array(
				'type'  => 'secret',
				'label' => __( 'Secret, plain', 'wpun-field-showcase' ),
			),
			'secret_required'       => array(
				'type'     => 'secret',
				'label'    => __( 'Secret, required', 'wpun-field-showcase' ),
				'required' => true,
			),
			// Number.
			'number_plain'          => array(
				'type'  => 'number',
				'label' => __( 'Number, plain', 'wpun-field-showcase' ),
			),
			'number_range'          => array(
				'type'        => 'number',
				'label'       => __( 'Number, min 1 and max 10', 'wpun-field-showcase' ),
				'description' => __( 'Try 0 or 11 to see the range error.', 'wpun-field-showcase' ),
				'min'         => 1,
				'max'         => 10,
			),
			'number_required'       => array(
				'type'     => 'number',
				'label'    => __( 'Number, required', 'wpun-field-showcase' ),
				'required' => true,
			),
			// Boolean.
			'bool_checkbox'         => array(
				'type'  => 'boolean',
				'label' => __( 'Boolean, checkbox (default display)', 'wpun-field-showcase' ),
			),
			'bool_radio'            => array(
				'type'    => 'boolean',
				'label'   => __( 'Boolean, radio', 'wpun-field-showcase' ),
				'display' => 'radio',
			),
			'bool_select'           => array(
				'type'    => 'boolean',
				'label'   => __( 'Boolean, select', 'wpun-field-showcase' ),
				'display' => 'select',
			),
			'bool_labels'           => array(
				'type'    => 'boolean',
				'label'   => __( 'Boolean, radio with custom labels', 'wpun-field-showcase' ),
				'display' => 'radio',
				'labels'  => array(
					'on'  => __( 'Always', 'wpun-field-showcase' ),
					'off' => __( 'Never', 'wpun-field-showcase' ),
				),
			),
			'bool_default_on'       => array(
				'type'    => 'boolean',
				'label'   => __( 'Boolean, checkbox, on by default', 'wpun-field-showcase' ),
				'default' => true,
			),
			// Enum, one choice.
			'enum_radio'            => array(
				'type'    => 'enum',
				'label'   => __( 'Enum, single, radio (default for up to five options)', 'wpun-field-showcase' ),
				'options' => self::options( 3 ),
			),
			'enum_select'           => array(
				'type'    => 'enum',
				'label'   => __( 'Enum, single, select', 'wpun-field-showcase' ),
				'display' => 'select',
				'options' => self::options( 3 ),
			),
			'enum_many_options'     => array(
				'type'    => 'enum',
				'label'   => __( 'Enum, single, more than five options (select by default)', 'wpun-field-showcase' ),
				'options' => self::options( 8 ),
			),
			// Enum, several choices.
			'enum_multi_checkboxes' => array(
				'type'     => 'enum',
				'label'    => __( 'Enum, several, checkboxes (default)', 'wpun-field-showcase' ),
				'multiple' => true,
				'options'  => self::options( 4 ),
			),
			'enum_multi_select'     => array(
				'type'     => 'enum',
				'label'    => __( 'Enum, several, select', 'wpun-field-showcase' ),
				'multiple' => true,
				'display'  => 'select',
				'options'  => self::options( 4 ),
			),
			'enum_multi_min_max'    => array(
				'type'        => 'enum',
				'label'       => __( 'Enum, several, min 2 and max 3 choices', 'wpun-field-showcase' ),
				'description' => __( 'Choose one, or more than three, to see the error.', 'wpun-field-showcase' ),
				'multiple'    => true,
				'min'         => 2,
				'max'         => 3,
				'options'     => self::options( 5 ),
			),
			'enum_multi_many'       => array(
				'type'     => 'enum',
				'label'    => __( 'Enum, several, more than five options (select by default)', 'wpun-field-showcase' ),
				'multiple' => true,
				'options'  => self::options( 8 ),
			),
			// Aliases.
			'alias_checkbox'        => array(
				'type'  => 'checkbox',
				'label' => __( 'Alias, type checkbox', 'wpun-field-showcase' ),
			),
			'alias_select'          => array(
				'type'    => 'select',
				'label'   => __( 'Alias, type select', 'wpun-field-showcase' ),
				'options' => self::options( 3 ),
			),
			// Custom type.
			'custom_colour'         => array(
				'type'    => 'showcase_colour',
				'label'   => __( 'Custom type, colour picker', 'wpun-field-showcase' ),
				'default' => '#2271b1',
			),
			// Callbacks.
			'callback_validate'     => array(
				'type'              => 'text',
				'label'             => __( 'Callback, validate_callback refuses "fail"', 'wpun-field-showcase' ),
				'description'       => __( 'Type fail and save to see the error on this field.', 'wpun-field-showcase' ),
				'validate_callback' => array( self::class, 'refuse_fail' ),
			),
			'callback_sanitize'     => array(
				'type'              => 'text',
				'label'             => __( 'Callback, sanitize_callback upper-cases the text', 'wpun-field-showcase' ),
				'sanitize_callback' => array( self::class, 'shout' ),
			),
		);
	}

	/**
	 * Deliver nothing. Log what would have been sent.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The instance.
	 * @return Delivery_Result
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A development channel, logging is its output.
		error_log(
			sprintf(
				'WPUN field showcase "%1$s": would send %2$s for %3$s.',
				$instance->get_id(),
				$notification->is_test() ? 'a test message' : 'a notification',
				$notification->get_site_name()
			)
		);

		return Delivery_Result::success( __( 'Nothing sent: showcase channel', 'wpun-field-showcase' ) );
	}
}
