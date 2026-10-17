<?php
/**
 * Writes docs/developers/hooks.md, the hook reference, from the doc blocks of the hooks the plugin fires.
 *
 * Usage: php bin/hook-docs.php <file or directory>...
 *
 * Run with `make hook-docs`; `make check-hooks` fails when the committed file is out of date.
 */

require_once __DIR__ . '/hooks-scan.php';

$target = dirname( __DIR__ ) . '/docs/developers/hooks.md';
if ( ! is_dir( dirname( $target ) ) ) {
	mkdir( dirname( $target ), 0755, true );
}
file_put_contents( $target, wpun_render_hook_docs( array_slice( $argv, 1 ) ) );

echo "wrote docs/developers/hooks.md\n";
