<?php
/**
 * What the Plugins screen and the Muted tab share when they mute: the capability, the installed items and the actions.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Mute_Store;

/**
 * Mutes and unmutes an item for a screen, and says what happened in words.
 *
 * @internal
 */
class Mute_Actions {
	/**
	 * Mute storage.
	 *
	 * @var Mute_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Mute_Store $store Mute storage.
	 */
	public function __construct( Mute_Store $store ) {
		$this->store = $store;
	}

	/**
	 * The capability needed to mute and unmute from the Plugins screen and the Muted tab.
	 *
	 * @return string
	 */
	public static function capability(): string {
		$default = Settings_Page::capability();

		/**
		 * Filters the capability needed to mute and unmute plugins and themes.
		 *
		 * It decides who sees the mute links and bulk actions on the Plugins screen, and who may use them. It
		 * defaults to the capability of the settings page (`sc_wpun_capability`, `manage_options` on a single
		 * site), so a site can let maintainers mute without giving them the whole settings screen. An empty or
		 * non-string value is ignored and the default is used.
		 *
		 * The Muted tab is part of the settings page, so seeing it still needs the settings capability, and
		 * its forms need both. WP-CLI and the PHP API are not affected: they do not check capabilities.
		 *
		 * Example, let users who can update plugins mute and unmute:
		 *
		 *     add_filter( 'sc_wpun_mute_capability', fn() => 'update_plugins' );
		 *
		 * @since 3.0.0
		 *
		 * @param string $capability Capability. Default is the one `sc_wpun_capability` returns.
		 */
		$capability = apply_filters( 'sc_wpun_mute_capability', $default );

		return is_string( $capability ) && '' !== $capability ? $capability : $default;
	}

	/**
	 * Whether the current user may mute and unmute.
	 *
	 * @return bool
	 */
	public static function can_mute(): bool {
		return current_user_can( self::capability() );
	}

	/**
	 * The installed plugins and themes, as name by key.
	 *
	 * @return array{plugin: array<string, string>, theme: array<string, string>}
	 */
	public function installed(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$items = array(
			'plugin' => array(),
			'theme'  => array(),
		);
		foreach ( get_plugins() as $file => $data ) {
			$items['plugin'][ (string) $file ] = '' !== (string) $data['Name'] ? (string) $data['Name'] : (string) $file;
		}
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$name                                   = (string) $theme->get( 'Name' );
			$items['theme'][ (string) $stylesheet ] = '' !== $name ? $name : (string) $stylesheet;
		}

		return $items;
	}

	/**
	 * Mute a plugin or theme and say what happened.
	 *
	 * @param string      $type     `plugin` or `theme`.
	 * @param string      $key      Plugin file or theme stylesheet.
	 * @param string      $source   Where the request came from, for the `sc_wpun_item_muted` action.
	 * @param bool        $skip     Whether to skip the waiting version instead of muting for good.
	 * @param string|null $expected The version the user saw when asking to skip it; null for any.
	 * @param int|null    $expires  When the mute ends, as a Unix timestamp (a snooze); null for no time limit.
	 *
	 * @return array{0: bool, 1: string} Whether it worked, and the text for the user.
	 */
	public function mute( string $type, string $key, string $source, bool $skip = false, ?string $expected = null, ?int $expires = null ): array {
		$name = $this->installed()[ $type ][ $key ] ?? null;
		if ( null === $name ) {
			return array( false, __( 'That plugin or theme is not installed.', 'wp-updates-notifier' ) );
		}

		$until = null;
		if ( $skip ) {
			$until = $this->store->offered_version( $type, $key );
			if ( null === $until ) {
				// translators: %s: Name of the plugin or theme.
				return array( false, sprintf( __( 'No update is waiting for %s, so there is no version to skip.', 'wp-updates-notifier' ), $name ) );
			}
			if ( null !== $expected && $expected !== $until ) {
				// translators: %s: Name of the plugin or theme.
				return array( false, sprintf( __( 'A newer version of %s is waiting than the one on the page. Reload the page and try again.', 'wp-updates-notifier' ), $name ) );
			}
		}

		$result = $this->store->mute( $type, $key, $until, $source, $expires );
		if ( is_wp_error( $result ) ) {
			return array( false, $result->get_error_message() );
		}

		if ( null !== $expires ) {
			$when = Settings_Page::format_site_time( $expires );
			if ( null === $until ) {
				// translators: 1: Name of the plugin or theme. 2: Date and time.
				return array( true, sprintf( __( 'Update notifications for %1$s are snoozed until %2$s.', 'wp-updates-notifier' ), $name, $when ) );
			}

			// translators: 1: Name of the plugin or theme. 2: Version that is skipped. 3: Date and time.
			return array( true, sprintf( __( 'Update notifications for %1$s version %2$s are skipped, until %3$s at the latest.', 'wp-updates-notifier' ), $name, $until, $when ) );
		}

		if ( null === $until ) {
			// translators: %s: Name of the plugin or theme.
			return array( true, sprintf( __( 'Update notifications for %s are muted.', 'wp-updates-notifier' ), $name ) );
		}

		// translators: 1: Name of the plugin or theme. 2: Version that is skipped.
		return array( true, sprintf( __( 'Update notifications for %1$s version %2$s are skipped.', 'wp-updates-notifier' ), $name, $until ) );
	}

	/**
	 * Unmute a plugin or theme and say what happened.
	 *
	 * @param string $type   Item type.
	 * @param string $key    Plugin file or theme stylesheet.
	 * @param string $source Where the request came from, for the `sc_wpun_item_unmuted` action.
	 *
	 * @return array{0: bool, 1: string} Whether it worked, and the text for the user.
	 */
	public function unmute( string $type, string $key, string $source ): array {
		$name   = $this->installed()[ $type ][ $key ] ?? $key;
		$result = $this->store->unmute( $type, $key, $source );
		if ( is_wp_error( $result ) ) {
			return array( false, $result->get_error_message() );
		}

		// translators: %s: Name of the plugin or theme.
		return array( true, sprintf( __( 'Update notifications for %s are on again.', 'wp-updates-notifier' ), $name ) );
	}

	/**
	 * Refuse the request with a 403.
	 *
	 * @return void
	 */
	public function deny(): void {
		wp_die( esc_html__( 'You are not allowed to do that.', 'wp-updates-notifier' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Go back to the screen the request came from, or to the fallback. A referrer on another site is not followed.
	 *
	 * @param string $fallback Where to go when there is no usable referrer.
	 *
	 * @return void
	 */
	public function go_back( string $fallback ): void {
		$referer = wp_get_referer();
		wp_safe_redirect( false !== $referer && '' !== $referer ? $referer : $fallback );
		exit;
	}
}
