<?php
/**
 * A configuration source of the test add-on.
 *
 * @package wpun-test-addon
 */

namespace WPUN_Test_Addon;

use WPUN\Config_Context;
use WPUN\Config_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Supplies the values the add-on's own data lists, for the channel instances it owns, and nothing else.
 */
class Addon_Config_Source implements Config_Source {

	/**
	 * The value of a key.
	 *
	 * @param string         $key     Constant style key.
	 * @param Config_Context $context What is asked for.
	 * @return mixed Null when the add-on has nothing for it.
	 */
	public function get( string $key, Config_Context $context ) {
		if ( Config_Context::SCOPE_CHANNEL !== $context->get_scope() || 0 !== strpos( $context->get_instance_id(), Addon::ID_PREFIX ) ) {
			return null;
		}

		$config = Addon::data( 'config' );

		return is_array( $config ) ? ( $config[ $key ] ?? null ) : null;
	}

	/**
	 * Describe where a value came from.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public function get_label( string $key ): string {
		return 'the test add-on (' . $key . ')';
	}
}
