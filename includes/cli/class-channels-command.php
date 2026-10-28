<?php
/**
 * The `wp updates-notifier channels` WP-CLI commands.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\CLI;

use WPUN\Status_Report;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Lists the channel instances.
 *
 * @internal
 */
class Channels_Command {
	/**
	 * Lists the channel instances.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render the output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit the output to these fields: id, label, module, availability, enabled, code_defined, last_result.
	 *
	 * ## EXAMPLES
	 *
	 *     wp updates-notifier channels list
	 *     wp updates-notifier channels list --format=json
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		unset( $args );
		$rows = array();
		foreach ( sc_wpun()->status_report()->channels() as $channel ) {
			$rows[] = array(
				'id'           => $channel['id'],
				'label'        => $channel['label'],
				'module'       => $channel['module'],
				'availability' => $channel['availability'],
				'enabled'      => $channel['enabled'] ? 'yes' : 'no',
				'code_defined' => $channel['code_defined'] ? 'yes' : 'no',
				'last_result'  => Status_Report::describe_last( $channel['last_result'] ),
			);
		}

		\WP_CLI\Utils\format_items(
			(string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$rows,
			(string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'fields', 'id,label,module,availability,enabled,code_defined,last_result' )
		);
	}
}
