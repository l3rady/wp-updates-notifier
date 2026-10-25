#!/bin/sh
# Builds the plugin as users get it: build/wp-updates-notifier/ and
# build/wp-updates-notifier-<version>.zip, from the files tracked in git minus
# everything in .distignore, plus the readme.txt that bin/readme.php generates (it is not committed). Run with
# `make dist`.
#
# The zip is reproducible: the same commit gives the same bytes whatever the
# machine, umask, time zone or clock. `make dist-repro` proves it. Times come
# from SOURCE_DATE_EPOCH (the commit time unless it is already set), modes are
# fixed, entries are sorted and the zip stores no extra attributes.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="wp-updates-notifier"
version="$(sed -n 's/^ \* Version: //p' "$root/wp-updates-notifier.php" | head -n 1)"
out="$root/build"

# The zip stores local (DOS) times, so the time zone must not leak in.
export TZ=UTC
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$root" log -1 --format=%ct)}"
[ -n "$SOURCE_DATE_EPOCH" ] || { echo "No commit time for SOURCE_DATE_EPOCH" >&2; exit 1; }

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

# readme.txt comes from committed files only (see bin/readme.php), so it does not break the reproducibility.
php "$root/bin/readme.php" "$out/$slug/readme.txt"

# Fixed modes and times, so neither the umask nor the clock reaches the zip.
find "$out/$slug" -type d -exec chmod 755 {} +
find "$out/$slug" -type f -exec chmod 644 {} +
find "$out/$slug" -exec touch -d "@$SOURCE_DATE_EPOCH" {} +

# Sorted entries (directories included); -X leaves out extra file attributes
# so the zip only depends on the content.
zip="$slug-$version.zip"
(
	cd "$out"
	find "$slug" | LC_ALL=C sort | zip -qX -@ "$zip"
	sha256sum "$zip" > "$zip.sha256"
)

echo "$out/$zip"
