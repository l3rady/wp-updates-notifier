#!/bin/sh
# Installs the zip built by `make dist` into a fresh WordPress with WP-CLI and
# checks it activates and schedules its check. Runs in the `dist` container
# (database from docker-compose). Run with `make test-dist`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="wp-updates-notifier"
version="$(sed -n 's/^ \* Version: //p' "$root/class-sc-wp-updates-notifier.php" | head -n 1)"
zip="$root/build/$slug-$version.zip"
wp_version="${WP_VERSION:-7.1.3}"
path="$(mktemp -d)"

[ -f "$zip" ] || fail "$zip not found; run make dist first"

# PHP notices go to stderr: they stay in the log, but out of the values read
# from WP-CLI (its own dependencies raise deprecations on the newest PHP).
wp() { php -d display_errors=stderr /usr/local/bin/wp --path="$path" --quiet "$@"; }

echo "WordPress $wp_version on PHP $(php -r 'echo PHP_VERSION;')"
wp core download --version="$wp_version" --skip-content
wp config create --dbname=wordpress_dist --dbuser=root --dbpass=root --dbhost="${WP_DB_HOST:-db}" --skip-check
# A fresh database each run, without needing the mysql client.
php -r '$db = new mysqli( getenv( "WP_DB_HOST" ) ?: "db", "root", "root" ); $db->query( "DROP DATABASE IF EXISTS wordpress_dist" ); $db->query( "CREATE DATABASE wordpress_dist" ) || exit( 1 );'
wp core install --url=http://localhost --title=dist --admin_user=admin --admin_password=admin --admin_email=admin@example.org --skip-email

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
