<?php
/**
 * A WordPress.org slug cannot end with a line break (HN5 of the 2.0.0 security audit).
 *
 * @package wp-updates-notifier
 */

use WPUN\Notification_Item;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * Without the D modifier, `$` also matches before a final line break, so "good-slug\n" would pass the slug check.
 *
 * @group security
 */
class Test_Hardening_Slug_Newline extends WPUN_Security_Test_Case {

	/**
	 * A slug with a final line break gets no link.
	 */
	public function test_a_slug_with_a_final_line_break_gets_no_link() {
		$this->save_settings();

		$items = array();
		add_filter(
			'sc_wpun_notification',
			static function ( $notification ) use ( &$items ) {
				$items = $notification->get_items();
				return $notification;
			}
		);

		foreach ( array( "good-slug\n", "good-slug\r\n" ) as $slug ) {
			$this->set_updates(
				null,
				array(
					'hello.php' => array(
						'new_version' => '2.0',
						'slug'        => $slug,
						'url'         => '',
					),
				)
			);
			$this->plugin()->do_update_check();

			$this->assertContainsOnlyInstancesOf( Notification_Item::class, $items );
			$this->assertNotEmpty( $items );
			$this->assertSame( array(), $items[0]->get_links(), bin2hex( $slug ) );
		}
	}
}
