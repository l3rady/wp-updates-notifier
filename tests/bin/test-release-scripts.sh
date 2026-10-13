#!/bin/sh
# Rehearses the scripts the release workflow runs on the Release PR's branch,
# on a throwaway copy of the repository files. Run with `make test-release`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin"
cp "$here"/bin/*.sh "$work/bin/"
cp "$here/class-sc-wp-updates-notifier.php" "$here/uninstall.php" "$here/readme.txt" "$work/"

# Copyright: a single year becomes a range, a range is moved on, a same-year range collapses.
sh "$work/bin/update-copyright-year.sh" 2031
grep -q 'Copyright 2015-2031' "$work/class-sc-wp-updates-notifier.php" || fail "range not set in the main file"
grep -q 'Copyright 2015-2031' "$work/uninstall.php" || fail "range not set in uninstall.php"
sh "$work/bin/update-copyright-year.sh" 2032
grep -q 'Copyright 2015-2032' "$work/uninstall.php" || fail "range not moved on"
grep -q '2031' "$work/uninstall.php" && fail "old end year left behind"
sed -E -i 's/Copyright 2015-2032/Copyright 2032/' "$work/uninstall.php"
sh "$work/bin/update-copyright-year.sh" 2032
grep -q 'Copyright 2032[^-]' "$work/uninstall.php" || fail "same-year range not collapsed"
echo "ok: copyright year"

# Stable tag: replaced, once, nothing else touched.
sh "$work/bin/set-stable-tag.sh" 2.0.0
[ "$(grep -c '^Stable tag: 2.0.0$' "$work/readme.txt")" = 1 ] || fail "stable tag not set"
[ "$(diff "$here/readme.txt" "$work/readme.txt" | grep -c '^>')" = 1 ] || fail "readme.txt changed in more than one line"
echo "ok: stable tag"

# The plugin header still has exactly one parseable Version line between the markers.
sed -n '/x-release-please-start-version/,/x-release-please-end/p' "$here/class-sc-wp-updates-notifier.php" | grep -Eq '^ \* Version: [0-9]+\.[0-9]+\.[0-9]+$' || fail "no semver Version line between the release-please markers"
echo "ok: version markers"

echo "OK"
