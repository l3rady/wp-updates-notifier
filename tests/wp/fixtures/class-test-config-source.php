<?php
/**
 * A configuration source for tests.
 *
 * @package wp-updates-notifier
 */

use WPUN\Config_Context;
use WPUN\Config_Source;

/**
 * Returns the values it was given by key, and can be told to throw.
 */
class Test_Config_Source implements Config_Source {

	/**
	 * Values by key.
	 *
	 * @var array
	 */
	public $values;

	/**
	 * Whether get() throws.
	 *
	 * @var bool
	 */
	public $throws = false;

	/**
	 * How often get() was called.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Constructor.
	 *
	 * @param array $values Values by key.
	 */
	public function __construct( array $values = array() ) {
		$this->values = $values;
	}

	/**
	 * The value.
	 *
	 * @param string         $key     Key.
	 * @param Config_Context $context Context.
	 * @return mixed
	 * @throws RuntimeException When asked to.
	 */
	public function get( string $key, Config_Context $context ) {
		++$this->calls;
		if ( $this->throws ) {
			throw new RuntimeException( 'vault-token-123 leaked' );
		}

		return $this->values[ $key ] ?? null;
	}

	/**
	 * Label.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public function get_label( string $key ): string {
		return 'Test vault';
	}
}
