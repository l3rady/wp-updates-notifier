#!/bin/sh
# Tests bin/coverage-check.php against small Clover reports: it passes at and above the floor, fails below it, and
# refuses a report that is empty or not Clover. Run by docker/coverage.sh (make coverage); needs only sh and php.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

report() { # statements covered
	cat > "$work/clover.xml" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1"><project timestamp="1">
<file name="/x/wp-updates-notifier/includes/a.php"><metrics statements="$1" coveredstatements="$2"/></file>
<file name="/x/wp-updates-notifier/includes/b.php"><metrics statements="10" coveredstatements="10"/></file>
<metrics statements="$(( $1 + 10 ))" coveredstatements="$(( $2 + 10 ))"/>
</project></coverage>
XML
}

check() { php "$here/bin/coverage-check.php" "$work/clover.xml" "$1" > "$work/out" 2> "$work/err"; }

report 10 5 # 15 of 20 = 75%
check 75 || fail "75% should pass a floor of 75"
check 74.9 || fail "75% should pass a floor of 74.9"
grep -q 'Line coverage: 75.00% (15 of 20' "$work/out" || fail "the total is not printed"
grep -q '50.00%.*includes/a.php' "$work/out" || fail "the least covered file is not listed"
if check 75.1; then fail "75% should fail a floor of 75.1"; else [ $? -eq 1 ] || fail "below the floor should exit 1"; fi
echo "ok: floor"


echo '<coverage><project><metrics statements="0" coveredstatements="0"/></project></coverage>' > "$work/clover.xml"
check 0 && fail "an empty report should fail"
echo 'not xml' > "$work/clover.xml"
check 0 && fail "a broken report should fail"
if php "$here/bin/coverage-check.php" "$work/missing.xml" 0 2> /dev/null; then fail "a missing report should fail"; fi
if php "$here/bin/coverage-check.php" "$work/clover.xml" abc 2> /dev/null; then fail "a non-numeric floor should fail"; fi
echo "ok: bad input"

echo "OK"
