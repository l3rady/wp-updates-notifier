#!/bin/sh
# Tests the WordPress.org assets checker (bin/check-wporg-assets.php) and the assembly (bin/build-wporg-assets.sh): a
# clean set passes and each kind of mistake fails with its message. The images are made on the fly with GD.
# Run with `make test-wporg-assets`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$here"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Tiny images written by hand (the PHP image has no GD): an all-black PNG, and a GIF header, which is enough for
# getimagesize().
png() { # width height file
	php -r '
		$w = (int) $argv[1];
		$h = (int) $argv[2];
		$chunk = static function ( $type, $data ) {
			return pack( "N", strlen( $data ) ) . $type . $data . pack( "N", crc32( $type . $data ) );
		};
		$rows = str_repeat( "\0" . str_repeat( "\0", $w * 3 ), $h );
		file_put_contents(
			$argv[3],
			"\x89PNG\r\n\x1a\n" . $chunk( "IHDR", pack( "NNCCCCC", $w, $h, 8, 2, 0, 0, 0 ) ) . $chunk( "IDAT", gzcompress( $rows ) ) . $chunk( "IEND", "" )
		);
	' "$1" "$2" "$3"
}

readme() { # number of captions
	{
		printf '=== X ===\n\n== Description ==\n\nText.\n\n== Screenshots ==\n\n'
		i=1
		while [ "$i" -le "$1" ]; do printf '%s. Caption %s.\n' "$i" "$i"; i=$((i + 1)); done
		printf '\n== Changelog ==\n\nNone.\n'
	} > "$work/readme.txt"
}

# A clean set: every icon and banner, two screenshots, the SVG icon, the blueprint.
clean() {
	rm -rf "$work/set"
	mkdir -p "$work/set/blueprints"
	png 128 128 "$work/set/icon-128x128.png"
	png 256 256 "$work/set/icon-256x256.png"
	png 772 250 "$work/set/banner-772x250.png"
	png 1544 500 "$work/set/banner-1544x500.png"
	png 40 30 "$work/set/screenshot-1.png"
	png 40 30 "$work/set/screenshot-2.png"
	printf '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><path d="M0 0h8v8z" fill="url(#g)"/></svg>\n' > "$work/set/icon.svg"
	printf '{}\n' > "$work/set/blueprints/blueprint.json"
	readme 2
}

check() { php bin/check-wporg-assets.php "$work/set" "$work/readme.txt" 2>&1; }

expect_fail() { # description, expected message
	out="$(check)" && fail "$1: the check passed"
	case "$out" in *"$2"*) echo "ok: $1" ;; *) fail "$1: expected '$2', got: $out" ;; esac
}

clean
check > /dev/null || fail "a clean set failed: $(check)"
echo "ok: a clean set passes"

clean; png 128 127 "$work/set/icon-128x128.png"
expect_fail "wrong icon size" "icon-128x128.png is 128x127, expected 128x128"

clean; png 772 250 "$work/set/banner-1544x500.png"
expect_fail "wrong banner size" "banner-1544x500.png is 772x250"

clean; printf 'GIF89a\200\000\200\000\000\000\000;' > "$work/set/icon-128x128.png"
expect_fail "format differs from the extension" "does not match its extension"

clean; echo "not an image" > "$work/set/screenshot-2.png"
expect_fail "a screenshot that is not an image" "screenshot-2.png is not a valid image"

clean; echo x > "$work/set/notes.txt"
expect_fail "an unknown file" "notes.txt is not a file name WordPress.org uses"

clean; mkdir "$work/set/src"; echo x > "$work/set/src/a.svg"
expect_fail "a source directory" "src/a.svg: only blueprints/blueprint.json"

clean; rm "$work/set/blueprints/blueprint.json"
expect_fail "a missing blueprint" "blueprints/blueprint.json is missing"

clean; mv "$work/set/screenshot-2.png" "$work/set/screenshot-3.png"; readme 2
expect_fail "a gap in the screenshots" "not 1..N without gaps"

clean; png 40 30 "$work/set/screenshot-3.png"
expect_fail "more screenshots than captions" "2 readme captions but 3 screenshots"

clean; readme 3
expect_fail "more captions than screenshots" "3 readme captions but 2 screenshots"

clean; printf '=== X ===\n\n== Screenshots ==\n\n1. A.\n3. B.\n' > "$work/readme.txt"
expect_fail "captions that skip a number" "captions are not numbered 1..N"

clean; printf '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>\n' > "$work/set/icon.svg"
expect_fail "an SVG with a script" "icon.svg has a <script> element"

clean; printf '<svg xmlns="http://www.w3.org/2000/svg"><rect onload="x()" width="1" height="1"/></svg>\n' > "$work/set/icon.svg"
expect_fail "an SVG with an event attribute" "icon.svg has an on* event attribute"

clean; printf '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="https://example.com/a.png"/></svg>\n' > "$work/set/icon.svg"
expect_fail "an SVG with an external href" "icon.svg has an external href"

clean; printf '<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill:url(//example.com/a)"/></svg>\n' > "$work/set/icon.svg"
expect_fail "an SVG with an external url()" "icon.svg has an external url()"

clean; printf '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject/></svg>\n' > "$work/set/icon.svg"
expect_fail "an SVG with a foreignObject" "icon.svg has a <foreignObject> element"

# ----- The assembly -----

sh bin/build-wporg-assets.sh "$work/one" > /dev/null
sh bin/build-wporg-assets.sh "$work/two" > /dev/null
diff -r "$work/one" "$work/two" > /dev/null || fail "two assemblies differ"
[ -f "$work/one/blueprints/blueprint.json" ] || fail "the assembly has no blueprint"
for d in src readme blueprint; do
	[ ! -e "$work/one/$d" ] || fail "the assembly contains $d/"
done
php bin/readme.php "$work/readme.txt"
php bin/check-wporg-assets.php "$work/one" "$work/readme.txt" > /dev/null || fail "the committed assets fail the check: $(php bin/check-wporg-assets.php "$work/one" "$work/readme.txt" 2>&1)"
echo "ok: the assembly is deterministic, leaves out the sources and passes the check"

echo "OK"
