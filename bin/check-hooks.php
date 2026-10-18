<?php
/**
 * Every hook the plugin fires must be documented with @since and prefixed sc_wpun_.
 *
 * Usage: php bin/check-hooks.php [--reference=<hooks.md>] <file or directory>...
 *
 * A call of apply_filters(), apply_filters_deprecated(), do_action() or do_action_deprecated()
 * passes if the doc block that ends within 3 lines above it has `@since`, or says it "is documented in"
 * another file (the WordPress core convention for a hook fired from several places).
 * The hooks WordPress itself defines, which the plugin only triggers, are allowed unprefixed.
 *
 * A public hook that the plugin only listens to (an add_action() or add_filter() of a literal sc_wpun_ name that
 * nothing fires) must have a doc block with `@hook-listened` and `@since` within 3 lines above the call, which is
 * where the reference documents it. `@hook-listened` on a hook that is fired is an error: the fired hook documents it.
 *
 * With --reference, it also fails if that file differs from the reference generated from the doc blocks
 * (see bin/hook-docs.php); bin/check-hooks.sh passes docs/developers/hooks.md. Run `make hook-docs` to update it.
 */

require_once __DIR__ . '/hooks-scan.php';

$core_hooks = array( 'wp_version_check', 'wp_update_plugins', 'wp_update_themes' );
$paths      = array_slice( $argv, 1 );
$reference  = null;
if ( isset( $paths[0] ) && 0 === strpos( $paths[0], '--reference=' ) ) {
	$reference = substr( (string) array_shift( $paths ), strlen( '--reference=' ) );
}
$problems   = array();
$count      = 0;

foreach ( wpun_scan_hooks( $paths ) as $call ) {
	++$count;
	$name  = $call['name'];
	$where = $call['file'] . ':' . $call['line'] . ' ' . $call['function'] . '( ' . ( null === $name ? '?' : "'" . $name . "'" ) . ' )';

	if ( null === $name ) {
		$problems[] = $where . ': the hook name must be a string literal so it can be checked.';
		continue;
	}
	if ( 0 !== strpos( $name, 'sc_wpun_' ) && ! in_array( $name, $core_hooks, true ) ) {
		$problems[] = $where . ': the hook name must start with sc_wpun_.';
	}
	if ( in_array( $name, $core_hooks, true ) ) {
		continue; // WordPress documents its own hooks.
	}
	if ( ! $call['documented'] ) {
		$problems[] = $where . ': no doc block with @since within 3 lines above.';
	}
}

// Hooks the plugin listens to: those nothing fires are documented where the handler is added.
$fired = array();
foreach ( wpun_scan_hooks( $paths ) as $call ) {
	if ( null !== $call['name'] ) {
		$fired[ $call['name'] ] = true;
	}
}
foreach ( wpun_scan_listened( $paths ) as $call ) {
	$where = $call['file'] . ':' . $call['line'] . ' ' . $call['function'] . "( '" . $call['name'] . "' )";
	if ( wpun_is_fired( $call['name'], array_keys( $fired ) ) ) {
		if ( $call['listened'] ) {
			$problems[] = $where . ': @hook-listened is for hooks that are never fired, and this one is fired; remove the tag.';
		}
	} elseif ( ! $call['listened'] || false === strpos( (string) $call['doc'], '@since' ) ) {
		$problems[] = $where . ': listened to but never fired; document it with @hook-listened and @since, or fire it.';
	}
}

// The committed reference must be what the doc blocks generate.
if ( null !== $reference && array() === $problems ) {
	$current = is_file( $reference ) ? (string) file_get_contents( $reference ) : null;
	if ( wpun_render_hook_docs( $paths ) !== $current ) {
		$problems[] = $reference . ' is missing or out of date: run `make hook-docs` and commit it.';
	}
}

if ( array() !== $problems ) {
	fwrite( STDERR, implode( "\n", $problems ) . "\n" );
	exit( 1 );
}

echo "ok: $count hook calls documented" . ( null !== $reference ? ", $reference is current" : '' ) . "\n";
