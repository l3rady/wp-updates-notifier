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
	 * Constructor.
	 *
	 * @param Channel_Registry $registry Channel registry.
	 */
	public function __construct( Channel_Registry $registry ) {
		$this->registry = $registry;
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

			$channel = $this->registry->get( $instance->get_type() );
			if ( null === $channel ) {
				$results[ $instance->get_id() ] = Delivery_Result::failure(
					// translators: %s: Channel type.
					sprintf( __( 'The "%s" channel is not available.', 'wp-updates-notifier' ), $instance->get_type() ),
					'unavailable'
				);
				continue;
			}

			$results[ $instance->get_id() ] = $this->send( $channel, $notification, $instance );
		}

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
			return Delivery_Result::failure( __( 'This channel is switched off.', 'wp-updates-notifier' ), 'disabled' );
		}

		$channel = $this->registry->get( $instance->get_type() );
		if ( null === $channel ) {
			return Delivery_Result::failure(
				// translators: %s: Channel type.
				sprintf( __( 'The "%s" channel is not available.', 'wp-updates-notifier' ), $instance->get_type() ),
				'unavailable'
			);
		}

		return $this->send( $channel, $notification, $instance );
	}

	/**
	 * The notification an instance receives. Routing will narrow it per instance.
	 *
	 * @param Notification     $notification The notification.
	 * @param Channel_Instance $instance     The instance.
	 *
	 * @return Notification
	 */
	protected function notification_for( Notification $notification, Channel_Instance $instance ): Notification { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Seam for routing.
		return $notification;
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
			return $channel->send( $this->notification_for( $notification, $instance ), $instance );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				wp_trigger_error(
					__METHOD__,
					sprintf( 'Channel instance "%1$s" failed: %2$s', $instance->get_id(), $e->getMessage() )
				);
			}

			if ( $e instanceof \TypeError && false !== stripos( $e->getMessage(), 'return value' ) && false !== strpos( $e->getMessage(), 'Delivery_Result' ) ) {
				return Delivery_Result::failure(
					// translators: %s: Channel type.
					sprintf( __( 'The "%s" channel did not return a Delivery_Result.', 'wp-updates-notifier' ), $instance->get_type() ),
					'invalid_result'
				);
			}

			// The exception's own message can contain request URLs with secrets in them
			// (some services put tokens in the URL), and results are shown in the admin,
			// so only the exception class is kept; the message goes to the debug log above.
			return Delivery_Result::failure(
				// translators: %s: Channel type.
				sprintf( __( 'The "%s" channel failed with an error.', 'wp-updates-notifier' ), $instance->get_type() ),
				'exception',
				null,
				null,
				array( 'exception' => get_class( $e ) )
			);
		}
	}
}
