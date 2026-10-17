#!/bin/sh
# Installs the zip built by `make dist` into a fresh WordPress with WP-CLI and
# checks it activates and schedules its check. Runs in the `dist` container
# (database from docker-compose). Run with `make test-dist`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="wp-updates-notifier"
version="$(sed -n 's/^ \* Version: //p' "$root/wp-updates-notifier.php" | head -n 1)"
zip="$root/build/$slug-$version.zip"

[ -f "$zip" ] || fail "$zip not found; run make dist first"

. "$root/bin/fresh-wordpress.sh"
fresh_wordpress

wp plugin install "$zip" --activate
wp plugin is-active "$slug" || fail "the plugin is not active"
echo "ok: installed and activated from the zip"

installed="$(wp plugin get "$slug" --field=version)"
[ "$installed" = "$version" ] || fail "installed version is not $version: got '$installed'"
wp cron event list --fields=hook --format=csv | grep -qx sc_wpun_update_check || fail "activation did not schedule the update check"
echo "ok: version $version, update check scheduled"

wp plugin deactivate "$slug"
wp cron event list --fields=hook --format=csv | grep -qx sc_wpun_update_check && fail "deactivation left the update check scheduled"
wp plugin uninstall "$slug"
[ -z "$(wp option list --search='sc_wpun_*' --field=option_name)" ] || fail "uninstall left options behind"
echo "ok: deactivated and uninstalled cleanly"

echo "OK"
