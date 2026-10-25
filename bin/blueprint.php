<?php
/**
 * Generates the WordPress Playground blueprint, the file that gives WordPress.org its Live Preview button, from files
 * that are committed. The blueprint itself is not (the same model as readme.txt, see docs/decisions/0018).
 *
 * Usage: php bin/blueprint.php <output file> [--plugin-vfs=<path>] [--source=<directory>]
 *
 * Run by `make blueprint`, which writes the preview build/blueprint.json. `make check-blueprint` validates the result
 * and `make blueprint-smoke` runs it in Playground.
 *
 * The input is .wordpress-org/blueprint (or --source): blueprint.json, the template, and the files it includes. Any
 * string of the template that is exactly `@file:<name>` is replaced by the contents of that file, which is a path
 * relative to the directory. A file that is missing, a `@file:` that is not the whole string and a file in the
 * directory that nothing includes are errors.
 *
 * --plugin-vfs replaces the installPlugin step that installs the plugin from WordPress.org with the file at that path
 * in Playground's file system. The smoke test uses it to run the zip that `make dist` built, because WordPress.org
 * does not have the release yet.
 *
 * The output depends on those files only: no date, locale, environment or network.
 */

/**
 * Replaces the `@file:` placeholders in a decoded template.
 *
 * @param mixed                $node   A value of the decoded template.
 * @param string               $dir    The directory the file names are relative to.
 * @param array<string, true> &$used   The files that were included, by name.
 *
 * @return mixed
 */
function wpun_blueprint_inline( $node, string $dir, array &$used ) {
	if ( is_array( $node ) ) {
		foreach ( $node as $key => $value ) {
			$node[ $key ] = wpun_blueprint_inline( $value, $dir, $used );
		}
		return $node;
	}
	if ( is_object( $node ) ) {
		foreach ( get_object_vars( $node ) as $key => $value ) {
			$node->$key = wpun_blueprint_inline( $value, $dir, $used );
		}
		return $node;
	}
	if ( ! is_string( $node ) || false === strpos( $node, '@file:' ) ) {
		return $node;
	}
	if ( ! preg_match( '#^@file:([a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*)*)$#', $node, $match ) ) {
		throw new RuntimeException( "The value \"$node\" has a @file: that is not the whole value, or a file name that is not allowed" );
	}
	$path = $dir . '/' . $match[1];
	if ( ! is_file( $path ) ) {
		throw new RuntimeException( "The placeholder {$node} names a file that does not exist" );
	}
	$used[ $match[1] ] = true;

	return str_replace( "\r\n", "\n", (string) file_get_contents( $path ) );
}

/**
 * The files below a directory, as paths relative to it.
 *
 * @param string $dir The directory.
 *
 * @return string[]
 */
function wpun_blueprint_files( string $dir ): array {
	$files    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$files[] = substr( $file->getPathname(), strlen( $dir ) + 1 );
		}
	}
	sort( $files );

	return $files;
}

/**
 * The blueprint for a source directory.
 *
 * @param string      $dir        The directory with blueprint.json and the files it includes.
 * @param string|null $plugin_vfs A path in Playground's file system to install the plugin from, instead of WordPress.org.
 *
 * @return string The JSON, with a newline at the end.
 */
function wpun_render_blueprint( string $dir, ?string $plugin_vfs = null ): string {
	$dir      = rtrim( $dir, '/' );
	$template = $dir . '/blueprint.json';
	if ( ! is_file( $template ) ) {
		throw new RuntimeException( "$template does not exist" );
	}
	$blueprint = json_decode( (string) file_get_contents( $template ), false, 512, JSON_THROW_ON_ERROR );
	if ( ! is_object( $blueprint ) ) {
		throw new RuntimeException( 'blueprint.json is not a JSON object' );
	}

	$used      = array();
	$blueprint = wpun_blueprint_inline( $blueprint, $dir, $used );

	foreach ( wpun_blueprint_files( $dir ) as $file ) {
		if ( 'blueprint.json' !== $file && ! isset( $used[ $file ] ) ) {
			throw new RuntimeException( "$file is not included by any @file: placeholder" );
		}
	}

	if ( null !== $plugin_vfs ) {
		$replaced = false;
		foreach ( $blueprint->steps ?? array() as $step ) {
			$data = $step->pluginData ?? null;
			if ( 'installPlugin' === ( $step->step ?? null ) && is_object( $data ) && 'wordpress.org/plugins' === ( $data->resource ?? null ) ) {
				$step->pluginData = (object) array(
					'resource' => 'vfs',
					'path'     => $plugin_vfs,
				);
				$replaced         = true;
			}
		}
		if ( ! $replaced ) {
			throw new RuntimeException( 'There is no installPlugin step from WordPress.org to replace' );
		}
	}

	return json_encode( $blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
}

// Run as a script: write the blueprint to the file named on the command line.
if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$usage  = "Usage: php bin/blueprint.php <output file> [--plugin-vfs=<path>] [--source=<directory>]\n";
	$output = null;
	$vfs    = null;
	$source = dirname( __DIR__ ) . '/.wordpress-org/blueprint';
	foreach ( array_slice( $argv, 1 ) as $argument ) {
		if ( 0 === strpos( $argument, '--plugin-vfs=' ) ) {
			$vfs = substr( $argument, strlen( '--plugin-vfs=' ) );
		} elseif ( 0 === strpos( $argument, '--source=' ) ) {
			$source = substr( $argument, strlen( '--source=' ) );
		} elseif ( null === $output && 0 !== strpos( $argument, '--' ) ) {
			$output = $argument;
		} else {
			fwrite( STDERR, $usage );
			exit( 2 );
		}
	}
	if ( null === $output ) {
		fwrite( STDERR, $usage );
		exit( 2 );
	}
	try {
		$blueprint = wpun_render_blueprint( $source, $vfs );
	} catch ( Throwable $e ) {
		fwrite( STDERR, 'blueprint.json: ' . $e->getMessage() . "\n" );
		exit( 1 );
	}
	if ( ! is_dir( dirname( $output ) ) ) {
		mkdir( dirname( $output ), 0755, true );
	}
	file_put_contents( $output, $blueprint );
}
