#!/bin/sh
# Tests bin/ci-failure-issue.sh (the issue for a failed scheduled run) with a stub gh that records its calls and lists
# the open issues in $STUB_ISSUES ("number<TAB>title" per line). Run by `make test-ci-failure-issue`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir "$work/bin"

cat > "$work/bin/gh" <<'STUB'
#!/bin/sh
printf '%s\n' "$*" >> "$STUB_LOG"
case "$1 $2" in
	'issue list') cat "$STUB_ISSUES" ;;
	'label create') [ -z "${STUB_LABEL_EXISTS:-}" ] || { echo 'label with name "ci-failure" already exists' >&2; exit 1; } ;;
esac
exit 0
STUB
chmod +x "$work/bin/gh"

tab="$(printf '\t')"
nightly='Scheduled ci run failed (17 3 * * *)'
weekly='Scheduled ci run failed (41 3 * * 1)'
url='https://github.com/o/r/actions/runs/42'

# run RESULT [SCHEDULE]: runs the script for the gate result; the calls are in $work/log.
run() {
	: > "$work/log"
	GATE_RESULT="$1" FAILED='phpstan: failure
rector: cancelled' WORKFLOW=ci SCHEDULE="${2:-17 3 * * *}" RUN_URL="$url" \
		PATH="$work/bin:$PATH" STUB_LOG="$work/log" STUB_ISSUES="$work/issues" sh "$here/bin/ci-failure-issue.sh" > "$work/out" 2>&1 \
		|| fail "the script failed: $(cat "$work/out")"
}
calls() { grep -c "^$1" "$work/log" || true; }

# A failure with no issue: the label is ensured and one issue is created.
: > "$work/issues"
run failure
grep -q '^label create ci-failure' "$work/log" || fail "no label create: $(cat "$work/log")"
[ "$(calls 'issue create')" = 1 ] || fail "expected one issue create: $(cat "$work/log")"
grep -qF -- "--title $nightly" "$work/log" || fail "wrong title: $(cat "$work/log")"
grep -q -- '--label ci-failure' "$work/log" || fail "the issue has no label"
grep -qF "$url" "$work/log" || fail "the run link is not in the issue"
grep -qF 'phpstan: failure' "$work/log" || fail "the failed jobs are not in the issue"
[ "$(calls 'issue comment')" = 0 ] && [ "$(calls 'issue close')" = 0 ] || fail "extra calls: $(cat "$work/log")"
echo "ok: failure opens an issue"

# The label exists already: not an error.
STUB_LABEL_EXISTS=1 run failure
[ "$(calls 'issue create')" = 1 ] || fail "an existing label stopped the issue: $(cat "$work/log")"
echo "ok: an existing label"

# A failure with the issue open: a comment, no new issue.
printf '7%s%s\n' "$tab" "$nightly" > "$work/issues"
run failure
[ "$(calls 'issue comment 7')" = 1 ] || fail "expected a comment on #7: $(cat "$work/log")"
grep -qF "$url" "$work/log" || fail "the run link is not in the comment"
[ "$(calls 'issue create')" = 0 ] || fail "a second issue was created"
echo "ok: failure comments on the open issue"

# A pass with the issue open: a comment, then close.
run success
[ "$(calls 'issue comment 7')" = 1 ] && grep -qF "Passed again in $url" "$work/log" || fail "no passed comment: $(cat "$work/log")"
[ "$(calls 'issue close 7')" = 1 ] || fail "the issue was not closed: $(cat "$work/log")"
echo "ok: success closes the issue"

# A pass with no issue: nothing is changed.
: > "$work/issues"
run success
[ "$(calls 'issue comment')" = 0 ] && [ "$(calls 'issue close')" = 0 ] && [ "$(calls 'issue create')" = 0 ] || fail "success with no issue changed something: $(cat "$work/log")"
echo "ok: success with no issue"

# The other schedule's issue is not touched, and a similar title is not a match.
printf '8%s%s\n9%s%s\n' "$tab" "$weekly" "$tab" "$nightly extra" > "$work/issues"
run success
[ "$(calls 'issue close')" = 0 ] || fail "success closed another schedule's issue"
run failure
[ "$(calls 'issue comment')" = 0 ] && [ "$(calls 'issue create')" = 1 ] || fail "failure used another schedule's issue: $(cat "$work/log")"
printf '7%s%s\n8%s%s\n' "$tab" "$nightly" "$tab" "$weekly" > "$work/issues"
run success '41 3 * * 1'
[ "$(calls 'issue close 8')" = 1 ] && [ "$(calls 'issue close')" = 1 ] || fail "the weekly pass closed the wrong issue: $(cat "$work/log")"
echo "ok: schedules stay apart"

# Cancelled and skipped runs do nothing, not even a list.
for result in cancelled skipped; do
	run "$result"
	[ ! -s "$work/log" ] || fail "$result called gh: $(cat "$work/log")"
done
echo "ok: cancelled and skipped"

# A failing issue list is an error, not a reason to open another issue.
printf '#!/bin/sh\nexit 1\n' > "$work/bin/gh"
if GATE_RESULT=failure WORKFLOW=ci SCHEDULE=x RUN_URL=u PATH="$work/bin:$PATH" sh "$here/bin/ci-failure-issue.sh" > /dev/null 2>&1; then
	fail "a failing gh list did not fail the script"
fi
echo "ok: gh errors fail"
echo "OK"
