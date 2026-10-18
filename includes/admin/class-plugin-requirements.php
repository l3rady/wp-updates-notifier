<?php
/**
 * The API requirement of add-ons, shown on the Plugins screen.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Plugin;
use WPUN\Version_Constraint;

/**
 * Adds a notice below each plugin whose `Requires WPUN API` header this site's API version does not satisfy,
 * or cannot be read, in the style of core's "requires a newer version of PHP" rows.
 *
 * It only reads the header data the plugins list already has: no remote calls. It does not block activation:
 * the `activate_plugin` hooks run too late to cancel cleanly, so the notice, the registry check of a channel's
 * `get_api_constraint()` and the add-on's own boot check do that work.
 *
 * @internal
 */
class Plugin_Requirements {
	/**
	 * Print the notice row for a plugin, when it has one.
	 *
	 * Hooked on `after_plugin_row`, so every installed plugin is covered without a hook per file.
	 *
	 * @param string $plugin_file Path to the plugin file relative to the plugins directory.
	 * @param mixed  $plugin_data The plugin's header data.
	 * @return void
	 */
	public function render_row( $plugin_file, $plugin_data ): void {
		if ( ! is_array( $plugin_data ) || plugin_basename( SC_WPUN_FILE ) === $plugin_file ) {
			return;
		}

		$constraint = $plugin_data[ Plugin::API_HEADER ] ?? '';
		$constraint = is_string( $constraint ) ? trim( $constraint ) : '';
		if ( '' === $constraint ) {
			return;
		}

		// Check that it can be read first: an unreadable constraint must not reach satisfies(), which would notify.
		if ( ! Version_Constraint::is_valid( $constraint ) ) {
			$message = sprintf(
				/* translators: %s: the API requirement an add-on declared, as written in its plugin header. */
				__( 'This add-on\'s WP Updates Notifier API requirement (%s) could not be read.', 'wp-updates-notifier' ),
				$constraint
			);
		} elseif ( ! Version_Constraint::satisfies( sc_wpun_api_version(), $constraint ) ) {
			$message = sprintf(
				/* translators: 1: the API version an add-on needs, as a constraint such as ^2.0. 2: the API version of this site, such as 1.0.0. */
				__( 'This add-on needs the WP Updates Notifier API %1$s. This site has %2$s. Update WP Updates Notifier.', 'wp-updates-notifier' ),
				$constraint,
				sc_wpun_api_version()
			);
		} else {
			return;
		}

		$this->print_row( $plugin_file, $message );
	}

	/**
	 * Print the row.
	 *
	 * @param string $plugin_file Path to the plugin file relative to the plugins directory.
	 * @param string $message     The plain text message.
	 * @return void
	 */
	private function print_row( string $plugin_file, string $message ): void {
		$is_active = is_network_admin() ? is_plugin_active_for_network( $plugin_file ) : is_plugin_active( $plugin_file );
		$slug      = dirname( $plugin_file );
		if ( '.' === $slug ) {
			$slug = basename( $plugin_file, '.php' );
		}

		printf(
			'<tr class="plugin-update-tr %1$s" id="%2$s" data-slug="%3$s" data-plugin="%4$s"><td colspan="%5$d" class="plugin-update colspanchange"><div class="update-message notice inline notice-error notice-alt"><p>%6$s</p></div></td></tr>',
			$is_active ? 'active' : 'inactive',
			esc_attr( sanitize_html_class( $slug ) . '-wpun-api' ),
			esc_attr( $slug ),
			esc_attr( $plugin_file ),
			(int) $this->column_count(),
			esc_html( $message )
		);
	}

	/**
	 * The number of columns of the plugins list, so the row spans the table.
	 *
	 * @return int
	 */
	private function column_count(): int {
		$table = $GLOBALS['wp_list_table'] ?? null;
		if ( ! $table instanceof \WP_Plugins_List_Table && function_exists( '_get_list_table' ) ) {
			$table = _get_list_table( 'WP_Plugins_List_Table' );
		}

		return $table instanceof \WP_List_Table ? max( 1, $table->get_column_count() ) : 4;
	}
}
