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
	 * Seconds to wait, read from the Retry-After header of an HTTP response.
	 *
	 * Understands both forms of RFC 9110: a number of seconds, or an HTTP date. Returns null if there is no
	 * header, it is not understood, or the wait is already over. It does not cap the wait; the plugin caps the
	 * time it defers an instance at 7 days when it stores it.
	 *
	 *     if ( 429 === $status ) {
	 *         return Delivery_Result::failure(
	 *             'Rate limited.',
	 *             'rate_limited',
	 *             429,
	 *             Delivery_Result::retry_after_from_response( $response )
	 *         );
	 *     }
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed>|\WP_Error|mixed $response What wp_remote_request() and friends returned.
	 *
	 * @return int|null
	 */
	public static function retry_after_from_response( $response ): ?int {
		if ( ! is_array( $response ) ) {
			return null;
		}

		$value = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( is_array( $value ) ) {
			$value = reset( $value ); // The header was sent more than once: use the first.
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( '' !== $value && ctype_digit( $value ) ) {
			$seconds = strlen( $value ) > 9 ? 999999999 : (int) $value; // Far beyond any cap, and no overflow.

			return $seconds > 0 ? $seconds : null;
		}

		// IMF-fixdate, RFC 850 and asctime. The day name is matched but not parsed: PHP reads it as "next <day>"
		// and would move a date whose day name is wrong.
		$formats = array(
			'/^[A-Z][a-z]{2}, (\d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2}) GMT$/' => 'd M Y H:i:s',
			'/^[A-Z][a-z]+day, (\d{2}-[A-Z][a-z]{2}-\d{2} \d{2}:\d{2}:\d{2}) GMT$/' => 'd-M-y H:i:s',
			'/^[A-Z][a-z]{2} ([A-Z][a-z]{2} +\d{1,2} \d{2}:\d{2}:\d{2} \d{4})$/'    => 'M j H:i:s Y',
		);
		foreach ( $formats as $pattern => $format ) {
			if ( ! preg_match( $pattern, $value, $matches ) ) {
				continue;
			}
			$date = \DateTimeImmutable::createFromFormat( '!' . $format, (string) preg_replace( '/ +/', ' ', $matches[1] ), new \DateTimeZone( 'UTC' ) );
			if ( false !== $date && 0 === ( \DateTimeImmutable::getLastErrors()['warning_count'] ?? 0 ) ) {
				$seconds = $date->getTimestamp() - time();

				return $seconds > 0 ? $seconds : null;
			}
		}

		return null;
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
