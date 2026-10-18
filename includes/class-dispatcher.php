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
	 * Constructor.
	 *
	 * @param Channel_Registry   $registry Channel registry.
	 * @param Channel_Store|null $store    Channel instances, to refuse a misconfigured one.
	 */
	public function __construct( Channel_Registry $registry, ?Channel_Store $store = null ) {
		$this->registry = $registry;
		$this->store    = $store;
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

			$misconfigured = $this->misconfigured( $instance );
			if ( null !== $misconfigured ) {
				$results[ $instance->get_id() ] = $this->finish( $misconfigured, $notification, $instance );
				continue;
			}

			// An instance routed nothing is skipped, but a test message has no items and always goes.
			$routed = $this->notification_for( $notification, $instance );
			if ( ! $routed->is_test() && ! $routed->has_items() ) {
				continue;
			}

			$channel = $this->registry->get( $instance->get_type() );
			if ( null === $channel ) {
				$results[ $instance->get_id() ] = $this->finish( $this->unavailable( $instance ), $routed, $instance );
				continue;
			}

			$results[ $instance->get_id() ] = $this->send( $channel, $routed, $instance );
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

		$misconfigured = $this->misconfigured( $instance );
		if ( null !== $misconfigured ) {
			return $this->finish( $misconfigured, $notification, $instance );
		}

		$channel = $this->registry->get( $instance->get_type() );
		if ( null === $channel ) {
			return $this->finish( $this->unavailable( $instance ), $notification, $instance );
		}

		return $this->send( $channel, $notification, $instance );
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
			 * Example, hold all deliveries during maintenance:
			 *
			 *     add_filter(
			 *         'sc_wpun_pre_send',
			 *         function ( $pre, $notification, $instance ) {
			 *             return my_maintenance_mode()
			 *                 ? \WPUN\Delivery_Result::failure( 'Held back.', 'maintenance' )
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
			if ( $pre instanceof Delivery_Result ) {
				return $this->finish( $pre, $notification, $instance );
			}

			return $this->finish( $channel->send( $notification, $instance ), $notification, $instance );
		} catch ( \Throwable $e ) {
			$this->log_exception( $instance, $e );
			if ( $e instanceof \TypeError && false !== stripos( $e->getMessage(), 'return value' ) && false !== strpos( $e->getMessage(), 'Delivery_Result' ) ) {
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
			// The exception's own message can contain request URLs with secrets in them
			// (some services put tokens in the URL), and results are shown in the admin,
			// so only the exception class is kept, and only the class is logged too.
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
			if ( $result->is_success() ) {
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
