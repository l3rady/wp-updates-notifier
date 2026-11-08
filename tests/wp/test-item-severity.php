<?php
/**
 * The severity and the compatibility of a notification item, and the marker of the renderer (#447).
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Notification_Renderer;
use WPUN\Routing;
use WPUN\Severity;

/**
 * Notification_Item::get_severity(), get_compatibility() and Severity.
 */
class Test_Item_Severity extends WPUN_Test_Case {

	/**
	 * An item.
	 *
	 * @param string $current Installed version.
	 * @param string $latest   Version available.
	 * @param array  $flags   Flags.
	 * @param string $type    Type.
	 * @return Notification_Item
	 */
	private function item( $current = '1.0', $latest = '1.1', array $flags = array(), $type = 'plugin' ) {
		return new Notification_Item(
			array(
				'type'            => $type,
				'key'             => 'plugin' === $type ? 'hello/hello.php' : $type,
				'name'            => 'Hello',
				'current_version' => $current,
				'new_version'     => $latest,
				'flags'           => $flags,
			)
		);
	}

	/**
	 * The running WordPress version.
	 *
	 * @return string
	 */
	private function wp() {
		return wp_get_wp_version();
	}

	/**
	 * A version the running PHP is older than.
	 *
	 * @return string
	 */
	private function newer_php() {
		return ( PHP_MAJOR_VERSION + 1 ) . '.0';
	}

	/**
	 * Versions and the severity they give.
	 *
	 * @return array
	 */
	public function severity_cases() {
		return array(
			'major'                         => array( '1.4.2', '2.0.0', 'high' ),
			'minor'                         => array( '1.4.2', '1.5.0', 'medium' ),
			'patch'                         => array( '1.4.2', '1.4.3', 'low' ),
			'same'                          => array( '1.4.2', '1.4.2', 'low' ),
			'date based minor'              => array( '2024.1', '2024.2', 'medium' ),
			'date based major'              => array( '2024.9', '2025.1', 'high' ),
			'not a number'                  => array( 'beta', '1.0', 'medium' ),
			'new not a number'              => array( '1.0', 'beta', 'medium' ),
			'pre-release to release'        => array( '1.0-rc1', '1.0', 'low' ),
			'longer version, same part'     => array( '4.2', '4.2.0.1', 'low' ),
			'longer version, other part'    => array( '4.2', '4.3.0.1', 'medium' ),
			'missing second number is zero' => array( '4', '4.0.1', 'low' ),
			'empty versions'                => array( '', '', 'medium' ),
			'empty current version'         => array( '', '1.0', 'medium' ),
		);
	}

	/**
	 * The severity follows the first two numbers of the versions.
	 *
	 * @dataProvider severity_cases
	 *
	 * @param string $current  Installed version.
	 * @param string $latest      Version available.
	 * @param string $expected Severity.
	 */
	public function test_severity_from_versions( $current, $latest, $expected ) {
		$this->assertSame( $expected, $this->item( $current, $latest )->get_severity() );
	}

	/**
	 * WordPress itself is always high.
	 */
	public function test_core_is_high() {
		$this->assertSame( 'high', $this->item( '6.7.1', '6.7.2', array(), 'core' )->get_severity() );
	}

	/**
	 * The helpers of Severity.
	 */
	public function test_severity_helpers() {
		$this->assertSame( 3, Severity::rank( 'high' ) );
		$this->assertSame( 2, Severity::rank( 'medium' ) );
		$this->assertSame( 1, Severity::rank( 'low' ) );
		$this->assertSame( 0, Severity::rank( 'critical' ) );
		$this->assertSame( 0, Severity::rank( '' ) );

		$this->assertSame( 'low', Severity::highest( array() ) );
		$this->assertSame( 'medium', Severity::highest( array( 'low', 'medium', 'low' ) ) );
		$this->assertSame( 'high', Severity::highest( array( 'medium', 'high', 'low' ) ) );
		$this->assertSame( 'medium', Severity::highest( array( 'medium', 'critical' ) ), 'An invalid value counts as none.' );
		$this->assertSame( 'low', Severity::highest( array( 'critical' ) ) );

		$this->assertTrue( Severity::is_valid( 'high' ) );
		$this->assertTrue( Severity::is_valid( 'medium' ) );
		$this->assertTrue( Severity::is_valid( 'low' ) );
		$this->assertFalse( Severity::is_valid( 'critical' ) );
		$this->assertFalse( Severity::is_valid( 'HIGH' ) );
		$this->assertFalse( Severity::is_valid( '' ) );
	}

	/**
	 * The filter can change the severity, and gets the computed one and the item.
	 */
	public function test_the_filter_changes_the_severity() {
		$seen = array();
		add_filter(
			'sc_wpun_item_severity',
			static function ( $severity, $item ) use ( &$seen ) {
				$seen[] = array( $severity, $item->get_key() );
				return 'high';
			},
			10,
			2
		);

		$this->assertSame( 'high', $this->item( '1.0.0', '1.0.1' )->get_severity() );
		$this->assertSame( array( array( 'low', 'hello/hello.php' ) ), $seen );
	}

	/**
	 * A value that is not one of the three is ignored.
	 */
	public function test_the_filter_ignores_a_bad_value() {
		foreach ( array( 'critical', 'HIGH', '', 3, null, true, array( 'high' ) ) as $bad ) {
			add_filter(
				'sc_wpun_item_severity',
				static function () use ( $bad ) {
					return $bad;
				}
			);
			$this->assertSame( 'medium', $this->item( '1.0', '1.1' )->get_severity() );
			remove_all_filters( 'sc_wpun_item_severity' );
		}
	}

	/**
	 * Nothing wrong gives an empty list.
	 */
	public function test_no_flags_no_problem() {
		$this->assertSame( array(), $this->item()->get_compatibility() );
	}

	/**
	 * Flag values that are not a problem.
	 *
	 * @return array
	 */
	public function fine_flags() {
		return array(
			'booleans'         => array(
				array(
					'tested'       => true,
					'requires_php' => true,
					'requires_wp'  => false,
				),
			),
			'empty'            => array(
				array(
					'tested'       => '',
					'requires_php' => ' ',
					'requires_wp'  => '',
				),
			),
			'only other flags' => array( array( 'active' => true ) ),
		);
	}

	/**
	 * A missing, empty or boolean flag is no problem.
	 *
	 * @dataProvider fine_flags
	 *
	 * @param array $flags Flags.
	 */
	public function test_flags_that_are_no_problem( array $flags ) {
		$this->assertSame( array(), $this->item( '1.0', '1.1', $flags )->get_compatibility() );
	}

	/**
	 * `tested` is compared by major.minor with the running WordPress: below is medium, equal or above is fine.
	 */
	public function test_tested() {
		$running = Routing::major_minor( $this->wp() );
		$older   = ( (int) explode( '.', $running )[0] - 1 ) . '.0';
		$newer   = ( (int) explode( '.', $running )[0] + 1 ) . '.0';

		$this->assertSame(
			array(
				array(
					'flag'     => 'tested',
					'required' => $older,
					'running'  => $this->wp(),
					'severity' => 'medium',
				),
			),
			$this->item( '1.0', '1.1', array( 'tested' => $older ) )->get_compatibility()
		);
		$this->assertSame( array(), $this->item( '1.0', '1.1', array( 'tested' => $running ) )->get_compatibility(), 'Equal.' );
		$this->assertSame( array(), $this->item( '1.0', '1.1', array( 'tested' => $running . '.99' ) )->get_compatibility(), 'Same major.minor, other patch.' );
		$this->assertSame( array(), $this->item( '1.0', '1.1', array( 'tested' => $newer ) )->get_compatibility(), 'Above.' );
	}

	/**
	 * `requires_php` is high when the running PHP is older.
	 */
	public function test_requires_php() {
		$this->assertSame(
			array(
				array(
					'flag'     => 'requires_php',
					'required' => $this->newer_php(),
					'running'  => PHP_VERSION,
					'severity' => 'high',
				),
			),
			$this->item( '1.0', '1.1', array( 'requires_php' => $this->newer_php() ) )->get_compatibility()
		);
		$this->assertSame( array(), $this->item( '1.0', '1.1', array( 'requires_php' => PHP_VERSION ) )->get_compatibility(), 'Equal.' );
		$this->assertSame( array(), $this->item( '1.0', '1.1', array( 'requires_php' => '5.6' ) )->get_compatibility(), 'Below.' );
	}

	/**
	 * `requires_wp` is high when the running WordPress is older.
	 */
	public function test_requires_wp() {
		$newer = ( (int) explode( '.', $this->wp() )[0] + 1 ) . '.0';

		$this->assertSame(
			array(
				array(
					'flag'     => 'requires_wp',
					'required' => $newer,
					'running'  => $this->wp(),
					'severity' => 'high',
				),
			),
			$this->item( '1.0', '1.1', array( 'requires_wp' => $newer ) )->get_compatibility()
		);
		$this->assertSame( array(), $this->item( '1.0', '1.1', array( 'requires_wp' => $this->wp() ) )->get_compatibility(), 'Equal.' );
		$this->assertSame( array(), $this->item( '1.0', '1.1', array( 'requires_wp' => '4.0' ) )->get_compatibility(), 'Below.' );
	}

	/**
	 * The problems come in the order tested, requires_php, requires_wp.
	 */
	public function test_the_order() {
		$flags = array(
			'requires_wp'  => '99.0',
			'requires_php' => $this->newer_php(),
			'tested'       => '1.0',
		);

		$this->assertSame( array( 'tested', 'requires_php', 'requires_wp' ), wp_list_pluck( $this->item( '1.0', '1.1', $flags )->get_compatibility(), 'flag' ) );
	}

	/**
	 * A compatibility problem does not change the severity.
	 */
	public function test_compatibility_does_not_change_severity() {
		$item = $this->item( '1.0.0', '1.0.1', array( 'requires_php' => $this->newer_php() ) );

		$this->assertNotSame( array(), $item->get_compatibility() );
		$this->assertSame( 'low', $item->get_severity() );
	}

	/**
	 * The array of an item is still what its constructor takes.
	 */
	public function test_to_array_is_unchanged() {
		$this->assertSame(
			array( 'type', 'key', 'name', 'current_version', 'new_version', 'links', 'notes', 'notified_at', 'flags' ),
			array_keys( $this->item()->to_array() )
		);
	}

	/**
	 * A notification of one item.
	 *
	 * @param Notification_Item $item Item.
	 * @return Notification
	 */
	private function notification( Notification_Item $item ) {
		return new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $item ) );
	}

	/**
	 * A high item gets the marker in each format.
	 */
	public function test_the_marker_on_a_high_item() {
		$notification = $this->notification( $this->item( '1.0', '2.0' ) );

		$this->assertSame( ' (important update)', Notification_Renderer::severity_marker( $this->item( '1.0', '2.0' ) ) );
		$this->assertSame( '', Notification_Renderer::severity_marker( $this->item( '1.0', '1.1' ) ) );

		$this->assertStringContainsString( "Hello from version 1.0 to 2.0 (important update)\n", Notification_Renderer::plain_text( $notification ) );
		$this->assertStringContainsString( '**Hello** from version 1\.0 to 2\.0 \(important update\)', Notification_Renderer::markdown( $notification ) );
		$this->assertStringContainsString( '<strong>Hello</strong> from version 1.0 to 2.0 (<strong>important update</strong>)', Notification_Renderer::html( $notification ) );
	}

	/**
	 * A medium or low item has no marker.
	 */
	public function test_no_marker_on_a_lower_item() {
		foreach ( array( array( '1.0', '1.1' ), array( '1.0.0', '1.0.1' ) ) as $versions ) {
			$notification = $this->notification( $this->item( $versions[0], $versions[1] ) );

			$this->assertStringNotContainsString( 'important', Notification_Renderer::plain_text( $notification ) );
			$this->assertStringNotContainsString( 'important', Notification_Renderer::markdown( $notification ) );
			$this->assertStringNotContainsString( 'important', Notification_Renderer::html( $notification ) );
		}
	}

	/**
	 * The filter makes any item high, and so marked; the marker counts towards the length limit.
	 */
	public function test_the_marker_follows_the_filter_and_the_limit() {
		add_filter(
			'sc_wpun_item_severity',
			static function () {
				return 'high';
			}
		);
		$notification = $this->notification( $this->item( '1.0.0', '1.0.1' ) );

		$text = Notification_Renderer::plain_text( $notification );
		$this->assertStringContainsString( '(important update)', $text );
		$this->assertStringNotContainsString( '(important update)', Notification_Renderer::plain_text( $notification, mb_strlen( $text ) - 1 ), 'The item is left out, the marker with it.' );
	}
}
