#!/bin/sh
# Tests bin/agent-stop-check.sh (the Claude Code Stop hook) with a stub make and a stub docker in a throwaway git
# repository: it stays silent when no plugin code changed or the hook is already continuing, sends a failure back with
# exit 2, skips steps that passed on the same tree, and skips with a note when Docker or vendor/ is missing. Run by
# `make test-check`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin" "$work/includes" "$work/docs" "$work/vendor"
cp "$here/bin/agent-stop-check.sh" "$here/bin/check.sh" "$work/bin/"
touch "$work/vendor/autoload.php"
echo '<?php' > "$work/includes/a.php"
echo doc > "$work/docs/a.md"
# A throwaway repository: identity and signing are set for it only.
git -C "$work" init -q
git -C "$work" config user.email test@example.com
git -C "$work" config user.name Test
git -C "$work" config commit.gpgsign false
printf '/build/\n/make\n/ran\n/out\n/err\n/docker\n' > "$work/.gitignore"
git -C "$work" add .
git -C "$work" commit -q -m init

cat > "$work/make" <<'STUB'
#!/bin/sh
echo "$2" >> "$STUB_RAN"
echo "$2 output"
[ "$2" != "${STUB_FAIL:-}" ] || exit 1
exit 0
STUB
cat > "$work/docker" <<'STUB'
#!/bin/sh
exit "${STUB_DOCKER_CODE:-0}"
STUB
chmod +x "$work/make" "$work/docker"

# Runs the hook with the given JSON on stdin; sets status, and out and err hold stdout and stderr.
hook() {
	status=0
	( cd "$work" && printf '%s' "$1" | CHECK_MAKE="$work/make" STOP_CHECK_DOCKER="$work/docker" STUB_RAN="$work/ran" \
		sh bin/agent-stop-check.sh ) > "$work/out" 2> "$work/err" || status=$?
}
idle='{"stop_hook_active":false}'

# Nothing relevant changed (only docs/): silent, no make.
: > "$work/ran"
echo changed >> "$work/docs/a.md"
hook "$idle"
[ "$status" -eq 0 ] || fail "a docs change exited $status"
[ ! -s "$work/ran" ] || fail "make ran for a docs change"
[ ! -s "$work/out" ] && [ ! -s "$work/err" ] || fail "a docs change printed something"

# A relevant change while the hook is already continuing: nothing runs, with either spacing of the JSON.
echo '// edit' >> "$work/includes/a.php"
for json in '{"stop_hook_active":true}' '{ "session_id": "x", "stop_hook_active": true }'; do
	hook "$json"
	[ "$status" -eq 0 ] || fail "stop_hook_active exited $status"
	[ ! -s "$work/ran" ] || fail "make ran with stop_hook_active"
done

# A relevant change and a failing step: exit 2, the preface, the failure and the fix on stderr.
rm -rf "$work/build"
STUB_FAIL=check-translations hook "$idle"
[ "$status" -eq 2 ] || fail "a failing step exited $status"
grep -q '^The quick checks failed on the changed plugin code\. Fix this before finishing' "$work/err" || fail "no preface"
grep -q '^FAIL  check-translations' "$work/err" || fail "no FAIL line"
grep -q '^fix: .*make translations' "$work/err" || fail "no fix line naming make translations"
[ "$(tr '\n' ' ' < "$work/ran")" = 'lint check-hooks check-public-api check-translations ' ] || fail "wrong steps: $(cat "$work/ran")"

# The same change, everything passes: exit 0. Run again unchanged: the passed steps are not run again.
: > "$work/ran"
hook "$idle"
[ "$status" -eq 0 ] || fail "a passing run exited $status"
[ "$(cat "$work/ran")" = check-translations ] || fail "only the step that failed should rerun: $(cat "$work/ran")"
: > "$work/ran"
hook "$idle"
[ "$status" -eq 0 ] || fail "an unchanged run exited $status"
[ ! -s "$work/ran" ] || fail "an unchanged tree ran: $(cat "$work/ran")"
[ "$(grep -c 'unchanged$' "$work/out")" -eq 4 ] || fail "expected 4 unchanged lines: $(cat "$work/out")"

# Docker is not running: exit 0 with a note, no make.
: > "$work/ran"
rm -rf "$work/build"
STUB_DOCKER_CODE=1 hook "$idle"
[ "$status" -eq 0 ] || fail "no Docker exited $status"
[ ! -s "$work/ran" ] || fail "make ran without Docker"
grep -q 'Docker is not running' "$work/out" || fail "no Docker note"

# No vendor/autoload.php: exit 0 with a note, no make.
mv "$work/vendor/autoload.php" "$work/vendor/autoload.off"
hook "$idle"
[ "$status" -eq 0 ] || fail "no vendor exited $status"
[ ! -s "$work/ran" ] || fail "make ran without vendor/"
grep -q 'run make deps first' "$work/out" || fail "no vendor note"

echo "ok: agent-stop-check.sh"
