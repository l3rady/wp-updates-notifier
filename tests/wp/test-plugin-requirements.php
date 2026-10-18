<?php
/**
 * The API requirement header of add-ons, and its notice on the Plugins screen (#191).
 *
 * @package wp-updates-notifier
 */

use WPUN\Plugin;

/**
 * The `Requires WPUN API` header and the row notice.
 */
class Test_Plugin_Requirements extends WPUN_Test_Case {

	/**
	 * The notice class, with the hook registered the way an admin request registers it.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		set_current_screen( 'plugins' );
		( new Plugin() )->register_admin_hooks();
	}

	/**
	 * Render the row of a plugin through the core action.
	 *
	 * @param string $file Plugin file.
	 * @param mixed  $data Plugin data.
	 * @return string
	 */
	private function row( $file, $data ): string {
		ob_start();
		do_action( 'after_plugin_row', $file, $data, 'all' );

		return (string) ob_get_clean();
	}

	/**
	 * Plugin data with a requirement.
	 *
	 * @param mixed $value Header value.
	 * @return array
	 */
	private function data( $value ): array {
		return array(
			'Name'             => 'Some add-on',
			Plugin::API_HEADER => $value,
		);
	}

	/**
	 * Core returns the header from get_plugin_data(), and the filter keeps other headers.
	 */
	public function test_get_plugin_data_returns_the_header() {
		$data = get_plugin_data( dirname( __DIR__ ) . '/fixtures/plugins/wpun-test-addon/wpun-test-addon.php', false, false );

		$this->assertSame( '^1.0', $data['Requires WPUN API'] );
		$this->assertSame( array( 'Foo', 'Requires WPUN API' ), ( new Plugin() )->add_plugin_header( array( 'Foo' ) ) );
		$this->assertSame( 'x', ( new Plugin() )->add_plugin_header( 'x' ) );
		$this->assertContains( 'Requires WPUN API', apply_filters( 'extra_plugin_headers', array() ) );
	}

	/**
	 * Registered on the hook that covers every plugin row.
	 */
	public function test_hook_is_registered() {
		$this->assertNotFalse( has_action( 'after_plugin_row' ) );
	}

	/**
	 * An unmet constraint gets a row with the constraint and the site's API version.
	 */
	public function test_unmet_constraint_gets_a_row() {
		$html = $this->row( 'some-addon/some-addon.php', $this->data( '^99.0' ) );

		$this->assertStringContainsString( 'plugin-update-tr', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '^99.0', $html );
		$this->assertStringContainsString( sc_wpun_api_version(), $html );
		$this->assertStringContainsString( 'colspan="', $html );
	}

	/**
	 * Cases that get no row.
	 */
	public function test_no_row() {
		$this->assertSame( '', $this->row( 'some-addon/some-addon.php', $this->data( '^1.0' ) ) );
		$this->assertSame( '', $this->row( 'some-addon/some-addon.php', array( 'Name' => 'No header' ) ) );
		$this->assertSame( '', $this->row( 'some-addon/some-addon.php', $this->data( '' ) ) );
		$this->assertSame( '', $this->row( 'some-addon/some-addon.php', $this->data( "  \t" ) ) );
		$this->assertSame( '', $this->row( 'some-addon/some-addon.php', 'not an array' ) );
		$this->assertSame( '', $this->row( plugin_basename( SC_WPUN_FILE ), $this->data( '^99.0' ) ) );
	}

	/**
	 * An unreadable value gets the "could not be read" row, without a notice or an error.
	 */
	public function test_invalid_constraint_gets_a_could_not_read_row() {
		foreach ( array( '~>1.0', 'garbage' ) as $value ) {
			$html = $this->row( 'some-addon/some-addon.php', $this->data( $value ) );

			$this->assertStringContainsString( 'could not be read', $html );
			$this->assertStringContainsString( esc_html( $value ), $html );
			$this->assertStringNotContainsString( 'needs the WP Updates Notifier API', $html );
		}
	}

	/**
	 * The value is escaped.
	 */
	public function test_values_are_escaped() {
		foreach ( array( '<script>alert(1)</script>', '^99.0<b>' ) as $value ) {
			$html = $this->row( 'some-addon/some-addon.php', $this->data( $value ) );

			$this->assertStringNotContainsString( '<script>', $html );
			$this->assertStringNotContainsString( '<b>', $html );
			$this->assertStringContainsString( '&lt;', $html );
		}
	}

	/**
	 * The row is marked active or inactive like core's.
	 */
	public function test_row_is_marked_inactive_for_an_inactive_plugin() {
		$this->assertStringContainsString( 'plugin-update-tr inactive', $this->row( 'some-addon/some-addon.php', $this->data( '^99.0' ) ) );
	}
}
