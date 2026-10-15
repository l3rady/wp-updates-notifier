<?php
/**
 * The channel instance store (#93).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Channel_Store;

/**
 * Storing channel instances.
 */
class Test_Channel_Store extends WPUN_Test_Case {

	/**
	 * Start every test without instances.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( 'sc_wpun_channels' );
	}

	/**
	 * An instance to store.
	 *
	 * @param string $id      Identifier.
	 * @param string $type    Type.
	 * @param string $label   Label.
	 * @param bool   $enabled Whether it is on.
	 * @return Channel_Instance
	 */
	private function instance( $id, $type = 'test', $label = 'Label', $enabled = true ) {
		return new Channel_Instance( $id, $type, $label, $enabled, array( 'key' => 'value' ), array( 'types' => array( 'core' ) ) );
	}

	/**
	 * An instance can be added and read back whole.
	 */
	public function test_save_get_and_has() {
		$store = new Channel_Store();

		$this->assertFalse( $store->has( 'one' ) );
		$this->assertNull( $store->get( 'one' ) );
		$this->assertSame( array(), $store->all() );

		$store->save( $this->instance( 'one' ) );

		$this->assertTrue( $store->has( 'one' ) );
		$this->assertEquals( $this->instance( 'one' ), $store->get( 'one' ) );
		$this->assertSame( array( 'one' ), array_keys( $store->all() ) );
		$this->assertSame(
			array(
				'one' => array(
					'type'     => 'test',
					'label'    => 'Label',
					'enabled'  => true,
					'settings' => array( 'key' => 'value' ),
					'routing'  => array( 'types' => array( 'core' ) ),
				),
			),
			get_option( 'sc_wpun_channels' )
		);
	}

	/**
	 * New instances go to the end; saving an existing one replaces it in place.
	 */
	public function test_order_is_kept() {
		$store = new Channel_Store();
		$store->save( $this->instance( 'one' ) );
		$store->save( $this->instance( 'two' ) );
		$store->save( $this->instance( 'three' ) );

		$store->save( $this->instance( 'two', 'test', 'Changed', false ) );

		$this->assertSame( array( 'one', 'two', 'three' ), array_keys( $store->all() ) );
		$this->assertSame( 'Changed', $store->get( 'two' )->get_label() );
		$this->assertFalse( $store->get( 'two' )->is_enabled() );

		$store->save( $this->instance( 'four' ) );
		$this->assertSame( array( 'one', 'two', 'three', 'four' ), array_keys( $store->all() ) );
	}

	/**
	 * Deleting removes the instance and says whether there was one.
	 */
	public function test_delete() {
		$store = new Channel_Store();
		$store->save( $this->instance( 'one' ) );
		$store->save( $this->instance( 'two' ) );

		$this->assertTrue( $store->delete( 'one' ) );
		$this->assertFalse( $store->delete( 'one' ) );
		$this->assertFalse( $store->has( 'one' ) );
		$this->assertSame( array( 'two' ), array_keys( $store->all() ) );
	}

	/**
	 * Entries that cannot be instances are skipped when reading, and survive writes.
	 */
	public function test_invalid_entries_are_skipped_on_read() {
		$bad = array(
			'no_type'    => array( 'label' => 'x' ),
			'bad_type'   => array( 'type' => 'Not Valid' ),
			'not_array'  => 'nonsense',
			'Bad Id'     => array( 'type' => 'test' ),
			'string_off' => array(
				'type'    => 'test',
				'enabled' => '0',
			),
		);
		add_option( 'sc_wpun_channels', $bad, '', false );
		$store = new Channel_Store();

		$this->assertSame( array( 'string_off' ), array_keys( $store->all() ) );
		$this->assertFalse( $store->get( 'string_off' )->is_enabled() );
		$this->assertSame( '', $store->get( 'string_off' )->get_label() );
		$this->assertNull( $store->get( 'no_type' ) );
		$this->assertFalse( $store->has( 'Bad Id' ) );

		$store->save( $this->instance( 'one' ) );
		$this->assertSame( array_merge( array_keys( $bad ), array( 'one' ) ), array_keys( get_option( 'sc_wpun_channels' ) ) );
	}

	/**
	 * A stored value that is not an array is treated as empty.
	 */
	public function test_a_non_array_option_is_empty() {
		add_option( 'sc_wpun_channels', 'nonsense', '', false );
		$store = new Channel_Store();

		$this->assertSame( array(), $store->all() );
		$store->save( $this->instance( 'one' ) );
		$this->assertSame( array( 'one' ), array_keys( $store->all() ) );
	}

	/**
	 * Instances of a type nobody has registered are stored and left untouched by other saves.
	 */
	public function test_an_instance_of_an_unregistered_type_survives_other_saves() {
		$store = new Channel_Store();
		$this->assertFalse( sc_wpun()->channels()->has( 'addon_type' ) );
		$addon = new Channel_Instance( 'addon_1', 'addon_type', 'Add-on', true, array( 'secret' => 'abc' ), array( 'x' => 1 ) );

		$store->save( $addon );
		$store->save( $this->instance( 'other' ) );
		$store->delete( 'other' );

		$this->assertEquals( $addon, $store->get( 'addon_1' ) );
	}

	/**
	 * The option is created without autoload, and stays that way when it is updated.
	 */
	public function test_the_option_is_not_autoloaded() {
		$store = new Channel_Store();
		$store->save( $this->instance( 'one' ) );
		$store->save( $this->instance( 'two' ) );

		$this->assertArrayNotHasKey( 'sc_wpun_channels', wp_load_alloptions() );
	}

	/**
	 * Reads and writes go through the documented options filters.
	 */
	public function test_the_options_filters_are_applied() {
		$store = new Channel_Store();
		$store->save( $this->instance( 'one' ) );

		add_filter(
			'sc_wpun_get_options_filter',
			static function ( $value, $field ) {
				if ( 'sc_wpun_channels' === $field ) {
					$value['one']['label'] = 'Filtered';
				}
				return $value;
			},
			10,
			2
		);
		$this->assertSame( 'Filtered', $store->get( 'one' )->get_label() );

		$put = array();
		add_filter(
			'sc_wpun_put_options_filter',
			static function ( $value, $field ) use ( &$put ) {
				$put[] = $field;
				return $value;
			},
			10,
			2
		);
		$store->save( $this->instance( 'two' ) );
		$store->delete( 'two' );

		$this->assertSame( array( 'sc_wpun_channels', 'sc_wpun_channels' ), $put );
	}

	/**
	 * The helper returns the plugin's own store.
	 */
	public function test_the_helper_returns_the_plugins_store() {
		$this->assertInstanceOf( Channel_Store::class, sc_wpun_channels() );
		$this->assertSame( sc_wpun()->channel_store(), sc_wpun_channels() );
	}

	/**
	 * The copy methods leave the original alone and change one thing each.
	 */
	public function test_instances_can_be_copied_with_changes() {
		$original = $this->instance( 'one' );

		$this->assertSame( 'New', $original->with_label( 'New' )->get_label() );
		$this->assertFalse( $original->with_enabled( false )->is_enabled() );
		$this->assertSame( array( 'a' => 1 ), $original->with_settings( array( 'a' => 1 ) )->get_settings() );
		$this->assertSame( array( 'b' => 2 ), $original->with_routing( array( 'b' => 2 ) )->get_routing() );

		$copy = $original->with_label( 'New' );
		$this->assertSame( 'one', $copy->get_id() );
		$this->assertSame( 'test', $copy->get_type() );
		$this->assertTrue( $copy->is_enabled() );
		$this->assertSame( array( 'key' => 'value' ), $copy->get_settings() );
		$this->assertSame( array( 'types' => array( 'core' ) ), $copy->get_routing() );
		$this->assertSame( 'Label', $original->get_label() );
	}
}
