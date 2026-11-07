<?php
/**
 * The Slack notification module.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Modules\Slack;

use WPUN\Channel_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

require_once __DIR__ . '/class-slack-message.php';
require_once __DIR__ . '/class-slack-channel.php';

// A module registers its channel on this action. The registry is passed in, so no global is needed.
add_action(
	'sc_wpun_register_channels',
	static function ( Channel_Registry $registry ) {
		$registry->register( new Slack_Channel() );
	}
);
