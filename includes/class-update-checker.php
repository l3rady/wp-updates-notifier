<?php
/**
 * Checking for updates and notifying about them.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Looks for core, plugin and theme updates and sends a notification about new ones.
 *
 * @internal
 * @phpstan-import-type Channel_State from State
 * @phpstan-type Instance_Plan array{status: string, items: Notification_Item[], notification: ?Notification, result: ?Delivery_Result, retry_at: int, pending: Notification_Item[]}
 */
class Update_Checker {
	/**
	 * The statuses of an instance in the plan of a check. A real run turns a send that failed into `failed`.
	 */
	const STATUS_DISABLED = 'disabled'; // Switched off.
	const STATUS_WAITING  = 'waiting';  // Told to wait (Retry-After) or held (deferred), see `retry_at`.
	const STATUS_FAILED   = 'failed';   // The routing threw or the send failed.
	const STATUS_SKIP     = 'skip';     // Items recorded without sending (or, with the module unavailable, the flag kept and nothing recorded).
	const STATUS_REMINDER = 'reminder'; // A reminder with everything pending is (to be) sent.
	const STATUS_SEND     = 'send';     // The new items are (to be) sent.
	const STATUS_NOTHING  = 'nothing';  // Nothing new.

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Run state.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Dispatcher.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Channel instance storage, where the email settings are kept.
	 *
	 * @var Channel_Store
	 */
	private $channels;

	/**
	 * Mute storage.
	 *
	 * @var Mute_Store
	 */
	private $mutes;

	/**
	 * Tells whether WordPress is set to install an update by itself.
	 *
	 * @var Auto_Update_Policy
	 */
	private $auto_updates;

	/**
	 * The notification built by the last check.
	 *
	 * @var Notification|null
	 */
	private $last_notification = null;

	/**
	 * The results of the last check by instance id.
	 *
	 * @var array<string, Delivery_Result>
	 */
	private $last_results = array();

	/**
	 * Where the plugin runs: one site or a whole network.
	 *
	 * @var Network_Context
	 */
	private $network_context;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings Settings.
	 * @param State                $state    Run state.
	 * @param Dispatcher           $dispatcher Dispatcher.
	 * @param Channel_Store        $channels Channel instance storage.
	 * @param Mute_Store           $mutes    Mute storage.
	 * @param Auto_Update_Policy   $auto_updates Tells whether WordPress is set to install an update by itself.
	 * @param Network_Context|null $network_context Where the plugin runs.
	 */
	public function __construct( Settings $settings, State $state, Dispatcher $dispatcher, Channel_Store $channels, Mute_Store $mutes, Auto_Update_Policy $auto_updates, ?Network_Context $network_context = null ) {
		$this->network_context = $network_context ?? new Network_Context();
		$this->auto_updates    = $auto_updates;
		$this->mutes           = $mutes;
		$this->settings        = $settings;
		$this->state           = $state;
		$this->dispatcher      = $dispatcher;
		$this->channels        = $channels;
	}

	/**
	 * This is run by the cron.
	 *
	 * @return void
	 */
	public function do_update_check() {
		$this->check( 'cron', false, false );
	}

	/**
	 * Runs the check now, for the `sc_wpun_run_check` action.
	 *
	 * @return void
	 */
	public function run_manual_check() {
		$this->check( 'manual', false, false );
	}

	/**
	 * Runs the check for the WP-CLI commands, and tells what each instance got or would get.
	 *
	 * A dry run does everything but change anything: it sends nothing, saves no state (no pruning,
	 * no reminder clock, no cleared skip, no `last_check_time`), fires neither `sc_wpun_after_dispatch`
	 * nor `sc_wpun_after_update_check`, and leaves last_results() and last_notification() as they were.
	 * Collecting still refreshes WordPress's update data and runs the filters, and a reminder is
	 * reported as due if a real run would send one now.
	 *
	 * A forced run only skips the "already told" test: everything waiting that the instance's routing
	 * gives it is sent as a normal message (never a reminder, whose clock is left as it is), also
	 * while a skip is pending, which a successful send clears. Switched-off and waiting (Retry-After)
	 * instances, routing, the veto and a missing module are respected. A dry run can be forced too.
	 *
	 * @internal
	 *
	 * @param string $source  What started the check, kept in the notification context.
	 * @param bool   $dry_run Report only: send and change nothing.
	 * @param bool   $force   Send everything waiting again, whatever was notified.
	 *
	 * @return array<string, Instance_Plan> By instance id, for every instance. `items` are those of the
	 *                                      message (for `skip` those recorded), `notification` the one sent
	 *                                      or to be sent, `result` its result (real runs), `retry_at` the
	 *                                      time a `waiting` instance may be tried again,
	 *                                      `pending` everything waiting that its routing gives it.
	 */
	public function run_check( string $source, bool $dry_run = false, bool $force = false ): array {
		return $this->check( $source, $dry_run, $force );
	}

	/**
	 * The update check collects what is waiting for an update, then delivers to each enabled
	 * channel instance the items it has not been told about yet.
	 *
	 * What was delivered is remembered per instance, so an instance that failed is tried again
	 * on the next run without the others sending twice.
	 *
	 * An instance with reminders on (the `remind` routing key) also gets one message, marked as a
	 * reminder, with everything still waiting for it once its interval has passed since the last
	 * reminder (with `every_check`, at every check after the first). The interval starts when
	 * something first waits for the instance, and stops when nothing does. A due reminder replaces
	 * the message of new items of that run; new items between reminders are sent at once as usual.
	 * A failed reminder does not restart the interval, and a reminder that stopped halfway (the channel
	 * said which items went out, see Delivery_Result::with_delivered()) resumes without the delivered items.
	 *
	 * @param string $source  What started the check, kept in the notification context.
	 * @param bool   $dry_run Whether to only report, see run_check().
	 * @param bool   $force   Whether to send what was notified again, see run_check().
	 *
	 * @return array<string, Instance_Plan>
	 */
	private function check( string $source, bool $dry_run, bool $force ): array {
		/**
		 * Fires when an update check starts, before anything is looked up.
		 *
		 * @since 2.0.0
		 */
		do_action( 'sc_wpun_before_update_check' );

		// Refresh WordPress's update data first, whatever notify_core says. Under WP-Cron this also runs
		// WordPress's automatic updates (wp_maybe_auto_update) before plugins and themes are read, so
		// what WordPress installs by itself is not reported. Do not remove, reorder or make this
		// conditional: it changes what users hear about (ADR 0020).
		do_action( 'wp_version_check' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.

		$options = $this->settings->get(); // get settings.
		$state   = $this->state->get();

		// A collector per check, so what it reads once (the network's actives, the installed items) is read again for the next one.
		$collector = new Update_Collector( $this->auto_updates, $this->network_context );
		$collected = $collector->collect( $options );
		$items     = $collected['items']; // What the check finds, in the order of the message.
		$checked   = $collected['checked']; // Item type => keys still waiting, for the types this run looked at.

		/**
		 * Filters the items found by the check, to add, remove or change items.
		 *
		 * Runs after core, plugins and themes were collected and before the global settings'
		 * routing. Only Notification_Item objects are kept. An item type other than core,
		 * plugin and theme must be registered with the `sc_wpun_item_types` filter for its
		 * history to be kept up to date: once no item of a registered type is returned,
		 * what was notified about it is forgotten.
		 *
		 * Example, report an item of your own:
		 *
		 *     add_filter(
		 *         'sc_wpun_update_items',
		 *         function ( $items ) {
		 *             $items[] = new \WPUN\Notification_Item(
		 *                 array(
		 *                     'type'            => 'php',
		 *                     'key'             => 'php',
		 *                     'name'            => 'PHP',
		 *                     'current_version' => PHP_VERSION,
		 *                     'new_version'     => '8.4',
		 *                 )
		 *             );
		 *             return $items;
		 *         }
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param Notification_Item[] $items The items found, in the order of the message.
		 */
		$filtered = apply_filters( 'sc_wpun_update_items', $items );
		$items    = array();
		foreach ( is_array( $filtered ) ? $filtered : array() as $item ) {
			if ( $item instanceof Notification_Item ) {
				$items[] = $item;
			}
		}

		// Registered add-on types are checked too: what they report now is what is waiting.
		foreach ( array_keys( Item_Types::all() ) as $type ) {
			if ( ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ) {
				$checked[ $type ] ??= array();
			}
		}

		// An item a filter added is waiting too, even if WordPress's own update data does not list it,
		// or its history would be forgotten and it would be sent again on every run.
		$checked = $this->add_pending_keys( $checked, $items );

		// Mutes a newer version or their expiry has ended, to report once. Recorded in the state, never in the mutes.
		$ended = $this->find_ended_mutes( $items, $state );

		// A snooze in force: its items are flagged as snoozed below, so they are new again when it ends.
		$snoozed = array();
		foreach ( $items as $item ) {
			$mute = $this->mutes->find( $item->get_type(), $item->get_key() );
			if ( null !== $mute && null !== $mute->get_expires() && $this->mutes->is_muted( $item ) ) {
				$snoozed[ $item->get_type() ][ $item->get_key() ] = true;
			}
		}

		// The global veto comes after, so a vetoed item still counts as waiting.
		$items = array_values( array_filter( $items, array( $this, 'should_notify' ) ) );

		// A snoozed item a filter reports anyway is remembered as usual, or it would be sent at every check.
		foreach ( $items as $item ) {
			unset( $snoozed[ $item->get_type() ][ $item->get_key() ] );
		}

		$notification = new Notification( $this->network_context->site_name(), $this->network_context->home_url(), $this->network_context->update_core_url(), $items, array( 'source' => $source ) );

		/**
		 * Filters the notification built by the update check, before any channel instance gets its share.
		 *
		 * Must return a Notification; anything else is ignored. Each instance then receives the
		 * items of the returned notification that its routing allows.
		 *
		 * Example, drop the notes of every item:
		 *
		 *     add_filter(
		 *         'sc_wpun_notification',
		 *         function ( $notification ) {
		 *             return $notification->with_items( my_strip_notes( $notification->get_items() ) );
		 *         }
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param Notification $notification The notification, with every item that is waiting.
		 */
		$filtered_notification = apply_filters( 'sc_wpun_notification', $notification );
		if ( $filtered_notification instanceof Notification ) {
			$notification = $filtered_notification;
		} else {
			_doing_it_wrong( 'sc_wpun_notification', esc_html__( 'The filter must return a WPUN\\Notification.', 'wp-updates-notifier' ), '2.0.0' );
		}

		$checked = $this->add_pending_keys( $checked, $notification->get_items() );

		if ( ! $dry_run ) {
			$this->last_notification = $notification;
			$this->last_results      = array();
		}
		$results = array(); // Delivery results of the instances sent to, kept apart so a dry run leaves last_results() alone.
		$plan    = array();

		$instances = $this->channels->all();
		$pruned    = $this->prune_notified( $this->state_for_instances( $state, $instances ), $checked, $snoozed );
		$channels  = $pruned['channels'];
		$gone      = $pruned['gone'];

		foreach ( $instances as $id => $instance ) {
			$planned     = $this->plan_instance( $instance, $channels[ $id ] ?? null, $notification, $options, $dry_run, $force );
			$plan[ $id ] = $planned['plan'];
			if ( null !== $plan[ $id ]['result'] ) {
				$results[ $id ] = $plan[ $id ]['result'];
			}
			if ( null !== $planned['entry'] ) {
				$channels[ $id ] = $planned['entry'];
			}
		}

		if ( $dry_run ) {
			return $plan; // Nothing is saved and no hook fires: the state stays exactly as it was.
		}

		$this->last_results = $results;

		// Sending saved each instance's last result, retry time and hold in the stored state, which the copy read at the start lacks.
		$fresh    = $this->state->get()['channels'];
		$resolved = $this->find_resolved( $gone, $state, $instances, $collector ); // Read before the details of what was notified are trimmed.
		foreach ( $channels as $id => $entry ) {
			$channels[ $id ] = $this->trim_notified_meta( $entry );
			unset( $channels[ $id ]['last_result'], $channels[ $id ]['retry_at'], $channels[ $id ]['held_until'] );
			foreach ( array( 'last_result', 'retry_at', 'held_until' ) as $key ) {
				if ( isset( $fresh[ $id ][ $key ] ) ) {
					$channels[ $id ][ $key ] = $fresh[ $id ][ $key ];
				}
			}
		}

		$state['channels']        = $channels;
		$state['last_check_time'] = time();
		unset( $state['legacy_notified'] ); // Every instance that existed has taken its share; later ones start clean.
		foreach ( array(
			'ended_mutes'   => 'recorded',
			'ended_snoozes' => 'snoozes',
		) as $key => $part ) {
			if ( array() !== $ended[ $part ] ) {
				$state[ $key ] = $ended[ $part ];
			} else {
				unset( $state[ $key ] );
			}
		}
		$this->state->save( $state );

		$this->fire_after_check( $ended['fire'], $resolved, $results, $notification );

		return $plan;
	}

	/**
	 * Forget, for every instance, what is no longer waiting for an update, and flag what is snoozed.
	 *
	 * Runs for every instance whether it is on or not. A snoozed item keeps its entry, flagged, so it is sent
	 * as new when the snooze ends even if that version was notified before it started (State::is_new()), and
	 * so it can still be resolved while it is snoozed.
	 *
	 * @param array<string, Channel_State>       $channels The state of each instance.
	 * @param array<string, string[]>            $checked  Item type => keys still waiting, for the types this run looked at.
	 * @param array<string, array<string, true>> $snoozed  Item type => key => true, for what is snoozed.
	 *
	 * @return array{channels: array<string, Channel_State>, gone: array<string, array<string, array<string, string>>>} The pruned state, and
	 *         what is no longer waiting at all (not just snoozed) and may be resolved, see find_resolved(): instance id => item
	 *         type => key => notified version, of the built-in types.
	 */
	private function prune_notified( array $channels, array $checked, array $snoozed ): array {
		$gone = array();
		foreach ( $channels as $id => $entry ) {
			foreach ( $checked as $type => $pending_keys ) {
				if ( isset( $entry['notified'][ $type ] ) ) {
					$waiting = array_intersect_key( $entry['notified'][ $type ], array_flip( $pending_keys ) );
					if ( in_array( $type, array( 'core', 'plugin', 'theme' ), true ) && count( $waiting ) < count( $entry['notified'][ $type ] ) ) {
						$gone[ $id ][ $type ] = array_diff_key( $entry['notified'][ $type ], $waiting );
					}
					$channels[ $id ]['notified'][ $type ] = $waiting;
					foreach ( array_keys( array_intersect_key( $waiting, $snoozed[ $type ] ?? array() ) ) as $key ) {
						$channels[ $id ]['notified_meta'][ $type ][ $key ]          ??= array(
							'name'            => '',
							'current_version' => '',
							'links'           => array(),
							'time'            => 0,
						); // An entry stored without details gets empty ones.
						$channels[ $id ]['notified_meta'][ $type ][ $key ]['snoozed'] = true;
					}
				}
			}
		}

		return array(
			'channels' => $channels,
			'gone'     => $gone,
		);
	}

	/**
	 * Plan, and unless it is a dry run send, what one instance gets in a check.
	 *
	 * @param Channel_Instance     $instance     The instance.
	 * @param Channel_State|null   $channel      Its state, null when it has no entry (no history, so everything waiting is new to it).
	 * @param Notification         $notification The notification of the check, with every item that is waiting.
	 * @param array<string, mixed> $options    The settings.
	 * @param bool                 $dry_run      Whether to only report.
	 * @param bool                 $force        Whether to send what was notified again.
	 *
	 * @return array{plan: Instance_Plan, entry: Channel_State|null} The plan of the instance, and its new state, null to leave
	 *         its state as it was (and not store one).
	 */
	private function plan_instance( Channel_Instance $instance, ?array $channel, Notification $notification, array $options, bool $dry_run, bool $force ): array {
		$plan = $this->plan_entry( self::STATUS_NOTHING );
		if ( ! $instance->is_enabled() ) {
			$plan['status'] = self::STATUS_DISABLED;
			return array(
				'plan'  => $plan,
				'entry' => null, // Switched off: nothing is sent or recorded, and a pending skip waits.
			);
		}
		$retry_at = $this->state->waiting_until( $channel ?? array() );
		if ( $retry_at > 0 ) {
			$plan['status']   = self::STATUS_WAITING;
			$plan['retry_at'] = $retry_at;
			return array(
				'plan'  => $plan,
				'entry' => null, // Told to wait (Retry-After) or held (deferred): nothing is sent or recorded, its items go out on the first check after, and a pending skip waits.
			);
		}
		$entry    = $channel ?? array(
			'notified'     => array(),
			'skip_pending' => false,
		);
		$original = $entry;

		// Everything waiting that this instance receives, and the part of it that is new to it.
		try {
			$pending = $this->items_for_instance( $instance, $notification->get_items() );
		} catch ( \Throwable $e ) {
			// A routing callback threw: this instance fails and the others still go out. Its state stays as it was.
			$plan['status'] = self::STATUS_FAILED;
			if ( ! $dry_run ) { // A dry run reports the failure without the result's hooks and stored last result.
				$plan['result'] = $this->dispatcher->exception_result( $instance, $e, $notification );
			}
			return array(
				'plan'  => $plan,
				'entry' => null,
			);
		}
		$plan['pending'] = $pending;
		$for_instance    = array();
		foreach ( $pending as $item ) {
			if ( $force || $this->state->is_new( $entry, $item ) ) {
				$for_instance[] = $item;
			}
		}

		$clock = $this->tick_reminder_clock( $instance, $entry, $pending, $options, $force );
		$entry = $clock['entry'];
		$due   = $clock['due'];

		$send  = null;
		$store = true; // Whether the entry is kept in the state, which an unchanged "nothing new" is not.
		if ( $entry['skip_pending'] && ( ! $force || ! $this->dispatcher->is_available( $instance ) ) ) {
			$plan['status'] = self::STATUS_SKIP;
			if ( ! $this->dispatcher->is_available( $instance ) ) {
				return array(
					'plan'  => $plan,
					'entry' => null, // Keep the flag until the channel is there.
				);
			}
			$plan['items']         = $for_instance;
			$entry['skip_pending'] = false;
			$entry                 = $this->record( $entry, $for_instance );
		} elseif ( $due ) {
			$reminder       = $this->plan_reminder( $notification, $entry, $pending );
			$entry          = $reminder['entry'];
			$send           = $reminder['send'];
			$plan['status'] = $reminder['status'];
			$plan['items']  = $reminder['items'];
		} elseif ( array() !== $for_instance ) {
			$send           = $notification->with_items( $for_instance );
			$plan['status'] = self::STATUS_SEND;
			$plan['items']  = $for_instance;
		} else {
			$store = $entry !== $original; // Nothing new, and unless the clock changed there is nothing to keep.
		}

		if ( null !== $send ) {
			$plan['notification'] = $send;
			if ( ! $dry_run ) {
				$sent  = $this->deliver( $send, $instance, $entry, $plan );
				$plan  = $sent['plan'];
				$entry = $sent['entry'];
			}
		}

		return array(
			'plan'  => $plan,
			'entry' => $store ? $entry : null,
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
	private function plan_reminder( Notification $notification, array $entry, array $pending ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- A phpstan-type alias.
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
				'status' => self::STATUS_NOTHING,
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
			'status' => self::STATUS_REMINDER,
			'items'  => $remaining,
		);
	}

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
	private function tick_reminder_clock( Channel_Instance $instance, array $entry, array $pending, array $options, bool $force ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- A phpstan-type alias.
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
	 * Send the message planned for an instance and record the outcome in its plan and state.
	 *
	 * @param Notification     $send     The message.
	 * @param Channel_Instance $instance The instance.
	 * @param Channel_State    $entry    Its state.
	 * @param Instance_Plan    $plan     Its plan, with the status and items of the message.
	 *
	 * @return array{plan: Instance_Plan, entry: Channel_State} The plan with the result (and the status the result gives), and the state.
	 */
	private function deliver( Notification $send, Channel_Instance $instance, array $entry, array $plan ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- A phpstan-type alias.
		$result         = $this->dispatcher->send_one( $send, $instance );
		$plan['result'] = $result;
		if ( $result->is_deferred() ) {
			// Held: only the items the channel says went out are recorded, a skip flag and the reminder clock stay, and the hold was stored by the send.
			$entry            = $this->record_partial( $entry, $plan, $result );
			$plan['status']   = self::STATUS_WAITING;
			$plan['retry_at'] = max( time(), $this->state->hold_until( $result ) );
		} elseif ( $result->is_success() ) {
			$entry                 = $this->record( $entry, $plan['items'] );
			$entry['skip_pending'] = false; // A forced message replaces the skip.
			if ( self::STATUS_REMINDER === $plan['status'] ) {
				$entry['last_reminder'] = time();
				unset( $entry['reminder_sent'] );
			}
		} else {
			$entry          = $this->record_partial( $entry, $plan, $result );
			$plan['status'] = self::STATUS_FAILED; // A failed reminder does not restart the interval either.
		}

		return array(
			'plan'  => $plan,
			'entry' => $entry,
		);
	}

	/**
	 * Record the items a failed or deferred result says were delivered.
	 *
	 * Always the plan's items, not the module's copies. For a reminder the part is also kept as progress in
	 * `reminder_sent`, so the retry leaves it out. A skip flag and the reminder clock stay as they are.
	 *
	 * @param Channel_State   $entry  The state of the instance.
	 * @param Instance_Plan   $plan   Its plan, with the status and items of the message.
	 * @param Delivery_Result $result What the channel returned.
	 *
	 * @return Channel_State
	 */
	private function record_partial( array $entry, array $plan, Delivery_Result $result ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- A phpstan-type alias.
		$part = $this->delivered_part( $result, $plan['items'] );
		if ( array() === $part ) {
			return $entry;
		}

		$entry = $this->record( $entry, $part );
		if ( self::STATUS_REMINDER === $plan['status'] ) {
			foreach ( $part as $item ) {
				$entry['reminder_sent'][ $item->get_type() ][ $item->get_key() ] = $item->get_new_version();
			}
		}

		return $entry;
	}

	/**
	 * The items of a message that a result says were delivered, matched by type, key and new version.
	 *
	 * @param Delivery_Result     $result The result.
	 * @param Notification_Item[] $items  The items of the message, as planned.
	 *
	 * @return Notification_Item[] The plan's own items.
	 */
	private function delivered_part( Delivery_Result $result, array $items ): array {
		$delivered = array();
		foreach ( $result->get_delivered() as $item ) {
			$delivered[ $item->get_type() ][ $item->get_key() ] = $item->get_new_version();
		}

		$part = array();
		foreach ( $items as $item ) {
			if ( ( $delivered[ $item->get_type() ][ $item->get_key() ] ?? null ) === $item->get_new_version() ) {
				$part[] = $item;
			}
		}

		return $part;
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
	 * Fire the hooks that follow a saved check, in order: ended mutes, resolved items, the dispatch, the end.
	 *
	 * @param array<int, array{0: Mute, 1: Notification_Item, 2: string}>             $ended        The mutes that ended, as found by find_ended_mutes().
	 * @param array<int, array{0: Channel_Instance, 1: Notification_Item, 2: string}> $resolved The items that are no longer pending, with the instance and how.
	 * @param array<string, Delivery_Result>                                          $results      Result by channel instance id.
	 * @param Notification                                                            $notification The notification of the check.
	 *
	 * @return void
	 */
	private function fire_after_check( array $ended, array $resolved, array $results, Notification $notification ): void {
		foreach ( $ended as [$mute, $item, $reason] ) {
			/**
			 * Fires once when a mute ends while an update is waiting: a "skip this version" mute because a newer
			 * version is offered, or a snooze because its time has passed.
			 *
			 * The check records the ended mute in the run state, not in the stored mutes, so the action does
			 * not fire on later runs. It never fires in a dry run. If two checks overlap, it can fire twice;
			 * see ADR 0009. It can also not fire at all: saving or removing any mute drops the ended ones, so
			 * if that happens after the mute ended and before the next check, the end is not reported.
			 * A mute that ended both ways at once is reported once, with `expired`.
			 *
			 * @since 3.0.0
			 *
			 * @param Mute              $mute   The mute that ended.
			 * @param Notification_Item $item   The item that is waiting.
			 * @param string            $reason `version` when a newer version ended a "skip this version" mute, `expired` when the time of a snooze passed.
			 */
			do_action( 'sc_wpun_mute_ended', $mute, $item, $reason );
		}

		foreach ( $resolved as [$instance, $item, $how] ) {
			/**
			 * Fires once per channel instance when an update it reported is no longer pending.
			 *
			 * The update check compares what each instance was told (its `notified` state) with what is
			 * pending now. An item that was reported and is no longer pending at all is resolved, and is then
			 * forgotten. Use it to close a helpdesk ticket opened from a webhook notification, to log the
			 * time to update (see `Notification_Item::get_notified_at()`), or to post "done" to whatever
			 * received the alert.
			 *
			 * `$how` is `updated` when the installed version of a plugin or theme is now the reported version
			 * or newer, `removed` when the plugin or theme is no longer installed, and `core` when WordPress
			 * is at or past the reported version.
			 *
			 * The check finds this out, not the install, so it does not matter how the update was installed
			 * (Updates screen, automatic updates, WP-CLI, a host, an uploaded zip or FTP), but it can take up
			 * to one check interval. Only core, plugin and theme items are reported; an item of an add-on type
			 * is forgotten silently. It fires for every instance that reported the item, also one that is
			 * switched off, waiting after a Retry-After or held by a deferred result (see
			 * `Channel_Instance::is_enabled()` and `sc_wpun_instance_waiting_until()`), and for an
			 * item recorded when an instance was added with "skip what's waiting". For a held instance
			 * `get_notified_at()` is when the item was finally delivered to it, so a time to update
			 * measured from it starts at delivery, not at detection.
			 *
			 * It does not fire for an item that is muted, snoozed, replaced by a newer version or hidden by the
			 * `sc_wpun_should_notify_item` filter: those are still pending. An update installed while the item
			 * is snoozed is reported, because the check keeps the entry of a snoozed item. It never fires in a
			 * dry run or for a test notification. If two checks overlap it can fire twice; see ADR 0009.
			 *
			 * The item is as it was reported: its new version is the one reported, and its name, installed
			 * version and links (without the download link) are from then. Entries stored before 3.0.0 have
			 * no details: the name is the installed one, if there is one, and `get_notified_at()` is null.
			 *
			 * Example, log the time to update:
			 *
			 *     add_action(
			 *         'sc_wpun_item_resolved',
			 *         function ( $item, $how, $instance ) {
			 *             $since = $item->get_notified_at();
			 *             if ( null !== $since ) {
			 *                 error_log( sprintf( '%s was %s after %d seconds.', $item->get_name(), $how, time() - $since ) );
			 *             }
			 *         },
			 *         10,
			 *         3
			 *     );
			 *
			 * @since 3.0.0
			 *
			 * @param Notification_Item $item     The item as it was reported.
			 * @param string            $how      `updated`, `removed` or `core`.
			 * @param Channel_Instance  $instance The channel instance that reported it.
			 */
			do_action( 'sc_wpun_item_resolved', $item, $how, $instance );
		}

		if ( array() !== $results ) {
			/**
			 * Fires when an update check has sent something, with the result of each instance it sent through.
			 *
			 * It does not fire for a check that sent nothing; see `sc_wpun_after_update_check`.
			 *
			 * @since 2.0.0
			 *
			 * @param array<string, Delivery_Result> $results      Result by channel instance id.
			 * @param Notification                   $notification The notification that was delivered.
			 */
			do_action( 'sc_wpun_after_dispatch', $results, $notification );
		}

		/**
		 * Fires when an update check has finished, after the state was saved.
		 *
		 * It fires even if nothing was sent, with an empty `$results`.
		 *
		 * @since 2.0.0
		 *
		 * @param Notification                $notification The notification of the check, with every item that was waiting.
		 * @param array<string, Delivery_Result> $results      Result by channel instance id, for the instances something was sent to.
		 */
		do_action( 'sc_wpun_after_update_check', $notification, $results );
	}

	/**
	 * Find the mutes that a newer version or their expiry has ended, and those not reported yet.
	 *
	 * A mute that both a newer version and its expiry have ended is reported once, as expired.
	 *
	 * @param Notification_Item[]  $items The items found.
	 * @param array<string, mixed> $state The run state.
	 *
	 * @return array{recorded: array<string, array<string, string>>, snoozes: array<string, array<string, string>>, fire: array<int, array{0: Mute, 1: Notification_Item, 2: string}>}
	 */
	private function find_ended_mutes( array $items, array $state ): array {
		/**
		 * Item type => key => the version of the ended mute.
		 *
		 * @var array<string, array<string, string>> $recorded
		 */
		$recorded = array();
		$known    = is_array( $state['ended_mutes'] ?? null ) ? $state['ended_mutes'] : array();
		foreach ( $known as $type => $keys ) {
			foreach ( $keys as $key => $until ) {
				$mute = $this->mutes->find( (string) $type, (string) $key );
				if ( null !== $mute && $mute->get_until() === $until ) {
					$recorded[ (string) $type ][ (string) $key ] = (string) $until;
				}
			}
		}

		/**
		 * Item type => key => the expiry time of the ended snooze, as a string.
		 *
		 * @var array<string, array<string, string>> $snoozes
		 */
		$snoozes = array();
		$known   = is_array( $state['ended_snoozes'] ?? null ) ? $state['ended_snoozes'] : array();
		foreach ( $known as $type => $keys ) {
			foreach ( $keys as $key => $expires ) {
				$mute = $this->mutes->find( (string) $type, (string) $key );
				if ( null !== $mute && (string) $mute->get_expires() === (string) $expires ) {
					$snoozes[ (string) $type ][ (string) $key ] = (string) $expires;
				}
			}
		}

		$fire = array();
		foreach ( $items as $item ) {
			$type = $item->get_type();
			$key  = $item->get_key();
			$mute = $this->mutes->find( $type, $key );
			if ( null === $mute ) {
				continue;
			}

			if ( $mute->is_expired() ) {
				$expires = (string) $mute->get_expires();
				if ( ( $snoozes[ $type ][ $key ] ?? null ) !== $expires ) {
					$snoozes[ $type ][ $key ] = $expires;
					if ( ! isset( $recorded[ $type ][ $key ] ) ) { // Not reported yet as ended by a newer version.
						$fire[] = array( $mute, $item, 'expired' );
					}
				}
				continue;
			}

			if ( $mute->is_always() || $mute->applies_to( $item->get_new_version() ) ) {
				continue;
			}
			$until = (string) $mute->get_until();
			if ( ( $recorded[ $type ][ $key ] ?? null ) !== $until ) {
				$recorded[ $type ][ $key ] = $until;
				$fire[]                    = array( $mute, $item, 'version' );
			}
		}

		return array(
			'recorded' => $recorded,
			'snoozes'  => $snoozes,
			'fire'     => $fire,
		);
	}

	/**
	 * Find the reported items that are no longer pending because they were installed or removed.
	 *
	 * Only the built-in types are looked at. An item that is gone from the update data while the installed
	 * version is still older was not installed (the update data may just be missing), so it is forgotten silently.
	 *
	 * @param array<string, array<string, array<string, string>>> $gone      Instance id => item type => key => notified version, for what is no longer waiting.
	 * @param array<string, mixed>                                $state     The run state as it was read, with the details of what was notified.
	 * @param array<string, Channel_Instance>                     $instances The stored instances.
	 * @param Update_Collector                                    $collector Reads what is installed.
	 *
	 * @return array<int, array{0: Channel_Instance, 1: Notification_Item, 2: string}> Instance, item and how it was resolved.
	 */
	private function find_resolved( array $gone, array $state, array $instances, Update_Collector $collector ): array {
		$resolved  = array();
		$installed = array(); // Item type => key => name and version, read when first needed.
		foreach ( $gone as $id => $types ) {
			foreach ( $types as $type => $versions ) {
				$installed[ $type ] ??= $collector->installed( $type );
				foreach ( $versions as $key => $version ) {
					$now = $installed[ $type ][ $key ] ?? null;
					if ( null === $now ) {
						$how = 'removed';
					} elseif ( version_compare( $now['version'], $version, '>=' ) ) {
						$how = 'core' === $type ? 'core' : 'updated';
					} else {
						continue; // Not installed: the update data is just missing.
					}

					$meta       = $state['channels'][ $id ]['notified_meta'][ $type ][ $key ] ?? null;
					$name       = null !== $meta && '' !== $meta['name'] ? $meta['name'] : ( $now['name'] ?? (string) $key );
					$resolved[] = array(
						$instances[ $id ],
						new Notification_Item(
							array(
								'type'            => $type,
								'key'             => (string) $key,
								'name'            => $name,
								'current_version' => $meta['current_version'] ?? '',
								'new_version'     => $version,
								'links'           => $meta['links'] ?? array(),
								'notified_at'     => $meta['time'] ?? null,
							)
						),
						$how,
					);
				}
			}
		}

		return $resolved;
	}

	/**
	 * Keep the details of what was notified only for what is still notified.
	 *
	 * One pass covers everything that forgets a notified version: items no longer waiting, snoozes, and a
	 * reset by `State::skip_pending()`.
	 *
	 * @param Channel_State $entry The state of one instance.
	 *
	 * @return Channel_State
	 */
	private function trim_notified_meta( array $entry ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- A phpstan-type alias.
		if ( ! isset( $entry['notified_meta'] ) ) {
			return $entry;
		}

		$meta = array();
		foreach ( $entry['notified_meta'] as $type => $keys ) {
			$kept = array_intersect_key( $keys, $entry['notified'][ $type ] ?? array() );
			if ( array() !== $kept ) {
				$meta[ $type ] = $kept;
			}
		}

		if ( array() !== $meta ) {
			$entry['notified_meta'] = $meta;
		} else {
			unset( $entry['notified_meta'] );
		}

		return $entry;
	}

	/**
	 * A fresh entry of the plan of a check.
	 *
	 * @param string $status One of the STATUS_ constants.
	 *
	 * @return Instance_Plan
	 */
	private function plan_entry( string $status ): array {
		return array(
			'status'       => $status,
			'items'        => array(),
			'notification' => null,
			'result'       => null,
			'retry_at'     => 0,
			'pending'      => array(),
		);
	}

	/**
	 * Whether an item is to be reported at all, by the global veto. Public only to serve as a callback.
	 *
	 * @internal
	 *
	 * @param Notification_Item $item The item.
	 *
	 * @return bool
	 */
	public function should_notify( Notification_Item $item ): bool {
		/**
		 * Filters whether an item is reported at all, to every channel instance.
		 *
		 * An item that is vetoed is not sent and not recorded, but still counts as waiting for an update.
		 *
		 * Since 3.0.0 the default is false when the item is muted (see `sc_wpun_mutes()`), so a callback
		 * that returns true whatever it is given unmutes everything. Pass `$notify` on instead.
		 *
		 * Example, never report one plugin:
		 *
		 *     add_filter(
		 *         'sc_wpun_should_notify_item',
		 *         function ( $notify, $item ) {
		 *             return 'noisy/noisy.php' === $item->get_key() ? false : $notify;
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param bool              $notify Whether to report the item. Default true, false when the item is muted (changed in 3.0.0).
		 * @param Notification_Item $item   The item.
		 */
		return (bool) apply_filters( 'sc_wpun_should_notify_item', ! $this->mutes->is_muted( $item ), $item );
	}

	/**
	 * The per-instance state to work on: deleted instances pruned, and instances with no entry given
	 * the pre-2.0.0 history, if the state still has it.
	 *
	 * @param array<string, mixed>            $state     The stored run state.
	 * @param array<string, Channel_Instance> $instances The stored instances.
	 *
	 * @return array<string, Channel_State>
	 */
	private function state_for_instances( array $state, array $instances ): array {
		$channels = is_array( $state['channels'] ?? null ) ? $state['channels'] : array();
		$channels = array_intersect_key( $channels, $instances );
		$legacy   = is_array( $state['legacy_notified'] ?? null ) ? $state['legacy_notified'] : null;

		if ( null !== $legacy ) {
			$generic = array();
			if ( ! empty( $legacy['core'] ) ) {
				$generic['core'] = array( 'core' => (string) $legacy['core'] );
			}
			foreach ( array( 'plugin', 'theme' ) as $type ) {
				if ( ! empty( $legacy[ $type ] ) && is_array( $legacy[ $type ] ) ) {
					$generic[ $type ] = $legacy[ $type ];
				}
			}
			foreach ( array_keys( $instances ) as $id ) {
				$channels[ $id ] ??= array(
					'notified'     => $generic,
					'skip_pending' => false,
				);
			}
		}

		return $channels;
	}

	/**
	 * Add the keys of items to the waiting keys of the types this run checked.
	 *
	 * @param array<string, string[]> $checked Item type => keys still waiting.
	 * @param Notification_Item[]     $items   Items reported this run.
	 *
	 * @return array<string, string[]>
	 */
	private function add_pending_keys( array $checked, array $items ): array {
		foreach ( $items as $item ) {
			if ( isset( $checked[ $item->get_type() ] ) && ! in_array( $item->get_key(), $checked[ $item->get_type() ], true ) ) {
				$checked[ $item->get_type() ][] = $item->get_key();
			}
		}

		return $checked;
	}

	/**
	 * Add items to the notified versions of an instance, with the details `sc_wpun_item_resolved` needs.
	 *
	 * The details and the time are stored when the version changes or the entry is flagged as snoozed (and so
	 * counts as new, see State::is_new()), which clears the flag; a reminder or a forced send of the same
	 * version keeps them. The download link is not stored, as it can hold a licence key or a private token.
	 *
	 * @param Channel_State       $entry The state of one instance.
	 * @param Notification_Item[] $items Items that were announced.
	 *
	 * @return Channel_State
	 */
	private function record( array $entry, array $items ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- A phpstan-type alias.
		foreach ( $items as $item ) {
			$type  = $item->get_type();
			$key   = $item->get_key();
			$known = ( $entry['notified'][ $type ][ $key ] ?? null ) === $item->get_new_version() && empty( $entry['notified_meta'][ $type ][ $key ]['snoozed'] );

			$entry['notified'][ $type ][ $key ] = $item->get_new_version();
			if ( ! $known ) {
				$links = $item->get_links();
				unset( $links['download'] );
				$entry['notified_meta'][ $type ][ $key ] = array(
					'name'            => $item->get_name(),
					'current_version' => $item->get_current_version(),
					'links'           => $links,
					'time'            => time(),
				);
			}
		}

		return $entry;
	}

	/**
	 * The items an instance is to be told about.
	 *
	 * The instance's routing rules (item types, scope, core major only) and the add-on filter
	 * are applied by Routing::should_receive().
	 *
	 * @param Channel_Instance    $instance The instance.
	 * @param Notification_Item[] $items    Everything waiting for an update.
	 *
	 * @return Notification_Item[]
	 */
	private function items_for_instance( Channel_Instance $instance, array $items ): array {
		return array_values(
			array_filter(
				$items,
				static fn( Notification_Item $item ): bool => Routing::should_receive( $item, $instance )
			)
		);
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

	/**
	 * The results of the most recent check by instance id, for the instances that were sent to, for tests.
	 *
	 * @internal
	 *
	 * @return array<string, Delivery_Result>
	 */
	public function last_results(): array {
		return $this->last_results;
	}

	/**
	 * The notification built by the most recent check, with every item that was waiting, for tests.
	 *
	 * @internal
	 *
	 * @return Notification|null Null before the first check.
	 */
	public function last_notification(): ?Notification {
		return $this->last_notification;
	}

	/**
	 * Remove plugins from an update list if every channel instance has been told about that version.
	 *
	 * Kept for the deprecated SC_WP_Updates_Notifier method of the same name: the update check no
	 * longer uses it, because what was notified is tracked per instance.
	 *
	 * @param array<mixed> $plugins_need_update Array of plugins that need an update.
	 *
	 * @return array<mixed> $plugins_need_update
	 */
	public function check_plugins_against_notified( $plugins_need_update ) {
		return $this->without_notified( $plugins_need_update, 'plugin' );
	}

	/**
	 * Remove themes from an update list if every channel instance has been told about that version.
	 *
	 * Kept for the deprecated SC_WP_Updates_Notifier method of the same name.
	 *
	 * @param array<mixed> $themes_need_update Array of themes that need an update.
	 *
	 * @return array<mixed> $themes_need_update
	 */
	public function check_themes_against_notified( $themes_need_update ) {
		return $this->without_notified( $themes_need_update, 'theme' );
	}

	/**
	 * Remove the entries of an update list that every stored instance has been told about.
	 *
	 * @param mixed  $needing_update Update list, keyed by plugin file or stylesheet.
	 * @param string $type           Item type: plugin or theme.
	 *
	 * @return mixed The list, unchanged if it is not an array or no instance has state.
	 */
	private function without_notified( $needing_update, string $type ) {
		$instances = $this->channels->all();
		if ( ! is_array( $needing_update ) || array() === $instances ) {
			return $needing_update;
		}

		$channels = $this->state->get()['channels'];
		foreach ( $needing_update as $key => $data ) {
			$version = is_object( $data ) ? ( $data->new_version ?? null ) : ( is_array( $data ) ? ( $data['new_version'] ?? null ) : null );
			if ( ! is_scalar( $version ) ) {
				continue;
			}
			$told_all = true;
			foreach ( array_keys( $instances ) as $id ) {
				if ( ( $channels[ $id ]['notified'][ $type ][ (string) $key ] ?? null ) !== (string) $version ) {
					$told_all = false;
					break;
				}
			}
			if ( $told_all ) {
				unset( $needing_update[ $key ] );
			}
		}

		return $needing_update;
	}
}
