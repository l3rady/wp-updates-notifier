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
	 * : Limit the output to these fields: id, label, module, availability, enabled, code_defined, last_result, held_until.
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
		\WP_CLI\Utils\format_items(
			(string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			self::rows( sc_wpun()->status_report()->channels() ),
			(string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'fields', 'id,label,module,availability,enabled,code_defined,last_result,held_until' )
		);
	}

	/**
	 * Table rows of channel instances, for `channels list` and `status`. Static, so WP-CLI does not offer it as a
	 * subcommand.
	 *
	 * @param array<int, array<string, mixed>> $channels Channels from Status_Report::channels().
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function rows( array $channels ): array {
		$rows = array();
		foreach ( $channels as $channel ) {
			$rows[] = array(
				'id'           => $channel['id'],
				'label'        => $channel['label'],
				'module'       => $channel['module'],
				'availability' => $channel['availability'],
				'enabled'      => $channel['enabled'] ? 'yes' : 'no',
				'code_defined' => $channel['code_defined'] ? 'yes' : 'no',
				'last_result'  => Status_Report::describe_last( $channel['last_result'] ),
				'last_failure' => Status_Report::describe_last( $channel['last_failure'] ),
				'retry_at'     => (string) $channel['retry_at'],
				'held_until'   => (string) $channel['held_until'],
			);
		}

		return $rows;
	}
}
