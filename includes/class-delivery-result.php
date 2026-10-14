<?php
/**
 * The outcome of delivering a notification.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * What a channel returns from Channel::send().
 *
 * Immutable. Part of the extension API.
 *
 * @since 2.0.0
 */
final class Delivery_Result {
	/**
	 * Whether delivery worked.
	 *
	 * @var bool
	 */
	private $success;

	/**
	 * Message.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Machine readable code.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * HTTP status, if there was one.
	 *
	 * @var int|null
	 */
	private $status;

	/**
	 * Seconds to wait before trying again, if the service said so.
	 *
	 * @var int|null
	 */
	private $retry_after;

	/**
	 * Extra data for logs.
	 *
	 * @var array<string, mixed>
	 */
	private $context;

	/**
	 * Use success() or failure().
	 *
	 * @param bool                 $success     Whether delivery worked.
	 * @param string               $message     Message.
	 * @param string               $code        Code.
	 * @param int|null             $status      HTTP status.
	 * @param int|null             $retry_after Seconds to wait before trying again.
	 * @param array<string, mixed> $context     Extra data.
	 */
	private function __construct( bool $success, string $message, string $code, ?int $status, ?int $retry_after, array $context ) {
		$this->success     = $success;
		$this->message     = $message;
		$this->code        = $code;
		$this->status      = $status;
		$this->retry_after = $retry_after;
		$this->context     = $context;
	}

	/**
	 * Delivery worked.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $message Optional message.
	 * @param array<string, mixed> $context Optional extra data.
	 *
	 * @return Delivery_Result
	 */
	public static function success( string $message = '', array $context = array() ): Delivery_Result {
		return new self( true, $message, 'success', null, null, $context );
	}

	/**
	 * Delivery failed.
	 *
	 * @since 2.0.0
	 *
	 * @param string               $message     What went wrong.
	 * @param string               $code        Machine readable code.
	 * @param int|null             $status      HTTP status, if there was one.
	 * @param int|null             $retry_after Seconds to wait before trying again, if the service said so.
	 * @param array<string, mixed> $context     Optional extra data.
	 *
	 * @return Delivery_Result
	 */
	public static function failure( string $message, string $code = 'failed', ?int $status = null, ?int $retry_after = null, array $context = array() ): Delivery_Result {
		return new self( false, $message, $code, $status, $retry_after, $context );
	}

	/**
	 * Whether delivery worked.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Message.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_message(): string {
		return $this->message;
	}

	/**
	 * Machine readable code: "success" or the code given to failure().
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_code(): string {
		return $this->code;
	}

	/**
	 * HTTP status, if there was one.
	 *
	 * @since 2.0.0
	 *
	 * @return int|null
	 */
	public function get_status(): ?int {
		return $this->status;
	}

	/**
	 * Seconds to wait before trying again, if the service said so.
	 *
	 * @since 2.0.0
	 *
	 * @return int|null
	 */
	public function get_retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * Extra data.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_context(): array {
		return $this->context;
	}
}
