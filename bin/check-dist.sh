#!/bin/sh
# Checks the zip built by `make dist`: only runtime files, the files that must
# be there, no bundled dependencies and the version in its name.
# Run with `make dist-check`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="wp-updates-notifier"
version="$(sed -n 's/^ \* Version: //p' "$root/wp-updates-notifier.php" | head -n 1)"
zip="$root/build/$slug-$version.zip"

[ -f "$zip" ] || fail "$zip not found; run make dist first"

entries="$(unzip -Z1 "$zip" | sort)"

# The plugin has no runtime dependencies: nothing from Composer or npm ships, and composer.json requires only php.
bundled="$(echo "$entries" | grep -E "^$slug/(.*/)?(vendor|node_modules)/" || true)"
[ -z "$bundled" ] || fail "the zip bundles dependencies:
$bundled"
extra="$(php -r '$c = json_decode(file_get_contents($argv[1]), true); echo implode(", ", array_diff(array_keys($c["require"] ?? array()), array("php")));' "$root/composer.json")"
[ -z "$extra" ] || fail "composer.json requires runtime dependencies besides php: $extra"
echo "ok: no vendor/ or node_modules/ in the zip, and composer.json requires only php"

# Everything in the zip must match one of these.
allowed="^$slug/$
^$slug/wp-updates-notifier\.php$
^$slug/uninstall\.php$
^$slug/readme\.txt$
^$slug/LICENSE$
^$slug/languages/$
^$slug/languages/[A-Za-z0-9_-]+\.l10n\.php$
^$slug/assets/
^$slug/includes/
^$slug/modules/"

unexpected="$(echo "$entries" | grep -Ev "$(echo "$allowed" | paste -sd'|')" || true)"
[ -z "$unexpected" ] || fail "unexpected files in the zip:
$unexpected"

for required in wp-updates-notifier.php uninstall.php readme.txt LICENSE; do
	echo "$entries" | grep -qx "$slug/$required" || fail "$required is missing from the zip"
done
echo "ok: zip contains only runtime files, and all required ones"

# The main file inside the zip is a plugin with the version the zip is named after.
unzip -p "$zip" "$slug/wp-updates-notifier.php" | grep -q "^ \* Plugin Name: WP Updates Notifier$" || fail "plugin header missing"
unzip -p "$zip" "$slug/wp-updates-notifier.php" | grep -qx " \* Version: $version" || fail "version in the zip differs from $version"
echo "ok: $slug/wp-updates-notifier.php is version $version"

echo "OK"
