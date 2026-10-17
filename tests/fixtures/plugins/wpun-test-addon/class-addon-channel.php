<?php
/**
 * The channel module of the test add-on.
 *
 * @package wpun-test-addon
 */

namespace WPUN_Test_Addon;

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Item_Types;
use WPUN\Notification;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * A channel that does not deliver anywhere: the add-on short-circuits its sends with `sc_wpun_pre_send`.
 */
class Addon_Channel extends Channel {

	const TYPE = 'test_addon';

	/**
	 * Channel type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return self::TYPE;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Test add-on';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return 'A module supplied by the test add-on.';
	}

	/**
	 * Fields: a code of the add-on's own field type, a name, the one item type to receive, and a note.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return array(
			'code'     => array(
				'type'  => 'test_addon_code',
				'label' => 'Code',
			),
			'name'     => array(
				'type'     => 'text',
				'label'    => 'Name',
				'required' => true,
			),
			'receives' => array(
				'type'    => 'enum',
				'label'   => 'Receives',
				'options' => array( 'all' => 'Every item type' ) + Item_Types::all(),
				'default' => 'all',
				'display' => 'select',
			),
			'note'     => array(
				'type'  => 'text',
				'label' => 'Note',
			),
		);
	}

	/**
	 * The settings form, drawn by the add-on.
	 *
	 * @param Channel_Instance $instance Instance.
	 * @return string|null
	 */
	public function render_settings( Channel_Instance $instance ): ?string {
		return '<div class="test-addon-settings">'
			. '<p>Test add-on settings</p>'
			. '<input type="text" name="settings[code]" value="' . esc_attr( (string) $instance->get_setting( 'code', '' ) ) . '" />'
			. '<input type="text" name="settings[name]" value="' . esc_attr( (string) $instance->get_setting( 'name', '' ) ) . '" />'
			. '<input type="text" name="settings[receives]" value="' . esc_attr( (string) $instance->get_setting( 'receives', 'all' ) ) . '" />'
			. '</div>';
	}

	/**
	 * Deliver. The add-on normally answers first through `sc_wpun_pre_send`; this is the same answer for when it does not.
	 *
	 * @param Notification     $notification Notification.
	 * @param Channel_Instance $instance     Instance.
	 * @return Delivery_Result
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		return Addon::deliver( $notification, $instance );
	}
}
