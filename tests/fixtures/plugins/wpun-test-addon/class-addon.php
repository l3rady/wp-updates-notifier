<?php
/**
 * The hooks of the test add-on.
 *
 * @package wpun-test-addon
 */

namespace WPUN_Test_Addon;

use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Delivery_Result;
use WPUN\Notification;
use WPUN\Notification_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Registers the module and plugs into the update check, the routing, the delivery and the configuration, all through
 * public hooks. What it adds comes from the option `wpun_test_addon`, which is empty unless a test fills it:
 *
 * - `items`: list of arrays with `key`, `name`, `current` and `new`, reported as items of the add-on's own type.
 * - `config`: values by key (for example `SC_WPUN_CHANNEL_ADDON_CODE__NAME`) for instances whose id starts with `addon_`.
 * - `code_channel`: whether to define the code-defined instance `addon_code`.
 */
class Addon {

	const OPTION     = 'wpun_test_addon';
	const ITEM_TYPE  = 'test_addon_note';
	const ID_PREFIX  = 'addon_';
	const CODE_ID    = 'addon_code';
	const CODE_REGEX = '/^[A-Z]{3}-[0-9]{3}$/';

	/**
	 * What the add-on's channel instances were given to deliver: instance id => list of item key lists.
	 *
	 * @var array<string, array<int, string[]>>
	 */
	public static $received = array();

	/**
	 * The sent and failed events of its instances, as array( 'sent' or 'failed', instance id, result code ).
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	public static $events = array();

	/**
	 * Forget what was recorded.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$received = array();
		self::$events   = array();
	}

	/**
	 * One entry of the add-on's data.
	 *
	 * @param string $name Entry.
	 * @return mixed Null if there is none.
	 */
	public static function data( string $name ) {
		$data = get_option( self::OPTION, array() );

		return is_array( $data ) ? ( $data[ $name ] ?? null ) : null;
	}

	/**
	 * Add every hook.
	 *
	 * @return void
	 */
	public static function hook(): void {
		add_action( 'sc_wpun_register_channels', array( self::class, 'register_channels' ) );
		add_filter( 'sc_wpun_field_types', array( self::class, 'field_types' ) );
		add_filter( 'sc_wpun_item_types', array( self::class, 'item_types' ) );
		add_filter( 'sc_wpun_update_items', array( self::class, 'update_items' ) );
		add_filter( 'sc_wpun_channel_should_receive_item', array( self::class, 'should_receive_item' ), 10, 3 );
		add_filter( 'sc_wpun_pre_send', array( self::class, 'pre_send' ), 10, 3 );
		add_action( 'sc_wpun_notification_sent', array( self::class, 'on_sent' ), 10, 3 );
		add_action( 'sc_wpun_notification_failed', array( self::class, 'on_failed' ), 10, 3 );
		add_filter( 'sc_wpun_config_sources', array( self::class, 'config_sources' ) );
		add_filter( 'sc_wpun_channels', array( self::class, 'channels' ) );
	}

	/**
	 * Register the module.
	 *
	 * @param Channel_Registry $registry The registry.
	 * @return void
	 */
	public static function register_channels( Channel_Registry $registry ): void {
		$registry->register( new Addon_Channel() );
	}

	/**
	 * Add the `test_addon_code` field type: a code like ABC-123.
	 *
	 * @param array<string, mixed> $types Field types.
	 * @return array<string, mixed>
	 */
	public static function field_types( $types ) {
		$types['test_addon_code'] = array(
			'render'   => static function ( $field, $name, $id, $value, $disabled = false, $described_by = '', $error = '' ) {
				return '<input type="text" class="test-addon-code" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '"'
					. ( $disabled ? ' disabled="disabled"' : '' )
					. ( '' !== $described_by ? ' aria-describedby="' . esc_attr( $described_by ) . '"' : '' )
					. ( '' !== $error ? ' aria-invalid="true"' : '' ) . ' />';
			},
			'sanitize' => static function ( $value ) {
				if ( is_string( $value ) && 1 === preg_match( self::CODE_REGEX, $value ) ) {
					return $value;
				}

				return new \WP_Error( 'invalid_code', 'Enter a code like ABC-123.' );
			},
		);

		return $types;
	}

	/**
	 * Add the add-on's item type.
	 *
	 * @param array<string, string> $types Item types.
	 * @return array<string, string>
	 */
	public static function item_types( $types ) {
		$types[ self::ITEM_TYPE ] = 'Add-on notes';

		return $types;
	}

	/**
	 * Report the items listed in the add-on's data.
	 *
	 * @param array<int, mixed> $items Items.
	 * @return array<int, mixed>
	 */
	public static function update_items( $items ) {
		$notes = self::data( 'items' );
		foreach ( is_array( $notes ) ? $notes : array() as $note ) {
			$items[] = new Notification_Item(
				array(
					'type'            => self::ITEM_TYPE,
					'key'             => (string) $note['key'],
					'name'            => (string) $note['name'],
					'current_version' => (string) $note['current'],
					'new_version'     => (string) $note['new'],
				)
			);
		}

		return $items;
	}

	/**
	 * Let an instance of the add-on's type take only the item type its `receives` setting names.
	 *
	 * @param bool              $receive  Whether the instance would receive the item.
	 * @param Notification_Item $item     The item.
	 * @param Channel_Instance  $instance The instance.
	 * @return bool
	 */
	public static function should_receive_item( $receive, $item, $instance ) {
		if ( Addon_Channel::TYPE !== $instance->get_type() ) {
			return $receive;
		}

		$only = $instance->get_setting( 'receives', 'all' );

		return $receive && ( 'all' === $only || $item->get_type() === $only );
	}

	/**
	 * Answer the sends of the add-on's instances.
	 *
	 * @param Delivery_Result|null $pre          Answer so far.
	 * @param Notification         $notification What to deliver.
	 * @param Channel_Instance     $instance     The instance.
	 * @return Delivery_Result|null
	 */
	public static function pre_send( $pre, $notification, $instance ) {
		if ( Addon_Channel::TYPE !== $instance->get_type() ) {
			return $pre;
		}

		return self::deliver( $notification, $instance );
	}

	/**
	 * Record what an instance was given and answer: a failure if its note says "fail".
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The instance.
	 * @return Delivery_Result
	 */
	public static function deliver( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		self::$received[ $instance->get_id() ][] = array_map(
			static function ( Notification_Item $item ) {
				return $item->get_type() . ':' . $item->get_key();
			},
			$notification->get_items()
		);

		if ( 'fail' === $instance->get_setting( 'note' ) ) {
			return Delivery_Result::failure( 'The add-on refused.', 'addon_refused' );
		}

		return Delivery_Result::success( sprintf( 'Received %d items.', count( $notification->get_items() ) ) );
	}

	/**
	 * Record a sent event of the add-on's instances.
	 *
	 * @param Delivery_Result  $result       The result.
	 * @param Notification     $notification The notification.
	 * @param Channel_Instance $instance     The instance.
	 * @return void
	 */
	public static function on_sent( $result, $notification, $instance ): void {
		if ( Addon_Channel::TYPE === $instance->get_type() ) {
			self::$events[] = array( 'sent', $instance->get_id(), $result->get_code() );
		}
	}

	/**
	 * Record a failed event of the add-on's instances.
	 *
	 * @param Delivery_Result  $result       The result.
	 * @param Notification     $notification The notification.
	 * @param Channel_Instance $instance     The instance.
	 * @return void
	 */
	public static function on_failed( $result, $notification, $instance ): void {
		if ( Addon_Channel::TYPE === $instance->get_type() ) {
			self::$events[] = array( 'failed', $instance->get_id(), $result->get_code() );
		}
	}

	/**
	 * Add the add-on's configuration source.
	 *
	 * @param array<int, mixed> $sources Sources.
	 * @return array<int, mixed>
	 */
	public static function config_sources( $sources ) {
		$sources[] = new Addon_Config_Source();

		return $sources;
	}

	/**
	 * Define the code-defined instance, when the add-on's data asks for it.
	 *
	 * @param array<int|string, mixed> $channels Instances defined in code.
	 * @return array<int|string, mixed>
	 */
	public static function channels( $channels ) {
		if ( true === self::data( 'code_channel' ) ) {
			$channels[] = array(
				'id'       => self::CODE_ID,
				'type'     => Addon_Channel::TYPE,
				'label'    => 'Defined by the add-on',
				'enabled'  => true,
				'settings' => array(
					'code' => 'COD-001',
					'name' => 'Code',
				),
			);
		}

		return $channels;
	}
}
