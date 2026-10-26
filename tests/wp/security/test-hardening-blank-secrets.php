<?php
/**
 * A secret of only blanks keeps the stored value (HN3 of the 2.0.0 security audit).
 *
 * @package wp-updates-notifier
 */

use WPUN\Field_Types;

require_once __DIR__ . '/class-wpun-security-test-case.php';

/**
 * A secret made of spaces or control characters is nothing once cleaned. It is treated as nothing typed, so it
 * does not clear a stored secret.
 *
 * @group security
 */
class Test_Hardening_Blank_Secrets extends WPUN_Security_Test_Case {

	/**
	 * Submissions that are nothing once cleaned.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function blank_values() {
		return array(
			'spaces'           => array( '   ' ),
			'tab and newline'  => array( "\t\n " ),
			'control chars'    => array( "\x01\x02\x7F" ),
			'spaces and nulls' => array( " \x00 " ),
		);
	}

	/**
	 * The schema of a secret.
	 *
	 * @param bool $required Whether the field is required.
	 * @return array<string, array<string, mixed>>
	 */
	private function fields( bool $required ): array {
		return array(
			'token' => array(
				'type'     => 'secret',
				'label'    => 'Token',
				'required' => $required,
			),
		);
	}

	/**
	 * A blank secret keeps the stored value of an optional field.
	 *
	 * @dataProvider blank_values
	 * @param string $blank Submitted value.
	 */
	public function test_a_blank_secret_keeps_the_stored_value( $blank ) {
		$result = Field_Types::validate( $this->fields( false ), array( 'token' => $blank ), array( 'token' => 'kept-secret' ) );

		$this->assertSame( array( 'token' => 'kept-secret' ), $result );
	}

	/**
	 * A blank secret is stored as empty for an optional field with no stored value, never raw.
	 *
	 * @dataProvider blank_values
	 * @param string $blank Submitted value.
	 */
	public function test_a_blank_secret_without_a_stored_value_is_stored_empty( $blank ) {
		$this->assertSame( array( 'token' => '' ), Field_Types::validate( $this->fields( false ), array( 'token' => $blank ), array() ) );
	}

	/**
	 * A blank secret keeps the stored value of a required field.
	 *
	 * @dataProvider blank_values
	 * @param string $blank Submitted value.
	 */
	public function test_a_blank_required_secret_keeps_the_stored_value( $blank ) {
		$result = Field_Types::validate( $this->fields( true ), array( 'token' => $blank ), array( 'token' => 'kept-secret' ) );

		$this->assertSame( array( 'token' => 'kept-secret' ), $result );
	}

	/**
	 * A blank secret is a required error for a required field with no stored value.
	 *
	 * @dataProvider blank_values
	 * @param string $blank Submitted value.
	 */
	public function test_a_blank_required_secret_without_a_stored_value_is_an_error( $blank ) {
		$result = Field_Types::validate( $this->fields( true ), array( 'token' => $blank ), array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'token', $result->get_error_data()['field'] ?? null );
	}

	/**
	 * A real value still replaces the stored one, and Clear still empties an optional secret.
	 */
	public function test_a_value_replaces_and_clear_empties() {
		$this->assertSame( array( 'token' => 'new-secret' ), Field_Types::validate( $this->fields( false ), array( 'token' => '  new-secret ' ), array( 'token' => 'old' ) ) );
		$this->assertSame( array( 'token' => '' ), Field_Types::validate( $this->fields( false ), array( 'token__clear' => '1' ), array( 'token' => 'old' ) ) );
	}

	/**
	 * A supplied secret of only blanks is cleaned too: the instance gets an empty value, not the raw one.
	 */
	public function test_a_blank_supplied_secret_is_cleaned() {
		$fields  = $this->fields( false );
		$channel = new class( 'blank_secret', $fields ) extends Test_Channel {
			/**
			 * Fields.
			 *
			 * @var array
			 */
			private $schema;

			/**
			 * Constructor.
			 *
			 * @param string $type   Channel type.
			 * @param array  $schema Fields.
			 */
			public function __construct( $type, array $schema ) {
				parent::__construct( $type );
				$this->schema = $schema;
			}

			/**
			 * Fields.
			 *
			 * @return array
			 */
			public function get_fields(): array {
				return $this->schema;
			}
		};
		sc_wpun()->channels()->register( $channel );
		sc_wpun_channels()->save( new WPUN\Channel_Instance( 'blanks', 'blank_secret', 'Blanks', true ) );
		putenv( "SC_WPUN_CHANNEL_BLANKS__TOKEN= \t " ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The environment route is under test.
		putenv( 'SC_WPUN_LOAD_FROM_ENV=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- The switch a site owner uses.
		sc_wpun()->config()->flush();

		try {
			$this->assertSame( '', sc_wpun_channels()->get( 'blanks' )->get_setting( 'token' ) );
		} finally {
			putenv( 'SC_WPUN_CHANNEL_BLANKS__TOKEN' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Put back.
			putenv( 'SC_WPUN_LOAD_FROM_ENV' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Put back.
			sc_wpun()->channels()->unregister( 'blank_secret' );
			sc_wpun()->config()->flush();
		}
	}
}
