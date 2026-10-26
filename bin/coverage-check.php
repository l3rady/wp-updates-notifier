<?php
/**
 * Line coverage from a Clover report, and a floor it must not fall below.
 *
 *   php bin/coverage-check.php build/coverage/clover.xml 80
 *
 * Prints the total and the five least covered files, and exits 1 when the total is below the minimum (a percentage,
 * decimals allowed). Used by docker/coverage.sh (make coverage); tests/bin/test-coverage-check.sh tests it.
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DevelopmentFunctions -- A command line script: it writes to the terminal and reads files directly, and nothing is sent to a browser.

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

/**
 * Stops with a message on stderr.
 *
 * @param string $message What went wrong.
 * @param int    $status  Exit status.
 */
function wpun_coverage_fail( $message, $status = 2 ) {
	fwrite( STDERR, $message . "\n" );
	exit( $status );
}

if ( $argc < 3 || ! is_file( $argv[1] ) || ! is_numeric( $argv[2] ) ) {
	wpun_coverage_fail( 'Usage: php coverage-check.php CLOVER_XML MIN_PERCENT' );
}

libxml_use_internal_errors( true );
$report = simplexml_load_file( $argv[1] );
if ( false === $report || ! isset( $report->project->metrics ) ) {
	wpun_coverage_fail( 'Not a Clover report: ' . $argv[1] );
}

$metrics = $report->project->metrics;
$total   = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
if ( 0 === $total ) {
	wpun_coverage_fail( 'The report has no statements: nothing was measured.' );
}
$percent = 100 * $covered / $total;
$min     = (float) $argv[2];

// Per file, least covered first.
$files = array();
foreach ( $report->xpath( '//file' ) as $file ) {
	$statements = (int) $file->metrics['statements'];
	if ( $statements > 0 ) {
		$files[ (string) $file['name'] ] = array( 100 * (int) $file->metrics['coveredstatements'] / $statements, $statements );
	}
}
uasort(
	$files,
	static function ( $a, $b ) {
		return $a[0] <=> $b[0];
	}
);

echo "\nLeast covered files:\n";
foreach ( array_slice( $files, 0, 5, true ) as $name => $row ) {
	printf( "  %6.2f%%  %4d statements  %s\n", $row[0], $row[1], preg_replace( '#^.*/wp-updates-notifier/#', '', $name ) );
}

printf( "\nLine coverage: %.2f%% (%d of %d statements), minimum %s%%\n", $percent, $covered, $total, $argv[2] );
if ( $percent < $min ) {
	wpun_coverage_fail( 'FAIL: coverage is below the minimum. Add tests for the code you changed.', 1 );
}
