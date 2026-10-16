<?php
/**
 * Every hook the plugin fires must be documented with @since and prefixed sc_wpun_.
 *
 * Usage: php bin/check-hooks.php <file or directory>...
 *
 * A call of apply_filters(), apply_filters_deprecated(), do_action() or do_action_deprecated()
 * passes if the doc block that ends within 3 lines above it has `@since`, or says it "is documented in"
 * another file (the WordPress core convention for a hook fired from several places).
 * The hooks WordPress itself defines, which the plugin only triggers, are allowed unprefixed.
 */

$core_hooks = array( 'wp_version_check', 'wp_update_plugins', 'wp_update_themes' );
$functions  = array( 'apply_filters', 'apply_filters_deprecated', 'do_action', 'do_action_deprecated' );

/**
 * PHP files below the given paths.
 *
 * @param string[] $paths Files and directories.
 * @return string[]
 */
function wpun_hook_files( array $paths ): array {
	$files = array();
	foreach ( $paths as $path ) {
		if ( is_dir( $path ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		} elseif ( is_file( $path ) ) {
			$files[] = $path;
		}
	}
	sort( $files );

	return $files;
}

$problems = array();
$count    = 0;

foreach ( wpun_hook_files( array_slice( $argv, 1 ) ) as $file ) {
	$tokens = token_get_all( (string) file_get_contents( $file ) );
	$total  = count( $tokens );
	$doc    = null; // Last doc block: array( text, end line ).

	for ( $i = 0; $i < $total; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) ) {
			continue;
		}
		if ( T_DOC_COMMENT === $token[0] ) {
			$doc = array( $token[1], $token[2] + substr_count( $token[1], "\n" ) );
			continue;
		}
		if ( T_STRING !== $token[0] || ! in_array( $token[1], $functions, true ) ) {
			continue;
		}

		// Only calls: the next significant token is "(" and the previous one is not "function" or "->" or "::".
		$next = $i + 1;
		while ( $next < $total && is_array( $tokens[ $next ] ) && in_array( $tokens[ $next ][0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
			++$next;
		}
		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
			--$prev;
		}
		if ( '(' !== ( $tokens[ $next ] ?? null ) || ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) ) {
			continue;
		}

		// The hook name: the first argument, when it is a plain or interpolated string.
		$name = null;
		$arg  = $next + 1;
		while ( $arg < $total && is_array( $tokens[ $arg ] ) && T_WHITESPACE === $tokens[ $arg ][0] ) {
			++$arg;
		}
		if ( is_array( $tokens[ $arg ] ?? null ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $arg ][0] ) {
			$name = trim( $tokens[ $arg ][1], '\'"' );
		} elseif ( '"' === ( $tokens[ $arg ] ?? null ) ) {
			$name = '';
			for ( $j = $arg + 1; $j < $total && '"' !== $tokens[ $j ]; $j++ ) {
				$name .= is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
			}
		}

		++$count;
		$line  = $token[2];
		$where = $file . ':' . $line . ' ' . $token[1] . '( ' . ( null === $name ? '?' : "'" . $name . "'" ) . ' )';

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
		$documented = null !== $doc && $line - $doc[1] <= 3 && $doc[1] <= $line
			&& ( false !== strpos( $doc[0], '@since' ) || false !== strpos( $doc[0], 'is documented in' ) );
		if ( ! $documented ) {
			$problems[] = $where . ': no doc block with @since within 3 lines above.';
		}
	}
}

if ( array() !== $problems ) {
	fwrite( STDERR, implode( "\n", $problems ) . "\n" );
	exit( 1 );
}

echo "ok: $count hook calls documented\n";
