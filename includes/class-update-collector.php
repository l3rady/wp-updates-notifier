<?php
/**
 * Collecting what is waiting for an update.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use stdClass;
use WP_Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Turns WordPress's update data, the plugin and theme headers and the network's actives into notification items.
 *
 * It reads only; it keeps no state and sends nothing. Make one per check: it remembers what it reads
 * (what is active across the network) for the length of that check.
 *
 * @internal
 */
class Update_Collector {
	/**
	 * Tells whether WordPress is set to install an update by itself.
	 *
	 * @var Auto_Update_Policy
	 */
	private $auto_updates;

	/**
	 * Where the plugin runs: one site or a whole network.
	 *
	 * @var Network_Context
	 */
	private $network_context;

	/**
	 * What is active across the network, read once: false until read, null for "everything installed".
	 *
	 * @var array{plugins: string[], themes: string[]}|null|false
	 */
	private $network_actives = false;

	/**
	 * Whether items get a download link, from the settings of the check.
	 *
	 * @var bool
	 */
	private $with_download_links = false;

	/**
	 * Constructor.
	 *
	 * @param Auto_Update_Policy $auto_updates    Tells whether WordPress is set to install an update by itself.
	 * @param Network_Context    $network_context Where the plugin runs.
	 */
	public function __construct( Auto_Update_Policy $auto_updates, Network_Context $network_context ) {
		$this->auto_updates    = $auto_updates;
		$this->network_context = $network_context;
	}

	/**
	 * Collects what is waiting for an update: core, plugins and themes, as far as the settings ask.
	 *
	 * @param array<string, mixed> $options The settings: `notify_core`, `notify_plugins`, `notify_themes` and `download_links`.
	 *
	 * @return array{items: Notification_Item[], checked: array<string, string[]>} The items in the order of the message, and the
	 *                                                                          keys still waiting by item type. A type is absent
	 *                                                                          when it was not looked at, empty when nothing is waiting.
	 */
	public function collect( array $options ): array {
		$items   = array();
		$checked = array();

		$this->with_download_links = (bool) $options['download_links'];

		if ( 'none' !== $options['notify_core'] ) { // are we to check for WP core updates?
			$this->core_update_check( $items, $options['notify_core'], $checked ); // check the WP core for updates.
		}
		if ( 'none' !== $options['notify_plugins'] ) { // are we to check for plugin updates?
			$this->plugins_update_check( $items, $options['notify_plugins'], $checked ); // check for plugin updates.
		}
		if ( 'none' !== $options['notify_themes'] ) { // are we to check for theme updates?
			$this->themes_update_check( $items, $options['notify_themes'], $checked ); // check for theme updates.
		}

		return array(
			'items'   => $items,
			'checked' => $checked,
		);
	}

	/**
	 * The name and version of what is installed, by item key.
	 *
	 * @param string $type Item type: `core`, `plugin` or `theme`.
	 *
	 * @return array<string, array{name: string, version: string}>
	 */
	public function installed( string $type ): array {
		$installed = array();
		if ( 'core' === $type ) {
			$installed['core'] = array(
				'name'    => 'WordPress',
				'version' => wp_get_wp_version(),
			);
		} elseif ( 'plugin' === $type ) {
			foreach ( $this->installed_plugins() as $file => $data ) {
				$installed[ (string) $file ] = array(
					'name'    => (string) $data['Name'],
					'version' => (string) $data['Version'],
				);
			}
		} else {
			foreach ( $this->installed_themes() as $stylesheet => $theme ) {
				$installed[ (string) $stylesheet ] = array(
					'name'    => (string) $theme->get( 'Name' ),
					'version' => (string) $theme->get( 'Version' ),
				);
			}
		}

		return $installed;
	}

	/**
	 * The installed plugins, with the headers core read once.
	 *
	 * @return array<string, array<string, mixed>> Plugin file => header, as get_plugins() returns it.
	 */
	private function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php'; // Not loaded in cron requests.
		}

		return get_plugins();
	}

	/**
	 * The installed themes, as wp_get_themes() loaded them.
	 *
	 * @return array<string, WP_Theme> Theme stylesheet => theme.
	 */
	private function installed_themes(): array {
		return wp_get_themes( array( 'errors' => null ) );
	}

	/**
	 * Checks to see if any WP core updates
	 *
	 * @param Notification_Item[]          $items   Collects the items to report.
	 * @param string                       $setting The notify_core setting: `all` or `major`.
	 * @param array<string, array<string>> $checked Collects the keys still waiting, by item type.
	 *
	 * @return void
	 */
	private function core_update_check( array &$items, string $setting, array &$checked ): void {
		$update_core = get_site_transient( 'update_core' ); // get information of updates.

		// No usable data (new site, api.wordpress.org unreachable): we know nothing,
		// so keep what was notified instead of forgetting it.
		if ( ! is_object( $update_core ) || empty( $update_core->updates ) || ! is_array( $update_core->updates ) ) {
			return;
		}

		$found = $this->core_upgrade_offer( $update_core->updates );
		if ( null === $found ) {
			$checked['core'] = array(); // No update is waiting, so nothing about core stays notified.
			return;
		}

		$offer           = $found['offer'];
		$new_core_ver    = $found['version'];
		$checked['core'] = array( 'core' );
		$current_version = wp_get_wp_version(); // Read from version.php, so plugins that change the $wp_version global do not affect it.

		/**
		 * Filters whether a WordPress core update is reported, like the plugin and theme filters do for theirs.
		 *
		 * The default follows the "Notify about WordPress updates?" setting: true, or with "only major
		 * releases" whether the update is a major.minor change. Returning false reports no core item.
		 * What was already notified about core is kept while the update is waiting.
		 *
		 * Example, ignore release candidates:
		 *
		 *     add_filter(
		 *         'sc_wpun_core_needs_update',
		 *         function ( $needs, $new_version ) {
		 *             return $needs && false === strpos( $new_version, '-RC' );
		 *         },
		 *         10,
		 *         2
		 *     );
		 *
		 * @since 2.0.0
		 *
		 * @param bool   $needs           Whether to report the update. Default true, or whether it is a major release with "only major releases".
		 * @param string $new_version     The version WordPress offers.
		 * @param string $current_version The installed version.
		 */
		$needs = 'major' !== $setting || Routing::is_major_core_update( $current_version, $new_core_ver );
		if ( ! apply_filters( 'sc_wpun_core_needs_update', $needs, $new_core_ver, $current_version ) ) {
			return;
		}

		$auto_update = $this->auto_updates->is_on( 'core', 'core', $offer, $new_core_ver );
		$items[]     = new Notification_Item(
			array(
				'type'            => 'core',
				'key'             => 'core',
				'name'            => 'WordPress',
				'current_version' => $current_version,
				'new_version'     => $new_core_ver,
				'links'           => $this->download_links( 'core', 'core', $this->core_package( $offer ) ),
				'notes'           => $this->auto_update_notes( $auto_update ),
				'flags'           => array( 'auto_update' => $auto_update ),
			)
		);
	}

	/**
	 * The version of the first core update offer that is an upgrade.
	 *
	 * WordPress lists several offers (the latest release, other locales,
	 * automatic update packages); only one with the "upgrade" response is
	 * something to tell the site owner about.
	 *
	 * @param array<mixed> $offers The `updates` list of the update_core transient.
	 *
	 * @return array{offer: object, version: string}|null The offer and its version, or null if there is no upgrade.
	 */
	private function core_upgrade_offer( array $offers ): ?array {
		foreach ( $offers as $offer ) {
			if ( is_object( $offer ) && isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response && is_scalar( $offer->current ) && '' !== (string) $offer->current ) {
				return array(
					'offer'   => $offer,
					'version' => (string) $offer->current,
				);
			}
		}

		return null;
	}

	/**
	 * The package address of a core update offer.
	 *
	 * @param object $offer The offer from the update_core transient.
	 *
	 * @return string The `download` address, else the `full` package, else an empty string.
	 */
	private function core_package( object $offer ): string {
		if ( isset( $offer->download ) && is_scalar( $offer->download ) && '' !== (string) $offer->download ) {
			return (string) $offer->download;
		}

		$packages = $offer->packages ?? null;
		if ( is_object( $packages ) ) {
			$packages = get_object_vars( $packages );
		}
		if ( is_array( $packages ) && isset( $packages['full'] ) && is_scalar( $packages['full'] ) ) {
			return (string) $packages['full'];
		}

		return '';
	}

	/**
	 * The download link of an item, if the setting is on and there is an address.
	 *
	 * @param string $type    Item type: `plugin`, `theme` or `core`.
	 * @param string $key     Plugin file, theme stylesheet or `core`.
	 * @param string $package The package address from the update data.
	 *
	 * @return array<string, string> The `download` link, or nothing.
	 */
	private function download_links( string $type, string $key, string $package ): array {
		if ( ! $this->with_download_links || '' === $package ) {
			return array();
		}

		/**
		 * Filters the address of a download link before it is added to an item, when download links are on.
		 *
		 * Not run when the "Download links" setting is off. Return an empty string to leave the link out.
		 * The address comes from the update data and can hold a licence key or a private token.
		 *
		 * Example, keep only downloads from WordPress.org:
		 *
		 *     add_filter(
		 *         'sc_wpun_item_download_url',
		 *         function ( $url ) {
		 *             return 0 === strpos( $url, 'https://downloads.wordpress.org/' ) ? $url : '';
		 *         }
		 *     );
		 *
		 * @since 3.0.0
		 *
		 * @param string $url  The package address from the update data.
		 * @param string $type Item type: `plugin`, `theme` or `core`.
		 * @param string $key  Plugin file, theme stylesheet or `core`.
		 */
		$url = apply_filters( 'sc_wpun_item_download_url', $package, $type, $key );
		$url = is_scalar( $url ) ? (string) $url : '';

		return '' === $url ? array() : array( 'download' => $url );
	}

	/**
	 * The note of an update that WordPress is set to install by itself.
	 *
	 * @param bool $auto_update Whether automatic updates are on for the update.
	 *
	 * @return string[] The note, or nothing.
	 */
	private function auto_update_notes( bool $auto_update ): array {
		return $auto_update ? array( __( 'Automatic updates are on, but WordPress has not installed this update yet.', 'wp-updates-notifier' ) ) : array();
	}


	/**
	 * Check to see if any plugin updates.
	 *
	 * @param Notification_Item[]          $items         Collects the items to report.
	 * @param string                       $all_or_active `all` to look at all plugins or `active` for just the active ones.
	 * @param array<string, array<string>> $checked       Collects the keys still waiting, by item type.
	 *
	 * @return void
	 */
	private function plugins_update_check( array &$items, string $all_or_active, array &$checked ): void {
		do_action( 'wp_update_plugins' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
		$update_plugins = get_site_transient( 'update_plugins' ); // get information of updates.
		$pending        = self::normalise_plugin_updates( is_object( $update_plugins ) && isset( $update_plugins->response ) ? $update_plugins->response : array() );

		// Everything still waiting, before any filtering, so the instances can forget plugins that were updated or deleted.
		$checked['plugin'] = array_map( 'strval', array_keys( $pending ) );

		if ( empty( $pending ) ) { // no plugin updates available.
			return;
		}

		$plugins_need_update = $pending; // plugins that need updating.
		if ( 'active' === $all_or_active ) { // are we to check just active plugins?
			$plugins_need_update = array_intersect_key( $plugins_need_update, array_flip( $this->active_plugins() ) ); // only keep plugins that are active.
		}
		/**
		 * Filters the plugins that are waiting for an update and will be reported.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, object> $plugins_need_update Update data from WordPress by plugin file (for example `hello.php`).
		 */
		$plugins_need_update = self::normalise_plugin_updates( apply_filters( 'sc_wpun_plugins_need_update', $plugins_need_update ) ); // additional filtering of plugins need update; filters may return anything.
		$installed           = $this->installed_plugins();
		$plugins_need_update = array_intersect_key( $plugins_need_update, $installed ); // Only plugins that are installed, whatever the transient or a filter holds: no path is built from the key.
		if ( empty( $plugins_need_update ) ) { // nothing needs updating after all the filtering gone on above.
			return;
		}

		$active_plugins = $this->active_plugins();
		foreach ( $plugins_need_update as $key => $data ) { // loop through the plugins that need updating.
			$plugin_info = $installed[ $key ]; // The header core read once for get_plugins().
			$wporg_slug  = $this->wporg_plugin_slug( $data );
			$links       = array();
			if ( '' !== $wporg_slug ) {
				$links['details']   = 'https://wordpress.org/plugins/' . $wporg_slug . '/';
				$links['changelog'] = 'https://wordpress.org/plugins/' . $wporg_slug . '/#developers';
			} elseif ( '' !== $data->url ) {
				$links['details'] = $data->url;
			}
			$links += $this->download_links( 'plugin', (string) $key, $data->package );

			$auto_update = $this->auto_updates->is_on( 'plugin', (string) $key, $data, $data->new_version );
			$items[]     = new Notification_Item(
				array(
					'type'            => 'plugin',
					'key'             => (string) $key,
					'name'            => $plugin_info['Name'],
					'current_version' => $plugin_info['Version'],
					'new_version'     => $data->new_version,
					'links'           => $links,
					'notes'           => array_merge( $this->plugin_compatibility_notes( $data ), $this->auto_update_notes( $auto_update ) ),
					'flags'           => array_merge(
						$this->plugin_flags( $data ),
						array(
							'active'      => in_array( (string) $key, $active_plugins, true ),
							'auto_update' => $auto_update,
						)
					),
				)
			);
		}
	}

	/**
	 * Check to see if any theme updates.
	 *
	 * @param Notification_Item[]          $items         Collects the items to report.
	 * @param string                       $all_or_active `all` to look at all themes or `active` for just the active ones.
	 * @param array<string, array<string>> $checked       Collects the keys still waiting, by item type.
	 *
	 * @return void
	 */
	private function themes_update_check( array &$items, string $all_or_active, array &$checked ): void {
		do_action( 'wp_update_themes' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
		$update_themes = get_site_transient( 'update_themes' ); // get information of updates.
		$pending       = self::normalise_theme_updates( is_object( $update_themes ) && isset( $update_themes->response ) ? $update_themes->response : array() );

		// Everything still waiting, before any filtering, so the instances can forget themes that were updated or deleted.
		$checked['theme'] = array_map( 'strval', array_keys( $pending ) );

		if ( empty( $pending ) ) { // no theme updates available.
			return;
		}

		$themes_need_update = $pending; // themes that need updating.
		$active_themes      = $this->active_themes(); // The active theme and, for a child theme, its parent.
		if ( 'active' === $all_or_active ) { // are we to check just active themes?
			$themes_need_update = array_intersect_key( $themes_need_update, array_flip( $active_themes ) ); // only keep theme that is active.
		}
		/**
		 * Filters the themes that are waiting for an update and will be reported.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, mixed>> $themes_need_update Update data from WordPress by theme stylesheet.
		 */
		$themes_need_update = self::normalise_theme_updates( apply_filters( 'sc_wpun_themes_need_update', $themes_need_update ) ); // additional filtering of themes need update; filters may return anything.
		$installed          = $this->installed_themes();
		$themes_need_update = array_intersect_key( $themes_need_update, $installed ); // Only themes that are installed, whatever the transient or a filter holds.
		if ( empty( $themes_need_update ) ) { // nothing needs updating after all the filtering gone on above.
			return;
		}

		foreach ( $themes_need_update as $key => $data ) { // loop through the themes that need updating.
			$theme_info  = $installed[ $key ]; // The theme wp_get_themes() already loaded.
			$auto_update = $this->auto_updates->is_on( 'theme', (string) $key, (object) $data, $data['new_version'] );
			$items[]     = new Notification_Item(
				array(
					'type'            => 'theme',
					'key'             => (string) $key,
					'name'            => $theme_info['Name'],
					'current_version' => $theme_info['Version'],
					'new_version'     => $data['new_version'],
					'links'           => ( '' !== $data['url'] ? array( 'details' => $data['url'] ) : array() ) + $this->download_links( 'theme', (string) $key, $data['package'] ),
					'notes'           => $this->auto_update_notes( $auto_update ),
					'flags'           => array(
						'active'      => in_array( (string) $key, $active_themes, true ),
						'auto_update' => $auto_update,
					),
				)
			);
		}
	}

	/**
	 * Plugin update entries as objects with the properties the plugin reads.
	 *
	 * Updaters for plugins outside WordPress.org add their own entries to the
	 * update_plugins transient, sometimes as arrays, often without `url` or
	 * `slug`. Entries without a new version are dropped: there is nothing to
	 * report about them.
	 *
	 * @param mixed $response The transient's `response`.
	 *
	 * @return array<string, \stdClass> Entries keyed by plugin file, each with string `new_version`, `url`, `slug`, `id` and `package`.
	 */
	public static function normalise_plugin_updates( $response ): array {
		$updates = array();

		foreach ( is_array( $response ) ? $response : array() as $file => $entry ) {
			if ( is_array( $entry ) ) {
				$entry = (object) $entry;
			} elseif ( is_object( $entry ) && ! $entry instanceof stdClass ) {
				$entry = (object) get_object_vars( $entry );
			}
			if ( ! $entry instanceof stdClass || ! isset( $entry->new_version ) || ! is_scalar( $entry->new_version ) || '' === (string) $entry->new_version ) {
				continue;
			}

			$entry->new_version = (string) $entry->new_version;
			foreach ( array( 'url', 'slug', 'id', 'package' ) as $property ) {
				$entry->$property = isset( $entry->$property ) && is_scalar( $entry->$property ) ? (string) $entry->$property : '';
			}

			$updates[ (string) $file ] = $entry;
		}

		return $updates;
	}

	/**
	 * Theme update entries as arrays with the keys the plugin reads.
	 *
	 * @param mixed $response The update_themes transient's `response`.
	 *
	 * @return array<string, array{new_version: string, url: string, package: string}> Entries keyed by stylesheet.
	 */
	public static function normalise_theme_updates( $response ): array {
		$updates = array();

		foreach ( is_array( $response ) ? $response : array() as $stylesheet => $entry ) {
			$entry = is_object( $entry ) ? get_object_vars( $entry ) : $entry;
			if ( ! is_array( $entry ) || ! isset( $entry['new_version'] ) || ! is_scalar( $entry['new_version'] ) || '' === (string) $entry['new_version'] ) {
				continue;
			}

			$entry['new_version'] = (string) $entry['new_version'];
			$entry['url']         = isset( $entry['url'] ) && is_scalar( $entry['url'] ) ? (string) $entry['url'] : '';
			$entry['package']     = isset( $entry['package'] ) && is_scalar( $entry['package'] ) ? (string) $entry['package'] : '';

			$updates[ (string) $stylesheet ] = $entry;
		}

		return $updates;
	}

	/**
	 * The facts the compatibility notes of a plugin update are drawn from.
	 *
	 * @param \stdClass $entry A normalised plugin update entry.
	 *
	 * @return array<string, string> `tested`, `requires_php` and `requires_wp`, each only if the entry has it.
	 */
	private function plugin_flags( stdClass $entry ): array {
		$flags = array();

		foreach ( array(
			'tested'       => 'tested',
			'requires_php' => 'requires_php',
			'requires'     => 'requires_wp',
		) as $property => $flag ) {
			if ( isset( $entry->$property ) && is_scalar( $entry->$property ) && '' !== (string) $entry->$property ) {
				$flags[ $flag ] = (string) $entry->$property;
			}
		}

		return $flags;
	}

	/**
	 * Lines about whether a plugin update suits this site, from the update data.
	 *
	 * WordPress.org puts `tested`, `requires` and `requires_php` in each entry of
	 * the update_plugins transient, so no request per plugin is needed. "Tested
	 * up to 6.5" covers every 6.5.x release, so only major.minor is compared.
	 *
	 * @param \stdClass $entry A normalised plugin update entry.
	 *
	 * @return string[]
	 */
	private function plugin_compatibility_notes( stdClass $entry ): array {
		$site_wp = Routing::major_minor( wp_get_wp_version() );
		$tested  = isset( $entry->tested ) && is_scalar( $entry->tested ) ? (string) $entry->tested : '';
		$notes   = array();

		if ( '' === $tested ) {
			// translators: %s: WordPress version of the site, for example 6.5.
			$notes[] = sprintf( __( 'Compatibility with WordPress %s: unknown', 'wp-updates-notifier' ), $site_wp );
		} elseif ( version_compare( Routing::major_minor( $tested ), $site_wp, '>=' ) ) {
			// translators: %s: WordPress version of the site, for example 6.5.
			$notes[] = sprintf( __( 'Compatibility with WordPress %s: tested by its author', 'wp-updates-notifier' ), $site_wp );
		} else {
			// translators: 1: WordPress version of the site. 2: Latest WordPress version the plugin was tested with.
			$notes[] = sprintf( __( 'Compatibility with WordPress %1$s: not tested yet (tested up to %2$s)', 'wp-updates-notifier' ), $site_wp, $tested );
		}

		$requires_php = isset( $entry->requires_php ) && is_scalar( $entry->requires_php ) ? (string) $entry->requires_php : '';
		if ( '' !== $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) {
			// translators: 1: PHP version the update needs. 2: PHP version of the site.
			$notes[] = sprintf( __( 'Warning: this version needs PHP %1$s; the site runs PHP %2$s.', 'wp-updates-notifier' ), $requires_php, PHP_VERSION );
		}

		$requires_wp = isset( $entry->requires ) && is_scalar( $entry->requires ) ? (string) $entry->requires : '';
		if ( '' !== $requires_wp && version_compare( wp_get_wp_version(), $requires_wp, '<' ) ) {
			// translators: 1: WordPress version the update needs. 2: WordPress version of the site.
			$notes[] = sprintf( __( 'Warning: this version needs WordPress %1$s; the site runs WordPress %2$s.', 'wp-updates-notifier' ), $requires_wp, wp_get_wp_version() );
		}

		return $notes;
	}

	/**
	 * The WordPress.org slug of a plugin update, or an empty string if it does not come from WordPress.org.
	 *
	 * WordPress.org entries carry an `id` like "w.org/plugins/akismet" and a URL
	 * under https://wordpress.org/plugins/; updaters for other plugins do not.
	 *
	 * @param \stdClass $entry A normalised plugin update entry.
	 *
	 * @return string
	 */
	private function wporg_plugin_slug( stdClass $entry ): string {
		$id         = (string) $entry->id;
		$slug       = (string) $entry->slug;
		$from_wporg = 0 === strpos( $id, 'w.org/plugins/' )
			|| 1 === preg_match( '#^https?://(www\.)?WordPress\.org/plugins/#', (string) $entry->url );

		if ( ! $from_wporg ) {
			return '';
		}

		$slug = '' !== $slug ? $slug : basename( $id );

		return 1 === preg_match( '/^[a-z0-9-]+$/D', $slug ) ? $slug : '';
	}

	/**
	 * Plugins active on this site, including those network-activated on multisite.
	 *
	 * @return string[] Plugin files relative to the plugins directory.
	 */
	private function active_plugins(): array {
		$active = array_filter( (array) get_option( 'active_plugins', array() ), 'is_string' );

		if ( is_multisite() ) {
			$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$active  = array_merge( $active, array_filter( $network, 'is_string' ) );
		}

		// Network mode: active on any site of the network counts.
		if ( $this->network_context->is_network() ) {
			$network = $this->network_actives();
			if ( null === $network ) {
				return array_map( 'strval', array_keys( $this->installed_plugins() ) ); // Too many sites to read: every installed plugin counts.
			}
			$active = array_merge( $active, $network['plugins'] );
		}

		return array_values( array_unique( $active ) );
	}

	/**
	 * Themes in use: the current site's, or every site's in network mode, each with its parent theme.
	 *
	 * @return string[] Theme stylesheets.
	 */
	private function active_themes(): array {
		if ( ! $this->network_context->is_network() ) {
			return array_unique( array( get_stylesheet(), get_template() ) );
		}

		$network = $this->network_actives();

		// Too many sites to read: every installed theme counts.
		return null === $network ? array_map( 'strval', array_keys( $this->installed_themes() ) ) : $network['themes'];
	}

	/**
	 * What is active across the network, read on first use in a check.
	 *
	 * @return array{plugins: string[], themes: string[]}|null Null when everything installed counts as active.
	 */
	private function network_actives(): ?array {
		if ( false === $this->network_actives ) {
			$this->network_actives = ( new Network_Actives() )->collect();
		}

		return $this->network_actives;
	}
}
