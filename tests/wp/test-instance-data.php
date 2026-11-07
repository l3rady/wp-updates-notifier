<?php
/**
 * The data a module keeps per channel instance (#443).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;

/**
 * Storing data per instance.
 */
class Test_Instance_Data extends WPUN_Test_Case {

	/**
	 * Start with a stored instance called `one`.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->network_context()->delete_option( 'sc_wpun_channels' );
		sc_wpun_channels()->save( new Channel_Instance( 'one', 'email', 'One', true ) );
	}

	/**
	 * Every option name the data of an instance created.
	 *
	 * @param string $like Start of the names.
	 * @return string[]
	 */
	private function option_names( $like = 'sc_wpun_data_' ) {
		global $wpdb;

		$table  = is_multisite() && sc_wpun()->network_context()->is_network() ? $wpdb->sitemeta : $wpdb->options;
		$column = $table === $wpdb->sitemeta ? 'meta_key' : 'option_name';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Test helper.
		return $wpdb->get_col( $wpdb->prepare( "SELECT $column FROM $table WHERE $column LIKE %s", $wpdb->esc_like( $like ) . '%' ) );
	}

	/**
	 * Set, get, keys, delete and clear.
	 */
	public function test_round_trip() {
		$data = sc_wpun_instance_data( 'one' );

		$this->assertSame( 'd', $data->get( 'cursor', 'd' ) );
		$this->assertNull( $data->get( 'cursor' ) );
		$this->assertSame( array(), $data->keys() );

		$this->assertTrue( $data->set( 'cursor', 'abc' ) );
		$this->assertTrue( $data->set( 'other', array( 'a' => 1 ) ) );
		$this->assertTrue( $data->set( 'cursor', 'def' ) );

		$this->assertSame( 'def', $data->get( 'cursor' ) );
		$this->assertSame( array( 'a' => 1 ), $data->get( 'other' ) );
		$this->assertSame( array( 'cursor', 'other' ), $data->keys() );

		$data->delete( 'cursor' );
		$this->assertSame( 'd', $data->get( 'cursor', 'd' ) );
		$this->assertSame( array( 'other' ), $data->keys() );

		$data->delete( 'other' );
		$this->assertSame( array(), $data->keys() );
		$this->assertSame( array(), $this->option_names(), 'The index goes with the last key.' );

		$data->set( 'a', 1 );
		$data->set( 'b', 2 );
		$data->clear();
		$this->assertSame( array(), $data->keys() );
		$this->assertSame( array(), $this->option_names() );
	}

	/**
	 * Falsy values are stored and read back, also as the first value of a key.
	 */
	public function test_falsy_values() {
		$data = sc_wpun_instance_data( 'one' );

		$this->assertTrue( $data->set( 'k', false ) );
		$this->assertFalse( $data->get( 'k', 'd' ) );
		$this->assertSame( array( 'k' ), $data->keys() );

		foreach ( array( null, 0, '', array() ) as $i => $value ) {
			$this->assertTrue( $data->set( 'v' . $i, $value ) );
			$this->assertSame( $value, $data->get( 'v' . $i, 'd' ), 'v' . $i );
		}

		$data->set( 'k', true );
		$this->assertTrue( $data->get( 'k' ) );
		$data->set( 'k', false );
		$this->assertFalse( $data->get( 'k', 'd' ) );
	}

	/**
	 * A value that cannot be read back is treated as missing.
	 */
	public function test_a_damaged_value_gives_the_default() {
		sc_wpun_instance_data( 'one' )->set( 'k', 'x' );
		sc_wpun()->network_context()->update_option( 'sc_wpun_data_one__k', 'a:2:{i:0;s:3:"abc";}', false );

		$this->assertSame( 'd', sc_wpun_instance_data( 'one' )->get( 'k', 'd' ) );
	}

	/**
	 * Values are not autoloaded.
	 */
	public function test_values_are_not_autoloaded() {
		if ( sc_wpun()->network_context()->is_network() ) {
			$this->markTestSkipped( 'Network options have no autoload.' );
		}

		$data = sc_wpun_instance_data( 'one' );
		$data->set( 'k', 'x' );
		$data->set( 'k', 'y' );
		$data->set( 'f', false );

		$all = wp_load_alloptions();
		$this->assertArrayNotHasKey( 'sc_wpun_data_one', $all );
		$this->assertArrayNotHasKey( 'sc_wpun_data_one__k', $all );
		$this->assertArrayNotHasKey( 'sc_wpun_data_one__f', $all );
		$this->assertCount( 3, $this->option_names() );
	}

	/**
	 * In network mode the values are site options.
	 */
	public function test_network_mode_uses_site_options() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs multisite (make test-wp-ms).' );
		}

		$this->set_network_activated( true );
		sc_wpun_channels()->save( new Channel_Instance( 'net', 'email', 'Net', true ) );
		$data = sc_wpun_instance_data( 'net' );

		$this->assertTrue( $data->set( 'k', 'x' ) );
		$this->assertTrue( $data->set( 'f', false ) );

		$this->assertSame( array( 'value' => 'x' ), get_site_option( 'sc_wpun_data_net__k' ) );
		$this->assertSame( array( 'value' => false ), get_site_option( 'sc_wpun_data_net__f' ) );
		$this->assertSame( array( 'k', 'f' ), get_site_option( 'sc_wpun_data_net' ) );
		$this->assertFalse( get_option( 'sc_wpun_data_net__k' ) );
		$this->assertFalse( get_option( 'sc_wpun_data_net' ) );

		$data->clear();
		$this->assertFalse( get_site_option( 'sc_wpun_data_net__k' ) );
		$this->assertFalse( get_site_option( 'sc_wpun_data_net' ) );
	}

	/**
	 * Keys that do not match the rules are refused.
	 */
	public function test_invalid_keys_are_refused() {
		$data = sc_wpun_instance_data( 'one' );

		foreach ( array( 'Upper', '', str_repeat( 'a', 65 ), 'a-b', "a\n" ) as $key ) {
			$this->assertFalse( $data->set( $key, 'x' ), $key );
			$this->assertSame( 'd', $data->get( $key, 'd' ), $key );
			$data->delete( $key );
		}
		$this->assertSame( array(), $this->option_names() );

		$this->assertTrue( $data->set( str_repeat( 'a', 64 ), 'x' ) );
		$this->assertTrue( $data->set( 'a_1', 'x' ) );
	}

	/**
	 * Ids that do not match the rules are refused, and cannot reach another instance's data.
	 */
	public function test_invalid_ids_are_refused() {
		foreach ( array( 'Bad', '', 'a-b', 'foo__bar', '_a', 'a_' ) as $id ) {
			$data = sc_wpun_instance_data( $id );
			$this->assertFalse( $data->set( 'k', 'x' ), $id );
			$this->assertSame( 'd', $data->get( 'k', 'd' ), $id );
			$this->assertSame( array(), $data->keys(), $id );
			$data->delete( 'k' );
			$data->clear();
		}
	}

	/**
	 * An id with a double underscore cannot read or delete the data of the instance before it.
	 */
	public function test_a_forged_id_cannot_reach_another_instance() {
		sc_wpun_channels()->save( new Channel_Instance( 'foo', 'email', 'Foo', true ) );
		$this->assertTrue( sc_wpun_instance_data( 'foo' )->set( 'bar__k', 'kept' ) );

		$forger = sc_wpun_instance_data( 'foo__bar' );
		$this->assertSame( 'd', $forger->get( 'k', 'd' ) );
		$forger->delete( 'k' );
		$forger->clear();

		$this->assertSame( 'kept', sc_wpun_instance_data( 'foo' )->get( 'bar__k' ) );
	}

	/**
	 * Only a known instance can store data.
	 */
	public function test_an_unknown_instance_cannot_store() {
		$data = sc_wpun_instance_data( 'nobody' );

		$this->assertFalse( $data->set( 'k', 'x' ) );
		$this->assertSame( 'd', $data->get( 'k', 'd' ) );
		$this->assertSame( array(), $this->option_names() );
	}

	/**
	 * An instance defined in code can store data.
	 */
	public function test_a_code_defined_instance_can_store() {
		$define = static function ( $channels ) {
			$channels[] = array(
				'id'    => 'from_code',
				'type'  => 'email',
				'label' => 'From code',
			);
			return $channels;
		};
		add_filter( 'sc_wpun_channels', $define );
		sc_wpun()->config()->flush();

		$data = sc_wpun_instance_data( 'from_code' );
		$this->assertTrue( $data->set( 'k', 'x' ) );
		$this->assertSame( 'x', $data->get( 'k' ) );

		remove_filter( 'sc_wpun_channels', $define );
		sc_wpun()->config()->flush();
	}

	/**
	 * An instance whose module is missing can store data.
	 */
	public function test_an_unavailable_instance_can_store() {
		sc_wpun_channels()->save( new Channel_Instance( 'addon_1', 'not_registered', 'Add-on', true ) );

		$data = sc_wpun_instance_data( 'addon_1' );
		$this->assertTrue( $data->set( 'k', 'x' ) );
		$this->assertSame( 'x', $data->get( 'k' ) );
	}

	/**
	 * A long id is hashed and every option name stays short enough.
	 */
	public function test_a_long_id_works() {
		$id = str_repeat( 'a', 150 );
		sc_wpun_channels()->save( new Channel_Instance( $id, 'email', 'Long', true ) );
		$data = sc_wpun_instance_data( $id );

		$this->assertTrue( $data->set( str_repeat( 'k', 64 ), 'x' ) );
		$this->assertSame( 'x', $data->get( str_repeat( 'k', 64 ) ) );

		$names = $this->option_names();
		$this->assertCount( 2, $names );
		foreach ( $names as $name ) {
			$this->assertLessThanOrEqual( 191, strlen( $name ) );
		}

		$data->clear();
		$this->assertSame( array(), $this->option_names() );
	}

	/**
	 * Deleting the instance removes its data after the event, so a listener can still read it.
	 */
	public function test_deleting_the_instance_removes_the_data() {
		sc_wpun_channels()->save( new Channel_Instance( 'two', 'email', 'Two', true ) );
		sc_wpun_instance_data( 'one' )->set( 'k', 'x' );
		sc_wpun_instance_data( 'two' )->set( 'k', 'y' );

		$seen = null;
		add_action(
			'sc_wpun_channel_deleted',
			static function ( $id ) use ( &$seen ) {
				$seen = sc_wpun_instance_data( $id )->get( 'k' );
			}
		);

		$this->assertTrue( sc_wpun_channels()->delete( 'one' ) );

		$this->assertSame( 'x', $seen );
		$this->assertSame( 'd', sc_wpun_instance_data( 'one' )->get( 'k', 'd' ) );
		$this->assertSame( array(), sc_wpun_instance_data( 'one' )->keys() );
		$this->assertSame( array( 'sc_wpun_data_two', 'sc_wpun_data_two__k' ), $this->sorted( $this->option_names() ) );
	}

	/**
	 * The data goes even when the stored entry is damaged and the event does not fire.
	 */
	public function test_a_damaged_entry_still_loses_its_data() {
		sc_wpun_instance_data( 'one' )->set( 'k', 'x' );
		$stored        = sc_wpun()->network_context()->get_option( 'sc_wpun_channels' );
		$stored['one'] = 'nonsense';
		sc_wpun()->network_context()->update_option( 'sc_wpun_channels', $stored, false );

		$fired = false;
		add_action(
			'sc_wpun_channel_deleted',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$this->assertTrue( sc_wpun_channels()->delete( 'one' ) );

		$this->assertFalse( $fired );
		$this->assertSame( array(), $this->option_names() );
	}

	/**
	 * Names sorted, for comparison.
	 *
	 * @param string[] $names Names.
	 * @return string[]
	 */
	private function sorted( array $names ) {
		sort( $names );

		return $names;
	}
}
