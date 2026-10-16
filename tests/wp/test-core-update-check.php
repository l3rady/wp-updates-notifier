<?php
/**
 * Reading WordPress's core update data (#68).
 *
 * @package wp-updates-notifier
 */

/**
 * The core part of the update check.
 */
class Test_Core_Update_Check extends WPUN_Test_Case {

	/**
	 * Set the update_core transient to a list of offers, fresh enough that core makes no request.
	 *
	 * @param array $offers Offers (arrays become objects).
	 */
	private function set_core_offers( array $offers ) {
		$this->set_updates();
		$core          = get_site_transient( 'update_core' );
		$core->updates = array_map(
			static function ( $offer ) {
				return is_array( $offer ) ? (object) $offer : $offer;
			},
			$offers
		);
		set_site_transient( 'update_core', $core );
	}

	/**
	 * When the API offers nothing, there is nothing to read and no warning.
	 */
	public function test_empty_update_data_does_not_warn() {
		$this->save_settings();
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '99.0.0' ) ) );
		$this->set_updates();
		delete_site_transient( 'update_core' );
		$this->fake_http(
			'api.wordpress.org/core/version-check',
			$this->fake_response(
				200,
				wp_json_encode(
					array(
						'offers'       => array(),
						'translations' => array(),
					)
				)
			)
		);

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ), 'Without data, what was notified is kept.' );
	}

	/**
	 * When the transient holds something unusable instead of update data, nothing breaks.
	 */
	public function test_missing_update_data_does_not_warn() {
		$this->save_settings();
		$this->set_updates();
		delete_site_transient( 'update_core' );
		add_filter( 'pre_site_transient_update_core', '__return_zero' ); // Any value but false short-circuits the read.
		$this->fake_http(
			'api.wordpress.org/core/version-check',
			$this->fake_response(
				200,
				wp_json_encode(
					array(
						'offers'       => array(),
						'translations' => array(),
					)
				)
			)
		);

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * Offers that are not objects, or have no version, are skipped.
	 */
	public function test_malformed_offers_are_skipped() {
		$this->save_settings();
		$this->set_core_offers(
			array(
				'not an object',
				array( 'response' => 'upgrade' ),
				array(
					'response' => 'upgrade',
					'current'  => '',
				),
			)
		);

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * The upgrade is found even when it is not the first offer.
	 */
	public function test_the_upgrade_offer_is_found_in_any_position() {
		$this->save_settings();
		$this->set_core_offers(
			array(
				array(
					'response' => 'autoupdate',
					'current'  => wp_get_wp_version(),
				),
				array(
					'response' => 'upgrade',
					'current'  => '99.0.0',
				),
			)
		);

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertStringContainsString( '99.0.0', $this->sent_mail()->body );
	}

	/**
	 * Development versions and the latest release are not upgrades.
	 */
	public function test_only_upgrade_offers_count() {
		$this->save_settings();
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '98.0.0' ) ) );
		$this->set_core_offers(
			array(
				array(
					'response' => 'development',
					'current'  => '99.1-alpha',
				),
				array(
					'response' => 'latest',
					'current'  => wp_get_wp_version(),
				),
			)
		);

		$this->plugin()->do_update_check();

		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( '', $this->notified_version( 'core', 'core' ), 'Up to date: the record is cleared for the next release.' );
	}

	/**
	 * The installed version in the message comes from version.php, not the global a plugin can change.
	 */
	public function test_the_installed_version_comes_from_version_php() {
		global $wp_version;

		$this->save_settings();
		$this->set_updates( '99.0.0' );
		$real       = $wp_version;
		$wp_version = '1.0-tampered'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		try {
			$this->plugin()->do_update_check();
		} finally {
			$wp_version = $real; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		$body = $this->sent_mail()->body;
		$this->assertStringContainsString( wp_get_wp_version(), $body );
		$this->assertStringNotContainsString( '1.0-tampered', $body );
	}

	/**
	 * DISALLOW_FILE_MODS (via its filter) does not stop core updates from being reported.
	 *
	 * Core's update checks never consult it; this pins that for the forum report in #68.
	 */
	public function test_disallowed_file_mods_still_report_core_updates() {
		add_filter( 'file_mod_allowed', '__return_false' );
		$this->save_settings();
		$this->set_updates( '99.0.0' );

		$this->plugin()->do_update_check();

		$this->assertSame( 1, $this->sent_mail_count() );
	}
}
