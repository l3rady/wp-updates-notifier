<?php
/**
 * Fails when the shape of what the plugin stores changes without a settings version bump.
 *
 * @package wp-updates-notifier
 */

/**
 * The keys and value types of the stored options, pinned per settings version.
 */
class Test_Stored_Shape extends WPUN_Test_Case {

	/**
	 * The keys and value types of a value, with no values.
	 *
	 * Lists describe their elements' types, so the count does not matter. Keys are sorted. The type names are
	 * the same on every PHP version.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return mixed A string for a scalar, an array of key => shape for an array.
	 */
	private static function shape( $value ) {
		if ( ! is_array( $value ) ) {
			return gettype( $value );
		}

		if ( array() === $value ) {
			return 'array';
		}

		if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
			$types = array();
			foreach ( $value as $item ) {
				$types[ wp_json_encode( self::shape( $item ) ) ] = self::shape( $item );
			}
			ksort( $types );

			return array( '[]' => array_values( $types ) );
		}

		$shape = array();
		foreach ( $value as $key => $item ) {
			$shape[ (string) $key ] = self::shape( $item );
		}
		ksort( $shape );

		return $shape;
	}

	/**
	 * A run state with every optional field the plugin can write, as the state normalises it.
	 *
	 * Maps keyed by an instance id, item type or plugin file are described by one example entry.
	 *
	 * @return array
	 */
	private function state(): array {
		$notified = array( 'plugin' => array( 'a/a.php' => '1.0' ) );
		$state    = sc_wpun()->state()->normalise(
			array(
				'channels'        => array(
					'x' => array(
						'notified'      => $notified,
						'skip_pending'  => true,
						'notified_meta' => array(
							'plugin' => array(
								'a/a.php' => array(
									'name'            => 'A',
									'current_version' => '0.9',
									'links'           => array( 'changelog' => 'https://example.org' ),
									'time'            => 1700000000,
									'snoozed'         => true,
								),
							),
						),
						'last_result'   => array(
							'success' => true,
							'message' => 'ok',
							'time'    => 1700000000,
						),
						'retry_at'      => 1700000000,
						'held_until'    => 1700000000,
						'last_reminder' => 1700000000,
						'reminder_sent' => $notified,
					),
				),
				'last_check_time' => 1700000000,
				'legacy_notified' => array(
					'core'   => '1.0',
					'plugin' => array(),
					'theme'  => array(),
				),
				'ended_mutes'     => $notified,
				'ended_snoozes'   => $notified,
			)
		);

		return $state;
	}

	/**
	 * Checksum of the shapes of the stored and default settings, the stored email instance and the run state.
	 *
	 * @return string
	 */
	private function checksum(): string {
		// What a fresh install stores: the settings and the email instance with every field it has.
		$context = sc_wpun()->network_context();
		$context->delete_option( \WPUN\Settings::OPT_FIELD );
		$context->delete_option( \WPUN\Settings::OPT_VERSION_FIELD );
		$context->delete_option( \WPUN\Channel_Store::OPT_FIELD );
		sc_wpun()->settings()->maybe_upgrade();

		$stored = $context->read( \WPUN\Channel_Store::OPT_FIELD );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'email', $stored );
		$this->assertNotEmpty( $stored['email']['settings'] ?? array(), 'The email instance is stored with its fields.' );

		$tree = array(
			'option'   => self::shape( $context->read( \WPUN\Settings::OPT_FIELD ) ),
			'settings' => self::shape( sc_wpun()->settings()->default_settings() ),
			'channel'  => self::shape( $stored['email'] ),
			'state'    => self::shape( $this->state() ),
		);

		return md5( (string) wp_json_encode( $tree ) );
	}

	/**
	 * The shape matches the one pinned for the current settings version.
	 */
	public function test_the_stored_shape_matches_the_settings_version() {
		$pinned   = require dirname( __DIR__ ) . '/fixtures/stored-shapes.php';
		$checksum = $this->checksum();

		// A version that is not in the map yet is compared with the newest one: Test_Contract pins the bump itself.
		uksort( $pinned, 'version_compare' );
		$expected = $pinned[ self::SETTINGS_VERSION ] ?? end( $pinned );

		$this->assertSame(
			$expected,
			$checksum,
			'The stored shape changed. Bump `Settings::OPT_VERSION`, add the upgrade step in `maybe_upgrade()`, add the old shape to `tests/fixtures/settings.php`, then add the new version to this map (tests/fixtures/stored-shapes.php) with checksum ' . $checksum . '.'
		);
	}
}
