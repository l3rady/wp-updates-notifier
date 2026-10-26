<?php
/**
 * A channel whose field schema throws, for tests.
 *
 * @package wp-updates-notifier
 */

/**
 * Behaves as Test_Channel until `$explode` is set, then get_fields() throws: the schema is read when the store
 * checks an instance for values supplied from outside the database.
 */
class Test_Throwing_Fields_Channel extends Test_Channel {

	/**
	 * Whether get_fields() throws.
	 *
	 * @var bool
	 */
	public $explode = false;

	/**
	 * Fields, or an exception carrying a secret in its message.
	 *
	 * @return array
	 * @throws RuntimeException When `$explode` is set.
	 */
	public function get_fields(): array {
		if ( $this->explode ) {
			throw new RuntimeException( 'schema blew up with secret-xyz' );
		}

		return parent::get_fields();
	}
}
