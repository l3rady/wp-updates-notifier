#!/bin/sh
# Run the plugin's own PHPUnit suite (tests/) with core's test bootstrap and a
# real database. Runs inside the wp-tests container; set WP_MULTISITE=1 for the
# multisite run (see `make test-wp` / `make test-wp-ms`).
#
# WPUN_FILTER is a PHPUnit --filter pattern. It comes in as an environment variable so that no shell reads it.
#
# The suite writes "sh: 1: gs: not found" to stderr (WordPress looks for Ghostscript, which the image does not have).
# That line is dropped; any other stderr output and PHPUnit's exit code stay.
set -u

cd /opt/wordpress-develop || exit 1

if [ -n "${WPUN_FILTER:-}" ]; then
	set -- --filter "$WPUN_FILTER" "$@"
fi

status="$(mktemp)"
exec 3>&1
{
	php ./vendor/bin/phpunit -c /opt/wp-updates-notifier/phpunit.xml.dist "$@"
	echo "$?" > "$status"
} 2>&1 1>&3 3>&- | grep -v '^sh: 1: gs: not found$' >&2
code="$(cat "$status")"
rm -f "$status"
exit "$code"
