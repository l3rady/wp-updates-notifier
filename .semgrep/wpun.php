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

// wpun-own-options-through-context
// ruleid: wpun-own-options-through-context
$a = get_option( 'sc_wpun_state' );
// ruleid: wpun-own-options-through-context
update_option( 'sc_wpun_settings', $settings );
// ruleid: wpun-own-options-through-context
add_option( 'sc_wpun_muted', array() );
// ruleid: wpun-own-options-through-context
delete_option( 'sc_wpun_channels' );
// ruleid: wpun-own-options-through-context
$a = get_site_option( Settings::OPT_FIELD );
// ruleid: wpun-own-options-through-context
update_site_option( self::OPT_VERSION_FIELD, $version );
// ruleid: wpun-own-options-through-context
delete_site_option( self::OPT_PREFIX . $group );
// ruleid: wpun-own-options-through-context
$a = \get_option( 'sc_wpun_data_' . $id );
// ruleid: wpun-own-options-through-context
$a = get_option( "sc_wpun_settings_{$group}" );
// ruleid: wpun-own-options-through-context
add_site_option( Instance_Data::PREFIX . $key, $value );
// ruleid: wpun-own-options-through-context
$a = get_blog_option( $site_id, State::OPT_FIELD );
// ruleid: wpun-own-options-through-context
update_network_option( null, 'sc_wpun_state', $state );
// ok: wpun-own-options-through-context
$a = $this->context->get_option( self::OPT_FIELD );
// ok: wpun-own-options-through-context
$this->network->delete_option( $this->value_name( $key ) );
// ok: wpun-own-options-through-context
$a = get_option( 'date_format' );
// ok: wpun-own-options-through-context
$a = get_site_option( 'active_sitewide_plugins', array() );
// ok: wpun-own-options-through-context
$a = get_site_option( "auto_update_{$type}s", array() );
// ok: wpun-own-options-through-context
update_option( 'my_addon_option', self::OPT_FIELD );

// wpun-no-admin-script
// ruleid: wpun-no-admin-script
wp_enqueue_script( 'wpun-settings', $url, array(), '1.0', true );
// ruleid: wpun-no-admin-script
wp_register_script( 'wpun-settings', $url );
// ruleid: wpun-no-admin-script
wp_add_inline_script( 'wpun-settings', 'go();' );
// ruleid: wpun-no-admin-script
wp_print_inline_script_tag( 'go();' );
// ruleid: wpun-no-admin-script
echo '<script>go();</script>';
// ruleid: wpun-no-admin-script
echo '<SCRIPT src="x.js"></SCRIPT>';
// ok: wpun-no-admin-script
wp_enqueue_style( 'wpun-settings', $url );
// ok: wpun-no-admin-script
echo '<p>No scripting needed.</p>';
