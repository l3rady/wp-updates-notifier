<?php
/**
 * The reminder rules.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Holds the reminder rules of decisions 0016 and 0022: the clock, when a reminder is due and what it sends.
 *
 * @internal
 *
 * @phpstan-import-type Channel_State from State
 * @phpstan-import-type Instance_Plan from Update_Checker
 */
final class Reminder_Clock {
	/**
	 * Start, keep or stop the reminder clock of an instance, and tell whether a reminder is due.
	 *
	 * The clock runs while the instance wants reminders and something is waiting for it.
	 *
	 * @param Channel_Instance     $instance The instance.
	 * @param Channel_State        $entry    Its state.
	 * @param Notification_Item[]  $pending  Everything waiting that it receives.
	 * @param array<string, mixed> $options  The settings.
	 * @param bool                 $force    Whether to send what was notified again, which never sends a reminder.
	 *
	 * @return array{entry: Channel_State, due: bool} The state with the clock changed, and whether a reminder is due.
	 */
	public function tick( Channel_Instance $instance, array $entry, array $pending, array $options, bool $force ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- A phpstan-type alias.
		$remind   = Routing::normalise( $instance->get_routing() )['remind'];
		$interval = $this->reminder_interval( $remind );
		$due      = false;
		if ( isset( $entry['reminder_sent'] ) ) {
			$entry = $this->prune_reminder_sent( $entry, $pending ); // Progress only counts for items still waiting at the same version.
		}
		if ( 0 === $interval || array() === $pending ) {
			unset( $entry['last_reminder'], $entry['reminder_sent'] ); // The clock stops, and a new round starts clean.
		} elseif ( ! isset( $entry['last_reminder'] ) ) {
			unset( $entry['reminder_sent'] );
			$entry['last_reminder'] = time(); // Starts now: the first reminder comes one interval after items start waiting.
		} else {
			$due = ! $force && ! $entry['skip_pending'] && ( 'every_check' === $remind || $this->reminder_is_due( $entry['last_reminder'], $interval, $options ) );
		}

		return array(
			'entry' => $entry,
			'due'   => $due,
		);
	}

	/**
	 * Plan the reminder that is due: one message with everything waiting, new items included, instead of a second message.
	 *
	 * A reminder that stopped halfway resumes with the items it has not delivered yet. With none left the round is
	 * complete: the interval restarts and nothing is sent.
	 *
	 * @param Notification        $notification The notification of the check.
	 * @param Channel_State       $entry        The state of the instance.
	 * @param Notification_Item[] $pending      Everything waiting that the instance receives.
	 *
	 * @return array{entry: Channel_State, send: Notification|null, status: string, items: Notification_Item[]} The state, the message (null if there is nothing to send), the status of the plan and its items.
	 */
	public function plan( Notification $notification, array $entry, array $pending ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- A phpstan-type alias.
		$remaining = array();
		foreach ( $pending as $item ) {
			if ( ( $entry['reminder_sent'][ $item->get_type() ][ $item->get_key() ] ?? null ) !== $item->get_new_version() ) {
				$remaining[] = $item;
			}
		}

		if ( array() === $remaining ) {
			$entry['last_reminder'] = time();
			unset( $entry['reminder_sent'] );

			return array(
				'entry'  => $entry,
				'send'   => null,
				'status' => Update_Checker::STATUS_NOTHING,
				'items'  => array(),
			);
		}

		return array(
			'entry'  => $entry,
			'send'   => new Notification(
				$notification->get_site_name(),
				$notification->get_site_url(),
				$notification->get_updates_url(),
				$remaining,
				array(
					'is_reminder' => true,
					'source'      => $notification->get_source(),
				)
			),
			'status' => Update_Checker::STATUS_REMINDER,
			'items'  => $remaining,
		);
	}

	/**
	 * Keep the reminder progress of items that still wait at the same version.
	 *
	 * @param Channel_State       $entry   The state of the instance.
	 * @param Notification_Item[] $pending Everything waiting that the instance receives.
	 *
	 * @return Channel_State
	 */
	private function prune_reminder_sent( array $entry, array $pending ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- A phpstan-type alias.
		$kept = array();
		foreach ( $pending as $item ) {
			$type = $item->get_type();
			$key  = $item->get_key();
			if ( ( $entry['reminder_sent'][ $type ][ $key ] ?? null ) === $item->get_new_version() ) {
				$kept[ $type ][ $key ] = $item->get_new_version();
			}
		}
		if ( array() === $kept ) {
			unset( $entry['reminder_sent'] );
		} else {
			$entry['reminder_sent'] = $kept;
		}

		return $entry;
	}

	/**
	 * The seconds between reminders for a `remind` routing value.
	 *
	 * @param mixed $remind The normalised `remind` routing value: never, daily, weekly or every_check.
	 *
	 * @return int 0 for never, 1 for every check (a reminder is due at each check, so there is no real interval).
	 */
	private function reminder_interval( $remind ): int {
		if ( 'every_check' === $remind ) {
			return 1;
		}

		if ( 'daily' === $remind ) {
			return DAY_IN_SECONDS;
		}

		return 'weekly' === $remind ? WEEK_IN_SECONDS : 0;
	}

	/**
	 * Whether a reminder is due, a little early if the interval is not reached yet.
	 *
	 * WP-Cron only runs when the site gets a visit, so a check is often late, and the next one is
	 * scheduled from the planned time, not the actual one. With a strict comparison a daily reminder
	 * on a daily check would then be a few minutes short at the next run and wait a whole extra
	 * cycle. So a reminder is due when the time since the last one is within half of the check
	 * frequency's interval of the reminder interval. The tolerance is never more than half the
	 * reminder interval, so with checks less often than reminders (weekly checks, daily reminders)
	 * every check sends one, but a "Check now" soon after a reminder does not send another.
	 *
	 * @param int                  $last_reminder When the last reminder was sent, or the clock started.
	 * @param int                  $interval      Seconds between reminders.
	 * @param array<string, mixed> $options       The settings, for the check frequency.
	 *
	 * @return bool
	 */
	private function reminder_is_due( int $last_reminder, int $interval, array $options ): bool {
		$frequency = is_string( $options['frequency'] ?? null ) ? $options['frequency'] : '';
		$tolerance = (int) min( ( Frequencies::all()[ $frequency ]['interval'] ?? 0 ) / 2, $interval / 2 );

		return time() - $last_reminder >= $interval - $tolerance;
	}
}
