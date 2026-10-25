<?php
/**
 * `make e2e-upgrade`: the 2.0.0 package the tests install over 1.6.0. Run once by run.sh with `wp eval-file`.
 *
 * Takes the zip of `make dist` (/build) and writes a copy to uploads/wpun-upgrade/, where the web server serves it, with
 * the Version header raised to 2.0.0: the repository's header still says 1.6.0 until the release, and an update has to
 * look newer. Only the copy changes. Nothing in the repository does.
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped
if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$wpun_zips = glob( '/build/wp-updates-notifier-*.zip' );
if ( ! $wpun_zips ) {
	WP_CLI::error( 'No zip in build/: run make dist first.' );
}

$wpun_dir = WP_CONTENT_DIR . '/uploads/wpun-upgrade';
wp_mkdir_p( $wpun_dir );
$wpun_target = $wpun_dir . '/wp-updates-notifier.zip';
if ( ! copy( $wpun_zips[0], $wpun_target ) ) {
	WP_CLI::error( 'Could not copy the zip.' );
}

$wpun_zip = new ZipArchive();
if ( true !== $wpun_zip->open( $wpun_target ) ) {
	WP_CLI::error( 'Could not open the zip.' );
}
$wpun_main = $wpun_zip->getFromName( 'wp-updates-notifier/wp-updates-notifier.php' );
if ( false === $wpun_main ) {
	WP_CLI::error( 'The zip has no wp-updates-notifier/wp-updates-notifier.php.' );
}
$wpun_bumped = preg_replace( '/^( \* Version:\s+)\S+/m', '${1}2.0.0', $wpun_main, 1, $wpun_count );
if ( 1 !== $wpun_count ) {
	WP_CLI::error( 'No Version header to raise.' );
}
$wpun_zip->addFromString( 'wp-updates-notifier/wp-updates-notifier.php', $wpun_bumped );
$wpun_zip->close();
echo 'package ' . basename( $wpun_zips[0] ) . ' as 2.0.0';
