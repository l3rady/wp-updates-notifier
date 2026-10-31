<?php
/**
 * The mute value object and its store (#82).
 *
 * @package wp-updates-notifier
 */

use WPUN\Mute;
use WPUN\Mute_Store;
use WPUN\Notification_Item;

/**
 * Mute and Mute_Store.
 */
class Test_Mute_Store extends WPUN_Test_Case {

	/**
	 * Start with nothing muted and no updates waiting.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->network_context()->delete_option( 'sc_wpun_muted' );
		$this->set_updates();
	}

	/**
	 * An item with the given type, key and new version.
	 *
	 * @param string $type    Type.
	 * @param string $key     Key.
	 * @param string $version New version.
	 * @return Notification_Item
	 */
	private function item( $type, $key, $version = '2.0' ) {
		return new Notification_Item(
			array(
				'type'        => $type,
				'key'         => $key,
				'new_version' => $version,
			)
		);
	}

	/**
	 * Fire-counting listener for an action.
	 *
	 * @param string $hook Action.
	 * @return array Reference to the calls (each the arguments).
	 */
	private function &record( $hook ) {
		$calls = array();
		add_action(
			$hook,
			function () use ( &$calls ) {
				$calls[] = func_get_args();
			},
			10,
			3
		);

		return $calls;
	}

	// The value object.

	/**
	 * Which versions a mute covers.
	 *
	 * @dataProvider data_applies_to
	 *
	 * @param string|null $until   Mute version.
	 * @param string      $offered Offered version.
	 * @param bool        $applies Expected.
	 */
	public function test_applies_to( $until, $offered, $applies ) {
		$mute = new Mute(
			array(
				'type'  => 'plugin',
				'key'   => 'a/a.php',
				'until' => $until,
			)
		);

		$this->assertSame( $applies, $mute->applies_to( $offered ) );
	}

	/**
	 * Cases.
	 *
	 * @return array
	 */
	public function data_applies_to() {
		return array(
			'always'             => array( null, '99', true ),
			'equal'              => array( '5.3', '5.3', true ),
			'older'              => array( '5.3', '5.2', true ),
			'newer'              => array( '5.3', '5.4', false ),
			'1.10 beats 1.9'     => array( '1.9', '1.10', false ),
			'1.9 below 1.10'     => array( '1.10', '1.9', true ),
			'2.0.0 beats 2.0'    => array( '2.0', '2.0.0', false ),
			'unknown is covered' => array( '2.0', '', true ),
		);
	}

	/**
	 * Invalid arguments are refused.
	 *
	 * @dataProvider data_invalid
	 *
	 * @param array $data Arguments.
	 */
	public function test_invalid_arguments_throw( $data ) {
		$this->expectException( InvalidArgumentException::class );
		new Mute( $data );
	}

	/**
	 * Cases.
	 *
	 * @return array
	 */
	public function data_invalid() {
		return array(
			'bad type'    => array(
				array(
					'type' => 'Plug in',
					'key'  => 'a',
				),
			),
			'no key'      => array(
				array(
					'type' => 'plugin',
					'key'  => '',
				),
			),
			'empty until' => array(
				array(
					'type'  => 'plugin',
					'key'   => 'a',
					'until' => '',
				),
			),
		);
	}

	// The store.

	/**
	 * Junk in the option is dropped or normalised.
	 */
	public function test_junk_in_the_option_is_normalised() {
		sc_wpun()->network_context()->update_option(
			'sc_wpun_muted',
			array(
				'plugin'   => array(
					'ok/ok.php'   => array(
						'until' => '1.5',
						'time'  => '100',
						'user'  => '3',
					),
					'bad/bad.php' => 'junk',
					'empty/e.php' => array( 'until' => '' ),
				),
				'Bad Type' => array( 'x' => array( 'until' => null ) ),
				'theme'    => 'junk',
			)
		);

		$all = sc_wpun_mutes()->all();

		$this->assertCount( 1, $all );
		$this->assertSame( 'ok/ok.php', $all[0]->get_key() );
		$this->assertSame( '1.5', $all[0]->get_until() );
		$this->assertSame( 100, $all[0]->get_time() );
		$this->assertSame( 3, $all[0]->get_user_id() );
		$this->assertFalse( $all[0]->is_from_code() );
	}

	/**
	 * A core entry in the option is ignored, also when a filter supplies it: core cannot be muted.
	 */
	public function test_a_stored_core_mute_is_ignored() {
		sc_wpun()->network_context()->update_option( 'sc_wpun_muted', array( 'core' => array( 'core' => array( 'until' => null ) ) ) );
		$core = new \WPUN\Notification_Item(
			array(
				'type'            => 'core',
				'key'             => 'core',
				'name'            => 'WordPress',
				'current_version' => '6.7',
				'new_version'     => '6.8',
			)
		);

		$this->assertSame( array(), sc_wpun_mutes()->all() );
		$this->assertNull( sc_wpun_mutes()->get( 'core', 'core' ) );
		$this->assertFalse( sc_wpun_mutes()->is_muted( $core ) );
	}

	/**
	 * Mute and unmute plugins, themes and an add-on type.
	 */
	public function test_mute_and_unmute_by_type_and_key() {
		add_filter(
			'sc_wpun_item_types',
			function ( $types ) {
				$types['php'] = 'PHP';
				return $types;
			}
		);
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$muted   = &$this->record( 'sc_wpun_item_muted' );
		$unmuted = &$this->record( 'sc_wpun_item_unmuted' );

		foreach ( array( array( 'plugin', 'a/a.php' ), array( 'theme', 'twentytwentyfive' ), array( 'php', 'php' ) ) as $pair ) {
			$mute = sc_wpun_mutes()->mute( $pair[0], $pair[1], null, 'cli' );
			$this->assertInstanceOf( Mute::class, $mute );
			$this->assertSame( $user, $mute->get_user_id() );
			$this->assertEqualsWithDelta( time(), $mute->get_time(), 5 );
			$this->assertTrue( sc_wpun_mutes()->is_muted( $this->item( $pair[0], $pair[1] ) ) );
		}
		$this->assertCount( 3, sc_wpun_mutes()->all() );
		$this->assertCount( 3, $muted );
		$this->assertSame( 'cli', $muted[0][1] );
		$this->assertFalse( sc_wpun_mutes()->is_muted( $this->item( 'plugin', 'b/b.php' ) ) );
		$this->assertFalse( sc_wpun()->network_context()->get_option( 'sc_wpun_muted' ) === false, 'Stored.' );

		$this->assertTrue( sc_wpun_mutes()->unmute( 'theme', 'twentytwentyfive', 'settings' ) );
		$this->assertNull( sc_wpun_mutes()->get( 'theme', 'twentytwentyfive' ) );
		$this->assertCount( 1, $unmuted );
		$this->assertSame( 'settings', $unmuted[0][1] );
		$this->assertSame( 'twentytwentyfive', $unmuted[0][0]->get_key() );
	}

	/**
	 * The option is not autoloaded.
	 */
	public function test_the_option_is_not_autoloaded() {
		sc_wpun_mutes()->mute( 'plugin', 'a/a.php' );

		$this->assertNotContains( 'sc_wpun_muted', array_keys( wp_load_alloptions( true ) ) );
	}

	/**
	 * Refusals.
	 */
	public function test_refusals() {
		$store = sc_wpun_mutes();

		$this->assertWPError( $store->mute( 'core', 'core' ) );
		$this->assertWPError( $store->mute( 'nonsense', 'x' ) );
		$this->assertWPError( $store->mute( 'plugin', '' ) );
		$this->assertWPError( $store->mute( 'plugin', 'a/a.php', '' ) );
		$this->assertWPError( $store->unmute( 'plugin', 'a/a.php' ), 'Not muted.' );
		$this->assertSame( 'sc_wpun_mute_core', $store->mute( 'core', 'core' )->get_error_code() );
		$this->assertSame( array(), $store->all() );
	}

	/**
	 * Muting a muted item replaces the mute and fires again.
	 */
	public function test_muting_again_replaces_the_mute() {
		$muted = &$this->record( 'sc_wpun_item_muted' );

		sc_wpun_mutes()->mute( 'plugin', 'a/a.php', '1.5' );
		sc_wpun_mutes()->mute( 'plugin', 'a/a.php' );

		$this->assertCount( 1, sc_wpun_mutes()->all() );
		$this->assertTrue( sc_wpun_mutes()->get( 'plugin', 'a/a.php' )->is_always() );
		$this->assertCount( 2, $muted );
	}

	/**
	 * Code mutes apply, are marked, and cannot be changed or removed.
	 */
	public function test_code_mutes() {
		add_filter(
			'sc_wpun_mutes',
			function ( $mutes ) {
				$mutes[] = new Mute(
					array(
						'type' => 'plugin',
						'key'  => 'code/code.php',
					)
				);
				$mutes[] = new Mute(
					array(
						'type' => 'core',
						'key'  => 'core',
					)
				);
				$mutes[] = new Mute(
					array(
						'type' => 'nonsense',
						'key'  => 'x',
					)
				);
				$mutes[] = 'junk';
				return $mutes;
			}
		);

		$all = sc_wpun_mutes()->all();

		$this->assertCount( 1, $all, 'Core, unregistered types and junk are dropped.' );
		$this->assertTrue( $all[0]->is_from_code() );
		$this->assertTrue( sc_wpun_mutes()->is_muted( $this->item( 'plugin', 'code/code.php' ) ) );
		$this->assertWPError( sc_wpun_mutes()->unmute( 'plugin', 'code/code.php' ) );
		$this->assertWPError( sc_wpun_mutes()->mute( 'plugin', 'code/code.php' ) );
	}

	/**
	 * A code mute replaces the stored one of the same item, and the later of two wins.
	 */
	public function test_a_code_mute_replaces_a_stored_one() {
		sc_wpun_mutes()->mute( 'plugin', 'a/a.php' );
		add_filter(
			'sc_wpun_mutes',
			function ( $mutes ) {
				$mutes[] = new Mute(
					array(
						'type'  => 'plugin',
						'key'   => 'a/a.php',
						'until' => '1.0',
					)
				);
				$mutes[] = new Mute(
					array(
						'type'  => 'plugin',
						'key'   => 'a/a.php',
						'until' => '2.0',
					)
				);
				return $mutes;
			}
		);

		$mute = sc_wpun_mutes()->get( 'plugin', 'a/a.php' );

		$this->assertTrue( $mute->is_from_code() );
		$this->assertSame( '2.0', $mute->get_until() );
		$this->assertCount( 1, sc_wpun_mutes()->all() );
	}

	/**
	 * A filter cannot hide a stored mute.
	 */
	public function test_a_filter_cannot_hide_a_stored_mute() {
		sc_wpun_mutes()->mute( 'plugin', 'a/a.php' );
		add_filter( 'sc_wpun_mutes', '__return_empty_array' );

		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'a/a.php' ) );
		$this->assertFalse( sc_wpun_mutes()->get( 'plugin', 'a/a.php' )->is_from_code() );
		$this->assertTrue( sc_wpun_mutes()->unmute( 'plugin', 'a/a.php' ) );
	}

	/**
	 * A WP_Error from the pre-mute filter refuses, and so does junk.
	 */
	public function test_pre_mute_refuses() {
		$muted = &$this->record( 'sc_wpun_item_muted' );
		add_filter(
			'sc_wpun_pre_mute',
			function ( $mute, $source ) {
				return 'refuse' === $source ? new WP_Error( 'my_policy', 'No.' ) : ( 'junk' === $source ? 'junk' : $mute );
			},
			10,
			2
		);

		$error = sc_wpun_mutes()->mute( 'plugin', 'a/a.php', null, 'refuse' );
		$this->assertWPError( $error );
		$this->assertSame( 'my_policy', $error->get_error_code() );
		$this->assertWPError( sc_wpun_mutes()->mute( 'plugin', 'a/a.php', null, 'junk' ) );
		$this->assertSame( array(), sc_wpun_mutes()->all() );
		$this->assertCount( 0, $muted );
		$this->assertInstanceOf( Mute::class, sc_wpun_mutes()->mute( 'plugin', 'a/a.php' ) );
	}

	/**
	 * The pre-mute filter can change the mute, but not the item, and cannot turn a refusal into a mute.
	 */
	public function test_pre_mute_cannot_mute_something_else() {
		$replacement = null;
		add_filter(
			'sc_wpun_pre_mute',
			function () use ( &$replacement ) {
				return $replacement;
			}
		);

		$replacement = new Mute(
			array(
				'type' => 'plugin',
				'key'  => 'other/other.php',
			)
		);
		$this->assertWPError( sc_wpun_mutes()->mute( 'plugin', 'a/a.php' ) );

		$replacement = new Mute(
			array(
				'type'  => 'plugin',
				'key'   => 'a/a.php',
				'until' => '3.0',
			)
		);
		$this->assertInstanceOf( Mute::class, sc_wpun_mutes()->mute( 'plugin', 'a/a.php' ), 'Same item, changed mute.' );
		$this->assertSame( '3.0', sc_wpun_mutes()->get( 'plugin', 'a/a.php' )->get_until() );

		$replacement = new Mute(
			array(
				'type' => 'core',
				'key'  => 'core',
			)
		);
		$this->assertWPError( sc_wpun_mutes()->mute( 'core', 'core' ), 'A refusal stays one.' );
		$replacement = new Mute(
			array(
				'type' => 'nonsense',
				'key'  => 'x',
			)
		);
		$this->assertWPError( sc_wpun_mutes()->mute( 'nonsense', 'x' ) );
	}

	/**
	 * The pre-unmute filter refuses.
	 */
	public function test_pre_unmute_refuses() {
		sc_wpun_mutes()->mute( 'plugin', 'a/a.php' );
		$unmuted = &$this->record( 'sc_wpun_item_unmuted' );
		add_filter(
			'sc_wpun_pre_unmute',
			function ( $allow, $mute, $source ) {
				return 'ui' === $source ? new WP_Error( 'my_policy', 'No.' ) : $allow;
			},
			10,
			3
		);

		$this->assertSame( 'my_policy', sc_wpun_mutes()->unmute( 'plugin', 'a/a.php', 'ui' )->get_error_code() );
		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'a/a.php' ) );
		$this->assertCount( 0, $unmuted );
		$this->assertTrue( sc_wpun_mutes()->unmute( 'plugin', 'a/a.php' ) );
	}

	/**
	 * A skip mute is hidden once a newer version is offered, and pruned on the next write.
	 */
	public function test_an_ended_skip_mute_is_hidden_and_pruned() {
		sc_wpun_mutes()->mute( 'plugin', 'hello.php', '1.5' );
		sc_wpun_mutes()->mute( 'plugin', 'b/b.php' );

		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '1.5' ) ) );
		$this->assertNotNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ), 'Equal version: still in effect.' );

		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '1.6' ) ) );
		$this->assertNull( sc_wpun_mutes()->get( 'plugin', 'hello.php' ) );
		$this->assertCount( 1, sc_wpun_mutes()->all() );
		$this->assertArrayHasKey( 'hello.php', sc_wpun()->network_context()->get_option( 'sc_wpun_muted' )['plugin'], 'Reading does not write.' );
		$this->assertWPError( sc_wpun_mutes()->unmute( 'plugin', 'hello.php' ), 'It is not muted any more.' );

		sc_wpun_mutes()->mute( 'plugin', 'c/c.php' );
		$this->assertArrayNotHasKey( 'hello.php', sc_wpun()->network_context()->get_option( 'sc_wpun_muted' )['plugin'] );
	}

	/**
	 * Which version WordPress offers.
	 */
	public function test_offered_version() {
		list( $theme ) = array_keys( wp_get_themes() );
		$this->set_updates( null, array( 'hello.php' => array( 'new_version' => '1.6' ) ), array( $theme => array( 'new_version' => '4.0' ) ) );
		$store = new Mute_Store();

		$this->assertSame( '1.6', $store->offered_version( 'plugin', 'hello.php' ) );
		$this->assertSame( '4.0', $store->offered_version( 'theme', $theme ) );
		$this->assertNull( $store->offered_version( 'plugin', 'none/none.php' ) );
		$this->assertNull( $store->offered_version( 'core', 'core' ) );
	}

	/**
	 * Deleting a plugin or theme removes its mute, but only when it was deleted.
	 */
	public function test_deleting_removes_the_mute() {
		sc_wpun_mutes()->mute( 'plugin', 'a/a.php' );
		sc_wpun_mutes()->mute( 'theme', 'gone' );
		$unmuted = &$this->record( 'sc_wpun_item_unmuted' );
		$pre     = &$this->record( 'sc_wpun_pre_unmute' );

		do_action( 'deleted_plugin', 'a/a.php', false );
		do_action( 'deleted_theme', 'gone', false );
		$this->assertCount( 2, sc_wpun_mutes()->all(), 'Not deleted.' );

		do_action( 'deleted_plugin', 'a/a.php', true );
		do_action( 'deleted_theme', 'gone', true );
		$this->assertSame( array(), sc_wpun_mutes()->all() );
		$this->assertCount( 2, $unmuted );
		$this->assertSame( 'deleted', $unmuted[0][1] );
		$this->assertSame( 'a/a.php', $unmuted[0][0]->get_key() );
		$this->assertCount( 0, $pre );
	}
}
