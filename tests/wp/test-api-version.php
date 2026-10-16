<?php
/**
 * The public API version add-ons check.
 *
 * @package wp-updates-notifier
 */

/**
 * SC_WPUN_API_VERSION, sc_wpun_api_version() and sc_wpun_api_supports().
 */
class Test_Api_Version extends WPUN_Test_Case {

	/**
	 * The constant and the function agree, and the version is semver.
	 */
	public function test_the_constant_and_the_function_agree() {
		$this->assertSame( SC_WPUN_API_VERSION, sc_wpun_api_version() );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/D', sc_wpun_api_version() );
	}

	/**
	 * Same major and at least as new is supported; anything else is not.
	 *
	 * @dataProvider data_requirements
	 *
	 * @param string $required  Version an add-on asks for.
	 * @param bool   $supported Expected answer against the current API version.
	 */
	public function test_requirements( $required, $supported ) {
		$this->assertSame( $supported, sc_wpun_api_supports( $required ) );
	}

	/**
	 * Requirements relative to the current API version.
	 *
	 * @return array
	 */
	public function data_requirements() {
		list( $major, $minor, $patch ) = array_map( 'intval', explode( '.', SC_WPUN_API_VERSION ) );

		return array(
			'the same version'      => array( SC_WPUN_API_VERSION, true ),
			'an older minor'        => array( "$major.0.0", true ),
			'a newer patch'         => array( "$major.$minor." . ( $patch + 1 ), false ),
			'a newer minor'         => array( "$major." . ( $minor + 1 ) . '.0', false ),
			'the next major'        => array( ( $major + 1 ) . '.0.0', false ),
			'an older major'        => array( ( $major - 1 ) . '.9.9', false ),
			'not a version'         => array( 'latest', false ),
			'a version with a tail' => array( SC_WPUN_API_VERSION . "\n", false ),
		);
	}
}
