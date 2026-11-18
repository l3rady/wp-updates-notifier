<?php
/**
 * How the status report shows the value of a field.
 *
 * @package wp-updates-notifier
 */

/**
 * A secret shows only whether it is set; any other field shows its value.
 */
class Test_Status_Report_Display_Field extends WPUN_Test_Case {

	/**
	 * A secret that has a value shows `set`, never the value.
	 */
	public function test_a_secret_with_a_value_shows_set() {
		$text = sc_wpun()->status_report()->display_field( 'bad-SECRET-VALUE-123', array( 'secret' => true ) );

		$this->assertSame( 'set', $text );
	}

	/**
	 * A secret without a value shows `not set`.
	 */
	public function test_an_empty_secret_shows_not_set() {
		$report = sc_wpun()->status_report();

		$this->assertSame( 'not set', $report->display_field( '', array( 'secret' => true ) ) );
		$this->assertSame( 'not set', $report->display_field( null, array( 'secret' => true ) ) );
	}

	/**
	 * A field that is not secret shows its value as text.
	 */
	public function test_a_normal_field_shows_its_value() {
		$report = sc_wpun()->status_report();

		$this->assertSame( 'visible', $report->display_field( 'visible', array( 'type' => 'text' ) ) );
		$this->assertSame( '', $report->display_field( null, array( 'type' => 'text' ) ) );
		$this->assertSame( 'yes', $report->display_field( true, array( 'type' => 'text' ) ) );
	}
}
