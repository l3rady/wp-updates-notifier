#!/bin/sh
# Runs the make targets named as arguments, one after the other, with short output: one line per step that passes,
# and for the first one that fails its last lines, the log path and a hint to fix it. The full output of a step is in
# build/check/<step>.log. Used by `make check` and `make check-full`, and meant for hooks that need the fast steps only.
#
#   sh bin/check.sh lint stan
#
# Runs on the host, as make starts Docker. CHECK_MAKE is the make command (the self-test uses a stub). Variables given
# to the outer make (make check PHP_VERSION=7.4) reach the steps through MAKEFLAGS.
#
# A step that passes leaves build/check/<step>.ok, holding a key for the tree it passed on. With CHECK_SKIP_PASSED=1 a
# step whose stamp equals the current key is not run again (the Stop hook, bin/agent-stop-check.sh, sets it). make check
# does not set it: it always runs everything, and writes the stamps.
set -eu

[ "$#" -gt 0 ] || { echo 'usage: sh bin/check.sh <make-target>...' >&2; exit 2; }

cd "$(dirname "$0")/.."
make_cmd="${CHECK_MAKE:-make}"
logs=build/check
mkdir -p "$logs"

# The key of the tree: HEAD, the changes to tracked files, the names and contents of untracked files that are not ignored,
# and MAKEFLAGS (PHP_VERSION=7.4 must not reuse a pass of the default version). Empty when git cannot tell (no
# checkout, no HEAD): then no step is skipped and no stamp is written. Computed once, before the first step: the steps
# run here (lint, checks) do not rewrite tracked files; a step that did would be stamped with the key from before.
# The input is gathered in a file of this run's own (mktemp), so two runs in one checkout (the Stop hook and a
# make check) never read each other's.
key_file="$(mktemp "$logs/key.XXXXXX")"
trap 'rm -f "$key_file"' EXIT
tree_key() {
	{
		git rev-parse --verify -q HEAD &&
			git diff HEAD --binary &&
			git ls-files -o --exclude-standard &&
			git ls-files -o --exclude-standard | git hash-object --stdin-paths &&
			printf '%s\n' "${MAKEFLAGS:-}"
	} > "$key_file" 2>/dev/null && git hash-object --stdin < "$key_file" 2>/dev/null || true
}
key="$(tree_key)"
rm -f "$key_file"

# The hint for a step that has a known fix.
fixer() {
	case "$1" in
		lint) echo 'make fix' ;;
		rector) echo 'make rector-fix' ;;
		stan) echo 'fix the code; phpstan-baseline.neon only shrinks' ;;
		check-hooks) echo 'make hook-docs; a new hook also goes in wpun_hook_sections() in bin/hooks-scan.php' ;;
		check-translations) echo 'the change-string skill: make translations-edit, translate, make translations' ;;
		check-public-api) echo 'use only the public API (docs/developers/stability.md)' ;;
		test-wp) echo 'rerun with: make test-wp FILE=tests/wp/test-class.php' ;;
		test-wp-ms) echo 'rerun with: make test-wp-ms FILE=tests/wp/test-class.php' ;;
		coverage) echo 'write the missing test; never lower COVERAGE_MIN' ;;
		quality-check) echo 'fix the finding; make quality-baseline only after a real fix shrank a baseline' ;;
		sast) echo 'add the " -- reason" to the ignore, or fix the finding' ;;
		commits) echo 'amend or reword the commit; the whole first line is at most 72 characters' ;;
	esac
}

for step in "$@"; do
	log="$logs/$step.log"
	stamp="$logs/$step.ok"
	if [ "${CHECK_SKIP_PASSED:-}" = 1 ] && [ -n "$key" ] && [ -f "$stamp" ] && [ "$(cat "$stamp")" = "$key" ]; then
		printf 'ok    %-20s unchanged\n' "$step"
		continue
	fi
	rm -f "$log"
	start="$(date +%s)"
	status=0
	"$make_cmd" --no-print-directory "$step" > "$log" 2>&1 || status=$?
	secs=$(( $(date +%s) - start ))
	if [ "$status" -eq 0 ]; then
		printf 'ok    %-20s %ss\n' "$step" "$secs"
		if [ -n "$key" ]; then echo "$key" > "$stamp"; else rm -f "$stamp"; fi
		continue
	fi
	rm -f "$stamp"
	printf 'FAIL  %s  %ss\n' "$step" "$secs"
	tail -n 60 "$log"
	echo "log: $log"
	hint="$(fixer "$step")"
	[ -z "$hint" ] || echo "fix: $hint"
	exit "$status"
done
