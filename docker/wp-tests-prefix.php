<?php
/**
 * The rules that keep test runs apart on the one shared test database (decision 0032).
 *
 * Loaded by the wp-tests-config.php that docker/wp-tests-entrypoint.sh renders (the table prefix), by
 * docker/wp-tests-drop.php (the tables to drop when a run ends) and by bin/fresh-wordpress.sh (the database name).
 * tests/bin/test-test-prefix.sh tests it. It uses no WordPress function: it runs before WordPress loads.
 *
 * @package wp-updates-notifier
 */

/**
 * Keep the characters that are safe in a table or database name: lower case letters, digits and the underscore.
 *
 * @param mixed $part A run token or a worker number. False (an unset environment variable) gives ''.
 * @return string
 */
function wpun_tests_clean( $part ) {
	return preg_replace( '/[^0-9a-z_]/', '', strtolower( (string) $part ) );
}

/**
 * The table prefix of one PHPUnit process: wptests_<run>_<worker>_, or wptests_<run>_ without a worker.
 *
 * Infection's workers are numbered from 0, and "0" is a worker, so only false (unset) and '' mean "no worker".
 *
 * @param mixed $run    The run token (WPUN_RUN_TOKEN). Empty gives "x".
 * @param mixed $worker The worker number (TEST_TOKEN), or false.
 * @return string
 */
function wpun_tests_table_prefix( $run, $worker = false ) {
	$run = wpun_tests_clean( $run );
	if ( '' === $run ) {
		$run = 'x';
	}
	$prefix = 'wptests_' . $run . '_';
	if ( false !== $worker && '' !== wpun_tests_clean( $worker ) ) {
		$prefix .= wpun_tests_clean( $worker ) . '_';
	}
	return $prefix;
}

/**
 * Escape a string for a LIKE pattern: the underscore and the percent sign are wildcards, the backslash escapes.
 *
 * @param string $text The text to match literally.
 * @return string
 */
function wpun_tests_like_escape( $text ) {
	return addcslashes( $text, '_%\\' );
}

/**
 * The LIKE pattern for every table of a run, whatever its worker: wptests\_<run>\_%.
 *
 * @param mixed $run The run token (WPUN_RUN_TOKEN). Empty gives "x".
 * @return string
 */
function wpun_tests_like_pattern( $run ) {
	$run = wpun_tests_clean( $run );
	return wpun_tests_like_escape( 'wptests_' . ( '' === $run ? 'x' : $run ) . '_' ) . '%';
}
