#!/bin/sh
# Tests the blueprint generator (bin/blueprint.php): the output is deterministic and carries the committed files, input
# the generator cannot render fails, and --plugin-vfs swaps the plugin's source. The schema check and its own tests are
# tests/bin/blueprint (node). Run with `make test-blueprint`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$here"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# ----- The generated blueprint -----

php bin/blueprint.php "$work/one.json"
TZ=Pacific/Auckland LC_ALL=C LANG=C php bin/blueprint.php "$work/two.json"
cmp -s "$work/one.json" "$work/two.json" || fail "two runs gave different blueprints"
echo "ok: deterministic"

grep -q '@file:' "$work/one.json" && fail "a placeholder is left in the blueprint"

# The file contents are in the blueprint, unchanged, and the output is JSON that PHP reads back.
php -r '
	$b = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
	$by_path = array();
	foreach ( $b["steps"] as $step ) {
		if ( "writeFile" === $step["step"] ) {
			$by_path[ $step["path"] ] = $step["data"];
		}
	}
	$dir = ".wordpress-org/blueprint/";
	$expected = array(
		"/wordpress/wp-content/mu-plugins/wpun-demo-fake-updates.php" => "fake-updates.php",
		"/wordpress/wp-content/mu-plugins/wpun-demo-mail-catcher.php" => "mail-catcher.php",
		"/wordpress/wp-content/plugins/example-gallery/example-gallery.php" => "fake-plugins/example-gallery.php",
	);
	foreach ( $expected as $path => $file ) {
		if ( ( $by_path[ $path ] ?? null ) !== file_get_contents( $dir . $file ) ) {
			fwrite( STDERR, "$path does not carry $file\n" );
			exit( 1 );
		}
	}
	if ( "/wp-admin/options-general.php?page=wp-updates-notifier" !== $b["landingPage"] ) {
		exit( 1 );
	}
' "$work/one.json" || fail "the blueprint does not carry the committed files, or has another landing page"
grep -q '"step": "runPHP"' "$work/one.json" || fail "no runPHP step"
head -n 1 "$work/one.json" | grep -q '^{$' || fail "the output is not an object"
[ "$(tail -c 1 "$work/one.json" | od -An -c | tr -d ' ')" = '\n' ] || fail "no newline at the end"
echo "ok: files inlined, landing page"

# ----- Input that fails -----

expect_failure() { # name, message that must appear, command...
	name="$1"; message="$2"; shift 2
	if "$@" > /dev/null 2> "$work/error"; then fail "$name was accepted"; fi
	grep -q "$message" "$work/error" || fail "$name: expected the message \"$message\", got: $(cat "$work/error")"
}

# A source directory: a template and one file it includes.
source_dir() {
	rm -rf "$work/src"
	mkdir -p "$work/src/sub"
	printf '{"landingPage":"/","steps":[{"step":"writeFile","path":"/a.php","data":"@file:a.php"},{"step":"installPlugin","pluginData":{"resource":"wordpress.org/plugins","slug":"x"}}]}\n' > "$work/src/blueprint.json"
	printf '<?php\n// "quoted" \\ and unicode: é\n' > "$work/src/a.php"
}

source_dir
php bin/blueprint.php "$work/ok.json" --source="$work/src" || fail "the small source did not generate"
php -r 'exit( json_decode( file_get_contents( $argv[1] ) )->steps[0]->data === file_get_contents( $argv[2] ) ? 0 : 1 );' "$work/ok.json" "$work/src/a.php" || fail "a file with quotes, a backslash and unicode did not round-trip"
grep -q 'unicode: é' "$work/ok.json" || fail "unicode was escaped"
grep -q 'wordpress.org/plugins"' "$work/ok.json" || fail "slashes were escaped"

rm "$work/src/a.php"
expect_failure "a missing file" 'does not exist' php bin/blueprint.php "$work/out.json" --source="$work/src"
[ ! -e "$work/out.json" ] || fail "a blueprint was written although the generator failed"

source_dir
printf 'x\n' > "$work/src/unused.php"
expect_failure "a file nothing includes" 'unused.php is not included' php bin/blueprint.php "$work/out.json" --source="$work/src"

source_dir
sed -i 's#"@file:a.php"#"before @file:a.php"#' "$work/src/blueprint.json"
expect_failure "a placeholder that is not the whole value" 'not the whole value' php bin/blueprint.php "$work/out.json" --source="$work/src"

source_dir
sed -i 's#@file:a.php#@file:../a.php#' "$work/src/blueprint.json"
expect_failure "a file outside the directory" 'not allowed' php bin/blueprint.php "$work/out.json" --source="$work/src"

source_dir
printf '{"steps":' > "$work/src/blueprint.json"
expect_failure "a template that is not JSON" 'blueprint.json' php bin/blueprint.php "$work/out.json" --source="$work/src"
expect_failure "no output file" 'Usage' php bin/blueprint.php --source="$work/src"
echo "ok: input that cannot be rendered fails"

# ----- --plugin-vfs -----

source_dir
php bin/blueprint.php "$work/vfs.json" --source="$work/src" --plugin-vfs=/tmp/plugin.zip || fail "--plugin-vfs failed"
grep -q '"resource": "vfs"' "$work/vfs.json" || fail "the plugin is not installed from the file system"
grep -q '"path": "/tmp/plugin.zip"' "$work/vfs.json" || fail "the path is not the one given"
grep -q 'wordpress.org/plugins' "$work/vfs.json" && fail "the WordPress.org resource is left"
php bin/blueprint.php "$work/real-vfs.json" --plugin-vfs=/tmp/plugin.zip || fail "--plugin-vfs failed on the real template"
grep -c '"resource": "vfs"' "$work/real-vfs.json" | grep -q '^1$' || fail "the real blueprint does not have exactly one vfs resource"
sed -i 's#wordpress.org/plugins#url#' "$work/src/blueprint.json"
expect_failure "no step to replace" 'no installPlugin step' php bin/blueprint.php "$work/out.json" --source="$work/src" --plugin-vfs=/tmp/plugin.zip
echo "ok: --plugin-vfs"

echo "OK"
