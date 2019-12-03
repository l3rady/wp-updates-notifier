<?php
/**
 * Publish to WP Updates Notifier Tests: sc_WPUpdatesNotifier class
 *
 * Contains a class which is used to test the sc_WPUpdatesNotifier class.
 *
 * @package sc_WPUpdatesNotifier
 * @subpackage Tests
 */

/**
 * A class which is used to test the sc_WPUpdatesNotifier class.
 */
class sc_WPUpdatesNotifier_Tests extends WP_UnitTestCase {
	
	/**
	 * A function containing operations to be run before each test function.
	 *
	 * @access public
	 */
	public function setUp() {
		parent::setup();
	}

	/**
	 * Ensures that the basic email functions return the correct values.
	 *
	 * @see sc_WPUpdatesNotifier::sc_wpun_wp_mail_*
	 *
	 * @access public
	 */
	public function testEmailInfo() {
		$sc_WPUpdatesNotifier = new sc_WPUpdatesNotifier();
	
		// Test email content type.
		$this->assertEquals(
			'WP Updates Notifier',
			$sc_WPUpdatesNotifier->sc_wpun_wp_mail_from_name()
		);

		// Test email content type.
		$this->assertEquals(
			'text/plain',
			$sc_WPUpdatesNotifier->sc_wpun_wp_mail_content_type()
		);
	}
}
