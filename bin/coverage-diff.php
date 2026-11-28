<?php
/**
 * The changed lines that no test ran, from a Clover report and a unified diff.
 *
 *   php bin/coverage-diff.php build/coverage/clover.xml build/coverage/changes.diff [SOURCE_ROOT]
 *
 * The diff is made with `git diff -U0`. For each added or changed line of includes/, modules/, wp-updates-notifier.php
 * and uninstall.php that Clover marks as executable with a count of 0, it prints `file:line` and the line, and exits 1.
 * A changed PHP file that is not in the report at all is one finding. SOURCE_ROOT is the plugin directory the lines
 * are read from (the directory above bin/ by default).
 *
 * A line is left out when it carries `// coverage-ignore -- reason`. The marker without a reason is a finding of its own.
 * A lone `continue;` is left out too: pcov does not reliably mark it as run, even when it is.
 *
 * Used by docker/coverage.sh (make coverage BASE=...); tests/bin/test-coverage-diff.sh tests it.
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
function wpun_coverage_diff_fail( $message, $status = 2 ) {
	fwrite( STDERR, $message . "\n" );
	exit( $status );
}

/**
 * Whether a path is shipped code the coverage report measures.
 *
 * @param string $path Path relative to the plugin directory.
 * @return bool
 */
function wpun_coverage_diff_in_scope( $path ) {
	if ( '.php' !== substr( $path, -4 ) ) {
		return false;
	}
	return in_array( $path, array( 'wp-updates-notifier.php', 'uninstall.php' ), true )
		|| 0 === strpos( $path, 'includes/' )
		|| 0 === strpos( $path, 'modules/' );
}

/**
 * The added lines of a `git diff -U0`, by file. Deleted files are left out.
 *
 * @param string $diff The diff.
 * @return array<string, int[]> Path to the new line numbers.
 */
function wpun_coverage_diff_parse( $diff ) {
	$changes = array();
	$path    = null;
	$line    = 0;
	$left    = 0;
	foreach ( preg_split( '/\r?\n/', $diff ) as $row ) {
		if ( $left > 0 && '' !== $row && '+' === $row[0] ) {
			$changes[ $path ][] = $line++;
			--$left;
			continue;
		}
		if ( 0 === strpos( $row, '+++ ' ) ) {
			$name = substr( $row, 4 );
			$name = preg_replace( '/\t.*$/', '', $name );
			$path = ( '/dev/null' === $name ) ? null : preg_replace( '#^b/#', '', $name );
			if ( null !== $path ) {
				$changes[ $path ] = isset( $changes[ $path ] ) ? $changes[ $path ] : array();
			}
		} elseif ( null !== $path && preg_match( '/^@@ -\S+ \+(\d+)(?:,(\d+))? @@/', $row, $m ) ) {
			$line = (int) $m[1];
			$left = isset( $m[2] ) ? (int) $m[2] : 1;
		}
	}
	return $changes;
}

if ( $argc < 3 || ! is_file( $argv[1] ) || ! is_file( $argv[2] ) ) {
	wpun_coverage_diff_fail( 'Usage: php coverage-diff.php CLOVER_XML UNIFIED_DIFF [SOURCE_ROOT]' );
}
$root = rtrim( isset( $argv[3] ) ? $argv[3] : dirname( __DIR__ ), '/' );

libxml_use_internal_errors( true );
$report = simplexml_load_file( $argv[1] );
if ( false === $report || ! isset( $report->project ) ) {
	wpun_coverage_diff_fail( 'Not a Clover report: ' . $argv[1] );
}
$diff = file_get_contents( $argv[2] );
if ( false === $diff ) {
	wpun_coverage_diff_fail( 'Cannot read the diff: ' . $argv[2] );
}

// Clover: path relative to the plugin directory to the line numbers that are executable and were not run.
$executable = array();
foreach ( $report->xpath( '//file' ) as $file ) {
	$name                = preg_replace( '#^.*?/wp-updates-notifier/#', '', (string) $file['name'] );
	$executable[ $name ] = array();
	foreach ( $file->line as $row ) {
		$type = (string) $row['type'];
		if ( ( 'stmt' === $type || 'method' === $type ) && 0 === (int) $row['count'] ) {
			$executable[ $name ][ (int) $row['num'] ] = true;
		}
	}
}

$findings = array();
foreach ( wpun_coverage_diff_parse( $diff ) as $path => $lines ) {
	if ( ! wpun_coverage_diff_in_scope( $path ) || ! $lines ) {
		continue;
	}
	if ( ! isset( $executable[ $path ] ) ) {
		$findings[] = $path . ' is not in the coverage report: no test loads it.';
		continue;
	}
	$source = is_file( $root . '/' . $path ) ? file( $root . '/' . $path, FILE_IGNORE_NEW_LINES ) : false;
	foreach ( $lines as $number ) {
		$text = ( false !== $source && isset( $source[ $number - 1 ] ) ) ? $source[ $number - 1 ] : '';
		if ( preg_match( '#//\s*coverage-ignore\b(.*)$#', $text, $marker ) ) {
			if ( ! preg_match( '/^\s*--\s*\S/', $marker[1] ) ) {
				$findings[] = $path . ':' . $number . ': coverage-ignore needs a reason after --' . "\n    " . trim( $text );
			}
			continue;
		}
		// pcov does not reliably mark a lone continue as run, so it is never reported.
		if ( preg_match( '#^\s*continue\s*;\s*(//.*|/\*.*\*/)?\s*$#', $text ) ) {
			continue;
		}
		if ( isset( $executable[ $path ][ $number ] ) ) {
			$findings[] = $path . ':' . $number . ': no test runs this line' . "\n    " . trim( $text );
		}
	}
}

if ( ! $findings ) {
	echo "No uncovered changed lines.\n";
	exit( 0 );
}

echo "\nChanged lines that no test runs:\n";
foreach ( $findings as $finding ) {
	echo '  ' . $finding . "\n";
}
echo "\nAdd a test for them, or mark a line that cannot be tested with: // coverage-ignore -- reason\n";
exit( 1 );
