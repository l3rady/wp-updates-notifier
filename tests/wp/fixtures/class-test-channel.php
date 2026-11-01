<?php
/**
 * A channel for tests.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;

/**
 * Behaves as the `behaviour` setting of the instance says: succeed (default), fail, limit (a 429 with the `retry_after` setting), defer (a deferred result until `$defer_offset` seconds from now), throw or invalid.
 *
 * It records every call, so tests can see what each instance received.
 */
class Test_Channel extends Channel {

	/**
	 * What the channel received: instance id => notifications.
	 *
	 * @var array<string, Notification[]>
	 */
	public $received = array();

	/**
	 * Seconds from now a `defer` instance holds until; negative for a time in the past.
	 *
	 * @var int
	 */
	public $defer_offset = 3600;

	/**
	 * Channel type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Constructor.
	 *
	 * @param string $type Channel type.
	 */
	public function __construct( $type = 'test' ) {
		$this->type = $type;
	}

	/**
	 * Channel type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Test channel';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function get_fields(): array {
		return array(
			'retry_after' => array(
				'type'              => 'text',
				'label'             => 'Retry after',
				'sanitize_callback' => 'absint',
			),
			'behaviour'   => array(
				'type'              => 'text',
				'label'             => 'Behaviour',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	/**
	 * Record the call, then do what the instance asks.
	 *
	 * @param Notification     $notification Notification.
	 * @param Channel_Instance $instance     Instance.
	 * @return Delivery_Result
	 * @throws RuntimeException When the instance asks for it.
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		$this->received[ $instance->get_id() ][] = $notification;

		switch ( $instance->get_setting( 'behaviour', 'succeed' ) ) {
			case 'fail':
				return Delivery_Result::failure( 'It failed.', 'test_failure', 500 );
			case 'limit':
				$wait = $instance->get_setting( 'retry_after', null );
				return Delivery_Result::failure( 'It is limiting requests.', 'rate_limited', 429, null === $wait ? null : (int) $wait );
			case 'defer':
				return Delivery_Result::deferred( time() + $this->defer_offset, 'Held back.' );
			case 'throw':
				throw new RuntimeException( 'It threw.' );
			case 'invalid':
				return 'not a result'; // phpcs:ignore -- Deliberately wrong.
			default:
				return Delivery_Result::success( 'Delivered.' );
		}
	}
}
