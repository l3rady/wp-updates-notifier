<?php
/**
 * Publish to WP Updates Notifier Tests: sc_WPUpdatesNotifier class
 *
 * Contains a class which is used to test the sc_WPUpdatesNotifier class.
 *
 * @package sc_WPUpdatesNotifier
 * @subpackage Tests
 */

use \Apple_Exporter\Settings;

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
		$this->settings = new Settings();
	}

}
