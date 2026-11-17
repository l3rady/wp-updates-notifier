#!/bin/sh
# Runs the gitleaks history scan and fails when it scanned no commits of a range that has some. gitleaks logs a git
# error ("not a git repository") and still ends with "0 commits scanned ... no leaks found" and exit 0, so its exit
# code alone is not enough.
#
# Usage: sh bin/secrets-history.sh <log-opts range, or empty for all history> <command that runs gitleaks...>
# gitleaks' own output is shown, and its exit code is kept. Tested by tests/bin/test-git-mount.sh.
set -u

range="$1"
shift

if [ -n "$range" ]; then
	expected="$(git rev-list --count "$range" 2>/dev/null)" || expected=''
else
	expected="$(git rev-list --count --all 2>/dev/null)" || expected=''
fi
case "$expected" in
	'' | *[!0-9]*)
		echo "secrets-history: could not count the commits to scan (range: ${range:-all history})." >&2
		exit 1
		;;
esac

log="$(mktemp)"
trap 'rm -f "$log"' EXIT
"$@" >"$log" 2>&1
status=$?
cat "$log"
[ "$status" -eq 0 ] || exit "$status"

[ "$expected" -gt 0 ] || exit 0
scanned="$(sed -n 's/.*[^0-9]\([0-9][0-9]*\) commits\{0,1\} scanned.*/\1/p' "$log" | tail -n 1)"
if [ -z "$scanned" ] || [ "$scanned" -eq 0 ]; then
	echo "secrets-history: gitleaks scanned ${scanned:-no} commits, but the range has $expected. The scan did not run; this is not a pass." >&2
	exit 1
fi
