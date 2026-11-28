<?php
/**
 * Turns PHPCS's Checkstyle report into GitHub workflow commands, so a coding-standard failure shows on the line.
 *
 * Usage: php bin/checkstyle-annotations.php <report.xml>...
 *
 * Prints one `::error` or `::warning` line for each finding (the severity PHPCS gave it) on stdout, where GitHub reads
 * them. File paths are made relative to the current directory, the repository root. The message and the sniff name
 * come from the report, so they are escaped: a message cannot end the command or start another one.
 * Run by bin/lint.sh when GITHUB_ACTIONS is set; tested by tests/bin/test-ci-annotations.sh.
 *
 * @package wp-updates-notifier
 */

/**
 * Escapes the data of a workflow command (the text after the last ::).
 *
 * @param string $text Text.
 * @return string
 */
function wpun_annotation_data( $text ) {
	return str_replace( array( '%', "\r", "\n" ), array( '%25', '%0D', '%0A' ), $text );
}

/**
 * Escapes a property of a workflow command (file=, title=).
 *
 * @param string $text Text.
 * @return string
 */
function wpun_annotation_property( $text ) {
	return str_replace( array( ':', ',' ), array( '%3A', '%2C' ), wpun_annotation_data( $text ) );
}

/**
 * Makes a path relative to the repository root, the current directory.
 *
 * @param string $path Path from the report.
 * @param string $root Current directory.
 * @return string
 */
function wpun_annotation_path( $path, $root ) {
	$root = rtrim( $root, '/' ) . '/';
	return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
}

/**
 * The workflow commands for one Checkstyle report.
 *
 * @param string $xml  The report.
 * @param string $root Current directory.
 * @return string[] One command each.
 */
function wpun_annotations( $xml, $root ) {
	$lines  = array();
	libxml_use_internal_errors( true );
	$report = simplexml_load_string( $xml );
	if ( false === $report ) {
		return $lines;
	}
	foreach ( $report->file as $file ) {
		$path = wpun_annotation_path( (string) $file['name'], $root );
		foreach ( $file->error as $error ) {
			$level   = 'warning' === (string) $error['severity'] ? 'warning' : 'error';
			$lines[] = sprintf(
				'::%s file=%s,line=%d,col=%d,title=%s::%s',
				$level,
				wpun_annotation_property( $path ),
				(int) $error['line'],
				(int) $error['column'],
				wpun_annotation_property( (string) $error['source'] ),
				wpun_annotation_data( (string) $error['message'] )
			);
		}
	}
	return $lines;
}

if ( isset( $argv ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	if ( $argc < 2 ) {
		fwrite( STDERR, "Usage: php bin/checkstyle-annotations.php <report.xml>...\n" );
		exit( 2 );
	}
	$root = (string) getcwd();
	foreach ( array_slice( $argv, 1 ) as $report_file ) {
		if ( ! is_file( $report_file ) ) {
			// A run that failed before it wrote a report: the log has the reason.
			continue;
		}
		foreach ( wpun_annotations( (string) file_get_contents( $report_file ), $root ) as $line ) {
			echo $line . "\n";
		}
	}
}
