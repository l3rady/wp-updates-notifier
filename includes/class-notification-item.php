<?php
/**
 * One update in a notification.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * One thing that has an update: WordPress itself, a plugin, a theme, or a type an add-on adds.
 *
 * Immutable. Part of the extension API: channels receive these inside a Notification.
 *
 * Values are single-line: in the name, versions, notes and link labels every run of control characters (line breaks,
 * tabs and the like) becomes one space and the value is trimmed, and control characters are removed from link URLs.
 * `key`, `type` and flags are kept as given.
 *
 * @since 2.0.0
 */
final class Notification_Item {
	/**
	 * Kind of item: core, plugin, theme or a custom type (lower case letters, digits and underscores).
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Identifier within the type: plugin file, theme stylesheet or "core".
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Installed version.
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Version available.
	 *
	 * @var string
	 */
	private $new_version;

	/**
	 * Links: label (for example "details" or "changelog") => URL.
	 *
	 * @var array<string, string>
	 */
	private $links;

	/**
	 * Lines of extra information, such as compatibility notes and warnings.
	 *
	 * @var string[]
	 */
	private $notes;

	/**
	 * Machine readable facts, such as `tested`, `requires_php` and `requires_wp`.
	 *
	 * @var array<string, scalar>
	 */
	private $flags;

	/**
	 * When the item was reported to the channel instance, or null.
	 *
	 * @var int|null
	 */
	private $notified_at;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param array<string, mixed> $data {
	 *     The item.
	 *
	 *     @type string   $type            Kind of item; must match /^[a-z0-9_]+$/.
	 *     @type string   $key             Plugin file, theme stylesheet or "core".
	 *     @type string   $name            Display name.
	 *     @type string   $current_version Installed version.
	 *     @type string   $new_version     Version available.
	 *     @type array    $links           Label => URL. The update check uses the labels `details`, `changelog` and `download`; the email channel adds `mute` to plugins and themes.
	 *     @type string[] $notes           Extra lines.
	 *     @type int      $notified_at     When the item was reported to the channel instance (Unix time). Set on items built from the run state, such as those passed to `sc_wpun_item_resolved`.
	 *     @type array    $flags           Name => scalar value. Plugin and theme items from the update check carry a bool `active` flag, and plugins also `tested`, `requires_php` and `requires_wp`. Core, plugin and theme items from the check carry a bool `auto_update` flag: true when WordPress is set to install the update by itself.
	 * }
	 *
	 * @throws InvalidArgumentException If the type is not valid.
	 */
	public function __construct( array $data ) {
		$type = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : '';
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/D', $type ) ) {
			throw new InvalidArgumentException( 'A notification item type may only contain a-z, 0-9 and underscores.' );
		}

		$this->type            = $type;
		$this->key             = self::string_value( $data, 'key' );
		$this->name            = self::single_line( self::string_value( $data, 'name' ) );
		$this->current_version = self::single_line( self::string_value( $data, 'current_version' ) );
		$this->new_version     = self::single_line( self::string_value( $data, 'new_version' ) );

		$this->links = array();
		foreach ( isset( $data['links'] ) && is_array( $data['links'] ) ? $data['links'] : array() as $label => $url ) {
			if ( is_scalar( $url ) ) {
				$this->links[ self::single_line( (string) $label ) ] = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', (string) $url );
			}
		}

		$this->notes = array();
		foreach ( isset( $data['notes'] ) && is_array( $data['notes'] ) ? $data['notes'] : array() as $note ) {
			if ( is_scalar( $note ) ) {
				$this->notes[] = self::single_line( (string) $note );
			}
		}

		$this->notified_at = isset( $data['notified_at'] ) && is_numeric( $data['notified_at'] ) && (int) $data['notified_at'] > 0 ? (int) $data['notified_at'] : null;

		$this->flags = array();
		foreach ( isset( $data['flags'] ) && is_array( $data['flags'] ) ? $data['flags'] : array() as $flag => $value ) {
			if ( is_scalar( $value ) ) {
				$this->flags[ (string) $flag ] = $value;
			}
		}
	}

	/**
	 * Collapse control characters (line breaks, tabs and the like) to one space and trim.
	 *
	 * @param string $value Value.
	 *
	 * @return string
	 */
	private static function single_line( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value ) );
	}

	/**
	 * A string entry of the constructor data, or an empty string.
	 *
	 * @param array<string, mixed> $data Constructor data.
	 * @param string               $key  Entry.
	 *
	 * @return string
	 */
	private static function string_value( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
	}

	/**
	 * Kind of item.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Plugin file, theme stylesheet or "core".
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_key(): string {
		return $this->key;
	}

	/**
	 * Display name.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Installed version.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_current_version(): string {
		return $this->current_version;
	}

	/**
	 * Version available.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_new_version(): string {
		return $this->new_version;
	}

	/**
	 * Links: label => URL.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, string>
	 */
	public function get_links(): array {
		return $this->links;
	}

	/**
	 * Extra lines of information.
	 *
	 * @since 2.0.0
	 *
	 * @return string[]
	 */
	public function get_notes(): array {
		return $this->notes;
	}

	/**
	 * Machine readable facts: name => scalar value.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, scalar>
	 */
	public function get_flags(): array {
		return $this->flags;
	}

	/**
	 * When the item was reported to the channel instance, as a Unix time.
	 *
	 * Set on items built from the run state, such as those passed to `sc_wpun_item_resolved`; null otherwise,
	 * and for items reported before 3.0.0. For an instance that was held by a deferred result it is the time the item
	 * was finally delivered, because that is when the instance was told.
	 *
	 * @since 3.0.0
	 *
	 * @return int|null
	 */
	public function get_notified_at(): ?int {
		return $this->notified_at;
	}

	/**
	 * How important the update is: `high`, `medium` or `low` (the constants of `Severity`).
	 *
	 * From the versions: WordPress itself and a change of the first part of the version (4.2 to 5.0) are high, a
	 * change of the second part (4.2 to 4.3) is medium and anything else (4.2.1 to 4.2.2) is low. A version that does
	 * not start with a number gives medium. The `sc_wpun_item_severity` filter can change it. The compatibility of the
	 * update (see `get_compatibility()`) does not change the severity.
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	public function get_severity(): string {
		$severity = Severity::of_versions( $this );

		/**
		 * Filters the severity of an update.
		 *
		 * Only `high`, `medium` and `low` are accepted; anything else is ignored and the computed severity is used.
		 *
		 * Example, treat every update of a business-critical plugin as high:
		 *
		 *     add_filter(
		 *         'sc_wpun_item_severity',
		 *         function ( $severity, $item ) {
		 *             return 'woocommerce/woocommerce.php' === $item->get_key() ? 'high' : $severity;
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param string            $severity The computed severity: `high`, `medium` or `low`.
		 * @param Notification_Item $item     The item.
		 */
		$filtered = apply_filters( 'sc_wpun_item_severity', $severity, $this );

		return is_string( $filtered ) && Severity::is_valid( $filtered ) ? $filtered : $severity;
	}

	/**
	 * What does not suit the site about the update, read from the `tested`, `requires_php` and `requires_wp` flags.
	 *
	 * Each problem is an array: `flag` (`tested`, `requires_php` or `requires_wp`), `required` (the version the flag
	 * holds), `running` (the version the site runs: PHP for `requires_php`, WordPress for the others) and `severity`.
	 * A plugin that needs a newer PHP or WordPress than the site has is `high`, as the update may not work at all. A
	 * plugin only tested with an older WordPress is `medium`. The problems come in the order tested, requires_php,
	 * requires_wp, and the list is empty when there are none. A flag that is missing, empty or a boolean is no problem.
	 *
	 * `tested` is compared without the patch part of the versions (tested up to 6.7 is fine on 6.7.2), the two
	 * `requires_` flags with the whole running version, as the compatibility notes of the update check do.
	 *
	 * @since 3.0.0
	 *
	 * @return array<int, array{flag: string, required: string, running: string, severity: string}>
	 */
	public function get_compatibility(): array {
		$wp      = wp_get_wp_version();
		$problem = array();

		$tested = $this->version_flag( 'tested' );
		if ( '' !== $tested && version_compare( Routing::major_minor( $tested ), Routing::major_minor( $wp ), '<' ) ) {
			$problem[] = array(
				'flag'     => 'tested',
				'required' => $tested,
				'running'  => $wp,
				'severity' => Severity::MEDIUM,
			);
		}

		$php = $this->version_flag( 'requires_php' );
		if ( '' !== $php && version_compare( PHP_VERSION, $php, '<' ) ) {
			$problem[] = array(
				'flag'     => 'requires_php',
				'required' => $php,
				'running'  => PHP_VERSION,
				'severity' => Severity::HIGH,
			);
		}

		$requires = $this->version_flag( 'requires_wp' );
		if ( '' !== $requires && version_compare( $wp, $requires, '<' ) ) {
			$problem[] = array(
				'flag'     => 'requires_wp',
				'required' => $requires,
				'running'  => $wp,
				'severity' => Severity::HIGH,
			);
		}

		return $problem;
	}

	/**
	 * A version flag as a string, or an empty string when it is not set to something version-like.
	 *
	 * @param string $name Flag.
	 *
	 * @return string
	 */
	private function version_flag( string $name ): string {
		if ( ! isset( $this->flags[ $name ] ) || is_bool( $this->flags[ $name ] ) ) {
			return '';
		}

		return trim( (string) $this->flags[ $name ] );
	}

	/**
	 * The item as the array its constructor takes.
	 *
	 * To copy an item with changes, put them in front: `new Notification_Item( array( 'new_version' => '2.0' ) + $item->to_array() )`.
	 * Unlike `Channel_Instance`, which has `with_*()` methods, a value object built from an array gives that array back.
	 *
	 * @since 3.0.0
	 *
	 * @return array{type: string, key: string, name: string, current_version: string, new_version: string, links: array<string, string>, notes: string[], notified_at: int|null, flags: array<string, scalar>}
	 */
	public function to_array(): array {
		return array(
			'type'            => $this->type,
			'key'             => $this->key,
			'name'            => $this->name,
			'current_version' => $this->current_version,
			'new_version'     => $this->new_version,
			'links'           => $this->links,
			'notes'           => $this->notes,
			'notified_at'     => $this->notified_at,
			'flags'           => $this->flags,
		);
	}
}
