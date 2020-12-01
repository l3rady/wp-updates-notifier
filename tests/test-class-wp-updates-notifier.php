<?php
/**
 * Publish to WP Updates Notifier Tests: SC_WP_Updates_Notifier class
 *
 * Contains a class which is used to test the SC_WP_Updates_Notifier class.
 *
 * @package SC_WP_Updates_Notifier
 * @subpackage Tests
 */

/**
 * A class which is used to test the SC_WP_Updates_Notifier class.
 */
class SC_WP_Updates_Notifier_Tests extends WP_UnitTestCase {

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
	 * @see SC_WP_Updates_Notifier::sc_wpun_wp_mail_*
	 *
	 * @access public
	 */
	public function testEmailInfo() {
		$sc_wp_updates_notifier = new SC_WP_Updates_Notifier();

		// Test email content type.
		$this->assertEquals(
			'WP Updates Notifier',
			$sc_wp_updates_notifier->sc_wpun_wp_mail_from_name()
		);

		// Test email content type.
		$this->assertEquals(
			'text/html',
			$sc_wp_updates_notifier->sc_wpun_wp_mail_content_type()
		);
	}
}
