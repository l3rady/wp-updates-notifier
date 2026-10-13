#!/bin/sh
# Runs WordPress.org's Plugin Check (the tool its reviewers use) against the zip
# built by `make dist`, in a fresh WordPress. Fails on any error; warnings are
# listed. Run with `make plugin-check`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="wp-updates-notifier"
version="$(sed -n 's/^ \* Version: //p' "$root/class-sc-wp-updates-notifier.php" | head -n 1)"
zip="$root/build/$slug-$version.zip"
plugin_check_version="${PLUGIN_CHECK_VERSION:-2.1.0}"

[ -f "$zip" ] || fail "$zip not found; run make dist first"

. "$root/bin/fresh-wordpress.sh"
fresh_wordpress

wp plugin install "$zip"
wp plugin install plugin-check --version="$plugin_check_version" --activate
echo "Plugin Check $plugin_check_version"

results="$(mktemp)"
# Exit status is not used: it differs between versions. The JSON is what counts.
php -d display_errors=stderr /usr/local/bin/wp --path="$path" plugin check "$slug" --format=json --fields=type,code,line,message > "$results" 2>/dev/null || true

php -r '
	$errors = 0;
	$file   = null;
	foreach ( file( $argv[1], FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( 0 === strpos( $line, "FILE: " ) ) {
			$file = substr( $line, 6 );
			continue;
		}
		$rows = json_decode( $line, true );
		if ( ! is_array( $rows ) ) {
			continue;
		}
		foreach ( $rows as $row ) {
			printf( "%-7s %s:%s %s\n        %s\n", $row["type"], $file, $row["line"], $row["code"], $row["message"] );
			$errors += "ERROR" === $row["type"] ? 1 : 0;
		}
	}
	echo $errors ? "\n$errors error(s)\n" : "\nNo errors\n";
	exit( $errors ? 1 : 0 );
' "$results" || fail "Plugin Check found errors"

echo "OK"
