<?php
/**
 * A mute: notifications about one plugin, theme or other item are switched off.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Notifications about one item are muted: always, for the version that is waiting, or for a while (a snooze).
 *
 * Immutable. Part of the extension API: the `sc_wpun_mutes` filter takes and returns these, and the
 * mute actions and filters pass them on. Read them through `sc_wpun_mutes()`.
 *
 * @since 3.0.0
 */
final class Mute {
	/**
	 * Kind of item: plugin, theme or a type an add-on registers.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Identifier within the type: plugin file or theme stylesheet.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * The newest version the mute covers, or null for always.
	 *
	 * @var string|null
	 */
	private $until;

	/**
	 * When it was set, as a Unix timestamp. Zero if unknown.
	 *
	 * @var int
	 */
	private $time;

	/**
	 * Who set it, as a user id. Zero if unknown.
	 *
	 * @var int
	 */
	private $user;

	/**
	 * Whether it came from the `sc_wpun_mutes` filter instead of the stored mutes.
	 *
	 * @var bool
	 */
	private $from_code;

	/**
	 * When the mute ends, as a Unix timestamp, or null if it does not end by time (a snooze has one).
	 *
	 * @var int|null
	 */
	private $expires;

	/**
	 * Where it was set from: `plugins_screen`, `settings`, `cli`, `api` or an add-on's slug. Empty if unknown.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @since 3.0.0
	 *
	 * @param array<string, mixed> $data {
	 *     The mute.
	 *
	 *     @type string      $type      Item type; must match /^[a-z0-9_]+$/.
	 *     @type string      $key       Plugin file or theme stylesheet. Not empty.
	 *     @type string|null $until     The newest version the mute covers, or null (the default) for always. Not an empty string.
	 *     @type int         $time      When it was set, as a Unix timestamp. Default 0.
	 *     @type int         $user      Id of the user who set it. Default 0.
	 *     @type bool        $from_code Whether it was defined in code. Default false; the store sets it for mutes from the `sc_wpun_mutes` filter.
	 *     @type int|null    $expires   When the mute ends, as a Unix timestamp (a snooze), or null (the default) for no time limit. A positive integer.
	 *     @type string      $source    Where it was set from. Default empty, unknown.
	 * }
	 *
	 * @throws InvalidArgumentException If the type, key, version or expiry is not valid.
	 */
	public function __construct( array $data ) {
		$type = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : '';
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/D', $type ) ) {
			throw new InvalidArgumentException( 'A mute type may only contain a-z, 0-9 and underscores.' );
		}

		$key = isset( $data['key'] ) && is_string( $data['key'] ) ? $data['key'] : '';
		if ( '' === $key ) {
			throw new InvalidArgumentException( 'A mute needs the key of the item.' );
		}

		$until = $data['until'] ?? null;
		if ( null !== $until && ( ! is_string( $until ) || '' === $until ) ) {
			throw new InvalidArgumentException( 'The version of a mute must be null or a version.' );
		}

		$expires = $data['expires'] ?? null;
		if ( is_string( $expires ) && is_numeric( $expires ) ) {
			$expires = (int) $expires; // Stored values may come back as strings.
		}
		if ( null !== $expires && ( ! is_int( $expires ) || $expires <= 0 ) ) {
			throw new InvalidArgumentException( 'The expiry of a mute must be null or a positive Unix timestamp.' );
		}

		$this->type      = $type;
		$this->key       = $key;
		$this->until     = $until;
		$this->time      = isset( $data['time'] ) && is_numeric( $data['time'] ) ? max( 0, (int) $data['time'] ) : 0;
		$this->user      = isset( $data['user'] ) && is_numeric( $data['user'] ) ? max( 0, (int) $data['user'] ) : 0;
		$this->from_code = ! empty( $data['from_code'] );
		$this->expires   = $expires;
		$this->source    = isset( $data['source'] ) && is_string( $data['source'] ) ? $data['source'] : '';
	}

	/**
	 * Kind of item.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Plugin file or theme stylesheet.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_key(): string {
		return $this->key;
	}

	/**
	 * The newest version the mute covers.
	 *
	 * @since 3.0.0
	 *
	 * @return string|null Null means always.
	 */
	public function get_until(): ?string {
		return $this->until;
	}

	/**
	 * Whether the mute covers every version.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public function is_always(): bool {
		return null === $this->until;
	}

	/**
	 * When the mute was set.
	 *
	 * @since 3.0.0
	 *
	 * @return int Unix timestamp; zero if unknown.
	 */
	public function get_time(): int {
		return $this->time;
	}

	/**
	 * Who set the mute.
	 *
	 * @since 3.0.0
	 *
	 * @return int User id; zero if unknown.
	 */
	public function get_user_id(): int {
		return $this->user;
	}

	/**
	 * Whether the mute came from the `sc_wpun_mutes` filter, so it cannot be changed or removed here.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public function is_from_code(): bool {
		return $this->from_code;
	}

	/**
	 * When the mute ends.
	 *
	 * @since 3.0.0
	 *
	 * @return int|null Unix timestamp, or null if it does not end by time.
	 */
	public function get_expires(): ?int {
		return $this->expires;
	}

	/**
	 * Whether the mute has ended by time.
	 *
	 * @since 3.0.0
	 *
	 * @param int|null $now Unix timestamp to compare with; default the current time.
	 *
	 * @return bool True when it has an expiry and the time has reached it.
	 */
	public function is_expired( ?int $now = null ): bool {
		return null !== $this->expires && ( $now ?? time() ) >= $this->expires;
	}

	/**
	 * Where the mute was set from.
	 *
	 * @since 3.0.0
	 *
	 * @return string `plugins_screen`, `settings`, `cli`, `api` or an add-on's slug; empty if unknown.
	 */
	public function get_source(): string {
		return $this->source;
	}

	/**
	 * Whether the mute still covers a version.
	 *
	 * An "always" mute covers every version. A mute until a version covers that version and any older
	 * one, compared with PHP's `version_compare()`, so `2.0.0` is newer than `2.0`. A version that is
	 * not known (an empty string) counts as covered. The expiry is not looked at; see `is_expired()`.
	 *
	 * @since 3.0.0
	 *
	 * @param string $offered_version The version offered.
	 *
	 * @return bool
	 */
	public function applies_to( string $offered_version ): bool {
		if ( null === $this->until || '' === $offered_version ) {
			return true;
		}

		return ! version_compare( $offered_version, $this->until, '>' );
	}
}
