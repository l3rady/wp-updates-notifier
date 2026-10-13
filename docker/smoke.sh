#!/bin/sh
# Smoke test for the Docker environment. Run inside the php container:
#   make smoke PHP_VERSION=7.4
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

echo "PHP:   $(php -r 'echo PHP_VERSION;') ($(php -r 'echo PHP_SAPI;'))"

php -r 'extension_loaded("mysqli") || exit(1);' || fail "mysqli extension not loaded"
echo "Ext:   mysqli ok"

# Every PHP file of the plugin must lint on this PHP version.
find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -not -path './build/*' \
    | while read -r file; do
        php -l "$file" >/dev/null || fail "$file does not lint on this PHP version"
    done
echo "Lint:  all PHP files ok"

echo "OK"
