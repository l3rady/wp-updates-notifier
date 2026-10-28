<?php
/**
 * The `wp updates-notifier` WP-CLI commands.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\CLI;

use WPUN\Channel_Instance;
use WPUN\Notification_Renderer;
use WPUN\Status_Report;
use WPUN\Update_Checker;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Checks for updates, tests channels and shows the state of WP Updates Notifier.
 *
 * @internal
 */
class Updates_Notifier_Command {
	/**
	 * Runs the update check now, as the scheduled one does.
	 *
	 * Sends what each channel instance has not been told about yet and exits with an error if any instance failed, so a system cron can report it. The notification source is `cli`.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be sent, and print the messages, without sending or changing anything. WordPress's own update data is still refreshed.
	 *
	 * [--force]
	 * : Send everything waiting again, including what was already notified, and also to instances that are set to skip it. Switched-off instances, instances told to wait, routing and filters still apply.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what a check would do.
	 *     wp updates-notifier check --dry-run
	 *
	 *     # Run the check from a system cron.
	 *     wp updates-notifier check
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function check( $args, $assoc_args ) {
		unset( $args );
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$force   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$report  = sc_wpun()->status_report();

		$usable = false;
		foreach ( sc_wpun()->channel_store()->all() as $instance ) {
			$usable = $usable || ( $instance->is_enabled() && Status_Report::AVAILABLE === $report->availability( $instance ) );
		}
		if ( ! $usable ) {
			\WP_CLI::warning( __( 'No channel instance is switched on and available, so there is nobody to notify.', 'wp-updates-notifier' ) );
			return;
		}

		$plan   = sc_wpun()->update_checker()->run_check( 'cli', $dry_run, $force );
		$labels = sc_wpun()->channel_store()->all();
		$sent   = 0;
		$failed = 0;
		foreach ( $plan as $id => $entry ) {
			\WP_CLI::log( sprintf( '%s (%s): %s', $labels[ $id ]->get_label(), $id, $this->describe( $entry, $dry_run ) ) );
			$failed += Update_Checker::STATUS_FAILED === $entry['status'] ? 1 : 0;
			if ( in_array( $entry['status'], array( Update_Checker::STATUS_SEND, Update_Checker::STATUS_REMINDER ), true ) ) {
				++$sent;
				if ( $dry_run && null !== $entry['notification'] ) {
					\WP_CLI::line( Notification_Renderer::plain_text( $entry['notification'] ) );
				}
			}
		}

		if ( $failed > 0 ) {
			// translators: %d: Number of channel instances that failed.
			\WP_CLI::error( sprintf( _n( '%d channel instance failed.', '%d channel instances failed.', $failed, 'wp-updates-notifier' ), $failed ) );
		}

		if ( $dry_run ) {
			// translators: %d: Number of channel instances that would be sent a message.
			\WP_CLI::success( sprintf( _n( 'Dry run finished, nothing was sent or changed. %d channel instance would be sent a message.', 'Dry run finished, nothing was sent or changed. %d channel instances would be sent a message.', $sent, 'wp-updates-notifier' ), $sent ) );
		} else {
			// translators: %d: Number of channel instances that were sent a message.
			\WP_CLI::success( sprintf( _n( 'Check finished. %d channel instance was sent a message.', 'Check finished. %d channel instances were sent a message.', $sent, 'wp-updates-notifier' ), $sent ) );
		}
	}

	/**
	 * Sends a test message through channel instances.
	 *
	 * Without --channel every switched-on instance whose module supports test messages is tested. Exits with an error if a test message could not be sent.
	 *
	 * ## OPTIONS
	 *
	 * [--channel=<id>]
	 * : The id of one channel instance to test. See `wp updates-notifier channels list`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp updates-notifier test
	 *     wp updates-notifier test --channel=email
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		unset( $args );
		$id = \WP_CLI\Utils\get_flag_value( $assoc_args, 'channel', null );

		if ( null !== $id ) {
			$instance = sc_wpun()->channel_store()->get( (string) $id );
			if ( null === $instance ) {
				// translators: %s: Id of a channel instance.
				\WP_CLI::error( sprintf( __( 'There is no channel instance "%s".', 'wp-updates-notifier' ), (string) $id ) );
			}
			$problem = $this->cannot_test( $instance );
			if ( null !== $problem ) {
				\WP_CLI::error( $problem );
			}
			$result = sc_wpun()->dispatcher()->send_test( $instance );
			if ( ! $result->is_success() ) {
				// translators: 1: Name of the channel. 2: Why it failed.
				\WP_CLI::error( sprintf( __( 'The test message could not be sent through %1$s: %2$s', 'wp-updates-notifier' ), $instance->get_label(), $result->get_message() ) );
			}
			// translators: %s: Name of the channel.
			\WP_CLI::success( sprintf( __( 'Test message sent through %s.', 'wp-updates-notifier' ), $instance->get_label() ) );
			return;
		}

		$tested = 0;
		$failed = 0;
		foreach ( sc_wpun()->channel_store()->all() as $instance ) {
			if ( null !== $this->cannot_test( $instance ) ) {
				continue; // Switched off, unavailable or no test messages: not part of "all".
			}
			++$tested;
			$result  = sc_wpun()->dispatcher()->send_test( $instance );
			$failed += $result->is_success() ? 0 : 1;
			\WP_CLI::log( sprintf( '%s (%s): %s', $instance->get_label(), $instance->get_id(), $result->is_success() ? __( 'test message sent', 'wp-updates-notifier' ) : $result->get_message() ) );
		}

		if ( 0 === $tested ) {
			\WP_CLI::error( __( 'No channel instance is switched on, available and able to send a test message.', 'wp-updates-notifier' ) );
		}
		if ( $failed > 0 ) {
			// translators: %d: Number of test messages that could not be sent.
			\WP_CLI::error( sprintf( _n( '%d test message could not be sent.', '%d test messages could not be sent.', $failed, 'wp-updates-notifier' ), $failed ) );
		}
		// translators: %d: Number of test messages sent.
		\WP_CLI::success( sprintf( _n( '%d test message sent.', '%d test messages sent.', $tested, 'wp-updates-notifier' ), $tested ) );
	}

	/**
	 * Shows the settings, the schedule, the channel instances and the pending updates.
	 *
	 * The pending updates come from a dry run of the check, which refreshes WordPress's update data like the dry run does but sends and saves nothing. Secret values are never shown, only whether they are set. The fields of an instance whose module is not registered are not shown.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render the report as a table or as JSON.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp updates-notifier status
	 *     wp updates-notifier status --format=json
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );
		$data = sc_wpun()->status_report()->all();

		if ( 'json' === \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		\WP_CLI::line( __( 'Settings', 'wp-updates-notifier' ) );
		\WP_CLI\Utils\format_items( 'table', $data['settings'], array( 'setting', 'value', 'source' ) );

		\WP_CLI::line( __( 'Schedule', 'wp-updates-notifier' ) );
		\WP_CLI\Utils\format_items( 'table', array( $data['schedule'] ), array( 'frequency', 'next_run', 'last_check' ) );

		\WP_CLI::line( __( 'Channels', 'wp-updates-notifier' ) );
		$rows = array();
		foreach ( $data['channels'] as $channel ) {
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
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'label', 'module', 'availability', 'enabled', 'code_defined', 'last_result', 'last_failure', 'retry_at' ) );

		foreach ( $data['channels'] as $channel ) {
			if ( array() !== $channel['fields'] ) {
				// translators: %s: Id of a channel instance.
				\WP_CLI::line( sprintf( __( 'Settings of %s', 'wp-updates-notifier' ), $channel['id'] ) );
				\WP_CLI\Utils\format_items( 'table', $channel['fields'], array( 'field', 'value', 'source' ) );
			}
		}

		\WP_CLI::line( __( 'Pending updates', 'wp-updates-notifier' ) );
		foreach ( $data['pending'] as $pending ) {
			// translators: 1: Id of a channel instance. 2: What it would do, for example "send".
			\WP_CLI::line( sprintf( __( '%1$s (%2$s)', 'wp-updates-notifier' ), $pending['id'], $pending['status'] ) );
			foreach ( $pending['items'] as &$item ) {
				$item['new'] = $item['new'] ? 'new' : 'notified';
			}
			unset( $item );
			\WP_CLI\Utils\format_items( 'table', $pending['items'], array( 'type', 'name', 'current_version', 'new_version', 'new' ) );
		}
	}

	/**
	 * Why a test message cannot be sent through an instance.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return string|null Null if it can.
	 */
	private function cannot_test( Channel_Instance $instance ): ?string {
		$availability = sc_wpun()->status_report()->availability( $instance );
		if ( Status_Report::AVAILABLE !== $availability ) {
			// translators: 1: Label of the channel instance. 2: Why its module is not available.
			return sprintf( __( '%1$s cannot be used: %2$s.', 'wp-updates-notifier' ), $instance->get_label(), $availability );
		}
		if ( ! $instance->is_enabled() ) {
			// translators: %s: Label of the channel instance.
			return sprintf( __( '%s is switched off.', 'wp-updates-notifier' ), $instance->get_label() );
		}
		$channel = sc_wpun()->channels()->get( $instance->get_type() );
		if ( null === $channel || ! $channel->supports( 'test_message' ) ) {
			// translators: %s: Label of the channel.
			return sprintf( __( '%s cannot send a test message.', 'wp-updates-notifier' ), $instance->get_label() );
		}

		return null;
	}

	/**
	 * What happened, or would happen, to an instance in a check.
	 *
	 * @param array<string, mixed> $entry   The instance's entry of the check's plan.
	 * @param bool                 $dry_run Whether nothing was sent.
	 *
	 * @return string
	 */
	private function describe( array $entry, bool $dry_run ): string {
		$count = count( $entry['items'] );

		switch ( $entry['status'] ) {
			case Update_Checker::STATUS_DISABLED:
				return __( 'switched off', 'wp-updates-notifier' );
			case Update_Checker::STATUS_WAITING:
				// translators: %s: Date and time the instance may be tried again.
				return sprintf( __( 'waiting until %s', 'wp-updates-notifier' ), Status_Report::format_time( $entry['retry_at'] ) );
			case Update_Checker::STATUS_SKIP:
				return __( 'recorded without sending', 'wp-updates-notifier' );
			case Update_Checker::STATUS_REMINDER:
				// translators: %d: Number of items.
				return sprintf( $dry_run ? _n( 'would send a reminder with %d item', 'would send a reminder with %d items', $count, 'wp-updates-notifier' ) : _n( 'sent a reminder with %d item', 'sent a reminder with %d items', $count, 'wp-updates-notifier' ), $count );
			case Update_Checker::STATUS_SEND:
				// translators: %d: Number of items.
				return sprintf( $dry_run ? _n( 'would send %d item', 'would send %d items', $count, 'wp-updates-notifier' ) : _n( 'sent %d item', 'sent %d items', $count, 'wp-updates-notifier' ), $count );
			case Update_Checker::STATUS_FAILED:
				// translators: %s: Why it failed.
				return sprintf( __( 'failed: %s', 'wp-updates-notifier' ), null === $entry['result'] ? __( 'the routing failed', 'wp-updates-notifier' ) : $entry['result']->get_message() );
			default:
				return __( 'nothing new', 'wp-updates-notifier' );
		}
	}
}
