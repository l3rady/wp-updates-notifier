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
 * Lists, switches on and switches off the channel instances.
 *
 * @internal
 */
class Channels_Command {
	/**
	 * Every column of a channel row, in the order `status` shows them.
	 */
	const FIELDS = array( 'id', 'label', 'module', 'availability', 'enabled', 'code_defined', 'last_result', 'last_failure', 'retry_at', 'held_until' );

	/**
	 * The columns `channels list` shows without --fields.
	 */
	const LIST_FIELDS = array( 'id', 'label', 'module', 'availability', 'enabled', 'code_defined', 'last_result', 'held_until' );

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
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit the output to these fields: id, label, module, availability, enabled, code_defined, last_result, last_failure, retry_at, held_until. Without --fields the list shows id, label, module, availability, enabled, code_defined, last_result, held_until.
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
			(string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'fields', implode( ',', self::LIST_FIELDS ) )
		);
	}

	/**
	 * Switches channel instances on.
	 *
	 * Instances defined in code, or with `enabled` set by a constant or environment variable, are refused. The
	 * rest are still switched. Exits with a non-zero status if any id could not be changed.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more channel instance ids, as `channels list` shows them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp updates-notifier channels enable email
	 *     wp updates-notifier channels enable email slack_ops
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function enable( $args, $assoc_args ) {
		unset( $assoc_args );
		$this->switch_each( $args, true );
	}

	/**
	 * Switches channel instances off.
	 *
	 * Instances defined in code, or with `enabled` set by a constant or environment variable, are refused. The
	 * rest are still switched. Exits with a non-zero status if any id could not be changed.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more channel instance ids, as `channels list` shows them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp updates-notifier channels disable email
	 *     wp updates-notifier channels disable email slack_ops
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function disable( $args, $assoc_args ) {
		unset( $assoc_args );
		$this->switch_each( $args, false );
	}

	/**
	 * Switch each instance, report each, then fail if any failed.
	 *
	 * @param string[] $ids     Instance ids.
	 * @param bool     $enabled Whether to switch them on.
	 *
	 * @return void
	 */
	private function switch_each( array $ids, bool $enabled ): void {
		if ( array() === $ids ) {
			\WP_CLI::error( __( 'Give one or more channel ids.', 'wp-updates-notifier' ) );
		}

		$form   = sc_wpun()->channel_form();
		$failed = 0;
		foreach ( $ids as $id ) {
			$id     = (string) $id;
			$result = $form->switch_enabled( $id, $enabled );
			if ( is_string( $result ) ) {
				++$failed;
				\WP_CLI::error( $id . ': ' . $result, false );
			} elseif ( $enabled ) {
				// translators: %s: Label of the channel.
				\WP_CLI::success( sprintf( __( '%s enabled.', 'wp-updates-notifier' ), $result->get_label() ) );
			} else {
				// translators: %s: Label of the channel.
				\WP_CLI::success( sprintf( __( '%s disabled.', 'wp-updates-notifier' ), $result->get_label() ) );
			}
		}

		if ( $failed > 0 ) {
			// translators: 1: Number of names that failed. 2: Number of names.
			\WP_CLI::error( sprintf( __( '%1$d of %2$d could not be changed.', 'wp-updates-notifier' ), $failed, count( $ids ) ) );
		}
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
