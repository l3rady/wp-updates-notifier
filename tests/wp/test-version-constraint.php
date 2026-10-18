<?php
/**
 * The version constraint matcher.
 *
 * @package wp-updates-notifier
 */

use WPUN\Version_Constraint;

/**
 * WPUN\Version_Constraint::satisfies() and is_valid().
 */
class Test_Version_Constraint extends WPUN_Test_Case {

	/**
	 * A version satisfies or fails a valid constraint.
	 *
	 * @dataProvider data_constraints
	 *
	 * @param string $version    The version.
	 * @param string $constraint The constraint.
	 * @param bool   $expected   Expected answer.
	 */
	public function test_satisfies( $version, $constraint, $expected ) {
		$this->assertSame( $expected, Version_Constraint::satisfies( $version, $constraint ) );
		$this->assertTrue( Version_Constraint::is_valid( $constraint ) );
	}

	/**
	 * Version, constraint and expected answer.
	 *
	 * @return array
	 */
	public function data_constraints() {
		$rows = array(
			// Exact.
			array( '1.2.0', '1.2.0', true ),
			array( '1.2.1', '1.2.0', false ),
			array( '1.2.0', '=1.2.0', true ),
			array( '1.2.1', '=1.2.0', false ),
			array( '1.2', '1.2', true ),
			array( '1.2.0', '1.2', true ),
			array( '1', '1.0.0', true ),
			array( '1.0.1', '1', false ),
			// Comparison.
			array( '1.0.0', '>=1.0', true ),
			array( '0.9.9', '>=1.0', false ),
			array( '1.0.0', '>1.0', false ),
			array( '1.0.1', '>1.0', true ),
			array( '1.99.99', '<2.0', true ),
			array( '2.0.0', '<2.0', false ),
			array( '1.4.0', '<=1.4', true ),
			array( '1.4.1', '<=1.4', false ),
			array( '1.2.0', '!=1.3.0', true ),
			array( '1.3.0', '!=1.3.0', false ),
			array( '1.3', '!=1.3.0', false ),
			// Caret.
			array( '1.1.9', '^1.2', false ),
			array( '1.2.0', '^1.2', true ),
			array( '1.99.99', '^1.2', true ),
			array( '2.0.0', '^1.2', false ),
			array( '1.2.2', '^1.2.3', false ),
			array( '1.2.3', '^1.2.3', true ),
			array( '1.9.0', '^1.2.3', true ),
			array( '2.0.0', '^1.2.3', false ),
			array( '1.0.0', '^1', true ),
			array( '1.9.9', '^1', true ),
			array( '2.0.0', '^1', false ),
			array( '0.9.9', '^1', false ),
			array( '0.2.9', '^0.3', false ),
			array( '0.3.0', '^0.3', true ),
			array( '0.3.9', '^0.3', true ),
			array( '0.4.0', '^0.3', false ),
			array( '0.3.3', '^0.3.4', false ),
			array( '0.3.4', '^0.3.4', true ),
			array( '0.3.9', '^0.3.4', true ),
			array( '0.4.0', '^0.3.4', false ),
			array( '0.0.2', '^0.0.3', false ),
			array( '0.0.3', '^0.0.3', true ),
			array( '0.0.4', '^0.0.3', false ),
			array( '0.0.0', '^0', true ),
			array( '0.9.9', '^0', true ),
			array( '1.0.0', '^0', false ),
			array( '0.0.0', '^0.0', true ),
			array( '0.0.9', '^0.0', true ),
			array( '0.1.0', '^0.0', false ),
			// Tilde.
			array( '1.0.0', '~1', true ),
			array( '1.9.9', '~1', true ),
			array( '2.0.0', '~1', false ),
			array( '1.1.9', '~1.2', false ),
			array( '1.2.0', '~1.2', true ),
			array( '1.9.0', '~1.2', true ),
			array( '2.0.0', '~1.2', false ),
			array( '1.2.2', '~1.2.3', false ),
			array( '1.2.3', '~1.2.3', true ),
			array( '1.2.99', '~1.2.3', true ),
			array( '1.3.0', '~1.2.3', false ),
			// Wildcard.
			array( '0.9.9', '1.*', false ),
			array( '1.0.0', '1.*', true ),
			array( '1.9.9', '1.*', true ),
			array( '2.0.0', '1.*', false ),
			array( '1.1.9', '1.2.*', false ),
			array( '1.2.0', '1.2.*', true ),
			array( '1.2.9', '1.2.*', true ),
			array( '1.3.0', '1.2.*', false ),
			// AND.
			array( '1.2.0', '>=1.0 <1.4', true ),
			array( '1.4.0', '>=1.0 <1.4', false ),
			array( '0.9.0', '>=1.0 <1.4', false ),
			array( '1.2.0', '>=1.0,<1.4', true ),
			array( '1.4.0', '>=1.0,<1.4', false ),
			array( '1.2.0', '>=1.0, <1.4', true ),
			array( '1.4.0', '>=1.0, <1.4', false ),
			array( '1.2.0', '>=1.0 , <1.4', true ),
			array( '1.2.0', ">=1.0\t<1.4", true ),
			array( '1.3.0', '>=1.0 <1.4 !=1.3.0', false ),
			array( '1.3.1', '>=1.0 <1.4 !=1.3.0', true ),
			// OR.
			array( '1.5.0', '^1.0 || ^2.0', true ),
			array( '2.5.0', '^1.0 || ^2.0', true ),
			array( '3.0.0', '^1.0 || ^2.0', false ),
			array( '2.5.0', '^1.0||^2.0', true ),
			array( '3.0.0', '^1.0||^2.0', false ),
			array( '2.5.0', '^1.0 ||^2.0', true ),
			array( '1.0.0', '>=1.1 <1.4 || ^2.0', false ),
			array( '1.2.0', '>=1.1 <1.4 || ^2.0', true ),
			array( '2.1.0', '>=1.1 <1.4 || ^2.0', true ),
			array( '1.5.0', '>=1.1 <1.4 || ^2.0', false ),
			// White space.
			array( '1.0.0', '>= 1.0', true ),
			array( '0.9.0', '>= 1.0', false ),
			array( '1.0.0', '>=   1.0', true ),
			array( '1.5.0', '  ^1.0  ', true ),
			array( '2.0.0', "\t^1.0\n", false ),
			array( '1.5.0', '^ 1.0', true ),
			array( '1.5.0', '>= 1.0 , < 2.0', true ),
			array( '2.5.0', '>= 1.0 , < 2.0 || ^ 2.0', true ),
			// Missing parts in the version.
			array( '1', '^1.0', true ),
			array( '1.2', '~1.2.0', true ),
			array( '2', '<2.0', false ),
		);

		$data = array();

		foreach ( $rows as $row ) {
			$data[ "{$row[0]} against {$row[1]}" ] = $row;
		}

		return $data;
	}

	/**
	 * A constraint that cannot be read never matches, and is reported.
	 *
	 * @dataProvider data_invalid_constraints
	 *
	 * @param string $constraint The constraint.
	 */
	public function test_an_invalid_constraint_is_false_with_a_notice( $constraint ) {
		$this->setExpectedIncorrectUsage( 'WPUN\Version_Constraint::satisfies' );

		$this->assertFalse( Version_Constraint::satisfies( '1.0.0', $constraint ) );
		$this->assertFalse( Version_Constraint::is_valid( $constraint ) );
	}

	/**
	 * Constraints that are not valid.
	 *
	 * @return array
	 */
	public function data_invalid_constraints() {
		$constraints = array(
			'empty'                  => '',
			'white space only'       => '  ',
			'an operator alone'      => '>=',
			'a caret alone'          => '^',
			'not a number'           => '^abc',
			'four parts'             => '1.2.3.4',
			'four parts with caret'  => '^1.2.3.4',
			'a doubled operator'     => '>> 1',
			'ruby pessimistic'       => '~>1.0',
			'reversed operator'      => '=>1.0',
			'hyphen range'           => '1.0 - 2.0',
			'a bare wildcard'        => '*',
			'a wildcard after =='    => '>=1.*',
			'a wildcard too deep'    => '1.2.3.*',
			'a v prefix'             => 'v1.0',
			'a v prefix on a caret'  => '^v1.0',
			'a pre-release'          => '1.0.0-beta',
			'a pre-release on caret' => '^1.0-rc1',
			'a single pipe'          => '^1.0 | ^2.0',
			'a trailing or'          => '^1.0 ||',
			'a leading or'           => '|| ^1.0',
			'an empty or branch'     => '^1.0 || || ^2.0',
			'a trailing comma'       => '>=1.0,',
			'a leading comma'        => ',>=1.0',
			'a doubled comma'        => '>=1.0,,<2.0',
			'a stray word'           => '^1.0 and ^2.0',
			'a leading dot'          => '.1',
			'a trailing dot'         => '1.',
		);

		$data = array();

		foreach ( $constraints as $label => $constraint ) {
			$data[ $label ] = array( $constraint );
		}

		return $data;
	}

	/**
	 * An invalid version is false with a notice, whatever the constraint.
	 *
	 * @dataProvider data_invalid_versions
	 *
	 * @param string $version The version.
	 */
	public function test_an_invalid_version_is_false_with_a_notice( $version ) {
		$this->setExpectedIncorrectUsage( 'WPUN\Version_Constraint::satisfies' );

		$this->assertFalse( Version_Constraint::satisfies( $version, '>=0.0.0' ) );
	}

	/**
	 * Versions that are not one to three numeric parts.
	 *
	 * @return array
	 */
	public function data_invalid_versions() {
		return array(
			'empty'         => array( '' ),
			'a pre-release' => array( '1.0.0-beta' ),
			'four parts'    => array( '1.2.3.4' ),
			'a v prefix'    => array( 'v1.0.0' ),
			'a tail'        => array( "1.0.0\n" ),
			'a word'        => array( 'latest' ),
		);
	}

	/**
	 * The notice fires once per distinct constraint string.
	 */
	public function test_an_invalid_constraint_is_reported_once_per_string() {
		$count = 0;
		add_action(
			'doing_it_wrong_run',
			static function () use ( &$count ) {
				++$count;
			}
		);
		$this->setExpectedIncorrectUsage( 'WPUN\Version_Constraint::satisfies' );

		Version_Constraint::satisfies( '1.0.0', 'once-a' );
		Version_Constraint::satisfies( '1.0.0', 'once-a' );
		$this->assertSame( 1, $count );

		Version_Constraint::satisfies( '1.0.0', 'once-b' );
		Version_Constraint::satisfies( '1.0.0', 'once-b' );
		Version_Constraint::satisfies( '1.0.0', 'once-a' );
		$this->assertSame( 2, $count );
	}

	/**
	 * Asking whether a constraint is valid never reports it.
	 */
	public function test_is_valid_does_not_trigger_a_notice() {
		$count = 0;
		add_action(
			'doing_it_wrong_run',
			static function () use ( &$count ) {
				++$count;
			}
		);

		$this->assertFalse( Version_Constraint::is_valid( 'is-valid-nope' ) );
		$this->assertTrue( Version_Constraint::is_valid( '^1.0 || >=3.0 <4.0' ) );
		$this->assertSame( 0, $count );
	}
}
