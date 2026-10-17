#!/bin/sh
# Built-in modules and add-ons may only use the public API (anything marked @internal is off limits),
# and the checker itself is tested against known-bad code so that it cannot pass by doing nothing.
# Run with `make check-public-api`.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

php bin/check-public-api.php modules tests/fixtures/plugins
sh tests/bin/test-check-public-api.sh
