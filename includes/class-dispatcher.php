<?php
/**
 * Delivering a notification through channel instances.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Sends a notification to each enabled instance and collects the results.
 *
 * @internal
 */
class Dispatcher {
	/**
	 * Channel registry.
	 *
	 * @var Channel_Registry
	 */
	private $registry;

	/**
	 * Channel instances, to know which are misconfigured.
	 *
	 * @var Channel_Store|null
	 */
	private $store;

	/**
	 * Where the plugin runs: one site or a whole network.
	 *
	 * @var Network_Context
	 */
	private $network_context;

	/**
	 * Constructor.
	 *
	 * @param Channel_Registry     $registry        Channel registry.
	 * @param Channel_Store|null   $store           Channel instances, to refuse a misconfigured one.
	 * @param Network_Context|null $network_context Where the plugin runs.
	 */
	public function __construct( Channel_Registry $registry, ?Channel_Store $store = null, ?Network_Context $network_context = null ) {
		$this->registry        = $registry;
		$this->store           = $store;
		$this->network_context = $network_context ?? new Network_Context();
	}

	/**
	 * Deliver a notification.
	 *
	 * A failing instance never stops the others.
	 *
	 * @param Notification       $notification What to deliver.
	 * @param Channel_Instance[] $instances    The instances to deliver through.
	 *
	 * @return array<string, Delivery_Result> Result by instance id; disabled instances are left out.
	 */
	public function dispatch( Notification $notification, array $instances ): array {
		$results = array();

		foreach ( $instances as $instance ) {
			if ( ! $instance instanceof Channel_Instance || ! $instance->is_enabled() ) {
				continue;
			}

			try {
				$result = $this->dispatch_one( $notification, $instance );
			} catch ( \Throwable $e ) {
				// Not the channel's send() (it has its own guard) but what comes before it: the settings checks, the routing, the lookup.
				$result = $this->exception_result( $instance, $e, $notification );
			}

			if ( null !== $result ) {
				$results[ $instance->get_id() ] = $result;
			}
		}

		/**
		 * Fires when a round of delivery has finished: a dispatch, or an update check that sent something.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, Delivery_Result> $results      Result by channel instance id.
		 * @param Notification                   $notification The notification that was delivered.
		 */
		do_action( 'sc_wpun_after_dispatch', $results, $notification );

		return $results;
	}

	/**
	 * Deliver a notification through one enabled instance of a dispatch.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The instance, switched on.
	 *
	 * @return Delivery_Result|null Null when the instance is routed nothing.
	 */
	private function dispatch_one( Notification $notification, Channel_Instance $instance ): ?Delivery_Result {
		$misconfigured = $this->misconfigured( $instance );
		if ( null !== $misconfigured ) {
			return $this->finish( $misconfigured, $notification, $instance );
		}

		// An instance routed nothing is skipped, but a test message has no items and always goes.
		$routed = $this->notification_for( $notification, $instance );
		if ( ! $routed->is_test() && ! $routed->has_items() ) {
			return null;
		}

		$channel = $this->registry->get( $instance->get_type() );
		if ( null === $channel ) {
			return $this->finish( $this->unavailable( $instance ), $routed, $instance );
		}

		return $this->send( $channel, $routed, $instance );
	}

	/**
	 * Whether the channel of an instance is registered.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return bool
	 */
	public function is_available( Channel_Instance $instance ): bool {
		return $this->registry->has( $instance->get_type() );
	}

	/**
	 * Send a test message through an instance, for the Channels tab and WP-CLI.
	 *
	 * The message has no items. A channel that does not support test messages, or whose module is
	 * not registered, is reported as a failure with the code `unsupported`.
	 *
	 * @internal
	 *
	 * @param Channel_Instance $instance The instance to test.
	 *
	 * @return Delivery_Result
	 */
	public function send_test( Channel_Instance $instance ): Delivery_Result {
		$channel = $this->registry->get( $instance->get_type() );
		if ( null === $channel || ! $channel->supports( 'test_message' ) ) {
			return Delivery_Result::failure( __( 'This channel cannot send a test message.', 'wp-updates-notifier' ), 'unsupported' );
		}

		$notification = new Notification(
			$this->network_context->site_name(),
			$this->network_context->home_url(),
			$this->network_context->update_core_url(),
			array(),
			array(
				'is_test' => true,
				'source'  => 'test',
			)
		);

		return $this->send_one( $notification, $instance );
	}

	/**
	 * Deliver a notification through one instance, for example a test message.
	 *
	 * Unlike dispatch(), a switched-off or unavailable instance is reported as a failure.
	 *
	 * @param Notification     $notification What to deliver.
	 * @param Channel_Instance $instance     The instance to deliver through.
	 *
	 * @return Delivery_Result
	 */
	public function send_one( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		if ( ! $instance->is_enabled() ) {
			return $this->finish( Delivery_Result::failure( __( 'This channel is switched off.', 'wp-updates-notifier' ), 'disabled' ), $notification, $instance );
		}

		try {
			$misconfigured = $this->misconfigured( $instance );
			if ( null !== $misconfigured ) {
				return $this->finish( $misconfigured, $notification, $instance );
			}

			$channel = $this->registry->get( $instance->get_type() );
			if ( null === $channel ) {
				return $this->finish( $this->unavailable( $instance ), $notification, $instance );
			}

			return $this->send( $channel, $notification, $instance );
		} catch ( \Throwable $e ) {
			return $this->exception_result( $instance, $e, $notification );
		}
	}

	/**
	 * The notification an instance receives: only the items its routing rules let through.
	 * A test notification is never narrowed.
	 *
	 * @param Notification     $notification The notification.
	 * @param Channel_Instance $instance     The instance.
	 *
	 * @return Notification
	 */
	protected function notification_for( Notification $notification, Channel_Instance $instance ): Notification {
		if ( $notification->is_test() ) {
			return $notification;
		}

		return $notification->with_items(
			array_values(
				array_filter(
					$notification->get_items(),
					static fn( Notification_Item $item ): bool => Routing::should_receive( $item, $instance )
				)
			)
		);
	}

	/**
	 * Call one channel, whatever it does.
	 *
	 * Channel::send() has a return type, so PHP itself throws a TypeError when a
	 * channel returns something else; that case is reported as `invalid_result`
	 * and any other exception as `exception`.
	 *
	 * @param Channel          $channel      The channel.
	 * @param Notification     $notification The notification.
	 * @param Channel_Instance $instance     The instance.
	 *
	 * @return Delivery_Result
	 */
	private function send( Channel $channel, Notification $notification, Channel_Instance $instance ): Delivery_Result {
		try {
			/**
			 * Filters a delivery before it happens, to replace it with a result of your own.
			 *
			 * Return a Delivery_Result to skip the channel and use that result; any other
			 * value sends as normal. Runs for every instance that is switched on and whose
			 * channel is available.
			 *
			 * Return `Delivery_Result::deferred( $time )` to hold the instance until a Unix time without an error.
			 * A test message ignores a deferral and is sent anyway.
			 *
			 * Example, hold all deliveries during maintenance:
			 *
			 *     add_filter(
			 *         'sc_wpun_pre_send',
			 *         function ( $pre, $notification, $instance ) {
			 *             return my_maintenance_mode()
			 *                 ? \WPUN\Delivery_Result::deferred( time() + HOUR_IN_SECONDS, 'Maintenance.' )
			 *                 : $pre;
			 *         },
			 *         10,
			 *         3
			 *     );
			 *
			 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
			 *
			 * @since 2.0.0
			 *
			 * @param null|Delivery_Result $pre          Null. Return a Delivery_Result to short-circuit.
			 * @param Notification         $notification What is being delivered.
			 * @param Channel_Instance     $instance     The instance it is delivered through.
			 */
			$pre = apply_filters( 'sc_wpun_pre_send', null, $notification, $instance );
			if ( $pre instanceof Delivery_Result && ! ( $pre->is_deferred() && $notification->is_test() ) ) {
				return $this->finish( $pre, $notification, $instance );
			}

			if ( $pre instanceof Delivery_Result ) {
				// A test always sends: the channel is called and the result says a hold would have applied.
				return $this->finish( $this->with_test_note( $channel->send( $notification, $instance ) ), $notification, $instance );
			}

			return $this->finish( $channel->send( $notification, $instance ), $notification, $instance );
		} catch ( \Throwable $e ) {
			if ( $e instanceof \TypeError && false !== stripos( $e->getMessage(), 'return value' ) && false !== strpos( $e->getMessage(), 'Delivery_Result' ) ) {
				$this->log_exception( $instance, $e );

				return $this->finish(
					Delivery_Result::failure(
						// translators: %s: Channel type.
						sprintf( __( 'The "%s" channel did not return a Delivery_Result.', 'wp-updates-notifier' ), $instance->get_type() ),
						'invalid_result'
					),
					$notification,
					$instance
				);
			}
			return $this->exception_result( $instance, $e, $notification );
		}
	}

	/**
	 * A channel's result with a note that a test message is not held back.
	 *
	 * @param Delivery_Result $result The channel's result.
	 *
	 * @return Delivery_Result
	 */
	private function with_test_note( Delivery_Result $result ): Delivery_Result {
		if ( $result->is_deferred() ) {
			return $result;
		}

		$note    = __( 'Not held back, because this is a test message.', 'wp-updates-notifier' );
		$message = trim( $result->get_message() . ' ' . $note );
		if ( $result->is_success() ) {
			return Delivery_Result::success( $message, $result->get_context() );
		}

		return Delivery_Result::failure( $message, $result->get_code(), $result->get_status(), $result->get_retry_after(), $result->get_context() );
	}

	/**
	 * The result for an instance that threw, announced to listeners; the exception is logged when debugging.
	 *
	 * The exception's own message can contain request URLs with secrets in them
	 * (some services put tokens in the URL), and results are shown in the admin,
	 * so only the exception class is kept, and only the class is logged too.
	 *
	 * @internal
	 *
	 * @param Channel_Instance $instance     The instance.
	 * @param \Throwable       $e            The exception.
	 * @param Notification     $notification What was to be delivered.
	 *
	 * @return Delivery_Result
	 */
	public function exception_result( Channel_Instance $instance, \Throwable $e, Notification $notification ): Delivery_Result {
		$this->log_exception( $instance, $e );

		return $this->finish(
			Delivery_Result::failure(
				// translators: %s: Channel type.
				sprintf( __( 'The "%s" channel failed with an error.', 'wp-updates-notifier' ), $instance->get_type() ),
				'exception',
				null,
				null,
				array( 'exception' => get_class( $e ) )
			),
			$notification,
			$instance
		);
	}

	/**
	 * The failure for an instance with a supplied value that cannot be used, or null if it is fine.
	 *
	 * Names the keys, never the values.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return Delivery_Result|null
	 */
	private function misconfigured( Channel_Instance $instance ): ?Delivery_Result {
		$keys = null === $this->store ? array() : $this->store->problems( $instance->get_id() );
		if ( array() === $keys ) {
			return null;
		}

		return Delivery_Result::failure(
			// translators: %s: Names of the constants or environment variables that are not valid.
			sprintf( __( 'The channel is misconfigured: %s is not valid.', 'wp-updates-notifier' ), implode( ', ', $keys ) ),
			'misconfigured'
		);
	}

	/**
	 * The result for an instance whose channel is not registered, with the reason if the module was refused.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return Delivery_Result
	 */
	private function unavailable( Channel_Instance $instance ): Delivery_Result {
		$rejected = $this->registry->get_rejected()[ $instance->get_type() ] ?? null;
		if ( null !== $rejected ) {
			return Delivery_Result::failure(
				// translators: 1: Channel type, 2: Why the module was refused.
				sprintf( __( 'The "%1$s" channel is not available: %2$s', 'wp-updates-notifier' ), $instance->get_type(), $rejected['reason'] ),
				'unavailable'
			);
		}

		return Delivery_Result::failure(
			// translators: %s: Channel type.
			sprintf( __( 'The "%s" channel is not available.', 'wp-updates-notifier' ), $instance->get_type() ),
			'unavailable'
		);
	}

	/**
	 * Announce a result to listeners. Every delivery path ends here.
	 *
	 * A listener that throws is logged and does not affect the result or the other instances.
	 *
	 * @param Delivery_Result  $result       The result.
	 * @param Notification     $notification What was delivered.
	 * @param Channel_Instance $instance     The instance.
	 *
	 * @return Delivery_Result The result, unchanged.
	 */
	private function finish( Delivery_Result $result, Notification $notification, Channel_Instance $instance ): Delivery_Result {
		try {
			if ( $result->is_deferred() ) {
				/**
				 * Fires when delivering a notification through a channel instance was held until later.
				 *
				 * A deferred result is not a failure: `sc_wpun_notification_failed` does not fire. Update checks
				 * skip the instance until the time, at most 7 days from now, then deliver everything still pending.
				 *
				 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
				 *
				 * @since 3.0.0
				 *
				 * @param Delivery_Result  $result       The result; `get_deferred_until()` is when the instance may next be delivered to.
				 * @param Notification     $notification What was to be delivered.
				 * @param Channel_Instance $instance     The instance.
				 */
				do_action( 'sc_wpun_notification_deferred', $result, $notification, $instance );
			} elseif ( $result->is_success() ) {
				/**
				 * Fires when a notification was delivered through a channel instance.
				 *
				 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
				 *
				 * @since 2.0.0
				 *
				 * @param Delivery_Result  $result       The result.
				 * @param Notification     $notification What was delivered.
				 * @param Channel_Instance $instance     The instance it was delivered through.
				 */
				do_action( 'sc_wpun_notification_sent', $result, $notification, $instance );
			} else {
				/**
				 * Fires when delivering a notification through a channel instance failed.
				 *
				 * Also fires for an instance that is switched off or whose channel is not available,
				 * when a delivery to it was asked for.
				 *
				 * A result with a `retry_after` defers the instance: update checks skip it until then (at most 7 days).
				 *
				 * `$instance` holds the resolved settings, secrets included: don't log or dump its settings.
				 *
				 * @since 2.0.0
				 *
				 * @param Delivery_Result  $result       The result, with the reason in its code and message.
				 * @param Notification     $notification What was to be delivered.
				 * @param Channel_Instance $instance     The instance.
				 */
				do_action( 'sc_wpun_notification_failed', $result, $notification, $instance );
			}
		} catch ( \Throwable $e ) {
			$this->log_exception( $instance, $e );
		}

		return $result;
	}

	/**
	 * Log an exception from a channel or a listener when debugging.
	 *
	 * @param Channel_Instance $instance The instance.
	 * @param \Throwable       $e        The exception.
	 *
	 * @return void
	 */
	private function log_exception( Channel_Instance $instance, \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Not the message: it can contain a resolved secret such as a webhook URL.
			wp_trigger_error(
				self::class,
				sprintf( 'Channel instance "%1$s" (type "%2$s") failed with %3$s.', $instance->get_id(), $instance->get_type(), get_class( $e ) )
			);
		}
	}
}
