<?php
/**
 * Reads lychee's JSON report (lychee --format json --output) and says what to do with the failed links.
 *
 * Usage: php bin/docs-links.php <report.json> <retry file>
 *
 * Prints every failed link, then exits with:
 *   0  no link failed
 *   1  a link failed with something other than a 5xx answer (404, a timeout, a network error): fail at once
 *   3  every failed link answered with a 5xx: the URLs are written to the retry file, one per line
 * A report that cannot be read exits 2.
 *
 * Run by bin/docs-links.sh on a scheduled run (`make docs-links LINKS_RETRY=1`) and tested by
 * tests/bin/test-docs-links.sh. It needs only PHP.
 *
 * @package wp-updates-notifier
 */

if ( 3 !== $argc ) {
	fwrite( STDERR, "Usage: php bin/docs-links.php <report.json> <retry file>\n" );
	exit( 2 );
}

$report = is_file( $argv[1] ) ? json_decode( (string) file_get_contents( $argv[1] ), true ) : null;
if ( ! is_array( $report ) ) {
	fwrite( STDERR, "FAIL: {$argv[1]} is not a lychee JSON report\n" );
	exit( 2 );
}

$transient = array(); // URL => true, for the 5xx answers.
$hard      = false;
$lines     = array();

foreach ( array( 'error_map', 'timeout_map' ) as $map ) {
	foreach ( (array) ( $report[ $map ] ?? array() ) as $file => $entries ) {
		foreach ( (array) $entries as $entry ) {
			$url    = (string) ( $entry['url'] ?? '' );
			$status = (array) ( $entry['status'] ?? array() );
			$code   = isset( $status['code'] ) ? (int) $status['code'] : 0;
			$text   = (string) ( $status['text'] ?? $map );
			$line   = isset( $entry['span']['line'] ) ? ':' . (int) $entry['span']['line'] : '';
			if ( 'error_map' === $map && $code >= 500 && $code <= 599 ) {
				$transient[ $url ] = true;
			} else {
				$hard = true;
			}
			$lines[] = "[ERROR] $url | $text ($file$line)";
		}
	}
}

if ( ! $lines ) {
	echo "No link failed.\n";
	exit( 0 );
}

echo implode( "\n", $lines ) . "\n";
if ( $hard ) {
	exit( 1 );
}

file_put_contents( $argv[2], implode( "\n", array_keys( $transient ) ) . "\n" );
exit( 3 );
