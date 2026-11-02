<?php
/**
 * The webhook notification module.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Modules\Webhook;

use WPUN\Channel_Registry;
use WPUN\Channel_Instance;
use WPUN\Notification_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

require_once __DIR__ . '/class-webhook-channel.php';

// A module registers its channel on this action. The registry is passed in, so no global is needed.
add_action(
	'sc_wpun_register_channels',
	static function ( Channel_Registry $registry ) {
		$registry->register( new Webhook_Channel() );
	}
);

// The `updates.resolved` message. These listeners are added once, here, and not in the callback above,
// which runs every time a registry is built. The update check fires `sc_wpun_item_resolved` once per item and instance,
// then `sc_wpun_after_update_check`: the first collects, the second sends one message per instance and forgets what it held.
add_action(
	'sc_wpun_item_resolved',
	static function ( $item, $how, $instance ) {
		if ( $item instanceof Notification_Item && is_string( $how ) && $instance instanceof Channel_Instance ) {
			Webhook_Channel::collect_resolved( $item, $how, $instance );
		}
	},
	10,
	3
);
add_action(
	'sc_wpun_after_update_check',
	static function ( $notification ) {
		Webhook_Channel::send_resolved( $notification );
	}
);
