#!/bin/sh
# Tests bin/check.sh with a stub make: the steps run in order, a failure stops the run with the step's exit code and a
# short report, and only a step with a known fixer prints a fix line. Run by `make test-check`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# The script writes build/check/ in the checkout it belongs to (the parent of its bin/): use a scratch copy.
mkdir -p "$work/bin" "$work/run"
cp "$here/bin/check.sh" "$work/bin/check.sh"

# A stub make: prints a few lines, or 200 for a step in STUB_LONG, and exits with STUB_CODE for STUB_FAIL.
cat > "$work/make" <<'STUB'
#!/bin/sh
[ "$1" = --no-print-directory ] || { echo "stub: unexpected arguments: $*" >&2; exit 99; }
step="$2"
echo "$step" >> "$STUB_RAN"
n=3
[ "$step" != "${STUB_LONG:-}" ] || n=200
i=1
while [ "$i" -le "$n" ]; do echo "$step output line $i"; i=$((i + 1)); done
[ "$step" != "${STUB_FAIL:-}" ] || exit "${STUB_CODE:-1}"
exit 0
STUB
chmod +x "$work/make"

run() {
	( cd "$work/run" && CHECK_MAKE="$work/make" STUB_RAN="$work/ran" sh ../bin/check.sh "$@" )
}

# Every step passes: exit 0 and one line per step.
: > "$work/ran"
run lint stan sast > "$work/out" 2>&1 || fail "a run where every step passes failed"
[ "$(wc -l < "$work/out")" -eq 3 ] || fail "expected one line per step, got: $(cat "$work/out")"
grep -Eq '^ok    lint +[0-9]+s$' "$work/out" || fail "no ok line for lint"
[ "$(tr '\n' ' ' < "$work/ran")" = 'lint stan sast ' ] || fail "the steps did not run in order"
[ -f "$work/build/check/stan.log" ] || fail "no log for stan"

# A failing step: its exit code, no later step, the last 60 lines of a long log, the log path, the fix line.
: > "$work/ran"
status=0
STUB_FAIL=stan STUB_CODE=3 STUB_LONG=stan run lint stan sast > "$work/out" 2>&1 || status=$?
[ "$status" -eq 3 ] || fail "expected exit code 3, got $status"
[ "$(tr '\n' ' ' < "$work/ran")" = 'lint stan ' ] || fail "a step ran after the failure"
grep -Eq '^FAIL  stan  [0-9]+s$' "$work/out" || fail "no FAIL line"
[ "$(grep -c 'stan output line' "$work/out")" -eq 60 ] || fail "the log tail is not 60 lines"
grep -q 'stan output line 200$' "$work/out" || fail "the log tail lacks the last line"
grep -q 'stan output line 140$' "$work/out" && fail "the log tail is longer than 60 lines"
grep -qx 'log: build/check/stan.log' "$work/out" || fail "no log path"
grep -q '^fix: fix the code; phpstan-baseline.neon only shrinks$' "$work/out" || fail "wrong fix line"
[ "$(wc -l < "$work/out")" -lt 80 ] || fail "the failure output has 80 lines or more"
grep -q 'sast' "$work/out" && fail "the output names a step that did not run"

# A step without a fixer: no fix line.
status=0
STUB_FAIL=taint STUB_CODE=5 run taint > "$work/out" 2>&1 || status=$?
[ "$status" -eq 5 ] || fail "expected exit code 5, got $status"
grep -q '^FAIL  taint' "$work/out" || fail "no FAIL line for taint"
grep -q '^fix:' "$work/out" && fail "a step without a fixer printed a fix line"

# No step is a usage error.
status=0
run > "$work/out" 2>&1 || status=$?
[ "$status" -eq 2 ] || fail "no step should exit 2, got $status"

echo "ok: check.sh"
