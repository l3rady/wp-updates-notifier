<?php
/**
 * What is active across the sites of a network, read cheaply.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Collects the plugins and themes active on any site of a network, for "active only" in network mode.
 *
 * Reading them with get_blog_option() would switch to every site and load all of its autoloaded options,
 * which then stay in memory for the rest of the request: on a large network that can take the update check
 * past the memory limit. This reads only the three options it needs, straight from each site's options
 * table, a batch of sites per query. Filters on those options (`option_active_plugins` and the like) are not applied.
 *
 * @internal
 */
class Network_Actives {
	/**
	 * Sites read per query.
	 */
	const BATCH = 100;

	/**
	 * Active plugins and themes of every site of the current network, or null on a large network or when a site cannot be read.
	 *
	 * On a network WordPress counts as large (`wp_is_large_network()`, 10,000 sites by default) reading
	 * every site costs too much, so the caller treats everything installed as active instead: it may
	 * report more than strictly needed, but the check finishes.
	 *
	 * @return array{plugins: string[], themes: string[]}|null Plugin files and theme stylesheets (with their parents).
	 */
	public function collect(): ?array {
		if ( wp_is_large_network( 'sites', get_current_network_id() ) ) {
			return null;
		}

		$plugins = array_filter( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ), 'is_string' );
		$themes  = array();
		$offset  = 0;
		do {
			$ids  = get_sites(
				array(
					'fields'     => 'ids',
					'number'     => self::BATCH,
					'offset'     => $offset,
					'network_id' => get_current_network_id(),
					'deleted'    => 0,
				)
			);
			$rows = $this->read( array_map( 'intval', (array) $ids ) );
			if ( null === $rows ) {
				return null; // A site's table could not be read: report too much rather than miss an update.
			}
			foreach ( $rows as $row ) {
				if ( 'active_plugins' === $row['option_name'] ) {
					$plugins = array_merge( $plugins, $this->plugin_files( $row['option_value'] ) );
				} else {
					$themes[] = (string) $row['option_value'];
				}
			}
			$found   = count( (array) $ids );
			$offset += self::BATCH;
		} while ( self::BATCH === $found );

		return array(
			'plugins' => array_values( array_unique( $plugins ) ),
			'themes'  => array_values( array_unique( array_filter( $themes ) ) ),
		);
	}

	/**
	 * The plugin files in a site's stored `active_plugins`, a serialized array of strings.
	 *
	 * Read without unserializing, which could build objects from the data: each entry is a serialized string,
	 * `s:<length>:"<file>";`, and a plugin file never holds a double quote.
	 *
	 * @param string $stored The option as stored.
	 *
	 * @return string[]
	 */
	private function plugin_files( string $stored ): array {
		return preg_match_all( '/s:\d+:"([^"]+)";/', $stored, $matches ) ? $matches[1] : array();
	}

	/**
	 * The three options of each site, in one query.
	 *
	 * @param int[] $site_ids Site ids.
	 *
	 * @return array<int, array{option_name: string, option_value: string}>|null Rows, or null when the query failed.
	 */
	private function read( array $site_ids ): ?array {
		global $wpdb;

		if ( empty( $site_ids ) ) {
			return array();
		}

		$select = "SELECT option_name, option_value FROM %i WHERE option_name IN ('active_plugins', 'stylesheet', 'template')";
		$tables = array();
		foreach ( $site_ids as $site_id ) {
			$tables[] = $wpdb->get_blog_prefix( $site_id ) . 'options';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One %i per table, read once per check.
		$rows = $wpdb->get_results( $wpdb->prepare( implode( ' UNION ALL ', array_fill( 0, count( $tables ), $select ) ), ...$tables ), ARRAY_A );

		return is_array( $rows ) && '' === $wpdb->last_error ? $rows : null;
	}
}
