<?php
/**
 * Finds the hooks the plugin fires and builds the hook reference from their doc blocks.
 *
 * A library for bin/check-hooks.php (which checks the hooks and that the committed reference is current)
 * and bin/hook-docs.php (which writes the reference). Nothing here prints or exits.
 */

/**
 * PHP files below the given paths, sorted.
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
	sort( $files, SORT_STRING );

	return $files;
}

/**
 * Every call of apply_filters(), apply_filters_deprecated(), do_action() or do_action_deprecated() in the given paths.
 *
 * Each call is an array with `file`, `line`, `function`, `name` (null if the first argument is not a string),
 * `doc` (the doc block that ends within 3 lines above it, or null) and `documented` (it has @since or "is documented in").
 *
 * @param string[] $paths Files and directories.
 * @return array<int, array<string, mixed>>
 */
function wpun_scan_hooks( array $paths ): array {
	return wpun_scan_calls( $paths, array( 'apply_filters', 'apply_filters_deprecated', 'do_action', 'do_action_deprecated' ) );
}

/**
 * Whether a hook name is one of the fired names, where a fired name with a `{...}` part also matches every name it can make.
 *
 * @param string   $name  A literal hook name.
 * @param string[] $fired Fired hook names, from wpun_scan_hooks().
 * @return bool
 */
function wpun_is_fired( string $name, array $fired ): bool {
	foreach ( $fired as $pattern ) {
		if ( $pattern === $name ) {
			return true;
		}
		if ( false !== strpos( $pattern, '{' ) ) {
			$regex = '/^' . preg_replace( '/\\\{[^}]*\\\}/', '.+', preg_quote( $pattern, '/' ) ) . '$/';
			if ( 1 === preg_match( $regex, $name ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Every add_action() or add_filter() call in the given paths whose hook name is a string literal starting with sc_wpun_.
 *
 * Each call has the keys of wpun_scan_hooks() plus `listened` (the doc block has the `@hook-listened` tag, which marks
 * a public hook that the plugin handles but never fires, and so documents it for the reference).
 *
 * @param string[] $paths Files and directories.
 * @return array<int, array<string, mixed>>
 */
function wpun_scan_listened( array $paths ): array {
	$calls = array();
	$used  = array();
	foreach ( wpun_scan_calls( $paths, array( 'add_action', 'add_filter' ) ) as $call ) {
		// A doc block belongs to the first call below it, not to the calls that follow within 3 lines.
		if ( null !== $call['doc'] ) {
			$key = $call['file'] . "\0" . $call['doc'];
			if ( isset( $used[ $key ] ) ) {
				$call['doc']        = null;
				$call['documented'] = false;
			}
			$used[ $key ] = true;
		}
		if ( null === $call['name'] || 0 !== strpos( $call['name'], 'sc_wpun_' ) || false !== strpos( $call['name'], '$' ) ) {
			continue;
		}
		$call['listened'] = null !== $call['doc'] && 1 === preg_match( '/^\s*\*?\s*@hook-listened\b/m', $call['doc'] );
		$calls[]          = $call;
	}

	return $calls;
}

/**
 * Every call of the given functions in the given paths, with the doc block above it.
 *
 * @param string[] $paths     Files and directories.
 * @param string[] $functions Function names.
 * @return array<int, array<string, mixed>>
 */
function wpun_scan_calls( array $paths, array $functions ): array {
	$calls = array();

	foreach ( wpun_hook_files( $paths ) as $file ) {
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

			$line   = $token[2];
			$linked = null !== $doc && $line - $doc[1] <= 3 && $doc[1] <= $line;

			$calls[] = array(
				'file'       => $file,
				'line'       => $line,
				'function'   => $token[1],
				'name'       => $name,
				'doc'        => $linked ? $doc[0] : null,
				'documented' => $linked && ( false !== strpos( $doc[0], '@since' ) || false !== strpos( $doc[0], 'is documented in' ) ),
			);
		}
	}

	return $calls;
}

/**
 * A doc block as lines, without the comment markers.
 *
 * @param string $doc The doc block.
 * @return string[]
 */
function wpun_doc_lines( string $doc ): array {
	$lines = array();
	foreach ( explode( "\n", str_replace( "\r", '', $doc ) ) as $line ) {
		$line = preg_replace( '#^\s*/\*\*+\s?|\s*\*+/\s*$#', '', $line );
		$line = preg_replace( '#^\s*\* ?#', '', (string) $line );
		$lines[] = rtrim( (string) $line );
	}

	// Trim blank lines at both ends.
	while ( array() !== $lines && '' === $lines[0] ) {
		array_shift( $lines );
	}
	while ( array() !== $lines && '' === $lines[ count( $lines ) - 1 ] ) {
		array_pop( $lines );
	}

	return $lines;
}

/**
 * A doc block parsed into its parts.
 *
 * @param string $doc The doc block.
 * @return array{text: string[], params: array<int, array{type: string, name: string, description: string}>, return: string, since: string[]}
 */
function wpun_parse_doc( string $doc ): array {
	$out   = array(
		'text'   => array(),
		'params' => array(),
		'return' => '',
		'since'  => array(),
	);
	$lines = wpun_doc_lines( $doc );
	$tag   = null; // The tag being read: array( name, text lines ).
	$tags  = array();
	$depth = 0;    // Inside the braces of a hash parameter.

	foreach ( $lines as $line ) {
		if ( 0 === $depth && 1 === preg_match( '/^@(\w+)\s*(.*)$/', $line, $m ) ) {
			if ( null !== $tag ) {
				$tags[] = $tag;
			}
			$tag = array( $m[1], array( $m[2] ) );
			if ( '{' === substr( $m[2], -1 ) ) {
				$depth = 1;
			}
			continue;
		}
		if ( null === $tag ) {
			$out['text'][] = $line;
			continue;
		}
		if ( $depth > 0 && '}' === trim( $line ) ) {
			$depth = 0;
			continue;
		}
		$tag[1][] = $line;
	}
	if ( null !== $tag ) {
		$tags[] = $tag;
	}

	foreach ( $tags as $tag ) {
		$text = trim( preg_replace( '/\s+/', ' ', implode( ' ', $tag[1] ) ) ?? '' );
		if ( 'since' === $tag[0] ) {
			$out['since'][] = $text;
		} elseif ( 'return' === $tag[0] ) {
			$out['return'] = $text;
		} elseif ( 'param' === $tag[0] ) {
			// The type may contain spaces inside <> or {}, as in `array<string, Delivery_Result>`.
			$length = strlen( $text );
			$depth2 = 0;
			for ( $at = 0; $at < $length; $at++ ) {
				$char = $text[ $at ];
				if ( '<' === $char || '{' === $char || '(' === $char ) {
					++$depth2;
				} elseif ( '>' === $char || '}' === $char || ')' === $char ) {
					--$depth2;
				} elseif ( ' ' === $char && $depth2 <= 0 ) {
					break;
				}
			}
			$rest  = ltrim( substr( $text, $at ) );
			$parts = array( substr( $text, 0, $at ) );
			if ( 1 === preg_match( '/^(\$\w+)\s*(.*)$/s', $rest, $pm ) ) {
				$parts[] = $pm[1];
				$parts[] = $pm[2];
			}
			$name = (string) ( $parts[1] ?? '' );
			$desc = (string) ( $parts[2] ?? '' );
			// A hash parameter: `{`, its description and `@type` lines.
			$desc            = trim( (string) preg_replace( '/\s*@type\s+(\S+)\s+(\$\w+)\s*/', "\n- `$1` `$2` ", trim( $desc, '{ ' ) ) );
			$out['params'][] = array(
				'type'        => (string) ( $parts[0] ?? '' ),
				'name'        => $name,
				'description' => $desc,
			);
		}
	}

	return $out;
}

/**
 * The description of a hook as Markdown: paragraphs as written, and indented example code in ```php fences.
 *
 * @param string[] $lines Description lines.
 * @return string
 */
function wpun_markdown_text( array $lines ): string {
	$out  = array();
	$code = array();
	$flush = static function () use ( &$out, &$code ) {
		if ( array() === $code ) {
			return;
		}
		while ( array() !== $code && '' === end( $code ) ) {
			array_pop( $code );
		}
		$indent = PHP_INT_MAX;
		foreach ( $code as $row ) {
			if ( '' !== $row ) {
				$indent = min( $indent, strlen( $row ) - strlen( ltrim( $row ) ) );
			}
		}
		$out[] = '```php';
		foreach ( $code as $row ) {
			$out[] = '' === $row ? '' : substr( $row, $indent );
		}
		$out[] = '```';
		$code  = array();
	};

	$count = count( $lines );
	foreach ( $lines as $index => $line ) {
		$indented = 1 === preg_match( '/^(\t| {4,})\S/', $line );
		if ( $indented || ( '' === $line && array() !== $code ) ) {
			// A blank line stays in the code only if more code follows.
			if ( '' === $line ) {
				$more = false;
				for ( $k = $index + 1; $k < $count; $k++ ) {
					if ( '' !== $lines[ $k ] ) {
						$more = 1 === preg_match( '/^(\t| {4,})\S/', $lines[ $k ] );
						break;
					}
				}
				if ( ! $more ) {
					$flush();
					$out[] = '';
					continue;
				}
			}
			$code[] = str_replace( "\t", '    ', $line );
			continue;
		}
		$flush();
		$out[] = $line;
	}
	$flush();

	// A fence is its own paragraph.
	$text = implode( "\n", $out );
	$text = (string) preg_replace( '/([^\n])\n```php/', "$1\n\n```php", $text );
	$text = (string) preg_replace( '/```\n([^\n])/', "```\n\n$1", $text );

	return trim( $text );
}

/**
 * The sections of the reference: title => the hook names or name prefixes in it, in the order shown.
 * The longest matching prefix decides. A hook that matches none goes to the last section, "Lifecycle and legacy".
 *
 * @return array<string, string[]>
 */
function wpun_hook_sections(): array {
	return array(
		'Update check'        => array( 'sc_wpun_before_update_check', 'sc_wpun_after_update_check', 'sc_wpun_item_resolved', 'sc_wpun_frequencies' ),
		'Items and routing'   => array( 'sc_wpun_update_items', 'sc_wpun_item_types', 'sc_wpun_core_needs_update', 'sc_wpun_plugins_need_update', 'sc_wpun_themes_need_update', 'sc_wpun_should_notify_item', 'sc_wpun_channel_should_receive_item', 'sc_wpun_notification' ),
		'Delivery'            => array( 'sc_wpun_pre_send', 'sc_wpun_after_dispatch', 'sc_wpun_notification_sent', 'sc_wpun_notification_failed', 'sc_wpun_message_text', 'sc_wpun_more_items_text', 'sc_wpun_channel_payload', 'sc_wpun_email_' ),
		'Channels and fields' => array( 'sc_wpun_register_channels', 'sc_wpun_channels', 'sc_wpun_channel_deleted', 'sc_wpun_channel_rejected', 'sc_wpun_channel_fields', 'sc_wpun_field_types' ),
		'Configuration'       => array( 'sc_wpun_config_', 'sc_wpun_load_from_env', 'sc_wpun_default_settings', 'sc_wpun_get_options_filter', 'sc_wpun_put_options_filter' ),
		'Muting'              => array( 'sc_wpun_mutes', 'sc_wpun_pre_mute', 'sc_wpun_pre_unmute', 'sc_wpun_mute_capability', 'sc_wpun_item_muted', 'sc_wpun_item_unmuted', 'sc_wpun_mute_ended', 'sc_wpun_snooze_durations', 'sc_wpun_mute_source_label' ),
		'Settings screen'     => array( 'sc_wpun_capability', 'sc_wpun_settings_tab', 'sc_wpun_before_channel_form', 'sc_wpun_after_channel_form' ),
	);
}

/**
 * One hook of the reference as Markdown lines: title, kind and since, description, parameters, return and where it is.
 *
 * @param array{name: string, kind: string, files: array<string, bool>, doc: string|null} $hook Hook.
 * @param string                                                                          $verb "Fired in" or "Handled in".
 * @return string[]
 */
function wpun_render_hook( array $hook, string $verb ): array {
	$out    = array();
	$doc    = null === $hook['doc'] ? array(
		'text'   => array(),
		'params' => array(),
		'return' => '',
		'since'  => array(),
	) : wpun_parse_doc( $hook['doc'] );
	$text   = null === $hook['doc'] ? '' : implode( "\n", $doc['text'] );
	$title2 = $hook['name'];
	if ( false !== strpos( $hook['name'], '{' ) ) {
		if ( 1 === preg_match( '/dynamic (?:part|portion)[^`]*`\$(\w+)`/', $text, $m ) || 1 === preg_match( '/\{\$(\w+)\}/', $hook['name'], $m ) ) {
			$placeholder = $m[1];
		} else {
			$placeholder = 'dynamic';
		}
		$title2 = (string) preg_replace( '/\{[^}]*\}/', '{' . $placeholder . '}', $hook['name'] );
	}

	$out[] = '### `' . $title2 . '`';
	$out[] = '';
	$since = array();
	foreach ( $doc['since'] as $entry ) {
		$bits    = explode( ' ', $entry, 2 );
		$since[] = 'since ' . $bits[0] . ( isset( $bits[1] ) ? '. ' . $bits[1] : '' );
	}
	$out[] = '*' . ucfirst( $hook['kind'] ) . '*' . ( array() !== $since ? ', ' . implode( '; ', $since ) : '' );
	$out[] = '';
	if ( '' !== $text ) {
		$out[] = wpun_markdown_text( $doc['text'] );
		$out[] = '';
	}
	if ( array() !== $doc['params'] ) {
		$out[] = '| Parameter | Type | Description |';
		$out[] = '| --- | --- | --- |';
		foreach ( $doc['params'] as $param ) {
			$desc  = str_replace( array( '|', "\n" ), array( '\\|', '<br>' ), $param['description'] );
			$out[] = '| `' . $param['name'] . '` | `' . str_replace( '|', '\\|', $param['type'] ) . '` | ' . $desc . ' |';
		}
		$out[] = '';
	}
	if ( 'filter' === $hook['kind'] && '' !== $doc['return'] ) {
		$out[] = '**Returns:** ' . $doc['return'];
		$out[] = '';
	}
	$files = array_keys( $hook['files'] );
	sort( $files, SORT_STRING );
	$out[] = $verb . ' ' . implode( ', ', array_map( static fn( $f ) => '`' . $f . '`', $files ) ) . '.';
	$out[] = '';

	return $out;
}

/**
 * The Markdown hook reference for the hooks fired in the given paths.
 *
 * Output depends only on the doc blocks and the file names (never on line numbers), so it is stable.
 *
 * @param string[] $paths Files and directories.
 * @return string
 */
function wpun_render_hook_docs( array $paths ): string {
	$core  = array( 'wp_version_check', 'wp_update_plugins', 'wp_update_themes' );
	$hooks = array();

	foreach ( wpun_scan_hooks( $paths ) as $call ) {
		$name = $call['name'];
		if ( null === $name || in_array( $name, $core, true ) ) {
			continue;
		}
		$key = $name;
		if ( ! isset( $hooks[ $key ] ) ) {
			$hooks[ $key ] = array(
				'name'  => $name,
				'kind'  => 0 === strpos( $call['function'], 'apply_filters' ) ? 'filter' : 'action',
				'files' => array(),
				'doc'   => null,
			);
		}
		$hooks[ $key ]['files'][ $call['file'] ] = true;
		// The full doc block is the one with a description, not the "is documented in" pointer.
		if ( null !== $call['doc'] && false !== strpos( $call['doc'], '@since' ) && null === $hooks[ $key ]['doc'] ) {
			$hooks[ $key ]['doc'] = $call['doc'];
		}
	}
	ksort( $hooks, SORT_STRING );

	// Put every hook in a section.
	$sections = array();
	foreach ( wpun_hook_sections() as $title => $prefixes ) {
		$sections[ $title ] = array();
	}
	$sections['Lifecycle and legacy'] = array();
	foreach ( $hooks as $name => $hook ) {
		// The longest matching prefix wins, so sc_wpun_notification_sent is not filed under sc_wpun_notification.
		$placed  = 'Lifecycle and legacy';
		$longest = 0;
		foreach ( wpun_hook_sections() as $title => $prefixes ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) && strlen( $prefix ) > $longest ) {
					$placed  = $title;
					$longest = strlen( $prefix );
				}
			}
		}
		$sections[ $placed ][ $name ] = $hook;
	}

	// Public hooks the plugin only listens to, documented where it adds its handler.
	$callable = array();
	foreach ( wpun_scan_listened( $paths ) as $call ) {
		$name = (string) $call['name'];
		if ( ! $call['listened'] || wpun_is_fired( $name, array_keys( $hooks ) ) ) {
			continue;
		}
		if ( ! isset( $callable[ $name ] ) ) {
			$callable[ $name ] = array(
				'name'  => $name,
				'kind'  => 'add_filter' === $call['function'] ? 'filter' : 'action',
				'files' => array(),
				'doc'   => $call['doc'],
			);
		}
		$callable[ $name ]['files'][ $call['file'] ] = true;
	}
	ksort( $callable, SORT_STRING );

	$out      = array();
	$out[]    = '# Hook reference';
	$out[] = '';
	$out[] = '<!-- This file is generated by `make hook-docs` from the doc blocks in the code. Do not edit it by hand. -->';
	$out[] = '';
	$out[] = '> This file is generated from the doc blocks of the hooks the plugin fires. To change it, edit the doc block in the source and run `make hook-docs`. `make check-hooks` (part of the lint workflow) fails when this file is out of date.';
	$out[] = '';
	$out[] = 'Every hook is prefixed `sc_wpun_`. A name with a part in braces, such as `sc_wpun_channel_payload_{type}`, is dynamic: the part in braces is replaced when the hook fires.';
	$out[] = '';

	$toc = array();
	foreach ( $sections as $title => $items ) {
		if ( array() === $items ) {
			continue;
		}
		$toc[] = '- [' . $title . '](#' . strtolower( str_replace( ' ', '-', $title ) ) . ')';
	}
	if ( array() !== $callable ) {
		$toc[] = '- [Actions you can fire](#actions-you-can-fire)';
	}
	$out = array_merge( $out, $toc, array( '' ) );

	foreach ( $sections as $title => $items ) {
		if ( array() === $items ) {
			continue;
		}
		$out[] = '## ' . $title;
		$out[] = '';
		foreach ( $items as $name => $hook ) {
			$out = array_merge( $out, wpun_render_hook( $hook, 'Fired in' ) );
		}
	}

	if ( array() !== $callable ) {
		$out[] = '## Actions you can fire';
		$out[] = '';
		$out[] = 'The plugin listens to these actions but does not fire them itself. Call them with `do_action()`.';
		$out[] = '';
		foreach ( $callable as $hook ) {
			$out = array_merge( $out, wpun_render_hook( $hook, 'Handled in' ) );
		}
	}

	return rtrim( implode( "\n", $out ) ) . "\n";
}
