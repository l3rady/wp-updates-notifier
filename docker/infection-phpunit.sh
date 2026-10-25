#!/bin/sh
# WordPress's PHPUnit for Infection (infection.json5, phpUnit.customPath). Infection stops the initial test run as soon
# as the test process writes to stderr, and the suite writes "sh: 1: gs: not found" there (WordPress looks for
# Ghostscript, which the image does not have). That line is dropped; any other stderr output and the exit code stay.
set -u

status="$(mktemp)"
exec 3>&1
{
	php /opt/wordpress-develop/vendor/bin/phpunit "$@"
	echo "$?" > "$status"
} 2>&1 1>&3 3>&- | grep -v '^sh: 1: gs: not found$' >&2
code="$(cat "$status")"
rm -f "$status"
exit "$code"
