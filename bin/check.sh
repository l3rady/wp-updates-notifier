#!/bin/sh
# Runs the make targets named as arguments, one after the other, with short output: one line per step that passes,
# and for the first one that fails its last lines, the log path and a hint to fix it. The full output of a step is in
# build/check/<step>.log. Used by `make check` and `make check-full`, and meant for hooks that need the fast steps only.
#
#   sh bin/check.sh lint stan
#
# Runs on the host, as make starts Docker. CHECK_MAKE is the make command (the self-test uses a stub). Variables given
# to the outer make (make check PHP_VERSION=7.4) reach the steps through MAKEFLAGS.
set -eu

[ "$#" -gt 0 ] || { echo 'usage: sh bin/check.sh <make-target>...' >&2; exit 2; }

cd "$(dirname "$0")/.."
make_cmd="${CHECK_MAKE:-make}"
logs=build/check
mkdir -p "$logs"

# The hint for a step that has a known fix.
fixer() {
	case "$1" in
		lint) echo 'make fix' ;;
		rector) echo 'make rector-fix' ;;
		stan) echo 'fix the code; phpstan-baseline.neon only shrinks' ;;
		check-hooks) echo 'make hook-docs; a new hook also goes in wpun_hook_sections() in bin/hooks-scan.php' ;;
		check-translations) echo 'the change-string skill: make translations-edit, translate, make translations' ;;
		check-public-api) echo 'use only the public API (docs/developers/stability.md)' ;;
		test-wp) echo 'rerun with: make test-wp ARGS="--filter Test_Class"' ;;
		test-wp-ms) echo 'rerun with: make test-wp-ms ARGS="--filter Test_Class"' ;;
		coverage) echo 'write the missing test; never lower COVERAGE_MIN' ;;
		quality-check) echo 'fix the finding; make quality-baseline only after a real fix shrank a baseline' ;;
		sast) echo 'add the " -- reason" to the ignore, or fix the finding' ;;
		commits) echo 'amend or reword the commit; the whole first line is at most 72 characters' ;;
	esac
}

for step in "$@"; do
	log="$logs/$step.log"
	rm -f "$log"
	start="$(date +%s)"
	status=0
	"$make_cmd" --no-print-directory "$step" > "$log" 2>&1 || status=$?
	secs=$(( $(date +%s) - start ))
	if [ "$status" -eq 0 ]; then
		printf 'ok    %-20s %ss\n' "$step" "$secs"
		continue
	fi
	printf 'FAIL  %s  %ss\n' "$step" "$secs"
	tail -n 60 "$log"
	echo "log: $log"
	hint="$(fixer "$step")"
	[ -z "$hint" ] || echo "fix: $hint"
	exit "$status"
done
