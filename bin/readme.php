<?php
/**
 * Generates readme.txt, the file WordPress.org reads, from files that are committed. The readme itself is not.
 *
 * Usage: php bin/readme.php <output file>
 *
 * Run by bin/build-dist.sh, so the zip carries it, and by `make readme`, which writes the preview build/readme.txt.
 * `make check-headers` checks the result, and the WordPress tests call wpun_render_readme().
 *
 * The inputs:
 * - .wordpress-org/readme/template.txt: the whole readme, with {{placeholders}} for what is written elsewhere;
 * - the plugin header of wp-updates-notifier.php: name, requirements, licence and the version (the stable tag);
 * - About_Tab::links(): the donate link;
 * - .wordpress-org/readme/faq.txt: the headings of docs/users/troubleshooting.md that become FAQ entries;
 * - CHANGELOG.md: the latest releases.
 *
 * The output depends on those files only: no date, locale, environment or network, so the zip stays reproducible.
 * Anything it cannot convert is an error, never silently dropped.
 */

if ( ! defined( 'WPUN_README_RELEASES' ) ) {
	// How many releases the readme's changelog lists; the rest is in CHANGELOG.md.
	define( 'WPUN_README_RELEASES', 3 );
}

/**
 * One header field of the plugin file.
 *
 * @param string $plugin_file Contents of wp-updates-notifier.php.
 * @param string $field       Name of the field, such as Version.
 *
 * @return string
 */
function wpun_readme_header( string $plugin_file, string $field ): string {
	if ( ! preg_match( '/^ \* ' . preg_quote( $field, '/' ) . ': *(.+?)\s*$/m', $plugin_file, $match ) ) {
		throw new RuntimeException( "No $field in the plugin header" );
	}

	return $match[1];
}

/**
 * The changelog of the readme: the latest releases of CHANGELOG.md.
 *
 * release-please writes `## [2.0.0](compare-url) (date)`, then `### Features` and bullets that end with a link to the
 * commit and, sometimes, `, closes [#1](url)`. The older entries are `## 1.6` and bullets.
 *
 * @param string $changelog CHANGELOG.md.
 * @param int    $count     Number of releases.
 *
 * @return string
 */
function wpun_readme_changelog( string $changelog, int $count = WPUN_README_RELEASES ): string {
	$releases = array();
	$current  = null;

	foreach ( preg_split( '/\R/', $changelog ) as $line ) {
		$line = rtrim( $line );

		if ( preg_match( '/^## \[?(\d[^\]\s()]*)/', $line, $match ) ) {
			if ( count( $releases ) >= $count ) {
				break;
			}
			$releases[] = array( '= ' . $match[1] . ' =' );
			$current    = count( $releases ) - 1;
		} elseif ( 0 === strpos( $line, '## ' ) ) {
			throw new RuntimeException( "CHANGELOG.md: no version in the heading \"$line\"" );
		} elseif ( null === $current || '' === $line ) {
			continue; // The introduction, or a blank line.
		} elseif ( 0 === strpos( $line, '### ' ) ) {
			$title = ucfirst( strtolower( trim( preg_replace( '/^[^A-Za-z]+/', '', substr( $line, 4 ) ) ) ) );
			array_push( $releases[ $current ], '', '**' . $title . '**', '' );
		} elseif ( 0 === strpos( $line, '* ' ) ) {
			$item = preg_replace( '/ \(\[[0-9a-f]{7,40}\]\([^)]*\)\)/', '', $line );
			$item = preg_replace( '/, closes(?: \[#\d+\]\([^)]*\))+/', '', $item );
			$item = preg_replace( '/^\* \*\*[^*]+:\*\* /', '* ', $item );

			$releases[ $current ][] = $item;
		} else {
			throw new RuntimeException( "CHANGELOG.md: cannot convert the line \"$line\"" );
		}
	}

	if ( ! $releases ) {
		throw new RuntimeException( 'CHANGELOG.md has no release' );
	}

	$text = array();
	foreach ( $releases as $lines ) {
		$text[] = implode( "\n", $lines );
	}
	$text[] = 'The full changelog is in [CHANGELOG.md](https://github.com/l3rady/wp-updates-notifier/blob/main/CHANGELOG.md).';

	return implode( "\n\n", $text );
}

/**
 * The text of a Markdown line with its inline parts converted: relative links become absolute.
 *
 * @param string $text     Text without code blocks.
 * @param string $page_url Address of the page the text is from, for links within it.
 * @param string $page_dir Directory of that page under the documentation root, such as users/.
 * @param string $base_url Address of the documentation root.
 *
 * @return string
 */
function wpun_readme_inline( string $text, string $page_url, string $page_dir, string $base_url ): string {
	// Code spans are kept as they are.
	$parts = preg_split( '/(`[^`]*`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );

	foreach ( $parts as $i => $part ) {
		if ( 1 === $i % 2 ) {
			continue;
		}
		if ( preg_match( '/<[A-Za-z\/!]|!\[|\]\[|\{[:.#]|^\s*>|\[\^/', $part ) ) {
			throw new RuntimeException( "Markdown the readme cannot show (HTML, image, reference link, attribute list or footnote): \"$text\"" );
		}

		$parts[ $i ] = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( array $match ) use ( $page_url, $page_dir, $base_url ): string {
				return '[' . $match[1] . '](' . wpun_readme_link( $match[2], $page_url, $page_dir, $base_url ) . ')';
			},
			$part
		);
	}

	return implode( '', $parts );
}

/**
 * The address a link points to once the Markdown is not on the documentation site any more. MkDocs serves
 * users/troubleshooting.md as users/troubleshooting/, and users/index.md and users/README.md as users/.
 *
 * @param string $target   The link target.
 * @param string $page_url Address of the page the link is on.
 * @param string $page_dir Directory of that page under the documentation root.
 * @param string $base_url Address of the documentation root.
 *
 * @return string
 */
function wpun_readme_link( string $target, string $page_url, string $page_dir, string $base_url ): string {
	if ( preg_match( '#^(https?:|mailto:)#', $target ) ) {
		return $target;
	}
	if ( '#' === $target[0] ) {
		return $page_url . $target;
	}

	$fragment = '';
	if ( false !== strpos( $target, '#' ) ) {
		list( $target, $fragment ) = explode( '#', $target, 2 );
		$fragment                  = '#' . $fragment;
	}
	if ( '.md' !== substr( $target, -3 ) ) {
		throw new RuntimeException( "The link \"$target\" is relative but not to a Markdown page" );
	}

	$path = array();
	foreach ( explode( '/', $page_dir . substr( $target, 0, -3 ) ) as $segment ) {
		if ( '..' === $segment ) {
			if ( ! $path ) {
				throw new RuntimeException( "The link \"$target\" leaves the documentation" );
			}
			array_pop( $path );
		} elseif ( '' !== $segment && '.' !== $segment ) {
			$path[] = $segment;
		}
	}
	if ( $path && in_array( end( $path ), array( 'index', 'README' ), true ) ) {
		array_pop( $path );
	}

	return $base_url . ( $path ? implode( '/', $path ) . '/' : '' ) . $fragment;
}

/**
 * Converts the lines of a Markdown block of the user guide to readme text. It handles what the guide uses: paragraphs,
 * lists, ### subheadings, fenced code, tables and admonitions. Anything else is an error.
 *
 * @param string[] $lines    The lines.
 * @param string   $page_url Address of the page the lines are from.
 * @param string   $page_dir Directory of that page under the documentation root.
 * @param string   $base_url Address of the documentation root.
 *
 * @return array<int, array{string, string}> Blocks, each a type (paragraph, list or other) and its text.
 */
function wpun_readme_blocks( array $lines, string $page_url, string $page_dir, string $base_url ): array {
	$inline = static function ( string $text ) use ( $page_url, $page_dir, $base_url ): string {
		return wpun_readme_inline( $text, $page_url, $page_dir, $base_url );
	};
	$blocks = array();
	$count  = count( $lines );
	$i      = 0;

	while ( $i < $count ) {
		$line = $lines[ $i ];

		if ( '' === trim( $line ) ) {
			++$i;
		} elseif ( preg_match( '/^```/', $line ) ) {
			$code = array();
			for ( ++$i; $i < $count && ! preg_match( '/^```\s*$/', $lines[ $i ] ); ++$i ) {
				$code[] = $lines[ $i ];
			}
			if ( $i >= $count ) {
				throw new RuntimeException( 'A code block is not closed' );
			}
			++$i;
			$blocks[] = array( 'other', "`\n" . implode( "\n", $code ) . "\n`" );
		} elseif ( preg_match( '/^### (.+)$/', $line, $match ) ) {
			$blocks[] = array( 'other', '**' . $inline( $match[1] ) . '**' );
			++$i;
		} elseif ( preg_match( '/^!!! ([a-z]+)(?: "([^"]*)")?\s*$/', $line, $match ) ) {
			$title = isset( $match[2] ) ? $match[2] : ucfirst( $match[1] );
			$body  = array();
			for ( ++$i; $i < $count && ( '' === trim( $lines[ $i ] ) || 0 === strpos( $lines[ $i ], '    ' ) ); ++$i ) {
				$body[] = substr( $lines[ $i ], 4 );
			}
			$inner = wpun_readme_blocks( $body, $page_url, $page_dir, $base_url );
			if ( ! $inner ) {
				throw new RuntimeException( "The admonition \"$title\" is empty" );
			}
			if ( 'paragraph' === $inner[0][0] ) {
				$inner[0][1] = '**' . $title . ':** ' . $inner[0][1];
			} else {
				array_unshift( $inner, array( 'paragraph', '**' . $title . ':**' ) );
			}
			$blocks = array_merge( $blocks, $inner );
		} elseif ( preg_match( '/^\|/', $line ) ) {
			$rows = array();
			for ( ; $i < $count && preg_match( '/^\|/', $lines[ $i ] ); ++$i ) {
				$rows[] = array_map( 'trim', explode( '|', trim( trim( $lines[ $i ] ), '|' ) ) );
			}
			if ( count( $rows ) < 3 || ! preg_match( '/^:?-+:?$/', $rows[1][0] ) ) {
				throw new RuntimeException( "A table needs a header row, a separator row and a row: \"$line\"" );
			}
			$header = array_shift( $rows );
			array_shift( $rows );
			$items = array();
			foreach ( $rows as $row ) {
				if ( count( $row ) !== count( $header ) ) {
					throw new RuntimeException( 'A table row has a different number of cells than the header: ' . implode( ' | ', $row ) );
				}
				$cells = array();
				foreach ( $row as $n => $cell ) {
					if ( $n > 0 ) {
						$cells[] = $header[ $n ] . ': ' . $cell;
					}
				}
				$items[] = '* ' . $inline( $row[0] ) . ' - ' . $inline( implode( '; ', $cells ) );
			}
			$blocks[] = array( 'list', implode( "\n", $items ) );
		} elseif ( preg_match( '/^(?:[-*] |\d+\. )/', $line ) ) {
			$items = array();
			for ( ; $i < $count && '' !== trim( $lines[ $i ] ); ++$i ) {
				if ( ! preg_match( '/^(?:[-*] |\d+\. )/', $lines[ $i ] ) ) {
					throw new RuntimeException( "A list item that continues on another line, or a nested list: \"{$lines[ $i ]}\"" );
				}
				$items[] = preg_replace( '/^- /', '* ', $inline( $lines[ $i ] ) );
			}
			$blocks[] = array( 'list', implode( "\n", $items ) );
		} elseif ( preg_match( '/^(?:#|\s|>|---|\*\*\*|\?\?\?|===)/', $line ) ) {
			throw new RuntimeException( "Markdown the readme cannot show: \"$line\"" );
		} else {
			$text = array();
			for ( ; $i < $count && '' !== trim( $lines[ $i ] ) && ! preg_match( '/^(?:```|### |!!! |\||[-*] |\d+\. )/', $lines[ $i ] ); ++$i ) {
				$text[] = $inline( rtrim( $lines[ $i ] ) );
			}
			$blocks[] = array( 'paragraph', implode( "\n", $text ) );
		}
	}

	return $blocks;
}

/**
 * The FAQ entries: the listed questions of a Markdown page, each a `##` heading and the text under it.
 *
 * @param string   $markdown  The page.
 * @param string[] $questions The headings, in output order.
 * @param string   $page_path Path of the page under the documentation root, such as users/troubleshooting.md.
 * @param string   $base_url  Address of the documentation root.
 *
 * @return string
 */
function wpun_readme_faq( string $markdown, array $questions, string $page_path, string $base_url ): string {
	// The lines under each ## heading, outside code blocks.
	$sections = array();
	$heading  = null;
	$fenced   = false;
	foreach ( preg_split( '/\R/', $markdown ) as $line ) {
		if ( preg_match( '/^```/', $line ) ) {
			$fenced = ! $fenced;
		}
		if ( ! $fenced && preg_match( '/^## (.+?)\s*$/', $line, $match ) ) {
			$heading              = $match[1];
			$sections[ $heading ] = array();
		} elseif ( null !== $heading ) {
			$sections[ $heading ][] = $line;
		}
	}

	$page_url = $base_url . preg_replace( '/(?:\/?index|\/?README)?\.md$/', '', $page_path ) . '/';
	$page_dir = false === strpos( $page_path, '/' ) ? '' : dirname( $page_path ) . '/';
	$entries  = array();
	foreach ( $questions as $question ) {
		if ( ! isset( $sections[ $question ] ) ) {
			throw new RuntimeException( "The FAQ lists \"$question\", but $page_path has no ## heading with that text" );
		}
		try {
			$blocks = wpun_readme_blocks( $sections[ $question ], $page_url, $page_dir, $base_url );
		} catch ( RuntimeException $e ) {
			throw new RuntimeException( "$page_path, \"$question\": " . $e->getMessage(), 0, $e );
		}
		if ( ! $blocks ) {
			throw new RuntimeException( "\"$question\" in $page_path has no text" );
		}
		$entries[] = "= $question =\n\n" . implode( "\n\n", array_column( $blocks, 1 ) );
	}

	return implode( "\n\n", $entries );
}

/**
 * The whole readme.txt.
 *
 * @param string $root The plugin's directory.
 *
 * @return string
 */
function wpun_render_readme( string $root ): string {
	$read = static function ( string $path ) use ( $root ): string {
		$text = is_readable( "$root/$path" ) ? file_get_contents( "$root/$path" ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( false === $text ) {
			throw new RuntimeException( "Cannot read $path" );
		}

		return $text;
	};

	// The class files exit outside WordPress and have nothing else to run, so a defined ABSPATH is enough to load them.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $root . '/' );
	}
	if ( ! class_exists( 'WPUN\Plugin' ) ) {
		require_once "$root/includes/class-plugin.php";
	}
	if ( ! class_exists( 'WPUN\Admin\About_Tab' ) ) {
		require_once "$root/includes/admin/class-about-tab.php";
	}

	$plugin   = $read( 'wp-updates-notifier.php' );
	$base_url = WPUN\Plugin::SITE_URL . 'latest/';

	$questions = array_values(
		array_filter(
			array_map( 'trim', preg_split( '/\R/', $read( '.wordpress-org/readme/faq.txt' ) ) ),
			static function ( string $line ): bool {
				return '' !== $line && '#' !== $line[0];
			}
		)
	);

	$values = array(
		'{{name}}'              => wpun_readme_header( $plugin, 'Plugin Name' ),
		'{{requires_at_least}}' => wpun_readme_header( $plugin, 'Requires at least' ),
		'{{requires_php}}'      => wpun_readme_header( $plugin, 'Requires PHP' ),
		'{{stable_tag}}'        => wpun_readme_header( $plugin, 'Version' ),
		'{{license}}'           => wpun_readme_header( $plugin, 'License' ),
		'{{license_uri}}'       => wpun_readme_header( $plugin, 'License URI' ),
		'{{donate_link}}'       => WPUN\Admin\About_Tab::links()['donate'],
		'{{faq}}'               => wpun_readme_faq( $read( 'docs/users/troubleshooting.md' ), $questions, 'users/troubleshooting.md', $base_url ),
		'{{changelog}}'         => wpun_readme_changelog( $read( 'CHANGELOG.md' ) ),
	);

	$readme = strtr( str_replace( "\r\n", "\n", $read( '.wordpress-org/readme/template.txt' ) ), $values );
	if ( preg_match( '/\{\{[a-z_]+\}\}/', $readme, $match ) ) {
		throw new RuntimeException( "template.txt has the placeholder {$match[0]}, which nothing fills in" );
	}

	return rtrim( $readme ) . "\n";
}

// Run as a script: write the readme to the file named on the command line.
if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	if ( 2 !== $argc ) {
		fwrite( STDERR, "Usage: php bin/readme.php <output file>\n" );
		exit( 2 );
	}
	try {
		$readme = wpun_render_readme( dirname( __DIR__ ) );
	} catch ( RuntimeException $e ) {
		fwrite( STDERR, 'readme.txt: ' . $e->getMessage() . "\n" );
		exit( 1 );
	}
	if ( ! is_dir( dirname( $argv[1] ) ) ) {
		mkdir( dirname( $argv[1] ), 0755, true );
	}
	file_put_contents( $argv[1], $readme );
}
