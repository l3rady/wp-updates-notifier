<?php
/**
 * Checks the assembled WordPress.org assets (build/wporg-assets, see bin/build-wporg-assets.sh).
 *
 * Usage: php bin/check-wporg-assets.php <assets directory> <readme.txt>
 *
 * Run by bin/check-wporg-assets.sh (`make wporg-assets-check`), which also validates blueprints/blueprint.json
 * against the Playground schema. Lists every problem it finds and exits 1.
 *
 * @package wp-updates-notifier
 */

// The limits, in one place. WordPress.org publishes no size limits for these files; they are ours, chosen to stay
// well under what a plugin page needs and to stop a huge image from being deployed by accident.
const WPUN_ASSET_MAX_BYTES_ICON_BANNER = 1048576;  // 1 MB.
const WPUN_ASSET_MAX_BYTES_SCREENSHOT  = 10485760; // 10 MB.

// Exact pixel sizes by file name stem (the Plugin Directory Handbook, "How your plugin assets work"). The -rtl
// banners are allowed there too.
const WPUN_ASSET_SIZES = array(
	'icon-128x128'        => array( 128, 128 ),
	'icon-256x256'        => array( 256, 256 ),
	'banner-772x250'      => array( 772, 250 ),
	'banner-772x250-rtl'  => array( 772, 250 ),
	'banner-1544x500'     => array( 1544, 500 ),
	'banner-1544x500-rtl' => array( 1544, 500 ),
);

// getimagesize() type constant by file extension.
const WPUN_ASSET_TYPES = array(
	'png'  => IMAGETYPE_PNG,
	'jpg'  => IMAGETYPE_JPEG,
	'jpeg' => IMAGETYPE_JPEG,
	'gif'  => IMAGETYPE_GIF,
);

if ( 3 !== $argc ) {
	fwrite( STDERR, "Usage: php bin/check-wporg-assets.php <assets directory> <readme.txt>\n" );
	exit( 2 );
}

$dir    = rtrim( $argv[1], '/' );
$readme = $argv[2];
if ( ! is_dir( $dir ) || ! is_file( $readme ) ) {
	fwrite( STDERR, "FAIL: $dir or $readme not found\n" );
	exit( 2 );
}

$problems = array();

/**
 * Checks one raster image: its real format, and its size when the name fixes it.
 *
 * @param string $path Full path.
 * @param string $name Name inside the assets directory.
 * @param string $stem Name without the extension.
 * @param string $ext  Lower-case extension.
 * @param array  $problems Problems found so far.
 */
function wpun_check_image( $path, $name, $stem, $ext, &$problems ) {
	$info = @getimagesize( $path );
	if ( false === $info ) {
		$problems[] = "$name is not a valid image";
		return;
	}
	if ( WPUN_ASSET_TYPES[ $ext ] !== $info[2] ) {
		$problems[] = "$name is " . image_type_to_mime_type( $info[2] ) . ', which does not match its extension';
	}
	if ( isset( WPUN_ASSET_SIZES[ $stem ] ) && WPUN_ASSET_SIZES[ $stem ] !== array( $info[0], $info[1] ) ) {
		$problems[] = "$name is {$info[0]}x{$info[1]}, expected " . implode( 'x', WPUN_ASSET_SIZES[ $stem ] );
	}
}

/**
 * Checks the icon: no scripts, event handlers, external references or foreign objects.
 *
 * @param string $path Full path.
 * @param string $name Name inside the assets directory.
 * @param array  $problems Problems found so far.
 */
function wpun_check_svg( $path, $name, &$problems ) {
	$svg = (string) file_get_contents( $path );
	if ( ! preg_match( '/<svg[\s>]/i', $svg ) ) {
		$problems[] = "$name is not an SVG";
	}
	$rules = array(
		'/<script/i'                          => 'a <script> element',
		'/<foreignObject/i'                   => 'a <foreignObject> element',
		'/\son[a-z]+\s*=/i'                   => 'an on* event attribute',
		'/(?:xlink:)?href\s*=\s*["\']?\s*(?:https?:)?\/\//i' => 'an external href',
		'/url\(\s*["\']?\s*(?:https?:)?\/\//i' => 'an external url()',
		'/@import/i'                          => 'an @import',
		'/javascript:/i'                      => 'a javascript: URL',
	);
	foreach ( $rules as $pattern => $what ) {
		if ( preg_match( $pattern, $svg ) ) {
			$problems[] = "$name has $what";
		}
	}
}

$entries = array();
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	$entries[] = substr( $file->getPathname(), strlen( $dir ) + 1 );
}
sort( $entries );

$screenshots = array();
foreach ( $entries as $name ) {
	$path = "$dir/$name";
	if ( is_link( $path ) ) {
		$problems[] = "$name is a symbolic link";
		continue;
	}
	if ( 'blueprints/blueprint.json' === $name ) {
		continue; // Validated by the schema check.
	}
	if ( false !== strpos( $name, '/' ) ) {
		$problems[] = "$name: only blueprints/blueprint.json may be in a subdirectory";
		continue;
	}
	$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
	$stem = pathinfo( $name, PATHINFO_FILENAME );
	$size = filesize( $path );

	if ( 'icon.svg' === $name ) {
		if ( $size > WPUN_ASSET_MAX_BYTES_ICON_BANNER ) {
			$problems[] = "$name is $size bytes, over " . WPUN_ASSET_MAX_BYTES_ICON_BANNER;
		}
		wpun_check_svg( $path, $name, $problems );
	} elseif ( isset( WPUN_ASSET_SIZES[ $stem ] ) && in_array( $ext, array( 'png', 'jpg' ), true ) ) {
		if ( $size > WPUN_ASSET_MAX_BYTES_ICON_BANNER ) {
			$problems[] = "$name is $size bytes, over " . WPUN_ASSET_MAX_BYTES_ICON_BANNER;
		}
		wpun_check_image( $path, $name, $stem, $ext, $problems );
	} elseif ( preg_match( '/^screenshot-([1-9][0-9]*)$/', $stem, $m ) && isset( WPUN_ASSET_TYPES[ $ext ] ) ) {
		$screenshots[] = (int) $m[1];
		if ( $size > WPUN_ASSET_MAX_BYTES_SCREENSHOT ) {
			$problems[] = "$name is $size bytes, over " . WPUN_ASSET_MAX_BYTES_SCREENSHOT;
		}
		wpun_check_image( $path, $name, $stem, $ext, $problems );
	} else {
		$problems[] = "$name is not a file name WordPress.org uses";
	}
}

if ( ! is_file( "$dir/blueprints/blueprint.json" ) ) {
	$problems[] = 'blueprints/blueprint.json is missing';
}

// Screenshots: numbered 1..N once each (also across extensions), and as many as the readme's captions.
sort( $screenshots );
if ( count( $screenshots ) !== count( array_unique( $screenshots ) ) ) {
	$problems[] = 'a screenshot number is used twice (two extensions)';
}
if ( array_values( array_unique( $screenshots ) ) !== ( $screenshots ? range( 1, max( $screenshots ) ) : array() ) ) {
	$problems[] = 'screenshot numbers are not 1..N without gaps: ' . implode( ', ', $screenshots );
}

$text = (string) file_get_contents( $readme );
if ( ! preg_match( '/^== Screenshots ==\R(.*?)(?=^== |\z)/ms', $text, $section ) ) {
	$problems[] = 'the readme has no == Screenshots == section';
	$captions   = array();
} else {
	preg_match_all( '/^(\d+)\.\s+\S/m', $section[1], $found );
	$captions = array_map( 'intval', $found[1] );
}
if ( $captions !== ( $captions ? range( 1, count( $captions ) ) : array() ) ) {
	$problems[] = 'the readme captions are not numbered 1..N: ' . implode( ', ', $captions );
}
if ( count( $captions ) !== count( array_unique( $screenshots ) ) ) {
	$problems[] = count( $captions ) . ' readme captions but ' . count( array_unique( $screenshots ) ) . ' screenshots';
}

if ( $problems ) {
	foreach ( $problems as $problem ) {
		fwrite( STDERR, "FAIL: $problem\n" );
	}
	exit( 1 );
}
echo 'ok: ' . count( $entries ) . " files, all with an allowed name, format, size and content\n";
