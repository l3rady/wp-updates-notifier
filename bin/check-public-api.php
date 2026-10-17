<?php
/**
 * Add-ons and built-in modules may only use the public API.
 *
 * Usage: php bin/check-public-api.php <file or directory>...
 *
 * What is internal is read from the plugin itself, not listed here: every class, interface or trait,
 * every method and every function in includes/ and wp-updates-notifier.php whose doc block has an
 * `@internal` tag. The legacy SC_WP_Updates_Notifier facade is always off limits.
 *
 * The files given are checked for:
 *  - a reference to an internal class: new, static access, ::class, instanceof, type hints, `use`, extends,
 *    implements, and the fully qualified name in a string. Names are resolved through the namespace and the
 *    `use` imports, so `Plugin` inside another namespace is not mistaken for WPUN\Plugin.
 *  - a call of an internal function (sc_wpun()).
 *  - a call of a method that is internal in an otherwise public class (for example ->stored_all()). This goes
 *    by the method name only, since types are not known here, so a method of your own with the same name is a
 *    false positive: rename it.
 *  - Reflection, Closure::bind(), ->bindTo() and ->call(), which reach past visibility. ->call() is any method
 *    of that name, so it can be a false positive too.
 *
 * Set the paths of what to read internals from with the environment variable WPUN_INTERNAL_PATHS
 * (space separated); the default is the plugin's own.
 */

if ( ! defined( 'T_NAME_QUALIFIED' ) ) {
	define( 'T_NAME_QUALIFIED', -1 );
	define( 'T_NAME_FULLY_QUALIFIED', -2 );
	define( 'T_NAME_RELATIVE', -3 );
}
if ( ! defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
	define( 'T_NULLSAFE_OBJECT_OPERATOR', -4 );
}
if ( ! defined( 'T_READONLY' ) ) {
	define( 'T_READONLY', -5 );
}
if ( ! defined( 'T_ENUM' ) ) {
	define( 'T_ENUM', -6 );
}

/**
 * PHP files below the given paths.
 *
 * @param string[] $paths Files and directories.
 * @return string[]
 */
function wpun_api_files( array $paths ): array {
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

/**
 * Tokens without white space and comments (doc blocks stay), names joined into one token each.
 *
 * Each token is array( type, text, line ). A name, qualified or not, has the type 'NAME'.
 *
 * @param string $code PHP source.
 * @return array[]
 */
function wpun_api_tokens( string $code ): array {
	$raw    = token_get_all( $code );
	$total  = count( $raw );
	$tokens = array();
	$line   = 1;
	$name   = array( T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE );

	for ( $i = 0; $i < $total; $i++ ) {
		$token = $raw[ $i ];
		if ( ! is_array( $token ) ) {
			$tokens[] = array( $token, $token, $line );
			continue;
		}
		$line = $token[2];
		$text = $token[1];

		if ( in_array( $token[0], $name, true ) ) {
			while ( isset( $raw[ $i + 1 ] ) && is_array( $raw[ $i + 1 ] ) && in_array( $raw[ $i + 1 ][0], $name, true ) ) {
				++$i;
				$text .= $raw[ $i ][1];
			}
			$tokens[] = array( 'NAME', $text, $line );
		} elseif ( T_NAMESPACE === $token[0] && isset( $raw[ $i + 1 ] ) && is_array( $raw[ $i + 1 ] ) && T_NS_SEPARATOR === $raw[ $i + 1 ][0] ) {
			// PHP 7.4: namespace\Foo, a name relative to the current namespace.
			$text = 'namespace';
			while ( isset( $raw[ $i + 1 ] ) && is_array( $raw[ $i + 1 ] ) && in_array( $raw[ $i + 1 ][0], $name, true ) ) {
				++$i;
				$text .= $raw[ $i ][1];
			}
			$tokens[] = array( 'NAME', $text, $line );
		} elseif ( ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
			$tokens[] = array( $token[0], $text, $line );
		}
		$line += substr_count( $text, "\n" );
	}

	return $tokens;
}

/**
 * Whether a token is a given single character or token type.
 *
 * @param array|null     $token Token.
 * @param string|int ...$types  Types or characters.
 * @return bool
 */
function wpun_api_is( $token, ...$types ): bool {
	return null !== $token && in_array( $token[0], $types, true );
}

/**
 * Normalise a class or function name for comparing.
 *
 * @param string $name Name.
 * @return string Lower case, without a leading backslash.
 */
function wpun_api_key( string $name ): string {
	return strtolower( ltrim( $name, '\\' ) );
}

/**
 * The last part of a qualified name, lower case.
 *
 * @param string $name Name.
 * @return string
 */
function wpun_api_last_segment( string $name ): string {
	$at = strrpos( $name, '\\' );

	return strtolower( false === $at ? $name : substr( $name, $at + 1 ) );
}

/**
 * What the plugin marks @internal.
 *
 * @param string[] $paths Where to read.
 * @return array{classes: array<string, string>, methods: array<string, string>, functions: array<string, string>}
 */
function wpun_api_internals( array $paths ): array {
	$found = array(
		'classes'   => array( 'sc_wp_updates_notifier' => 'SC_WP_Updates_Notifier' ),
		'methods'   => array(),
		'functions' => array(),
	);

	$modifiers = array( T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_VAR, T_READONLY );

	foreach ( wpun_api_files( $paths ) as $file ) {
		$tokens    = wpun_api_tokens( (string) file_get_contents( $file ) );
		$total     = count( $tokens );
		$namespace = '';
		$depth     = 0;
		$pending   = false; // The last doc block has @internal and nothing but modifiers came since.
		$class     = null;  // Current class: array( name, internal, body depth ).
		$awaiting  = null;  // Class declared whose "{" has not come yet.

		for ( $i = 0; $i < $total; $i++ ) {
			$token = $tokens[ $i ];

			if ( T_DOC_COMMENT === $token[0] ) {
				$pending = 1 === preg_match( '/^\s*\*\s*@internal\b/m', $token[1] );
				continue;
			}
			if ( in_array( $token[0], $modifiers, true ) ) {
				continue;
			}

			if ( '{' === $token[0] || T_CURLY_OPEN === $token[0] || T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) {
				++$depth;
				if ( '{' === $token[0] && null !== $awaiting ) {
					$class    = array( $awaiting[0], $awaiting[1], $depth );
					$awaiting = null;
				}
			} elseif ( '}' === $token[0] ) {
				if ( null !== $class && $depth === $class[2] ) {
					$class = null;
				}
				--$depth;
			}

			if ( T_NAMESPACE === $token[0] && wpun_api_is( $tokens[ $i + 1 ] ?? null, 'NAME' ) ) {
				$namespace = ltrim( $tokens[ $i + 1 ][1], '\\' );
			} elseif ( in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ), true ) && ! wpun_api_is( $tokens[ $i - 1 ] ?? null, T_DOUBLE_COLON, T_NEW ) && wpun_api_is( $tokens[ $i + 1 ] ?? null, 'NAME' ) ) {
				$name = ( '' === $namespace ? '' : $namespace . '\\' ) . $tokens[ $i + 1 ][1];
				if ( $pending ) {
					$found['classes'][ wpun_api_key( $name ) ] = $name;
				}
				$awaiting = array( $name, $pending );
			} elseif ( T_FUNCTION === $token[0] ) {
				$at = $i + 1;
				if ( wpun_api_is( $tokens[ $at ] ?? null, '&' ) ) {
					++$at;
				}
				if ( $pending && wpun_api_is( $tokens[ $at ] ?? null, 'NAME' ) ) {
					$name = $tokens[ $at ][1];
					if ( null !== $class && $depth === $class[2] ) {
						// A method; if the whole class is internal there is nothing more to say.
						if ( ! $class[1] ) {
							$found['methods'][ strtolower( $name ) ] = $class[0] . '::' . $name;
						}
					} elseif ( null === $class ) {
						$found['functions'][ strtolower( $name ) ] = $name;
					}
				}
			}

			$pending = false;
		}
	}

	return $found;
}

/**
 * Resolve a class name through the namespace and the imports.
 *
 * @param string                $name      Name as written.
 * @param string                $namespace Current namespace.
 * @param array<string, string> $imports   Imported classes: lower case alias => full name.
 * @return string Full name, lower case, without a leading backslash.
 */
function wpun_api_resolve( string $name, string $namespace, array $imports ): string {
	if ( '\\' === $name[0] ) {
		return wpun_api_key( $name );
	}
	if ( 0 === stripos( $name, 'namespace\\' ) ) {
		return wpun_api_key( ( '' === $namespace ? '' : $namespace . '\\' ) . substr( $name, 10 ) );
	}

	$parts = explode( '\\', $name, 2 );
	$alias = strtolower( $parts[0] );
	if ( isset( $imports[ $alias ] ) ) {
		return wpun_api_key( $imports[ $alias ] . ( isset( $parts[1] ) ? '\\' . $parts[1] : '' ) );
	}

	return wpun_api_key( ( '' === $namespace ? '' : $namespace . '\\' ) . $name );
}

/**
 * Check one file.
 *
 * @param string $file      Path.
 * @param array  $internals What wpun_api_internals() found.
 * @return string[] Problems as "file:line: message".
 */
function wpun_api_check_file( string $file, array $internals ): array {
	$tokens    = wpun_api_tokens( (string) file_get_contents( $file ) );
	$total     = count( $tokens );
	$problems  = array();
	$namespace = '';
	$imports   = array();
	$depth     = 0;
	$skip_to   = -1; // Tokens up to here belong to a statement already handled.

	$report = static function ( int $line, string $message ) use ( $file, &$problems ) {
		$problems[] = $file . ':' . $line . ': ' . $message;
	};

	// The name of a class is internal, or reaches past visibility.
	$check_class = static function ( string $resolved, int $line, string $written ) use ( $internals, $report ) {
		if ( isset( $internals['classes'][ $resolved ] ) ) {
			$report( $line, 'uses the internal class ' . $internals['classes'][ $resolved ] . ' (written ' . $written . ').' );
		} elseif ( 1 === preg_match( '/^reflection[a-z]*$/', $resolved ) ) {
			$report( $line, 'uses ' . $written . ': reflection reaches past the public API.' );
		}
	};

	for ( $i = 0; $i < $total; $i++ ) {
		$token = $tokens[ $i ];
		$prev  = $tokens[ $i - 1 ] ?? null;
		$next  = $tokens[ $i + 1 ] ?? null;

		if ( '{' === $token[0] || T_CURLY_OPEN === $token[0] || T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) {
			++$depth;
		} elseif ( '}' === $token[0] ) {
			--$depth;
		}

		if ( $i <= $skip_to ) {
			continue;
		}

		if ( T_NAMESPACE === $token[0] && wpun_api_is( $next, 'NAME' ) ) {
			$namespace = ltrim( $next[1], '\\' );
			$imports   = array();
			$skip_to   = $i + 1;
			continue;
		}

		// An import at the top of the file; "use" inside a class is a trait, inside a closure it has "(".
		if ( T_USE === $token[0] && 0 === $depth && null !== $next && ( 'NAME' === $next[0] || in_array( $next[0], array( T_FUNCTION, T_CONST ), true ) ) ) {
			$kind = 'class';
			$at   = $i + 1;
			if ( wpun_api_is( $tokens[ $at ], T_FUNCTION ) ) {
				$kind = 'function';
				++$at;
			} elseif ( wpun_api_is( $tokens[ $at ], T_CONST ) ) {
				$kind = 'const';
				++$at;
			}
			$prefix = '';
			$group  = false;
			for ( ; $at < $total && ';' !== $tokens[ $at ][0]; $at++ ) {
				$part = $tokens[ $at ];
				if ( 'NAME' === $part[0] && '\\' === substr( $part[1], -1 ) ) {
					$prefix = $part[1]; // A group: use Foo\{A, B}.
					$group  = true;
					continue;
				}
				if ( 'NAME' !== $part[0] ) {
					continue;
				}
				if ( wpun_api_is( $tokens[ $at - 1 ], T_AS ) ) {
					continue;
				}
				$full  = ( $group ? $prefix : '' ) . $part[1];
				$alias = $part[1];
				if ( wpun_api_is( $tokens[ $at + 1 ] ?? null, T_AS ) && wpun_api_is( $tokens[ $at + 2 ] ?? null, 'NAME' ) ) {
					$alias = $tokens[ $at + 2 ][1];
				}
				$alias_key = wpun_api_last_segment( $alias );
				$key       = wpun_api_key( $full );
				if ( 'class' === $kind ) {
					$imports[ $alias_key ] = $full;
					$check_class( $key, $part[2], 'use ' . $full );
				} elseif ( 'function' === $kind && isset( $internals['functions'][ wpun_api_last_segment( $key ) ] ) ) {
					$report( $part[2], 'imports the internal function ' . $full . '().' );
				}
			}
			$skip_to = $at;
			continue;
		}

		if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			$text = strtolower( ltrim( str_replace( '\\\\', '\\', substr( $token[1], 1, -1 ) ), '\\' ) );
			if ( isset( $internals['classes'][ $text ] ) ) {
				$report( $token[2], 'names the internal class ' . $internals['classes'][ $text ] . ' in a string.' );
			} elseif ( isset( $internals['functions'][ $text ] ) ) {
				$report( $token[2], 'names the internal function ' . $internals['functions'][ $text ] . '() in a string.' );
			}
			continue;
		}

		if ( 'NAME' !== $token[0] ) {
			continue;
		}

		$name = $token[1];

		// Members and declarations are not references to a class or a function.
		if ( wpun_api_is( $prev, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ) ) {
			$method = strtolower( $name );
			if ( wpun_api_is( $next, '(' ) ) {
				if ( isset( $internals['methods'][ $method ] ) ) {
					$report( $token[2], 'calls ->' . $name . '(), which is internal (' . $internals['methods'][ $method ] . ').' );
				} elseif ( 'bindto' === $method || 'call' === $method ) {
					$report( $token[2], 'calls ->' . $name . '(), which reaches past visibility.' );
				}
			}
			continue;
		}
		if ( wpun_api_is( $prev, T_DOUBLE_COLON ) ) {
			$method = strtolower( $name );
			if ( wpun_api_is( $next, '(' ) ) {
				$on = $tokens[ $i - 2 ] ?? null;
				if ( isset( $internals['methods'][ $method ] ) ) {
					$report( $token[2], 'calls ::' . $name . '(), which is internal (' . $internals['methods'][ $method ] . ').' );
				} elseif ( 'bind' === $method && wpun_api_is( $on, 'NAME' ) && 'closure' === wpun_api_resolve( $on[1], $namespace, $imports ) ) {
					$report( $token[2], 'calls Closure::bind(), which reaches past visibility.' );
				}
			}
			continue;
		}
		if ( wpun_api_is( $prev, T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_GOTO ) || ( wpun_api_is( $prev, '&' ) && wpun_api_is( $tokens[ $i - 2 ] ?? null, T_FUNCTION ) ) ) {
			continue;
		}

		if ( wpun_api_is( $next, '(' ) && ! wpun_api_is( $prev, T_NEW ) ) {
			// A call. Without a namespace of its own a name falls back to the global function.
			$function = strtolower( ltrim( $name, '\\' ) );
			if ( isset( $internals['functions'][ $function ] ) ) {
				$report( $token[2], 'calls the internal function ' . $internals['functions'][ $function ] . '().' );
			}
			continue;
		}

		$check_class( wpun_api_resolve( $name, $namespace, $imports ), $token[2], $name );
	}

	return $problems;
}

$paths = array_slice( $argv, 1 );
if ( array() === $paths ) {
	fwrite( STDERR, "Usage: php bin/check-public-api.php <file or directory>...\n" );
	exit( 2 );
}

$internal_paths = preg_split( '/\s+/', trim( (string) getenv( 'WPUN_INTERNAL_PATHS' ) ), -1, PREG_SPLIT_NO_EMPTY );
if ( array() === $internal_paths ) {
	$internal_paths = array( 'wp-updates-notifier.php', 'includes' );
}

$internals = wpun_api_internals( $internal_paths );
// A broken scan must not pass for "nothing is internal".
if ( count( $internals['classes'] ) < 5 || array() === $internals['methods'] || array() === $internals['functions'] ) {
	fwrite( STDERR, "Found too few @internal classes, methods or functions to trust the scan: check the paths.\n" );
	exit( 2 );
}

$problems = array();
$files    = wpun_api_files( $paths );
foreach ( $files as $file ) {
	$problems = array_merge( $problems, wpun_api_check_file( $file, $internals ) );
}

if ( array() !== $problems ) {
	fwrite( STDERR, implode( "\n", $problems ) . "\n" );
	exit( 1 );
}

printf(
	"ok: %d files use only the public API (%d internal classes, %d methods, %d functions)\n",
	count( $files ),
	count( $internals['classes'] ),
	count( $internals['methods'] ),
	count( $internals['functions'] )
);
