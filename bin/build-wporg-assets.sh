#!/bin/sh
# Assembles what the release deploy puts in the SVN assets/ directory of WordPress.org: build/wporg-assets/.
# It is the top-level files of .wordpress-org/ (icons, banners, screenshots) without the directories that are
# sources (src/, readme/, blueprint/), plus blueprints/blueprint.json, which bin/blueprint.php generates. Run with
# `make wporg-assets`; `make wporg-assets-check` checks the result.
#
# It only touches build/wporg-assets/, so it does not matter whether `make dist` (which wipes build/) ran before or
# after it. The output depends on committed files only.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/build/wporg-assets}"

rm -rf "$out"
mkdir -p "$out/blueprints"

# Regular files directly in .wordpress-org/ only. The checker rejects any name WordPress.org does not know.
find "$root/.wordpress-org" -maxdepth 1 -type f | LC_ALL=C sort | while IFS= read -r file; do
	cp "$file" "$out/"
done

php "$root/bin/blueprint.php" "$out/blueprints/blueprint.json"

find "$out" -type d -exec chmod 755 {} +
find "$out" -type f -exec chmod 644 {} +

echo "$out"
