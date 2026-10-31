<?php
/**
 * Merges the coverage of several runs of the suite into one report.
 *
 *   php bin/coverage-merge.php VENDOR_AUTOLOAD OUT_DIR RUN.cov [RUN.cov ...]
 *
 * Each RUN.cov is what PHPUnit's --coverage-php wrote. Writes OUT_DIR/clover.xml and OUT_DIR/html/, and prints the text
 * summary. Used by docker/coverage.sh (make coverage) to count the single-site and the multisite run together: the code
 * of each mode is only run by the suite of that mode.
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DevelopmentFunctions -- A command line script: it writes to the terminal and reads files directly, and nothing is sent to a browser.

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as Html;
use SebastianBergmann\CodeCoverage\Report\Text;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

if ( $argc < 4 || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php coverage-merge.php VENDOR_AUTOLOAD OUT_DIR RUN.cov [RUN.cov ...]\n" );
	exit( 2 );
}

require $argv[1];

$wpun_merged = null;
foreach ( array_slice( $argv, 3 ) as $wpun_run ) {
	$wpun_coverage = is_file( $wpun_run ) ? include $wpun_run : null;
	if ( ! $wpun_coverage instanceof CodeCoverage ) {
		fwrite( STDERR, "Not a PHPUnit coverage file: $wpun_run\n" );
		exit( 2 );
	}
	if ( null === $wpun_merged ) {
		$wpun_merged = $wpun_coverage;
	} else {
		$wpun_merged->merge( $wpun_coverage );
	}
}

// Files no test loaded are still counted, from their source; including them here would run their ABSPATH guard and exit.
$wpun_merged->doNotProcessUncoveredFiles();

$wpun_out = rtrim( $argv[2], '/' );
( new Clover() )->process( $wpun_merged, $wpun_out . '/clover.xml' );
( new Html() )->process( $wpun_merged, $wpun_out . '/html' );
echo ( new Text() )->process( $wpun_merged, false );
