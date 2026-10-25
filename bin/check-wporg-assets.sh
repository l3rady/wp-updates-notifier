#!/bin/sh
# Checks build/wporg-assets (make wporg-assets): file names, formats, sizes, the SVG icon and the screenshots against
# the captions of the generated readme (bin/check-wporg-assets.php). The blueprint is validated against the Playground
# schema by the Makefile target afterwards (tests/bin/blueprint/check.js, in the node image).
# Run with `make wporg-assets-check`.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
dir="${1:-$root/build/wporg-assets}"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

php "$root/bin/readme.php" "$work/readme.txt"
php "$root/bin/check-wporg-assets.php" "$dir" "$work/readme.txt"
