<?php
// Test cases for wpun.yml: `semgrep --test .semgrep/`. Not shipped and not run.

// wpun-forbidden-function
// ruleid: wpun-forbidden-function
eval( $code );
// ruleid: wpun-forbidden-function
assert( 'is_string( $a )' );
// ruleid: wpun-forbidden-function
assert( 'is_' . $type . '( $a )' );
// ruleid: wpun-forbidden-function
$f = create_function( '$a', 'return $a;' );
// ruleid: wpun-forbidden-function
extract( $values );
// ruleid: wpun-forbidden-function
exec( 'ls' );
// ruleid: wpun-forbidden-function
shell_exec( 'ls' );
// ruleid: wpun-forbidden-function
system( 'ls' );
// ruleid: wpun-forbidden-function
passthru( 'ls' );
// ruleid: wpun-forbidden-function
proc_open( 'ls', array(), $pipes );
// ruleid: wpun-forbidden-function
popen( 'ls', 'r' );
// ok: wpun-forbidden-function
assert( $value === 1 );
// ok: wpun-forbidden-function
$parts = explode( ',', $list );

// wpun-backtick-shell
// ruleid: wpun-backtick-shell
$out = `ls -l`;

// wpun-no-request-superglobal
// ruleid: wpun-no-request-superglobal
$a = $_REQUEST['a'];
// ok: wpun-no-request-superglobal
$a = $_GET['a'];
// ok: wpun-no-request-superglobal
$a = $_POST['a'];

// wpun-no-unserialize
// ruleid: wpun-no-unserialize
$a = unserialize( $data );
// ruleid: wpun-no-unserialize
$a = maybe_unserialize( $data );
// ok: wpun-no-unserialize
$a = serialize( $data );
// ok: wpun-no-unserialize
$a = json_decode( $data, true );

// wpun-outbound-http-safe-only
// ruleid: wpun-outbound-http-safe-only
wp_remote_get( $url );
// ruleid: wpun-outbound-http-safe-only
wp_remote_post( $url, $args );
// ruleid: wpun-outbound-http-safe-only
wp_remote_request( $url );
// ruleid: wpun-outbound-http-safe-only
wp_remote_head( $url );
// ruleid: wpun-outbound-http-safe-only
file_get_contents( 'https://example.com/x' );
// ruleid: wpun-outbound-http-safe-only
curl_exec( $ch );
// ruleid: wpun-outbound-http-safe-only
fsockopen( 'example.com', 80 );
// ok: wpun-outbound-http-safe-only
wp_safe_remote_post( $url, $args );
// ok: wpun-outbound-http-safe-only
wp_safe_remote_get( $url );
// ok: wpun-outbound-http-safe-only
$code = wp_remote_retrieve_response_code( $response );
// ok: wpun-outbound-http-safe-only
$text = file_get_contents( __DIR__ . '/readme.txt' );

// wpun-wpdb-unprepared
// ruleid: wpun-wpdb-unprepared
$wpdb->query( "DELETE FROM $table WHERE id = $id" );
// ruleid: wpun-wpdb-unprepared
$wpdb->get_var( $sql );
// ruleid: wpun-wpdb-unprepared
$wpdb->get_row( 'SELECT * FROM ' . $table );
// ruleid: wpun-wpdb-unprepared
$wpdb->get_col( $sql );
// ruleid: wpun-wpdb-unprepared
$wpdb->get_results( $sql );
// ok: wpun-wpdb-unprepared
$wpdb->query( $wpdb->prepare( 'DELETE FROM t WHERE id = %d', $id ) );
// ok: wpun-wpdb-unprepared
$wpdb->get_results( 'SELECT 1' );
// ok: wpun-wpdb-unprepared
$wpdb->insert( $table, $data );

// wpun-no-nopriv-hooks
// ruleid: wpun-no-nopriv-hooks
add_action( 'wp_ajax_nopriv_thing', 'handler' );
// ruleid: wpun-no-nopriv-hooks
add_action( 'admin_post_nopriv_thing', 'handler' );
// ok: wpun-no-nopriv-hooks
add_action( 'admin_post_thing', 'handler' );
// ok: wpun-no-nopriv-hooks
add_action( 'wp_ajax_thing', 'handler' );
