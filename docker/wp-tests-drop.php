<?php
/**
 * Drop the tables of this run (WPUN_RUN_TOKEN) from the test database. Run by docker/wp-tests-entrypoint.sh when the
 * run ends, whatever its result. A failure is reported and never changes the exit code of the run.
 *
 * @package wp-updates-notifier
 */

require_once __DIR__ . '/wp-tests-prefix.php';

$run = getenv( 'WPUN_RUN_TOKEN' );
if ( ! is_string( $run ) || '' === wpun_tests_clean( $run ) ) {
	// Without a token the pattern would be the one of a hand-run "x": never drop on a guess.
	exit( 0 );
}

try {
	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
	$wpun_db     = new mysqli( getenv( 'WP_DB_HOST' ) ?: 'db', getenv( 'WP_DB_USER' ) ?: 'root', getenv( 'WP_DB_PASSWORD' ) ?: 'root', getenv( 'WP_DB_NAME' ) ?: 'wordpress_test' );
	$wpun_result = $wpun_db->query( "SHOW TABLES LIKE '" . $wpun_db->real_escape_string( wpun_tests_like_pattern( $run ) ) . "'" );
	$wpun_tables = array();
	foreach ( $wpun_result->fetch_all() as $wpun_row ) {
		$wpun_tables[] = '`' . str_replace( '`', '``', $wpun_row[0] ) . '`';
	}
	if ( $wpun_tables ) {
		$wpun_db->query( 'SET FOREIGN_KEY_CHECKS = 0' );
		$wpun_db->query( 'DROP TABLE IF EXISTS ' . implode( ', ', $wpun_tables ) );
	}
} catch ( Throwable $wpun_e ) {
	fwrite( STDERR, 'Could not drop the tables of run ' . wpun_tests_clean( $run ) . ': ' . $wpun_e->getMessage() . "\n" );
}
