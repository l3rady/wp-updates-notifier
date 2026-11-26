<?php
/**
 * Environment helpers: the user, fixture plugins and the site kind.
 *
 * @package wp-updates-notifier
 */

/**
 * Helpers that change or check what the test runs in.
 */
trait WPUN_Environment {

	/**
	 * Plugin files written by fixture_plugin(), removed when the test ends.
	 *
	 * @var string[]
	 */
	private $fixture_plugin_files = array();

	/**
	 * Become a plain administrator, who is not a super admin on a multisite.
	 *
	 * @return int User id.
	 */
	protected function become_admin(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );

		return $id;
	}

	/**
	 * Write a plugin file with a name and a version. It is removed when the test ends.
	 *
	 * Writing the same file again changes the version.
	 *
	 * @param string $file    Path under the plugins directory, for example `my-plugin/my-plugin.php`.
	 * @param string $version Version header.
	 * @param string $name    Plugin name header.
	 */
	protected function fixture_plugin( string $file, string $version, string $name = 'Fixture Plugin' ): void {
		$dir = dirname( $file );
		wp_mkdir_p( '.' === $dir ? WP_PLUGIN_DIR : WP_PLUGIN_DIR . '/' . $dir );
		file_put_contents( WP_PLUGIN_DIR . '/' . $file, "<?php\n/*\nPlugin Name: $name\nVersion: $version\n*/\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		wp_clean_plugins_cache();

		if ( ! in_array( $file, $this->fixture_plugin_files, true ) ) {
			$this->fixture_plugin_files[] = $file;
		}
	}

	/**
	 * Remove the plugins fixture_plugin() wrote. The base test case calls it when the test ends.
	 */
	protected function remove_fixture_plugins(): void {
		if ( array() === $this->fixture_plugin_files ) {
			return;
		}

		foreach ( $this->fixture_plugin_files as $file ) {
			$dir = dirname( $file );
			if ( '.' === $dir ) {
				if ( is_file( WP_PLUGIN_DIR . '/' . $file ) ) {
					unlink( WP_PLUGIN_DIR . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
				}
			} else {
				$this->remove_dir( WP_PLUGIN_DIR . '/' . $dir );
			}
		}

		$this->fixture_plugin_files = array();
		wp_clean_plugins_cache();
	}

	/**
	 * Delete a fixture directory and the files in it.
	 *
	 * @param string $dir Directory.
	 */
	protected function remove_dir( string $dir ): void {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture.
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture.
		}
	}

	/**
	 * Skip on a single site.
	 */
	protected function require_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite.' );
		}
	}

	/**
	 * Skip on a multisite.
	 */
	protected function require_single_site(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Needs a single site.' );
		}
	}

	/**
	 * Network settings and the email instance, as a network would store them.
	 *
	 * @param array $overrides Settings to override.
	 */
	protected function save_network_settings( array $overrides = array() ): void {
		update_site_option( 'sc_wpun_settings', $this->global_settings( $overrides ) );
		update_site_option( 'sc_wpun_settings_ver', self::SETTINGS_VERSION );
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'email', 'email', 'Email', true, array( 'to' => 'ops@example.org' ) ) );
	}
}
