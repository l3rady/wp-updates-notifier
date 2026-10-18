#!/bin/sh
# Tests bin/check-security-ignores.sh against small known-good and known-bad code, so that it cannot pass the real
# scan by finding nothing. Run by `make sast`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$here"
fixtures=tests/bin/check-security-ignores
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

sh bin/check-security-ignores.sh "$fixtures/good" > /dev/null || fail "the good fixture was reported"

if sh bin/check-security-ignores.sh "$fixtures/bare-phpcs" 2> "$work/report" > /dev/null; then
	fail "a bare phpcs:ignore of a WordPress.Security sniff passed"
fi
grep -q 'a.php:5:.*phpcs:ignore' "$work/report" || fail "the bare phpcs:ignore was not reported on its line"

if sh bin/check-security-ignores.sh "$fixtures/bare-nosemgrep" 2> "$work/report" > /dev/null; then
	fail "a bare nosemgrep passed"
fi
grep -q 'a.php:5:.*nosemgrep' "$work/report" || fail "the bare nosemgrep was not reported on its line"

if sh bin/check-security-ignores.sh "$fixtures/no-guard" 2> "$work/report" > /dev/null; then
	fail "a file without an ABSPATH guard passed"
fi
grep -q 'a.php: no ABSPATH guard' "$work/report" || fail "the missing guard was not reported"

echo "ok: security ignores checker"
