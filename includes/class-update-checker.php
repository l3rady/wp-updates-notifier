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
 */
class Update_Checker {
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
	 * Constructor.
	 *
	 * @param Settings      $settings Settings.
	 * @param State         $state    Run state.
	 * @param Dispatcher    $dispatcher Dispatcher.
	 * @param Channel_Store $channels Channel instance storage.
	 */
	public function __construct( Settings $settings, State $state, Dispatcher $dispatcher, Channel_Store $channels ) {
		$this->settings   = $settings;
		$this->state      = $state;
		$this->dispatcher = $dispatcher;
		$this->channels   = $channels;
	}

	/**
	 * This is run by the cron.
	 *
	 * @return void
	 */
	public function do_update_check() {
		$this->check( 'cron' );
	}

	/**
	 * Runs the check now, for the `sc_wpun_run_check` action.
	 *
	 * @return void
	 */
	public function run_manual_check() {
		$this->check( 'manual' );
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
	 * reminder. The interval starts when something first waits for the instance, and stops when
	 * nothing does. A due reminder replaces the message of new items of that run; new items
	 * between reminders are sent at once as usual. A failed reminder does not restart the interval.
	 *
	 * @param string $source What started the check, kept in the notification context.
	 *
	 * @return void
	 */
	private function check( string $source ) {
		/**
		 * Fires when an update check starts, before anything is looked up.
		 *
		 * @since 2.0.0
		 */
		do_action( 'sc_wpun_before_update_check' );

		$options = $this->settings->get(); // get settings.
		$state   = $this->state->get();
		$items   = array(); // What the check finds, in the order of the message.
		$checked = array(); // Item type => keys still waiting, for the types this run looked at.

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

		// The global veto comes after, so a vetoed item still counts as waiting.
		$items = array_values( array_filter( $items, array( $this, 'should_notify' ) ) );

		$notification = new Notification( get_bloginfo(), home_url(), admin_url( 'update-core.php' ), $items, array( 'source' => $source ) );

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

		$this->last_notification = $notification;
		$this->last_results      = array();

		$instances = $this->channels->all();
		$channels  = $this->state_for_instances( $state, $instances );

		// Forget what is no longer waiting for an update, for every instance whether it is on or not.
		foreach ( $channels as $id => $entry ) {
			foreach ( $checked as $type => $pending_keys ) {
				if ( isset( $entry['notified'][ $type ] ) ) {
					$channels[ $id ]['notified'][ $type ] = array_intersect_key( $entry['notified'][ $type ], array_flip( $pending_keys ) );
				}
			}
		}

		foreach ( $instances as $id => $instance ) {
			if ( ! $instance->is_enabled() ) {
				continue; // Switched off: nothing is sent or recorded, and a pending skip waits.
			}
			if ( ( $channels[ $id ]['retry_at'] ?? 0 ) > time() ) {
				continue; // Told to wait (Retry-After): nothing is sent or recorded, its items go out on the first check after, and a pending skip waits.
			}
			$entry    = $channels[ $id ] ?? array(
				'notified'     => array(),
				'skip_pending' => false,
			); // No entry: no history, so everything waiting is new to it.
			$original = $entry;

			// Everything waiting that this instance receives, and the part of it that is new to it.
			$pending      = $this->items_for_instance( $instance, $notification->get_items() );
			$for_instance = array();
			foreach ( $pending as $item ) {
				if ( ( $entry['notified'][ $item->get_type() ][ $item->get_key() ] ?? null ) !== $item->get_new_version() ) {
					$for_instance[] = $item;
				}
			}

			// The reminder clock runs while the instance wants reminders and something is waiting for it.
			$interval = $this->reminder_interval( Routing::normalise( $instance->get_routing() )['remind'] );
			$due      = false;
			if ( 0 === $interval || array() === $pending ) {
				unset( $entry['last_reminder'] );
			} elseif ( ! isset( $entry['last_reminder'] ) ) {
				$entry['last_reminder'] = time(); // Starts now: the first reminder comes one interval after items start waiting.
			} else {
				$due = ! $entry['skip_pending'] && $this->reminder_is_due( $entry['last_reminder'], $interval, $options );
			}

			if ( $entry['skip_pending'] ) {
				if ( ! $this->dispatcher->is_available( $instance ) ) {
					continue; // Keep the flag until the channel is there.
				}
				$entry['skip_pending'] = false;
				$entry['notified']     = $this->record( $entry['notified'], $for_instance );
			} elseif ( $due ) {
				// One message with everything waiting, new items included, instead of a second message.
				$reminder                  = new Notification(
					$notification->get_site_name(),
					$notification->get_site_url(),
					$notification->get_updates_url(),
					$pending,
					array(
						'is_reminder' => true,
						'source'      => $notification->get_source(),
					)
				);
				$result                    = $this->dispatcher->send_one( $reminder, $instance );
				$this->last_results[ $id ] = $result;
				if ( $result->is_success() ) {
					$entry['notified']      = $this->record( $entry['notified'], $pending );
					$entry['last_reminder'] = time();
				}
			} elseif ( array() !== $for_instance ) {
				$result                    = $this->dispatcher->send_one( $this->last_notification->with_items( $for_instance ), $instance );
				$this->last_results[ $id ] = $result;
				if ( $result->is_success() ) {
					$entry['notified'] = $this->record( $entry['notified'], $for_instance );
				}
			} elseif ( $entry === $original ) {
				continue; // Nothing new and the clock did not change.
			}

			$channels[ $id ] = $entry;
		}

		// Sending saved each instance's last result and retry time in the stored state, which the copy read at the start lacks.
		$fresh = $this->state->get()['channels'];
		foreach ( $channels as $id => $entry ) {
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
		$this->state->save( $state );

		if ( array() !== $this->last_results ) {
			/** This action is documented in includes/class-dispatcher.php */
			do_action( 'sc_wpun_after_dispatch', $this->last_results, $notification );
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
		do_action( 'sc_wpun_after_update_check', $notification, $this->last_results );
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
		 * @param bool              $notify Whether to report the item. Default true.
		 * @param Notification_Item $item   The item.
		 */
		return (bool) apply_filters( 'sc_wpun_should_notify_item', true, $item );
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
	 * Add items to a list of notified versions.
	 *
	 * @param array<string, array<string, string>> $notified Notified versions by item type and key.
	 * @param Notification_Item[]                  $items    Items that were announced.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function record( array $notified, array $items ): array {
		foreach ( $items as $item ) {
			$notified[ $item->get_type() ][ $item->get_key() ] = $item->get_new_version();
		}

		return $notified;
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
	 * @param mixed $remind The normalised `remind` routing value: never, daily or weekly.
	 *
	 * @return int 0 for never.
	 */
	private function reminder_interval( $remind ): int {
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
		do_action( 'wp_version_check' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
		$update_core = get_site_transient( 'update_core' ); // get information of updates.

		// No usable data (new site, api.wordpress.org unreachable): we know nothing,
		// so keep what was notified instead of forgetting it.
		if ( ! is_object( $update_core ) || empty( $update_core->updates ) || ! is_array( $update_core->updates ) ) {
			return;
		}

		$new_core_ver = $this->core_upgrade_offer( $update_core->updates );
		if ( '' === $new_core_ver ) {
			$checked['core'] = array(); // No update is waiting, so nothing about core stays notified.
			return;
		}

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

		$items[] = new Notification_Item(
			array(
				'type'            => 'core',
				'key'             => 'core',
				'name'            => 'WordPress',
				'current_version' => $current_version,
				'new_version'     => $new_core_ver,
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
	 * @return string The offered version, or an empty string if there is no upgrade.
	 */
	private function core_upgrade_offer( array $offers ): string {
		foreach ( $offers as $offer ) {
			if ( is_object( $offer ) && isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response && is_scalar( $offer->current ) && '' !== (string) $offer->current ) {
				return (string) $offer->current;
			}
		}

		return '';
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
					$items[] = new Notification_Item(
						array(
							'type'            => 'plugin',
							'key'             => (string) $key,
							'name'            => $plugin_info['Name'],
							'current_version' => $plugin_info['Version'],
							'new_version'     => $data->new_version,
							'links'           => $links,
							'notes'           => $this->plugin_compatibility_notes( $data ),
							'flags'           => array_merge( $this->plugin_flags( $data ), array( 'active' => in_array( (string) $key, $active_plugins, true ) ) ),
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
			$active_themes      = array_unique( array( get_stylesheet(), get_template() ) ); // The active theme and, for a child theme, its parent.
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
					$theme_info = wp_get_theme( $key ); // get theme info.
					$items[]    = new Notification_Item(
						array(
							'type'            => 'theme',
							'key'             => (string) $key,
							'name'            => $theme_info['Name'],
							'current_version' => $theme_info['Version'],
							'new_version'     => $data['new_version'],
							'links'           => '' !== $data['url'] ? array( 'details' => $data['url'] ) : array(),
							'flags'           => array( 'active' => in_array( (string) $key, $active_themes, true ) ),
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
	 * @return array<string, \stdClass> Entries keyed by plugin file, each with string `new_version`, `url`, `slug` and `id`.
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
			foreach ( array( 'url', 'slug', 'id' ) as $property ) {
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
	 * @return array<string, array{new_version: string, url: string}> Entries keyed by stylesheet.
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

		return 1 === preg_match( '/^[a-z0-9-]+$/', $slug ) ? $slug : '';
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

		return array_values( array_unique( $active ) );
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
