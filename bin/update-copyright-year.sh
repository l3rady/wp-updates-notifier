#!/bin/sh
# Moves the end of the copyright range in the licence notices on to the given
# year: "2015" becomes "2015-2026", and an existing range such as "2015-2024" is
# moved on. Run by the release workflow on the Release PR's branch, because
# release-please only rewrites version strings.
set -eu

year="${1:?usage: update-copyright-year.sh <year>}"
root="$(dirname "$0")/.."

for file in "$root/wp-updates-notifier.php" "$root/uninstall.php"; do
	# "Copyright 2015" or "Copyright 2015-2024" becomes "Copyright 2015-<year>".
	sed -E -i "s/(Copyright )([0-9]{4})(-[0-9]{4})?/\1\2-${year}/" "$file"
	# A range that starts and ends in the same year is just that year.
	sed -E -i "s/(Copyright )([0-9]{4})-\2([^0-9])/\1\2\3/" "$file"
done
