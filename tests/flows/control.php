<?php
/**
 * The WP-CLI door for `make e2e` (tests/flows): a tiny web server, run with `php -S` in the wp-cli container, that
 * runs `wp` with the arguments a test posts and returns its exit code and output as JSON.
 *
 * The Playwright image has no PHP or WP-CLI, and the browser cannot run a cron event or reset the stored state, so a
 * test asks this server (specs/helpers.js, `wp()`). It is the real WP-CLI in the real site, not a second
 * implementation of what it does. It listens on the private network of a throwaway compose project only; no port is
 * published. Never run this anywhere else.
 *
 * Request: POST with a JSON body {"args": ["cron", "event", "run", "sc_wpun_update_check"]}.
 * Response: {"code": 0, "stdout": "...", "stderr": "..."}.
 *
 * @package wp-updates-notifier
 */

// A test tool, not WordPress code: it runs outside WordPress, answers JSON it builds itself and must call proc_open.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

header( 'Content-Type: application/json' );

$body = json_decode( (string) file_get_contents( 'php://input' ), true );
$args = is_array( $body ) && isset( $body['args'] ) && is_array( $body['args'] ) ? array_map( 'strval', $body['args'] ) : null;

if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || null === $args || array() === $args ) {
	http_response_code( 400 );
	echo wpun_flows_json( array( 'code' => 2, 'stdout' => '', 'stderr' => 'POST {"args": [...]} expected' ) );
	exit;
}

// An array command runs without a shell, so no argument can add a command.
$process = proc_open(
	array_merge( array( 'wp' ), $args ),
	array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$pipes,
	'/var/www/html'
);
if ( ! is_resource( $process ) ) {
	http_response_code( 500 );
	echo wpun_flows_json( array( 'code' => 127, 'stdout' => '', 'stderr' => 'could not start wp' ) );
	exit;
}
fclose( $pipes[0] );
$stdout = stream_get_contents( $pipes[1] );
$stderr = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );

echo wpun_flows_json( array( 'code' => proc_close( $process ), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr ) );

/**
 * JSON that survives any bytes in the output.
 *
 * @param array<string, mixed> $data The response.
 *
 * @return string
 */
function wpun_flows_json( array $data ) {
	return (string) json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE );
}
