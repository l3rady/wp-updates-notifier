<?php
/**
 * The `wp updates-notifier` WP-CLI commands.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\CLI;

use WPUN\Admin\Mute_Actions;
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
	 * The columns of the list of muted items.
	 *
	 * @var string[]
	 */
	const MUTED_FIELDS = array( 'type', 'name', 'key', 'mute', 'expires', 'set_in_code', 'muted_by', 'source', 'muted_at', 'update' );

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

		\WP_CLI::line( __( 'Muted', 'wp-updates-notifier' ) );
		\WP_CLI\Utils\format_items( 'table', $this->muted_rows( $data['muted'] ), self::MUTED_FIELDS );

		\WP_CLI::line( __( 'Pending updates', 'wp-updates-notifier' ) );
		foreach ( $data['pending'] as $pending ) {
			// translators: 1: Id of a channel instance. 2: What it would do, for example "send".
			\WP_CLI::line( sprintf( __( '%1$s (%2$s)', 'wp-updates-notifier' ), $pending['id'], $pending['status'] ) );
			foreach ( $pending['items'] as &$item ) {
				$item['new']         = $item['new'] ? 'new' : 'notified';
				$item['auto_update'] = $item['auto_update'] ? 'yes' : 'no';
			}
			unset( $item );
			\WP_CLI\Utils\format_items( 'table', $pending['items'], array( 'type', 'name', 'current_version', 'new_version', 'new', 'auto_update' ) );
		}
	}

	/**
	 * Mutes plugins or themes, so their updates are left out of the notifications.
	 *
	 * Every name is handled, then the command exits with an error if any of them failed. A name that is already muted the same way is only a warning. A mute set in code, or one a `sc_wpun_pre_mute` filter refuses, is an error. With --for or --until-date the mute is a snooze that ends by itself; the update check then sends the item again. A wrong or past time is an error before anything is changed. The change is reported to `sc_wpun_item_muted` with the source `cli`.
	 *
	 * ## OPTIONS
	 *
	 * <plugin|theme>
	 * : What the names are.
	 *
	 * <name>...
	 * : One or more plugins or themes. A plugin is its directory name (or its file for a single-file plugin) or its full file path, as for `wp plugin`. A theme is its stylesheet.
	 *
	 * [--until-next-version]
	 * : Skip only the version that is waiting now. Notifications start again when a newer version is offered. Fails for an item with no update waiting. With --for or --until-date, the mute ends at the earlier of the two.
	 *
	 * [--for=<duration>]
	 * : Snooze for this long: a whole number and `h` (hours), `d` (days) or `w` (weeks), for example `12h`, `3d` or `2w`. Not with --until-date.
	 *
	 * [--until-date=<date>]
	 * : Snooze until this date, in the site's timezone, as `YYYY-MM-DD` (the start of that day) or `YYYY-MM-DD HH:MM`. It must be in the future. Not with --for.
	 *
	 * ## EXAMPLES
	 *
	 *     # Never notify about this plugin.
	 *     wp updates-notifier mute plugin akismet
	 *
	 *     # Skip the version of a theme that is waiting.
	 *     wp updates-notifier mute theme twentytwentyfive --until-next-version
	 *
	 *     # Snooze a plugin for a week.
	 *     wp updates-notifier mute plugin akismet --for=1w
	 *
	 *     # Snooze until a date and time on the site's clock.
	 *     wp updates-notifier mute plugin akismet --until-date="2026-12-01 09:00"
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function mute( $args, $assoc_args ) {
		$skip    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'until-next-version', false );
		$expires = $this->snooze_end( $assoc_args );
		$this->each(
			$args,
			function ( string $type, string $key, string $name ) use ( $skip, $expires ): ?string {
				$store    = sc_wpun()->mute_store();
				$existing = $store->get( $type, $key );
				$until    = $skip ? $store->offered_version( $type, $key ) : null;
				if ( null !== $existing && ! $existing->is_from_code() && ( ! $skip || null !== $until ) && $existing->get_until() === $until && null === $expires && null === $existing->get_expires() ) {
					// translators: %s: Name of the plugin or theme.
					\WP_CLI::warning( sprintf( __( '%s is already muted that way.', 'wp-updates-notifier' ), $name ) );
					return null;
				}

				$result = ( new Mute_Actions( $store ) )->mute( $type, $key, 'cli', $skip, null, $expires );
				if ( ! $result[0] ) {
					return $result[1];
				}
				\WP_CLI::log( $result[1] );
				return null;
			}
		);
	}

	/**
	 * When a snooze ends, from --for or --until-date. Exits with an error for a wrong value, both options or a time that has passed.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 *
	 * @return int|null Unix timestamp; null if no snooze was asked for.
	 */
	private function snooze_end( array $assoc_args ): ?int {
		$for  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'for', null );
		$date = \WP_CLI\Utils\get_flag_value( $assoc_args, 'until-date', null );
		if ( null === $for && null === $date ) {
			return null;
		}
		if ( null !== $for && null !== $date ) {
			\WP_CLI::error( __( 'Use --for or --until-date, not both.', 'wp-updates-notifier' ) );
		}

		if ( null !== $for ) {
			if ( ! is_string( $for ) || 1 !== preg_match( '/^([1-9][0-9]{0,5})([hdw])$/D', $for, $parts ) ) {
				\WP_CLI::error( __( '--for must be a whole number and h, d or w, for example 12h, 3d or 2w.', 'wp-updates-notifier' ) );
			}
			$units = array(
				'h' => HOUR_IN_SECONDS,
				'd' => DAY_IN_SECONDS,
				'w' => WEEK_IN_SECONDS,
			);

			return time() + (int) $parts[1] * $units[ $parts[2] ];
		}

		$expires = is_string( $date ) ? self::parse_site_date( $date ) : null;
		if ( null === $expires ) {
			\WP_CLI::error( __( '--until-date must be a date as YYYY-MM-DD or YYYY-MM-DD HH:MM, in the site\'s timezone.', 'wp-updates-notifier' ) );
		}
		if ( $expires <= time() ) {
			\WP_CLI::error( __( '--until-date must be in the future.', 'wp-updates-notifier' ) );
		}

		return $expires;
	}

	/**
	 * A date given on the command line, in the site's timezone.
	 *
	 * @param string $date `YYYY-MM-DD` (midnight) or `YYYY-MM-DD HH:MM`.
	 *
	 * @return int|null Unix timestamp; null if it is not a real date in one of those forms.
	 */
	private static function parse_site_date( string $date ): ?int {
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}( [0-9]{2}:[0-9]{2})?$/D', trim( $date ) ) ) {
			return null; // createFromFormat() would accept 2099-6-1 too.
		}

		foreach ( array( '!Y-m-d H:i', '!Y-m-d' ) as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( $format, trim( $date ), wp_timezone() );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( false !== $parsed && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $parsed->getTimestamp();
			}
		}

		return null;
	}

	/**
	 * Turns update notifications on again for muted plugins or themes.
	 *
	 * Every name is handled, then the command exits with an error if any of them failed. A name that is not muted is only a warning. A mute set in code, or one a `sc_wpun_pre_unmute` filter refuses to remove, is an error. The change is reported to `sc_wpun_item_unmuted` with the source `cli`.
	 *
	 * ## OPTIONS
	 *
	 * <plugin|theme>
	 * : What the names are.
	 *
	 * <name>...
	 * : One or more plugins or themes, named as for `wp updates-notifier mute`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp updates-notifier unmute plugin akismet
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function unmute( $args, $assoc_args ) {
		unset( $assoc_args );
		$this->each(
			$args,
			function ( string $type, string $key, string $name ): ?string {
				$store = sc_wpun()->mute_store();
				if ( null === $store->get( $type, $key ) ) {
					// translators: %s: Name of the plugin or theme.
					\WP_CLI::warning( sprintf( __( '%s is not muted.', 'wp-updates-notifier' ), $name ) );
					return null;
				}

				$result = ( new Mute_Actions( $store ) )->unmute( $type, $key, 'cli' );
				if ( ! $result[0] ) {
					return $result[1];
				}
				\WP_CLI::log( $result[1] );
				return null;
			}
		);
	}

	/**
	 * Lists the muted plugins and themes.
	 *
	 * Includes the mutes set in code, and leaves out a skipped version once a newer one is offered and a snooze once its time has passed. `expires` is when a snooze ends, in the site's timezone, and empty for a mute with no time limit. `source` is where the mute was set from. `update` is the version waiting now, if any.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render the list in this format.
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
	 * ## EXAMPLES
	 *
	 *     wp updates-notifier muted
	 *     wp updates-notifier muted --format=json
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 *
	 * @return void
	 */
	public function muted( $args, $assoc_args ) {
		unset( $args );
		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		\WP_CLI\Utils\format_items( $format, $this->muted_rows( sc_wpun()->status_report()->muted() ), self::MUTED_FIELDS );
	}

	/**
	 * Resolve the names of a mute or unmute command and run the action on each, then fail if any failed.
	 *
	 * @param string[]                                  $args   Type, then names.
	 * @param callable(string, string, string): ?string $action Does one item; returns an error message, or null.
	 *
	 * @return void
	 */
	private function each( array $args, callable $action ): void {
		$type  = (string) array_shift( $args );
		$names = $args;
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || array() === $names ) {
			\WP_CLI::error( __( 'Say whether the names are plugins or themes, then one or more names.', 'wp-updates-notifier' ) );
		}

		$installed = ( new Mute_Actions( sc_wpun()->mute_store() ) )->installed()[ $type ];
		$failed    = 0;
		foreach ( $names as $name ) {
			$key = $this->resolve( $type, (string) $name, array_keys( $installed ) );
			if ( null === $key ) {
				$message = 'plugin' === $type
					// translators: %s: Name given on the command line.
					? sprintf( __( 'The plugin "%s" is not installed.', 'wp-updates-notifier' ), $name )
					// translators: %s: Name given on the command line.
					: sprintf( __( 'The theme "%s" is not installed.', 'wp-updates-notifier' ), $name );
			} else {
				$message = $action( $type, $key, $installed[ $key ] );
			}
			if ( null !== $message ) {
				++$failed;
				\WP_CLI::error( $message, false );
			}
		}

		if ( $failed > 0 ) {
			// translators: 1: Number of names that failed. 2: Number of names.
			\WP_CLI::error( sprintf( __( '%1$d of %2$d could not be changed.', 'wp-updates-notifier' ), $failed, count( $names ) ) );
		}
	}

	/**
	 * The key of an installed item for a name given on the command line.
	 *
	 * @param string   $type Item type.
	 * @param string   $name Name given.
	 * @param string[] $keys Keys of the installed items.
	 *
	 * @return string|null Null if nothing matches.
	 */
	private function resolve( string $type, string $name, array $keys ): ?string {
		if ( in_array( $name, $keys, true ) ) {
			return $name; // The plugin file or the stylesheet.
		}
		if ( 'plugin' !== $type ) {
			return null;
		}
		foreach ( $keys as $file ) {
			if ( dirname( $file ) === $name || ( false === strpos( $file, '/' ) && $file === $name . '.php' ) ) {
				return $file; // The directory, or a single-file plugin without its extension.
			}
		}

		return null;
	}

	/**
	 * The rows of the muted list as the commands print them.
	 *
	 * @param array<int, array{type: string, key: string, name: ?string, until: ?string, from_code: bool, time: int, user: int, offered: ?string, expires: ?int, source: string}> $rows From Status_Report::muted().
	 *
	 * @return array<int, array<string, string>>
	 */
	private function muted_rows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$user  = $row['user'] > 0 ? get_userdata( $row['user'] ) : false;
			$out[] = array(
				'type'        => $row['type'],
				'name'        => (string) $row['name'],
				'key'         => $row['key'],
				'mute'        => Status_Report::describe_mute( $row['until'] ),
				'expires'     => null === $row['expires'] ? '' : Status_Report::format_time( $row['expires'] ),
				'set_in_code' => $row['from_code'] ? 'yes' : 'no',
				'muted_by'    => false === $user ? '' : $user->user_login,
				'source'      => $row['source'],
				'muted_at'    => $row['time'] > 0 ? Status_Report::format_time( $row['time'] ) : '',
				'update'      => (string) $row['offered'],
			);
		}

		return $out;
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
