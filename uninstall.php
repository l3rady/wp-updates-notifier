<?php
/**
 * Uninstall function for plugin.
 *
 * This file is read by WordPress to generate the plugin information in the
 * admin panel.
 *
 * @link    https://github.com/l3rady/wp-updates-notifier
 * @since   1.5.0
 * @package SC_WP_Updates_Notifier
 */

/**
Copyright 2015-2026 Scott Cariss (email:scott@cariss.dev)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

@package
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

/**
 * Remove everything the plugin stored for the current site.
 */
$sc_wpun_options = array( 'sc_wpun_settings', 'sc_wpun_settings_ver', 'sc_wpun_state', 'sc_wpun_channels', 'sc_wpun_muted' );

$sc_wpun_uninstall_site = static function () use ( $sc_wpun_options ) {
	foreach ( $sc_wpun_options as $sc_wpun_option ) {
		delete_option( $sc_wpun_option );
	}
	delete_transient( 'sc_wpun_send_test_email' );
	delete_transient( 'sc_wpun_send_test_slack' );

	// Normally gone already on deactivation, but not if the files were removed or
	// replaced without one.
	wp_clear_scheduled_hook( 'sc_wpun_update_check' );
};

// User meta is shared by the whole network, so it is removed once.
delete_metadata( 'user', 0, 'sc_wpun_dismissed_slack_notice', '', true );
delete_metadata( 'user', 0, 'sc_wpun_notice', '', true );
delete_metadata( 'user', 0, 'sc_wpun_channel_form', '', true );

if ( is_multisite() ) {
	// Network-activated, the options are the network's: delete them for every network, then the sites' own below.
	$sc_wpun_network_ids = (array) get_networks(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $sc_wpun_network_ids as $sc_wpun_network_id ) {
		foreach ( $sc_wpun_options as $sc_wpun_option ) {
			delete_network_option( $sc_wpun_network_id, $sc_wpun_option );
		}
	}

	// Settings, state and the event are kept per site, so clean every site.
	// A page at a time, so a large network does not load every site id at once.
	$sc_wpun_batch  = 100;
	$sc_wpun_offset = 0;
	do {
		$sc_wpun_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $sc_wpun_batch,
				'offset' => $sc_wpun_offset,
			)
		);
		foreach ( $sc_wpun_site_ids as $sc_wpun_site_id ) {
			switch_to_blog( $sc_wpun_site_id );
			$sc_wpun_uninstall_site();
			restore_current_blog();
		}
		$sc_wpun_found   = count( $sc_wpun_site_ids );
		$sc_wpun_offset += $sc_wpun_batch;
	} while ( $sc_wpun_found === $sc_wpun_batch );
} else {
	$sc_wpun_uninstall_site();
}
