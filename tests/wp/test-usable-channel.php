<?php
/**
 * Status_Report::has_usable_channel(): a channel is switched on and its module is registered (#481).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;

/**
 * None, switched off only, unregistered module only, and one that works.
 */
class Test_Usable_Channel extends WPUN_Test_Case {

	/**
	 * Start with no channel instance at all.
	 */
	public function set_up() {
		parent::set_up();

		$this->save_settings();
		sc_wpun_channels()->delete( 'email' );
	}

	/**
	 * Save an instance of a type.
	 *
	 * @param string $id      Instance id.
	 * @param string $type    Module type.
	 * @param bool   $enabled Whether it is switched on.
	 */
	private function add( $id, $type, $enabled ) {
		sc_wpun_channels()->save( new Channel_Instance( $id, $type, $id, $enabled, array() ) );
	}

	/**
	 * Nothing is stored.
	 */
	public function test_none() {
		$this->assertFalse( sc_wpun()->status_report()->has_usable_channel() );
	}

	/**
	 * A switched-off instance of a registered module does not count.
	 */
	public function test_disabled_only() {
		$this->add( 'off', 'email', false );

		$this->assertFalse( sc_wpun()->status_report()->has_usable_channel() );
	}

	/**
	 * A switched-on instance of an unregistered module does not count.
	 */
	public function test_unavailable_only() {
		$this->add( 'gone', 'no_such_module', true );

		$this->assertFalse( sc_wpun()->status_report()->has_usable_channel() );
	}

	/**
	 * One usable instance is enough, after others that are not.
	 */
	public function test_one_usable() {
		$this->add( 'off', 'email', false );
		$this->add( 'gone', 'no_such_module', true );
		$this->add( 'on', 'email', true );

		$this->assertTrue( sc_wpun()->status_report()->has_usable_channel() );
	}
}
