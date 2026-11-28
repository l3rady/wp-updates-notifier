#!/bin/sh
# Tests the retry of the link check: bin/docs-links.php (reads lychee's JSON report) on reports that lychee 0.24.2 wrote,
# and bin/docs-links.sh (the pause and the second run) with a stub for docker compose. Run by `make test-docs-links`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
fixtures="$here/tests/bin/docs-links"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# ----- The report parser -----

# parse FIXTURE: runs the parser; the exit status is in $status, the output in $work/out and the retry file in $work/retry.
parse() {
	rm -f "$work/retry"
	status=0
	php "$here/bin/docs-links.php" "$fixtures/$1" "$work/retry" > "$work/out" 2>&1 || status=$?
}

parse clean.json
[ "$status" = 0 ] || fail "a clean report exits $status"
[ ! -e "$work/retry" ] || fail "a clean report wrote a retry file"

parse only-5xx.json
[ "$status" = 3 ] || fail "5xx answers exit $status, expected 3"
grep -qF '[ERROR] https://httpbin.org/status/503 | Rejected status code: 503 Service Unavailable (docs/page.md:1)' "$work/out" || fail "the 503 is not listed: $(cat "$work/out")"
grep -qF 'https://httpbin.org/status/502' "$work/out" || fail "the 502 is not listed"
[ "$(sort "$work/retry" | tr '\n' ' ')" = 'https://httpbin.org/status/502 https://httpbin.org/status/503 ' ] || fail "the retry file is wrong: $(cat "$work/retry")"

for f in only-404.json mixed.json timeout.json; do
	parse "$f"
	[ "$status" = 1 ] || fail "$f exits $status, expected 1"
	[ ! -e "$work/retry" ] || fail "$f wrote a retry file"
	grep -q '^\[ERROR\] https://' "$work/out" || fail "$f lists no failed link"
done
parse mixed.json
grep -qF 'status/503' "$work/out" && grep -qF 'status/404' "$work/out" || fail "a mixed report does not list both links"

echo '{}x' > "$work/bad.json"
status=0
php "$here/bin/docs-links.php" "$work/bad.json" "$work/retry" > /dev/null 2>&1 || status=$?
[ "$status" = 2 ] || fail "an unreadable report exits $status, expected 2"
echo "ok: the report parser"

# ----- The script, with a stub docker compose -----

repo="$work/repo"
mkdir -p "$repo/bin"
cp "$here/bin/docs-links.sh" "$here/bin/docs-links.php" "$repo/bin/"

# The stub: a lychee run with --output copies the report in $STUB_REPORT and exits $STUB_FIRST; any other lychee run
# exits $STUB_SECOND; a php run is real. Every call is logged.
cat > "$work/compose" <<'STUB'
#!/bin/sh
echo "$*" >> "$STUB_LOG"
case "$*" in
	*' lychee '*--output*) cp "$STUB_REPORT" build/links/first.json; exit "${STUB_FIRST:-0}" ;;
	*' lychee '*) exit "${STUB_SECOND:-0}" ;;
	*' php php '*) while [ "$1" != php ]; do shift; done; shift; exec "$@" ;;
esac
exit 0
STUB
chmod +x "$work/compose"
log="$work/log"

# run [VAR=value ...]: runs the script in the scratch repository; the status is in $status, the output in $work/out.
run() {
	: > "$log"
	rm -rf "$repo/build"
	status=0
	( cd "$repo" && env COMPOSE="$work/compose" LINKS_RETRY_WAIT=0 STUB_LOG="$log" "$@" sh bin/docs-links.sh ) > "$work/out" 2>&1 || status=$?
}
lychee_runs() { grep -c ' lychee ' "$log" || true; }

# Without LINKS_RETRY: one strict run, no report, no php, whatever the status.
run STUB_REPORT="$fixtures/clean.json" STUB_SECOND=2
[ "$status" = 2 ] && [ "$(lychee_runs)" = 1 ] || fail "a strict run: status $status, $(lychee_runs) runs"
! grep -q -- '--output' "$log" || fail "a strict run wrote a report"
run STUB_REPORT="$fixtures/clean.json"
[ "$status" = 0 ] || fail "a clean strict run exits $status"
echo "ok: strict without LINKS_RETRY"

# With it: a clean first run is one run.
run LINKS_RETRY=1 STUB_REPORT="$fixtures/clean.json"
[ "$status" = 0 ] && [ "$(lychee_runs)" = 1 ] || fail "a clean first run: status $status, $(lychee_runs) runs: $(cat "$work/out")"

# 5xx only, and the second run passes: success, and the second run is on the retry file.
run LINKS_RETRY=1 STUB_REPORT="$fixtures/only-5xx.json" STUB_FIRST=2 STUB_SECOND=0
[ "$status" = 0 ] || fail "5xx then pass exits $status: $(cat "$work/out")"
[ "$(lychee_runs)" = 2 ] || fail "expected two lychee runs: $(cat "$log")"
tail -n 1 "$log" | grep -q 'build/links/retry.txt$' || fail "the second run is not on the retry file: $(cat "$log")"
grep -q 'status/503' "$work/out" || fail "the log does not name the failed link"

# 5xx twice: failure.
run LINKS_RETRY=1 STUB_REPORT="$fixtures/only-5xx.json" STUB_FIRST=2 STUB_SECOND=2
[ "$status" = 2 ] && [ "$(lychee_runs)" = 2 ] || fail "5xx twice: status $status, $(lychee_runs) runs"

# A 404, or a 404 next to a 503: failure at once, no second run.
for f in only-404.json mixed.json timeout.json; do
	run LINKS_RETRY=1 STUB_REPORT="$fixtures/$f" STUB_FIRST=2
	[ "$status" = 1 ] && [ "$(lychee_runs)" = 1 ] || fail "$f: status $status, $(lychee_runs) runs"
done

# lychee itself failing (status 1) is not retried.
run LINKS_RETRY=1 STUB_REPORT="$fixtures/clean.json" STUB_FIRST=1
[ "$status" = 1 ] && [ "$(lychee_runs)" = 1 ] || fail "a lychee crash: status $status"
echo "ok: the retry"
echo "OK"
