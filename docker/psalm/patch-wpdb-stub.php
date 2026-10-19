<?php
/**
 * Marks wpdb::query() as an SQL sink in php-stubs/wordpress-stubs.
 *
 * Psalm keeps the first declaration of a class, so wordpress-taint.phpstub cannot redeclare wpdb the way it
 * redeclares functions. Run once by the Dockerfile; fails if the stubs change shape, so that a bump of the
 * stubs cannot silently lose the sink.
 *
 * @package WP_Updates_Notifier
 */

$file = $argv[1] ?? '';
$code = is_file( $file ) ? file_get_contents( $file ) : false;
if ( false === $code ) {
	fwrite( STDERR, "Usage: php patch-wpdb-stub.php <wordpress-stubs.php>\n" );
	exit( 1 );
}

$class = strpos( $code, "\n    class wpdb\n" );
$needle = "         * @param string \$query Database query.\n         * @return int|bool Boolean true for CREATE";
$at     = false === $class ? false : strpos( $code, $needle, $class );
if ( false === $at ) {
	fwrite( STDERR, "wpdb::query() not found in the stubs; update patch-wpdb-stub.php\n" );
	exit( 1 );
}

$code = substr_replace( $code, "         * @psalm-taint-sink sql \$query\n", $at, 0 );
file_put_contents( $file, $code );
echo "wpdb::query() is an SQL sink\n";
