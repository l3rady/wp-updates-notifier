<?php
/**
 * Update notice handling in the admin.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Settings;

/**
 * Hides the WordPress update notice from users who cannot update WordPress.
 *
 * @internal
 */
class Update_Nag {
	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hides WordPress's "update available" notice from users who cannot update WordPress.
	 *
	 * The notice is about core updates, and core itself words it by the
	 * `update_core` capability, so that is what decides who still sees it.
	 *
	 * @return void
	 */
	public function remove_update_nag_for_nonadmins() {
		$settings = $this->settings->get(); // get settings.
		if ( true === $settings['hide_updates'] && ! current_user_can( 'update_core' ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
			remove_action( 'network_admin_notices', 'update_nag', 3 );
		}
	}
}
