<?php
/**
 * A channel instance does not give up its settings when it is serialised (#325).
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * The settings of an instance can hold resolved secrets, so `serialize()` redacts them as `var_dump()` does.
 *
 * @group security
 */
class Test_Hardening_Instance_Serialize extends WPUN_Security_Test_Case {

	/**
	 * The secret is not in the serialised instance, and the rest is.
	 */
	public function test_serialize_redacts_the_settings() {
		$instance = new Channel_Instance( 'hook', 'webhook', 'Hook', true, array( 'token' => 'ok-SECRETTOKEN-7f3c1a' ) );

		$serialised = serialize( $instance ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Looks for a leak.

		$this->assertStringNotContainsString( 'SECRETTOKEN', $serialised );
		$this->assertStringContainsString( 'token', $serialised, 'The keys are kept.' );
		$this->assertStringContainsString( 'Hook', $serialised );
	}
}
