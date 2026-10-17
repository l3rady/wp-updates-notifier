<?php
/**
 * A channel with a configurable field schema, for tests.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;

/**
 * Declares the fields it was given. A `token` must start with "ok-" to be valid, and an instance with
 * the setting `explode` throws an exception that contains the token, to prove logs never show it.
 */
class Test_Fields_Channel extends Channel {

	/**
	 * Channel type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Field schema.
	 *
	 * @var array
	 */
	private $fields;

	/**
	 * What the channel received: instance id => notifications.
	 *
	 * @var array
	 */
	public $received = array();

	/**
	 * Constructor.
	 *
	 * @param string $type   Channel type.
	 * @param array  $fields Field schema.
	 */
	public function __construct( $type, array $fields ) {
		$this->type   = $type;
		$this->fields = $fields;
	}

	/**
	 * Type.
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
		return 'Fields channel';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function get_fields(): array {
		return $this->fields;
	}

	/**
	 * A token must start with "ok-".
	 *
	 * @param array $settings Settings.
	 * @return array|WP_Error
	 * @throws RuntimeException For a token that starts with "throw-".
	 */
	public function validate_settings( array $settings ) {
		if ( isset( $settings['token'] ) && 0 === strpos( (string) $settings['token'], 'throw-' ) ) {
			throw new RuntimeException( 'validation blew up on ' . $settings['token'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test fixture.
		}
		if ( isset( $settings['token'] ) && 0 !== strpos( (string) $settings['token'], 'ok-' ) ) {
			return new WP_Error( 'bad_token', 'The token is not valid.', array( 'field' => 'token' ) );
		}

		return parent::validate_settings( $settings );
	}

	/**
	 * Succeed, or throw with the token in the message.
	 *
	 * @param Notification     $notification Notification.
	 * @param Channel_Instance $instance     Instance.
	 * @return Delivery_Result
	 * @throws RuntimeException When the instance asks for it.
	 */
	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		$this->received[ $instance->get_id() ][] = $notification;
		if ( $instance->get_setting( 'explode' ) ) {
			throw new RuntimeException( 'failed with ' . $instance->get_setting( 'token' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test fixture.
		}

		return Delivery_Result::success();
	}
}
