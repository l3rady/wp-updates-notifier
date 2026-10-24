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
$sc_wpun_uninstall_site = static function () {
	delete_option( 'sc_wpun_settings' );
	delete_option( 'sc_wpun_settings_ver' );
	delete_option( 'sc_wpun_state' );
	delete_option( 'sc_wpun_channels' );
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
	// Settings, state and the event are kept per site, so clean every site.
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $sc_wpun_site_id ) {
		switch_to_blog( $sc_wpun_site_id );
		$sc_wpun_uninstall_site();
		restore_current_blog();
	}
} else {
	$sc_wpun_uninstall_site();
}
