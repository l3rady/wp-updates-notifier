#!/bin/sh
# Tests .githooks/commit-msg. Every message the hook accepts must also pass commitlint, so the hook stays a strict
# subset of commitlint.config.js. Run inside the commitlint image by `make test-commit-msg`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# The first line, 72 characters long.
h72="feat: $(printf 'a%.0s' $(seq 1 66))"
[ "${#h72}" -eq 72 ] || fail "the test header is not 72 characters"
nl='
'

# commitlint gets the message as git saves it: without comment lines and without the scissors part.
lint() { printf '%s\n' "$1" | sed -e '/^# -* >8 -*$/,$d' -e '/^#/d' | sed -e '/./,$!d' | commitlint --config "$here/commitlint.config.js" > "$work/lint" 2>&1; }

# accept NAME MESSAGE [lint]: the hook must accept it, and commitlint too unless the third word is "hook-only".
accept() {
	printf '%s\n' "$2" > "$work/msg"
	sh "$here/.githooks/commit-msg" "$work/msg" 2> "$work/err" || fail "$1: the hook refused it: $(cat "$work/err")"
	[ "${3:-}" = hook-only ] || lint "$2" || fail "$1: the hook accepts it but commitlint does not: $(cat "$work/lint")"
}

# refuse NAME MESSAGE REASON: the hook must refuse it with a message containing REASON.
refuse() {
	printf '%s\n' "$2" > "$work/msg"
	if sh "$here/.githooks/commit-msg" "$work/msg" 2> "$work/err"; then fail "$1: the hook accepted it"; fi
	grep -q -- "$3" "$work/err" || fail "$1: the reason does not say '$3': $(cat "$work/err")"
}

accept "plain" "fix(cron): stop the double run"
accept "no scope" "chore: update the lockfile"
accept "72 characters" "$h72"
accept "body" "fix: x${nl}${nl}The body says why."
accept "breaking" "feat(api)!: drop the old filter${nl}${nl}BREAKING CHANGE: the filter is gone."
accept "breaking with hyphen" "feat!: drop it${nl}${nl}BREAKING-CHANGE: gone."
accept "comment lines" "fix: x${nl}# a comment${nl}${nl}# another"
accept "scissors" "fix: x${nl}${nl}# ------------------------ >8 ------------------------${nl}# Do not touch the line above.${nl}diff --git a/a b/a"
accept "leading blank lines" "${nl}${nl}fix: x"
accept "commented-out footer" "fix: x${nl}${nl}# BREAKING CHANGE: nothing"
accept "merge" "Merge branch 'a' into main" hook-only
accept "fixup" "fixup! fix: x" hook-only
accept "squash" "squash! fix: x" hook-only
accept "amend" "amend! fix: x" hook-only
accept "revert" 'Revert "fix: x"' hook-only

refuse "73 characters" "${h72}a" "the first line is 73 characters; the limit is 72"
refuse "unknown type" "feature: add it" "type(scope)!: summary"
refuse "no type" "add it" "type(scope)!: summary"
refuse "upper-case scope" "fix(Cron): x" "type(scope)!: summary"
refuse "no space after colon" "fix:x" "type(scope)!: summary"
refuse "capital summary" "fix: Stop it" "upper-case"
refuse "full stop" "fix: stop it." "full stop"
refuse "empty summary" "fix: " "summary is empty"
refuse "no blank line after the first line" "fix: x${nl}body" "second line"
refuse "! without footer" "feat!: drop it" "needs a 'BREAKING CHANGE:' footer"
refuse "footer without !" "feat: drop it${nl}${nl}BREAKING CHANGE: gone." "needs a '!'"
refuse "footer without blank line" "feat!: drop it${nl}${nl}Body.${nl}BREAKING CHANGE: gone." "after a blank line"
refuse "commented-out footer with !" "feat!: drop it${nl}${nl}# BREAKING CHANGE: gone." "needs a 'BREAKING CHANGE:' footer"
refuse "only a comment" "# fix: x" "type(scope)!: summary"

echo "commit-msg: all tests passed"
