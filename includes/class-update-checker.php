<?php
/**
 * Checking for updates and notifying about them.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use stdClass;

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
	const STATUS_WAITING  = 'waiting';  // Told to wait (Retry-After), see `retry_at`.
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
	 * What is active across the network, read once per check: false until read, null for "everything installed".
	 *
	 * @var array{plugins: string[], themes: string[]}|null|false
	 */
	private $network_actives = false;

	/**
	 * Whether items get a download link, read once per check.
	 *
	 * @var bool
	 */
	private $with_download_links = false;

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
	 * A failed reminder does not restart the interval.
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

		$this->network_actives = false; // Read again for each check, at most once.

		// Refresh WordPress's update data first, whatever notify_core says. Under WP-Cron this also runs
		// WordPress's automatic updates (wp_maybe_auto_update) before plugins and themes are read, so
		// what WordPress installs by itself is not reported. Do not remove, reorder or make this
		// conditional: it changes what users hear about (ADR 0020).
		do_action( 'wp_version_check' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.

		$options = $this->settings->get(); // get settings.
		$state   = $this->state->get();
		$items   = array(); // What the check finds, in the order of the message.
		$checked = array(); // Item type => keys still waiting, for the types this run looked at.

		$this->with_download_links = $options['download_links'];

		if ( 'none' !== $options['notify_core'] ) { // are we to check for WP core updates?
			$this->core_update_check( $items, $options['notify_core'], $checked ); // check the WP core for updates.
		}
		if ( 'none' !== $options['notify_plugins'] ) { // are we to check for plugin updates?
			$this->plugins_update_check( $items, $options['notify_plugins'], $checked ); // check for plugin updates.
		}
		if ( 'none' !== $options['notify_themes'] ) { // are we to check for theme updates?
			$this->themes_update_check( $items, $options['notify_themes'], $checked ); // check for theme updates.
		}

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

		// An item a filter added is waiting too, even if WordPress's own update data does not list it,
		// or its history would be forgotten and it would be sent again on every run.
		$checked = $this->add_pending_keys( $checked, $items );

		// Registered add-on types are checked too: what they report now is what is waiting.
		foreach ( array_keys( Item_Types::all() ) as $type ) {
			if ( ! isset( $checked[ $type ] ) && ! in_array( $type, array( 'core', 'plugin', 'theme' ), true ) ) {
				$checked[ $type ] = array();
				foreach ( $items as $item ) {
					if ( $item->get_type() === $type ) {
						$checked[ $type ][] = $item->get_key();
					}
				}
			}
		}

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
		$channels  = $this->state_for_instances( $state, $instances );

		// Forget what is no longer waiting for an update, for every instance whether it is on or not, and flag what is snoozed.
		// A snoozed item keeps its entry, flagged, so it is sent as new when the snooze ends even if that version was notified
		// before it started (State::is_new()), and so it can still be resolved while it is snoozed.
		// What is no longer waiting at all (not just snoozed) may be resolved, see find_resolved().
		$gone = array(); // Instance id => item type => key => notified version, of the built-in types.
		foreach ( $channels as $id => $entry ) {
			foreach ( $checked as $type => $pending_keys ) {
				if ( isset( $entry['notified'][ $type ] ) ) {
					$waiting = array_intersect_key( $entry['notified'][ $type ], array_flip( $pending_keys ) );
					if ( in_array( $type, array( 'core', 'plugin', 'theme' ), true ) && count( $waiting ) < count( $entry['notified'][ $type ] ) ) {
						$gone[ $id ][ $type ] = array_diff_key( $entry['notified'][ $type ], $waiting );
					}
					$channels[ $id ]['notified'][ $type ] = $waiting;
					foreach ( array_keys( array_intersect_key( $waiting, $snoozed[ $type ] ?? array() ) ) as $key ) {
						$channels[ $id ]['notified_meta'][ $type ][ $key ]            = $channels[ $id ]['notified_meta'][ $type ][ $key ] ?? array(
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

		foreach ( $instances as $id => $instance ) {
			$plan[ $id ] = $this->plan_entry( self::STATUS_NOTHING );
			if ( ! $instance->is_enabled() ) {
				$plan[ $id ]['status'] = self::STATUS_DISABLED;
				continue; // Switched off: nothing is sent or recorded, and a pending skip waits.
			}
			$retry_at = (int) ( $channels[ $id ]['retry_at'] ?? 0 );
			if ( $retry_at > time() ) {
				$plan[ $id ]['status']   = self::STATUS_WAITING;
				$plan[ $id ]['retry_at'] = $retry_at;
				continue; // Told to wait (Retry-After): nothing is sent or recorded, its items go out on the first check after, and a pending skip waits.
			}
			$entry    = $channels[ $id ] ?? array(
				'notified'     => array(),
				'skip_pending' => false,
			); // No entry: no history, so everything waiting is new to it.
			$original = $entry;

			// Everything waiting that this instance receives, and the part of it that is new to it.
			try {
				$pending = $this->items_for_instance( $instance, $notification->get_items() );
			} catch ( \Throwable $e ) {
				// A routing callback threw: this instance fails and the others still go out. Its state stays as it was.
				$plan[ $id ]['status'] = self::STATUS_FAILED;
				if ( ! $dry_run ) { // A dry run reports the failure without the result's hooks and stored last result.
					$plan[ $id ]['result'] = $this->dispatcher->exception_result( $instance, $e, $notification );
					$results[ $id ]        = $plan[ $id ]['result'];
				}
				continue;
			}
			$plan[ $id ]['pending'] = $pending;
			$for_instance           = array();
			foreach ( $pending as $item ) {
				if ( $force || $this->state->is_new( $entry, $item ) ) {
					$for_instance[] = $item;
				}
			}

			// The reminder clock runs while the instance wants reminders and something is waiting for it.
			$remind   = Routing::normalise( $instance->get_routing() )['remind'];
			$interval = $this->reminder_interval( $remind );
			$due      = false;
			if ( 0 === $interval || array() === $pending ) {
				unset( $entry['last_reminder'] );
			} elseif ( ! isset( $entry['last_reminder'] ) ) {
				$entry['last_reminder'] = time(); // Starts now: the first reminder comes one interval after items start waiting.
			} else {
				$due = ! $force && ! $entry['skip_pending'] && ( 'every_check' === $remind || $this->reminder_is_due( $entry['last_reminder'], $interval, $options ) );
			}

			$store = true; // Whether the entry is kept in the state, which an unchanged "nothing new" is not.
			if ( $entry['skip_pending'] && ( ! $force || ! $this->dispatcher->is_available( $instance ) ) ) {
				$plan[ $id ]['status'] = self::STATUS_SKIP;
				if ( ! $this->dispatcher->is_available( $instance ) ) {
					continue; // Keep the flag until the channel is there.
				}
				$plan[ $id ]['items']  = $for_instance;
				$entry['skip_pending'] = false;
				$entry                 = $this->record( $entry, $for_instance );
			} elseif ( $due ) {
				// One message with everything waiting, new items included, instead of a second message.
				$send                  = new Notification(
					$notification->get_site_name(),
					$notification->get_site_url(),
					$notification->get_updates_url(),
					$pending,
					array(
						'is_reminder' => true,
						'source'      => $notification->get_source(),
					)
				);
				$plan[ $id ]['status'] = self::STATUS_REMINDER;
				$plan[ $id ]['items']  = $pending;
			} elseif ( array() !== $for_instance ) {
				$send                  = $notification->with_items( $for_instance );
				$plan[ $id ]['status'] = self::STATUS_SEND;
				$plan[ $id ]['items']  = $for_instance;
			} else {
				$store = $entry !== $original; // Nothing new, and unless the clock changed there is nothing to keep.
			}

			if ( isset( $send ) ) {
				$plan[ $id ]['notification'] = $send;
				if ( ! $dry_run ) {
					$result = $this->dispatcher->send_one( $send, $instance );

					$plan[ $id ]['result'] = $result;
					$results[ $id ]        = $result;
					if ( $result->is_success() ) {
						$entry                 = $this->record( $entry, $plan[ $id ]['items'] );
						$entry['skip_pending'] = false; // A forced message replaces the skip.
						if ( self::STATUS_REMINDER === $plan[ $id ]['status'] ) {
							$entry['last_reminder'] = time();
						}
					} else {
						$plan[ $id ]['status'] = self::STATUS_FAILED; // A failed reminder does not restart the interval either.
					}
				}
				unset( $send );
			}

			if ( $store ) {
				$channels[ $id ] = $entry;
			}
		}

		if ( $dry_run ) {
			return $plan; // Nothing is saved and no hook fires: the state stays exactly as it was.
		}

		$this->last_results = $results;

		// Sending saved each instance's last result and retry time in the stored state, which the copy read at the start lacks.
		$fresh    = $this->state->get()['channels'];
		$resolved = $this->find_resolved( $gone, $state, $instances ); // Read before the details of what was notified are trimmed.
		foreach ( $channels as $id => $entry ) {
			$channels[ $id ] = $this->trim_notified_meta( $entry );
			unset( $channels[ $id ]['last_result'], $channels[ $id ]['retry_at'] );
			if ( isset( $fresh[ $id ]['last_result'] ) ) {
				$channels[ $id ]['last_result'] = $fresh[ $id ]['last_result'];
			}
			if ( isset( $fresh[ $id ]['retry_at'] ) ) {
				$channels[ $id ]['retry_at'] = $fresh[ $id ]['retry_at'];
			}
		}

		$state['channels']        = $channels;
		$state['last_check_time'] = time();
		unset( $state['legacy_notified'] ); // Every instance that existed has taken its share; later ones start clean.
		if ( array() !== $ended['recorded'] ) {
			$state['ended_mutes'] = $ended['recorded'];
		} else {
			unset( $state['ended_mutes'] );
		}
		if ( array() !== $ended['snoozes'] ) {
			$state['ended_snoozes'] = $ended['snoozes'];
		} else {
			unset( $state['ended_snoozes'] );
		}
		$this->state->save( $state );

		foreach ( $ended['fire'] as [$mute, $item, $reason] ) {
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
			 * switched off or waiting after a Retry-After (see `Channel_Instance::is_enabled()`), and for an
			 * item recorded when an instance was added with "skip what's waiting".
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
			/** This action is documented in includes/class-dispatcher.php */
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

		return $plan;
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
	 *
	 * @return array<int, array{0: Channel_Instance, 1: Notification_Item, 2: string}> Instance, item and how it was resolved.
	 */
	private function find_resolved( array $gone, array $state, array $instances ): array {
		$resolved  = array();
		$installed = array(); // Item type => key => name and version, read when first needed.
		foreach ( $gone as $id => $types ) {
			foreach ( $types as $type => $versions ) {
				$installed[ $type ] ??= $this->installed_versions( $type );
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
	 * The name and version of what is installed, by item key.
	 *
	 * @param string $type Item type: `core`, `plugin` or `theme`.
	 *
	 * @return array<string, array{name: string, version: string}>
	 */
	private function installed_versions( string $type ): array {
		$installed = array();
		if ( 'core' === $type ) {
			$installed['core'] = array(
				'name'    => 'WordPress',
				'version' => wp_get_wp_version(),
			);
		} elseif ( 'plugin' === $type ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php'; // Not loaded in cron requests.
			}
			foreach ( get_plugins() as $file => $data ) {
				$installed[ (string) $file ] = array(
					'name'    => (string) $data['Name'],
					'version' => (string) $data['Version'],
				);
			}
		} else {
			foreach ( wp_get_themes( array( 'errors' => null ) ) as $stylesheet => $theme ) {
				$installed[ (string) $stylesheet ] = array(
					'name'    => (string) $theme->get( 'Name' ),
					'version' => (string) $theme->get( 'Version' ),
				);
			}
		}

		return $installed;
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
	 * Checks to see if any WP core updates
	 *
	 * @param Notification_Item[]          $items   Collects the items to report.
	 * @param string                       $setting The notify_core setting: `all` or `major`.
	 * @param array<string, array<string>> $checked Collects the keys still waiting, by item type.
	 *
	 * @return void
	 */
	private function core_update_check( array &$items, string $setting, array &$checked ): void {
		$update_core = get_site_transient( 'update_core' ); // get information of updates.

		// No usable data (new site, api.wordpress.org unreachable): we know nothing,
		// so keep what was notified instead of forgetting it.
		if ( ! is_object( $update_core ) || empty( $update_core->updates ) || ! is_array( $update_core->updates ) ) {
			return;
		}

		$found = $this->core_upgrade_offer( $update_core->updates );
		if ( null === $found ) {
			$checked['core'] = array(); // No update is waiting, so nothing about core stays notified.
			return;
		}

		$offer           = $found['offer'];
		$new_core_ver    = $found['version'];
		$checked['core'] = array( 'core' );
		$current_version = wp_get_wp_version(); // Read from version.php, so plugins that change the $wp_version global do not affect it.

		/**
		 * Filters whether a WordPress core update is reported, like the plugin and theme filters do for theirs.
		 *
		 * The default follows the "Notify about WordPress updates?" setting: true, or with "only major
		 * releases" whether the update is a major.minor change. Returning false reports no core item.
		 * What was already notified about core is kept while the update is waiting.
		 *
		 * Example, ignore release candidates:
		 *
		 *     add_filter(
		 *         'sc_wpun_core_needs_update',
		 *         function ( $needs, $new_version ) {
		 *             return $needs && false === strpos( $new_version, '-RC' );
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param bool   $needs           Whether to report the update. Default true, or whether it is a major release with "only major releases".
		 * @param string $new_version     The version WordPress offers.
		 * @param string $current_version The installed version.
		 */
		$needs = 'major' !== $setting || Routing::is_major_core_update( $current_version, $new_core_ver );
		if ( ! apply_filters( 'sc_wpun_core_needs_update', $needs, $new_core_ver, $current_version ) ) {
			return;
		}

		$auto_update = $this->auto_updates->is_on( 'core', 'core', $offer, $new_core_ver );
		$items[]     = new Notification_Item(
			array(
				'type'            => 'core',
				'key'             => 'core',
				'name'            => 'WordPress',
				'current_version' => $current_version,
				'new_version'     => $new_core_ver,
				'links'           => $this->download_links( 'core', 'core', $this->core_package( $offer ) ),
				'notes'           => $this->auto_update_notes( $auto_update ),
				'flags'           => array( 'auto_update' => $auto_update ),
			)
		);
	}

	/**
	 * The version of the first core update offer that is an upgrade.
	 *
	 * WordPress lists several offers (the latest release, other locales,
	 * automatic update packages); only one with the "upgrade" response is
	 * something to tell the site owner about.
	 *
	 * @param array<mixed> $offers The `updates` list of the update_core transient.
	 *
	 * @return array{offer: object, version: string}|null The offer and its version, or null if there is no upgrade.
	 */
	private function core_upgrade_offer( array $offers ): ?array {
		foreach ( $offers as $offer ) {
			if ( is_object( $offer ) && isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response && is_scalar( $offer->current ) && '' !== (string) $offer->current ) {
				return array(
					'offer'   => $offer,
					'version' => (string) $offer->current,
				);
			}
		}

		return null;
	}

	/**
	 * The package address of a core update offer.
	 *
	 * @param object $offer The offer from the update_core transient.
	 *
	 * @return string The `download` address, else the `full` package, else an empty string.
	 */
	private function core_package( object $offer ): string {
		if ( isset( $offer->download ) && is_scalar( $offer->download ) && '' !== (string) $offer->download ) {
			return (string) $offer->download;
		}

		$packages = $offer->packages ?? null;
		if ( is_object( $packages ) ) {
			$packages = get_object_vars( $packages );
		}
		if ( is_array( $packages ) && isset( $packages['full'] ) && is_scalar( $packages['full'] ) ) {
			return (string) $packages['full'];
		}

		return '';
	}

	/**
	 * The download link of an item, if the setting is on and there is an address.
	 *
	 * @param string $type    Item type: `plugin`, `theme` or `core`.
	 * @param string $key     Plugin file, theme stylesheet or `core`.
	 * @param string $package The package address from the update data.
	 *
	 * @return array<string, string> The `download` link, or nothing.
	 */
	private function download_links( string $type, string $key, string $package ): array {
		if ( ! $this->with_download_links || '' === $package ) {
			return array();
		}

		/**
		 * Filters the address of a download link before it is added to an item, when download links are on.
		 *
		 * Not run when the "Download links" setting is off. Return an empty string to leave the link out.
		 * The address comes from the update data and can hold a licence key or a private token.
		 *
		 * Example, keep only downloads from WordPress.org:
		 *
		 *     add_filter(
		 *         'sc_wpun_item_download_url',
		 *         function ( $url ) {
		 *             return 0 === strpos( $url, 'https://downloads.wordpress.org/' ) ? $url : '';
		 *         }
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param string $url  The package address from the update data.
		 * @param string $type Item type: `plugin`, `theme` or `core`.
		 * @param string $key  Plugin file, theme stylesheet or `core`.
		 */
		$url = apply_filters( 'sc_wpun_item_download_url', $package, $type, $key );
		$url = is_scalar( $url ) ? (string) $url : '';

		return '' === $url ? array() : array( 'download' => $url );
	}

	/**
	 * The note of an update that WordPress is set to install by itself.
	 *
	 * @param bool $auto_update Whether automatic updates are on for the update.
	 *
	 * @return string[] The note, or nothing.
	 */
	private function auto_update_notes( bool $auto_update ): array {
		return $auto_update ? array( __( 'Automatic updates are on, but WordPress has not installed this update yet.', 'wp-updates-notifier' ) ) : array();
	}


	/**
	 * Check to see if any plugin updates.
	 *
	 * @param Notification_Item[]          $items         Collects the items to report.
	 * @param string                       $all_or_active `all` to look at all plugins or `active` for just the active ones.
	 * @param array<string, array<string>> $checked       Collects the keys still waiting, by item type.
	 *
	 * @return void
	 */
	private function plugins_update_check( array &$items, string $all_or_active, array &$checked ): void {
		do_action( 'wp_update_plugins' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
		$update_plugins = get_site_transient( 'update_plugins' ); // get information of updates.
		$pending        = $this->normalise_plugin_updates( is_object( $update_plugins ) && isset( $update_plugins->response ) ? $update_plugins->response : array() );

		// Everything still waiting, before any filtering, so the instances can forget plugins that were updated or deleted.
		$checked['plugin'] = array_map( 'strval', array_keys( $pending ) );

		if ( ! empty( $pending ) ) { // any plugin updates available?
			$plugins_need_update = $pending; // plugins that need updating.
			if ( 'active' === $all_or_active ) { // are we to check just active plugins?
				$plugins_need_update = array_intersect_key( $plugins_need_update, array_flip( $this->active_plugins() ) ); // only keep plugins that are active.
			}
			/**
			 * Filters the plugins that are waiting for an update and will be reported.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string, object> $plugins_need_update Update data from WordPress by plugin file (for example `hello.php`).
			 */
			$plugins_need_update = $this->normalise_plugin_updates( apply_filters( 'sc_wpun_plugins_need_update', $plugins_need_update ) ); // additional filtering of plugins need update; filters may return anything.
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php'; // Not loaded in cron requests.
			}
			$plugins_need_update = array_intersect_key( $plugins_need_update, get_plugins() ); // Only read the files of installed plugins, whatever the transient or a filter holds.
			if ( count( $plugins_need_update ) >= 1 ) { // any plugins need updating after all the filtering gone on above?
				$active_plugins = $this->active_plugins();
				foreach ( $plugins_need_update as $key => $data ) { // loop through the plugins that need updating.
					$plugin_info = get_plugin_data( WP_PLUGIN_DIR . '/' . $key, false, false ); // get local plugin info.
					$wporg_slug  = $this->wporg_plugin_slug( $data );
					$links       = array();
					if ( '' !== $wporg_slug ) {
						$links['details']   = 'https://wordpress.org/plugins/' . $wporg_slug . '/';
						$links['changelog'] = 'https://wordpress.org/plugins/' . $wporg_slug . '/#developers';
					} elseif ( '' !== $data->url ) {
						$links['details'] = $data->url;
					}
					$links += $this->download_links( 'plugin', (string) $key, $data->package );

					$auto_update = $this->auto_updates->is_on( 'plugin', (string) $key, $data, $data->new_version );
					$items[]     = new Notification_Item(
						array(
							'type'            => 'plugin',
							'key'             => (string) $key,
							'name'            => $plugin_info['Name'],
							'current_version' => $plugin_info['Version'],
							'new_version'     => $data->new_version,
							'links'           => $links,
							'notes'           => array_merge( $this->plugin_compatibility_notes( $data ), $this->auto_update_notes( $auto_update ) ),
							'flags'           => array_merge(
								$this->plugin_flags( $data ),
								array(
									'active'      => in_array( (string) $key, $active_plugins, true ),
									'auto_update' => $auto_update,
								)
							),
						)
					);
				}
			}
		}
	}


	/**
	 * Check to see if any theme updates.
	 *
	 * @param Notification_Item[]          $items         Collects the items to report.
	 * @param string                       $all_or_active `all` to look at all themes or `active` for just the active ones.
	 * @param array<string, array<string>> $checked       Collects the keys still waiting, by item type.
	 *
	 * @return void
	 */
	private function themes_update_check( array &$items, string $all_or_active, array &$checked ): void {
		do_action( 'wp_update_themes' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
		$update_themes = get_site_transient( 'update_themes' ); // get information of updates.
		$pending       = $this->normalise_theme_updates( is_object( $update_themes ) && isset( $update_themes->response ) ? $update_themes->response : array() );

		// Everything still waiting, before any filtering, so the instances can forget themes that were updated or deleted.
		$checked['theme'] = array_map( 'strval', array_keys( $pending ) );

		if ( ! empty( $pending ) ) { // any theme updates available?
			$themes_need_update = $pending; // themes that need updating.
			$active_themes      = $this->active_themes(); // The active theme and, for a child theme, its parent.
			if ( 'active' === $all_or_active ) { // are we to check just active themes?
				$themes_need_update = array_intersect_key( $themes_need_update, array_flip( $active_themes ) ); // only keep theme that is active.
			}
			/**
			 * Filters the themes that are waiting for an update and will be reported.
			 *
			 * @since 1.0.0
			 *
			 * @param array<string, array<string, mixed>> $themes_need_update Update data from WordPress by theme stylesheet.
			 */
			$themes_need_update = $this->normalise_theme_updates( apply_filters( 'sc_wpun_themes_need_update', $themes_need_update ) ); // additional filtering of themes need update; filters may return anything.
			$themes_need_update = array_intersect_key( $themes_need_update, wp_get_themes( array( 'errors' => null ) ) ); // Only read the files of installed themes, whatever the transient or a filter holds.
			if ( count( $themes_need_update ) >= 1 ) { // any themes need updating after all the filtering gone on above?
				foreach ( $themes_need_update as $key => $data ) { // loop through the themes that need updating.
					$theme_info  = wp_get_theme( $key ); // get theme info.
					$auto_update = $this->auto_updates->is_on( 'theme', (string) $key, (object) $data, $data['new_version'] );
					$items[]     = new Notification_Item(
						array(
							'type'            => 'theme',
							'key'             => (string) $key,
							'name'            => $theme_info['Name'],
							'current_version' => $theme_info['Version'],
							'new_version'     => $data['new_version'],
							'links'           => ( '' !== $data['url'] ? array( 'details' => $data['url'] ) : array() ) + $this->download_links( 'theme', (string) $key, $data['package'] ),
							'notes'           => $this->auto_update_notes( $auto_update ),
							'flags'           => array(
								'active'      => in_array( (string) $key, $active_themes, true ),
								'auto_update' => $auto_update,
							),
						)
					);
				}
			}
		}
	}


	/**
	 * Plugin update entries as objects with the properties the plugin reads.
	 *
	 * Updaters for plugins outside WordPress.org add their own entries to the
	 * update_plugins transient, sometimes as arrays, often without `url` or
	 * `slug`. Entries without a new version are dropped: there is nothing to
	 * report about them.
	 *
	 * @param mixed $response The transient's `response`.
	 *
	 * @return array<string, \stdClass> Entries keyed by plugin file, each with string `new_version`, `url`, `slug`, `id` and `package`.
	 */
	public function normalise_plugin_updates( $response ): array {
		$updates = array();

		foreach ( is_array( $response ) ? $response : array() as $file => $entry ) {
			if ( is_array( $entry ) ) {
				$entry = (object) $entry;
			} elseif ( is_object( $entry ) && ! $entry instanceof stdClass ) {
				$entry = (object) get_object_vars( $entry );
			}
			if ( ! $entry instanceof stdClass || ! isset( $entry->new_version ) || ! is_scalar( $entry->new_version ) || '' === (string) $entry->new_version ) {
				continue;
			}

			$entry->new_version = (string) $entry->new_version;
			foreach ( array( 'url', 'slug', 'id', 'package' ) as $property ) {
				$entry->$property = isset( $entry->$property ) && is_scalar( $entry->$property ) ? (string) $entry->$property : '';
			}

			$updates[ (string) $file ] = $entry;
		}

		return $updates;
	}

	/**
	 * Theme update entries as arrays with the keys the plugin reads.
	 *
	 * @param mixed $response The update_themes transient's `response`.
	 *
	 * @return array<string, array{new_version: string, url: string, package: string}> Entries keyed by stylesheet.
	 */
	private function normalise_theme_updates( $response ): array {
		$updates = array();

		foreach ( is_array( $response ) ? $response : array() as $stylesheet => $entry ) {
			$entry = is_object( $entry ) ? get_object_vars( $entry ) : $entry;
			if ( ! is_array( $entry ) || ! isset( $entry['new_version'] ) || ! is_scalar( $entry['new_version'] ) || '' === (string) $entry['new_version'] ) {
				continue;
			}

			$entry['new_version'] = (string) $entry['new_version'];
			$entry['url']         = isset( $entry['url'] ) && is_scalar( $entry['url'] ) ? (string) $entry['url'] : '';
			$entry['package']     = isset( $entry['package'] ) && is_scalar( $entry['package'] ) ? (string) $entry['package'] : '';

			$updates[ (string) $stylesheet ] = $entry;
		}

		return $updates;
	}

	/**
	 * The facts the compatibility notes of a plugin update are drawn from.
	 *
	 * @param \stdClass $entry A normalised plugin update entry.
	 *
	 * @return array<string, string> `tested`, `requires_php` and `requires_wp`, each only if the entry has it.
	 */
	private function plugin_flags( stdClass $entry ): array {
		$flags = array();

		foreach ( array(
			'tested'       => 'tested',
			'requires_php' => 'requires_php',
			'requires'     => 'requires_wp',
		) as $property => $flag ) {
			if ( isset( $entry->$property ) && is_scalar( $entry->$property ) && '' !== (string) $entry->$property ) {
				$flags[ $flag ] = (string) $entry->$property;
			}
		}

		return $flags;
	}

	/**
	 * Lines about whether a plugin update suits this site, from the update data.
	 *
	 * WordPress.org puts `tested`, `requires` and `requires_php` in each entry of
	 * the update_plugins transient, so no request per plugin is needed. "Tested
	 * up to 6.5" covers every 6.5.x release, so only major.minor is compared.
	 *
	 * @param \stdClass $entry A normalised plugin update entry.
	 *
	 * @return string[]
	 */
	private function plugin_compatibility_notes( stdClass $entry ): array {
		$site_wp = Routing::major_minor( wp_get_wp_version() );
		$tested  = isset( $entry->tested ) && is_scalar( $entry->tested ) ? (string) $entry->tested : '';
		$notes   = array();

		if ( '' === $tested ) {
			// translators: %s: WordPress version of the site, for example 6.5.
			$notes[] = sprintf( __( 'Compatibility with WordPress %s: unknown', 'wp-updates-notifier' ), $site_wp );
		} elseif ( version_compare( Routing::major_minor( $tested ), $site_wp, '>=' ) ) {
			// translators: %s: WordPress version of the site, for example 6.5.
			$notes[] = sprintf( __( 'Compatibility with WordPress %s: tested by its author', 'wp-updates-notifier' ), $site_wp );
		} else {
			// translators: 1: WordPress version of the site. 2: Latest WordPress version the plugin was tested with.
			$notes[] = sprintf( __( 'Compatibility with WordPress %1$s: not tested yet (tested up to %2$s)', 'wp-updates-notifier' ), $site_wp, $tested );
		}

		$requires_php = isset( $entry->requires_php ) && is_scalar( $entry->requires_php ) ? (string) $entry->requires_php : '';
		if ( '' !== $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) {
			// translators: 1: PHP version the update needs. 2: PHP version of the site.
			$notes[] = sprintf( __( 'Warning: this version needs PHP %1$s; the site runs PHP %2$s.', 'wp-updates-notifier' ), $requires_php, PHP_VERSION );
		}

		$requires_wp = isset( $entry->requires ) && is_scalar( $entry->requires ) ? (string) $entry->requires : '';
		if ( '' !== $requires_wp && version_compare( wp_get_wp_version(), $requires_wp, '<' ) ) {
			// translators: 1: WordPress version the update needs. 2: WordPress version of the site.
			$notes[] = sprintf( __( 'Warning: this version needs WordPress %1$s; the site runs WordPress %2$s.', 'wp-updates-notifier' ), $requires_wp, wp_get_wp_version() );
		}

		return $notes;
	}

	/**
	 * The WordPress.org slug of a plugin update, or an empty string if it does not come from WordPress.org.
	 *
	 * WordPress.org entries carry an `id` like "w.org/plugins/akismet" and a URL
	 * under https://wordpress.org/plugins/; updaters for other plugins do not.
	 *
	 * @param \stdClass $entry A normalised plugin update entry.
	 *
	 * @return string
	 */
	private function wporg_plugin_slug( stdClass $entry ): string {
		$id         = (string) $entry->id;
		$slug       = (string) $entry->slug;
		$from_wporg = 0 === strpos( $id, 'w.org/plugins/' )
			|| 1 === preg_match( '#^https?://(www\.)?WordPress\.org/plugins/#', (string) $entry->url );

		if ( ! $from_wporg ) {
			return '';
		}

		$slug = '' !== $slug ? $slug : basename( $id );

		return 1 === preg_match( '/^[a-z0-9-]+$/D', $slug ) ? $slug : '';
	}

	/**
	 * Plugins active on this site, including those network-activated on multisite.
	 *
	 * @return string[] Plugin files relative to the plugins directory.
	 */
	private function active_plugins(): array {
		$active = array_filter( (array) get_option( 'active_plugins', array() ), 'is_string' );

		if ( is_multisite() ) {
			$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$active  = array_merge( $active, array_filter( $network, 'is_string' ) );
		}

		// Network mode: active on any site of the network counts.
		if ( $this->network_context->is_network() ) {
			$network = $this->network_actives();
			if ( null === $network ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php'; // Not loaded in cron requests.
				}
				return array_map( 'strval', array_keys( get_plugins() ) ); // Too many sites to read: every installed plugin counts.
			}
			$active = array_merge( $active, $network['plugins'] );
		}

		return array_values( array_unique( $active ) );
	}

	/**
	 * Themes in use: the current site's, or every site's in network mode, each with its parent theme.
	 *
	 * @return string[] Theme stylesheets.
	 */
	private function active_themes(): array {
		if ( ! $this->network_context->is_network() ) {
			return array_unique( array( get_stylesheet(), get_template() ) );
		}

		$network = $this->network_actives();

		// Too many sites to read: every installed theme counts.
		return null === $network ? array_map( 'strval', array_keys( wp_get_themes( array( 'errors' => null ) ) ) ) : $network['themes'];
	}

	/**
	 * What is active across the network, read on first use in a check.
	 *
	 * @return array{plugins: string[], themes: string[]}|null Null when everything installed counts as active.
	 */
	private function network_actives(): ?array {
		if ( false === $this->network_actives ) {
			$this->network_actives = ( new Network_Actives() )->collect();
		}

		return $this->network_actives;
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
