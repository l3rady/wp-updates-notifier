<?php
/**
 * The exact text of the notification (email body).
 *
 * The snapshots in tests/fixtures/snapshots freeze today's message, known
 * flaws included (HTML entities, #65), so that reworking how the message is
 * built cannot change what users receive. When a fix is meant to change the
 * text, change the snapshots in the same commit.
 *
 * @package wp-updates-notifier
 */

/**
 * Compares the sent messages with the snapshot files.
 */
class Test_Message_Snapshot extends WPUN_Test_Case {

	/**
	 * A site with the email channel on and Slack settings kept from before.
	 */
	public function set_up() {
		parent::set_up();

		$this->save_settings();
		update_option( 'blogname', 'Bob & Sons' );
	}

	/**
	 * Read a snapshot with its placeholders replaced by this run's values.
	 *
	 * @param string $name File name in tests/fixtures/snapshots.
	 * @return string
	 */
	private function snapshot( $name ) {
		$wp_version = wp_get_wp_version();
		$parts      = explode( '.', (string) preg_replace( '/-.*$/', '', $wp_version ) );
		$theme      = wp_get_theme();
		$own_plugin = get_plugin_data( WP_PLUGIN_DIR . '/wp-updates-notifier/class-sc-wp-updates-notifier.php', false, false );

		return strtr(
			(string) file_get_contents( dirname( __DIR__ ) . '/fixtures/snapshots/' . $name ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			array(
				'{{wp_version}}'       => $wp_version,
				'{{wp_major_minor}}'   => implode( '.', array_slice( $parts, 0, 2 ) ),
				'{{php_version}}'      => PHP_VERSION,
				'{{home_url}}'         => home_url(),
				'{{admin_update_url}}' => admin_url( 'update-core.php' ),
				'{{site_name}}'        => esc_html( get_bloginfo() ),
				'{{own_version}}'      => $own_plugin['Version'],
				'{{theme_name}}'       => $theme->get( 'Name' ),
				'{{theme_version}}'    => $theme->get( 'Version' ),
			)
		);
	}

	/**
	 * One run with a core update, a WordPress.org plugin, a plugin from elsewhere and a theme.
	 */
	public function test_message_matches_the_snapshots() {
		$this->set_updates(
			'99.0.0',
			array(
				'hello.php' => array(
					'id'           => 'w.org/plugins/hello-dolly',
					'slug'         => 'hello-dolly',
					'url'          => 'https://wordpress.org/plugins/hello-dolly/',
					'new_version'  => '2.0.0',
					'tested'       => '5.0',
					'requires'     => '99.0',
					'requires_php' => '99.0',
				),
			),
			array(
				get_stylesheet() => array(
					'new_version' => '3.1.0',
					'url'         => 'https://example.com/themes/default/',
				),
			)
		);
		$updates           = get_site_transient( 'update_plugins' );
		$updates->response = array_merge(
			$updates->response,
			array(
				'wp-updates-notifier/class-sc-wp-updates-notifier.php' => (object) array(
					'plugin'      => 'wp-updates-notifier/class-sc-wp-updates-notifier.php',
					'new_version' => '4.5.6',
					'url'         => 'https://example.com/premium-plugin/',
					'package'     => '',
				),
			)
		);
		set_site_transient( 'update_plugins', $updates );

		$this->plugin()->do_update_check();

		// The mailer ends lines with CRLF and the body with a line break; the snapshot uses LF.
		$this->assertSame( $this->snapshot( 'email-body.txt' ), str_replace( "\r\n", "\n", $this->sent_mail()->body ) );
	}
}
