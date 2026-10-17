#!/bin/sh
# Builds the plugin as users get it: build/wp-updates-notifier/ and
# build/wp-updates-notifier-<version>.zip, from the files tracked in git minus
# everything in .distignore. Run with `make dist`.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="wp-updates-notifier"
version="$(sed -n 's/^ \* Version: //p' "$root/wp-updates-notifier.php" | head -n 1)"
out="$root/build"

[ -n "$version" ] || { echo "No Version in the plugin header" >&2; exit 1; }

rm -rf "$out"
mkdir -p "$out/$slug"

# Tracked files only, so nothing local (caches, vendor/, build/) can slip in,
# then .distignore applied to the whole tree (rsync does not apply directory
# excludes to paths listed with --files-from, hence two steps).
tracked="$(mktemp -d)"
git -C "$root" ls-files -z | rsync -a --from0 --files-from=- "$root/" "$tracked/"
rsync -a --exclude-from="$root/.distignore" "$tracked/" "$out/$slug/"
rm -rf "$tracked"

# -X leaves out extra file attributes so the zip only depends on the content.
( cd "$out" && zip -qrX "$slug-$version.zip" "$slug" )

echo "$out/$slug-$version.zip"
