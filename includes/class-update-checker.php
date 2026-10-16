<?php
/**
 * Checking for updates and notifying about them.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Looks for core, plugin and theme updates and sends a notification about new ones.
 *
 * @internal
 */
class Update_Checker {
	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Run state.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Notifier.
	 *
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * Channel instance storage, where the email settings are kept.
	 *
	 * @var Channel_Store
	 */
	private $channels;

	/**
	 * The notification built by the last check.
	 *
	 * @var Notification|null
	 */
	private $last_notification = null;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings.
	 * @param State         $state    Run state.
	 * @param Notifier      $notifier Notifier.
	 * @param Channel_Store $channels Channel instance storage.
	 */
	public function __construct( Settings $settings, State $state, Notifier $notifier, Channel_Store $channels ) {
		$this->settings = $settings;
		$this->state    = $state;
		$this->notifier = $notifier;
		$this->channels = $channels;
	}

	/**
	 * This is run by the cron. The update check checks the core always, the
	 * plugins and themes if asked. If updates found email notification sent.
	 *
	 * @return void
	 */
	public function do_update_check() {
		$options  = $this->settings->get(); // get settings.
		$state    = $this->state->get();
		$notified = $state['notified']; // What this run will record, if a notification goes out.
		$items    = array(); // What the check finds, in the order of the message.

		$core_updated = $this->core_update_check( $items, $notified ); // check the WP core for updates.
		if ( 0 !== $options['notify_plugins'] ) { // are we to check for plugin updates?
			$plugins_updated = $this->plugins_update_check( $items, $options['notify_plugins'], $notified ); // check for plugin updates.
		} else {
			$plugins_updated = false; // no plugin updates.
		}
		if ( 0 !== $options['notify_themes'] ) { // are we to check for theme updates?
			$themes_updated = $this->themes_update_check( $items, $options['notify_themes'], $notified ); // check for theme updates.
		} else {
			$themes_updated = false; // no theme updates.
		}

		$this->last_notification = new Notification( get_bloginfo(), home_url(), admin_url( 'update-core.php' ), $items, array( 'source' => 'cron' ) );

		if ( $core_updated || $plugins_updated || $themes_updated ) { // Did anything come back as need updating?
			$message = Legacy_Message::build( $this->last_notification );

			// Send email notification.
			$delivered = $this->is_enabled( 'email' ) && $this->notifier->send_email_message( $message );

			// Only remember what was announced if it reached someone, so a failed send is retried.
			if ( $delivered ) {
				$state['notified'] = $notified;
			}
		} else {
			$state['notified'] = $notified; // Nothing new: keep the forgetting of updated items.
		}

		$state['last_check_time'] = time();
		$this->state->save( $state );
	}


	/**
	 * Whether the instance exists and is switched on.
	 *
	 * @param string $id Instance id.
	 *
	 * @return bool
	 */
	private function is_enabled( string $id ): bool {
		$instance = $this->channels->get( $id );

		return null !== $instance && $instance->is_enabled();
	}

	/**
	 * The notification built by the most recent check, for tests.
	 *
	 * @internal
	 *
	 * @return Notification|null Null before the first check.
	 */
	public function last_notification(): ?Notification {
		return $this->last_notification;
	}

	/**
	 * Checks to see if any WP core updates
	 *
	 * @param Notification_Item[] $items    Collects the items to report.
	 * @param array               $notified What has been notified about; updated with what this check reports.
	 * @phpstan-param array{core: string, plugin: array<string, string>, theme: array<string, string>} $notified
	 *
	 * @return bool
	 */
	private function core_update_check( array &$items, array &$notified ): bool {
		do_action( 'wp_version_check' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
		$update_core = get_site_transient( 'update_core' ); // get information of updates.

		// No usable data (new site, api.wordpress.org unreachable): we know nothing,
		// so keep what was notified instead of forgetting it.
		if ( ! is_object( $update_core ) || empty( $update_core->updates ) || ! is_array( $update_core->updates ) ) {
			return false;
		}

		$new_core_ver = $this->core_upgrade_offer( $update_core->updates );
		if ( '' === $new_core_ver ) {
			$notified['core'] = ''; // no updates lets set this nothing.
			return false; // no updates return false.
		}

		if ( $new_core_ver === $notified['core'] ) { // have we already notified about this version?
			return false; // There are updates but we have already notified in the past.
		}

		$old_core_ver     = wp_get_wp_version(); // Read from version.php, so plugins that change the $wp_version global do not affect it.
		$items[]          = new Notification_Item(
			array(
				'type'            => 'core',
				'key'             => 'core',
				'name'            => 'WordPress',
				'current_version' => $old_core_ver,
				'new_version'     => $new_core_ver,
			)
		);
		$notified['core'] = $new_core_ver; // set core version we are notifying about.
		return true; // we have updates so return true.
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
	 * @return string The offered version, or an empty string if there is no upgrade.
	 */
	private function core_upgrade_offer( array $offers ): string {
		foreach ( $offers as $offer ) {
			if ( is_object( $offer ) && isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response && is_scalar( $offer->current ) && '' !== (string) $offer->current ) {
				return (string) $offer->current;
			}
		}

		return '';
	}


	/**
	 * Check to see if any plugin updates.
	 *
	 * @param Notification_Item[] $items        Collects the items to report.
	 * @param int                 $all_or_active Should we look for all plugins or just active ones.
	 * @param array               $notified      What has been notified about; updated with what this check reports.
	 * @phpstan-param array{core: string, plugin: array<string, string>, theme: array<string, string>} $notified
	 *
	 * @return bool
	 */
	private function plugins_update_check( array &$items, $all_or_active, array &$notified ): bool {
		do_action( 'wp_update_plugins' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
		$update_plugins = get_site_transient( 'update_plugins' ); // get information of updates.
		$pending        = $this->normalise_plugin_updates( is_object( $update_plugins ) && isset( $update_plugins->response ) ? $update_plugins->response : array() );

		// Forget plugins that are no longer waiting for an update (updated or deleted).
		$notified['plugin'] = array_intersect_key( $notified['plugin'], $pending );

		if ( ! empty( $pending ) ) { // any plugin updates available?
			$plugins_need_update = $pending; // plugins that need updating.
			if ( 2 === $all_or_active ) { // are we to check just active plugins?
				$plugins_need_update = array_intersect_key( $plugins_need_update, array_flip( $this->active_plugins() ) ); // only keep plugins that are active.
			}
			$plugins_need_update = $this->normalise_plugin_updates( apply_filters( 'sc_wpun_plugins_need_update', $plugins_need_update ) ); // additional filtering of plugins need update; filters may return anything.
			if ( count( $plugins_need_update ) >= 1 ) { // any plugins need updating after all the filtering gone on above?
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php'; // Not loaded in cron requests.
				}
				foreach ( $plugins_need_update as $key => $data ) { // loop through the plugins that need updating.
					$plugin_info = get_plugin_data( WP_PLUGIN_DIR . '/' . $key, false, false ); // get local plugin info.
					$wporg_slug  = $this->wporg_plugin_slug( $data );
					$links       = array();
					if ( '' !== $wporg_slug ) {
						$links['details']   = 'https://wordpress.org/plugins/' . $wporg_slug . '/';
						$links['changelog'] = 'https://wordpress.org/plugins/' . $wporg_slug . '/#developers';
					} elseif ( '' !== $data->url ) {
						$links['details'] = $data->url;
					}
					$items[]                             = new Notification_Item(
						array(
							'type'            => 'plugin',
							'key'             => (string) $key,
							'name'            => $plugin_info['Name'],
							'current_version' => $plugin_info['Version'],
							'new_version'     => $data->new_version,
							'links'           => $links,
							'notes'           => $this->plugin_compatibility_notes( $data ),
							'flags'           => $this->plugin_flags( $data ),
						)
					);
					$notified['plugin'][ (string) $key ] = (string) $data->new_version; // set plugin version we are notifying about.
				}
				return true; // we have plugin updates return true.
			}
		}
		return false; // No plugin updates so return false.
	}


	/**
	 * Check to see if any theme updates.
	 *
	 * @param Notification_Item[] $items        Collects the items to report.
	 * @param int                 $all_or_active Should we look for all themes or just active ones.
	 * @param array               $notified      What has been notified about; updated with what this check reports.
	 * @phpstan-param array{core: string, plugin: array<string, string>, theme: array<string, string>} $notified
	 *
	 * @return bool
	 */
	private function themes_update_check( array &$items, $all_or_active, array &$notified ): bool {
		do_action( 'wp_update_themes' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, run to refresh WordPress's update data.
		$update_themes = get_site_transient( 'update_themes' ); // get information of updates.
		$pending       = $this->normalise_theme_updates( is_object( $update_themes ) && isset( $update_themes->response ) ? $update_themes->response : array() );

		// Forget themes that are no longer waiting for an update (updated or deleted).
		$notified['theme'] = array_intersect_key( $notified['theme'], $pending );

		if ( ! empty( $pending ) ) { // any theme updates available?
			$themes_need_update = $pending; // themes that need updating.
			if ( 2 === $all_or_active ) { // are we to check just active themes?
				$active_themes      = array_flip( array_unique( array( get_stylesheet(), get_template() ) ) ); // The active theme and, for a child theme, its parent.
				$themes_need_update = array_intersect_key( $themes_need_update, $active_themes ); // only keep theme that is active.
			}
			$themes_need_update = $this->normalise_theme_updates( apply_filters( 'sc_wpun_themes_need_update', $themes_need_update ) ); // additional filtering of themes need update; filters may return anything.
			if ( count( $themes_need_update ) >= 1 ) { // any themes need updating after all the filtering gone on above?
				foreach ( $themes_need_update as $key => $data ) { // loop through the themes that need updating.
					$theme_info                         = wp_get_theme( $key ); // get theme info.
					$items[]                            = new Notification_Item(
						array(
							'type'            => 'theme',
							'key'             => (string) $key,
							'name'            => $theme_info['Name'],
							'current_version' => $theme_info['Version'],
							'new_version'     => $data['new_version'],
							'links'           => '' !== $data['url'] ? array( 'details' => $data['url'] ) : array(),
						)
					);
					$notified['theme'][ (string) $key ] = $data['new_version']; // set theme version we are notifying about.
				}
				return true; // we have theme updates return true.
			}
		}
		return false; // No theme updates so return false.
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
	 * @return array<string, \stdClass> Entries keyed by plugin file, each with string `new_version`, `url`, `slug` and `id`.
	 */
	public function normalise_plugin_updates( $response ): array {
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
			foreach ( array( 'url', 'slug', 'id' ) as $property ) {
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
	 * @return array<string, array{new_version: string, url: string}> Entries keyed by stylesheet.
	 */
	private function normalise_theme_updates( $response ): array {
		$updates = array();

		foreach ( is_array( $response ) ? $response : array() as $stylesheet => $entry ) {
			$entry = is_object( $entry ) ? get_object_vars( $entry ) : $entry;
			if ( ! is_array( $entry ) || ! isset( $entry['new_version'] ) || ! is_scalar( $entry['new_version'] ) || '' === (string) $entry['new_version'] ) {
				continue;
			}

			$entry['new_version'] = (string) $entry['new_version'];
			$entry['url']         = isset( $entry['url'] ) && is_scalar( $entry['url'] ) ? (string) $entry['url'] : '';

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
		$site_wp = $this->major_minor( wp_get_wp_version() );
		$tested  = isset( $entry->tested ) && is_scalar( $entry->tested ) ? (string) $entry->tested : '';
		$notes   = array();

		if ( '' === $tested ) {
			// translators: %s: WordPress version of the site, for example 6.5.
			$notes[] = sprintf( __( 'Compatibility with WordPress %s: unknown', 'wp-updates-notifier' ), $site_wp );
		} elseif ( version_compare( $this->major_minor( $tested ), $site_wp, '>=' ) ) {
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
	 * The major.minor part of a version, for example 6.5 from 6.5.3 or 6.6-RC1.
	 *
	 * @param string $version Version.
	 *
	 * @return string
	 */
	private function major_minor( string $version ): string {
		$parts = explode( '.', (string) preg_replace( '/-.*$/', '', $version ) );

		return implode( '.', array_slice( $parts, 0, 2 ) );
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

		return 1 === preg_match( '/^[a-z0-9-]+$/', $slug ) ? $slug : '';
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

		return array_values( array_unique( $active ) );
	}

	/**
	 * Filter for removing plugins from update list if already been notified about
	 *
	 * @param array<mixed> $plugins_need_update Array of plugins that need an update.
	 *
	 * @return array<mixed> $plugins_need_update
	 */
	public function check_plugins_against_notified( $plugins_need_update ) {
		if ( ! is_array( $plugins_need_update ) ) {
			return $plugins_need_update;
		}
		$notified = $this->state->get()['notified'];
		foreach ( $plugins_need_update as $key => $data ) { // loop through plugins that need update.
			if ( isset( $notified['plugin'][ $key ], $data->new_version ) ) { // has this plugin been notified before?
				if ( (string) $data->new_version === $notified['plugin'][ $key ] ) { // does this plugin version match that of the one that's been notified?
					unset( $plugins_need_update[ $key ] ); // don't notify this plugin as has already been notified.
				}
			}
		}
		return $plugins_need_update;
	}


	/**
	 * Filter for removing themes from update list if already been notified about
	 *
	 * @param array<mixed> $themes_need_update Array of themes that need an update.
	 *
	 * @return array<mixed> $themes_need_update
	 */
	public function check_themes_against_notified( $themes_need_update ) {
		if ( ! is_array( $themes_need_update ) ) {
			return $themes_need_update;
		}
		$notified = $this->state->get()['notified'];
		foreach ( $themes_need_update as $key => $data ) { // loop through themes that need update.
			if ( isset( $notified['theme'][ $key ], $data['new_version'] ) ) { // has this theme been notified before?
				if ( (string) $data['new_version'] === $notified['theme'][ $key ] ) { // does this theme version match that of the one that's been notified?
					unset( $themes_need_update[ $key ] ); // don't notify this theme as has already been notified.
				}
			}
		}
		return $themes_need_update;
	}
}
