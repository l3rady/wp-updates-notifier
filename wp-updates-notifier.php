<?php
/**
 * Entry point for the plugin.
 *
 * This file is read by WordPress to generate the plugin information in the
 * admin panel.
 *
 * @link    https://github.com/l3rady/wp-updates-notifier
 * @since   1.5.0
 * @package SC_WP_Updates_Notifier
 */

/*
 * Plugin Name: WP Updates Notifier
 * Plugin URI: https://wpun.cariss.dev/
 * Description: Sends an email to notify you if there are any updates for your WordPress site. Can notify about core, plugin and theme updates.
 * x-release-please-start-version
 * Version: 2.0.0
 * x-release-please-end
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Network: true
 * Author: Scott Cariss
 * Author URI: https://wpun.cariss.dev/#about-the-developer
 * Text Domain: wp-updates-notifier
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

/*
	Copyright 2015-2026  Scott Cariss  (email : scott@cariss.dev)

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
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

defined( 'SC_WPUN_FILE' ) || define( 'SC_WPUN_FILE', __FILE__ );

/*
 * Version of the plugin, the same as the Version header. Used to version the admin stylesheet.
 * Release-please keeps it in step with the header; bin/check-headers.sh fails if they differ.
 */
/* x-release-please-start-version */
defined( 'SC_WPUN_VERSION' ) || define( 'SC_WPUN_VERSION', '2.0.0' );
/* x-release-please-end */

/*
 * Version of the public API (channels, routing, hooks), separate from the plugin version.
 * Semver: a minor release adds API, a major release removes or changes some. Add-ons check
 * it with sc_wpun_api_satisfies().
 */
defined( 'SC_WPUN_API_VERSION' ) || define( 'SC_WPUN_API_VERSION', '1.1.0' );

require_once __DIR__ . '/includes/class-autoloader.php';
\WPUN\Autoloader::register();

// Built-in notification modules.
require_once __DIR__ . '/modules/email/email.php';
require_once __DIR__ . '/modules/webhook/webhook.php';
require_once __DIR__ . '/modules/slack/slack.php';

require_once __DIR__ . '/includes/class-sc-wp-updates-notifier.php';
require_once __DIR__ . '/includes/functions.php';

// Runs on every include: the activation and deactivation hooks must exist when WordPress loads this file to activate the plugin.
sc_wpun()->register();
