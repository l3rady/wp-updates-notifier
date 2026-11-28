#!/bin/sh
# Writes the tests a PHPUnit log lists as failed or errored, as Markdown for the job summary (CI appends it to
# $GITHUB_STEP_SUMMARY when a test step fails):
#
#   sh bin/phpunit-summary.sh build/phpunit.log >> "$GITHUB_STEP_SUMMARY"
#
# It reads PHPUnit's "There was 1 failure:" and "There were 2 errors:" sections: each "N) Class::test" line, and the
# first line of its message. The title carries the PHP and WordPress versions and the multisite flag when PHP_VERSION,
# WP_VERSION and WP_MULTISITE are set (the jobs set them), and the random seed when the log has one. Runs on the
# runner; needs only sh and awk. Tested by tests/bin/test-ci-annotations.sh.
set -eu

[ "$#" -eq 1 ] && [ -f "$1" ] || { echo 'usage: sh bin/phpunit-summary.sh <phpunit log>' >&2; exit 2; }

title='Failed tests'
details=
[ -z "${PHP_VERSION:-}" ] || details="PHP $PHP_VERSION"
[ -z "${WP_VERSION:-}" ] || details="${details:+$details, }WordPress $WP_VERSION"
[ "${WP_MULTISITE:-}" != 1 ] || details="${details:+$details, }multisite"
seed="$(sed -n 's/^Random Seed:[[:space:]]*//p' "$1" | head -n 1)"
[ -z "$seed" ] || details="${details:+$details, }random seed $seed"
[ -z "$details" ] || title="$title ($details)"

printf '### %s\n\n' "$title"

awk '
	# Backticks end a code span: a test name or message must not.
	function code(text) { gsub(/`/, "\047", text); return "`" text "`" }
	/^There (was|were) [0-9]+ (error|failure)s?:$/ { kind = ($4 ~ /^error/) ? "error" : "failure"; next }
	/^There (was|were) [0-9]+ / { kind = ""; next }
	/^(ERRORS!|FAILURES!|OK |OK$|Tests: )/ { kind = ""; next }
	kind != "" && /^[0-9]+\) / {
		name = $0
		sub(/^[0-9]+\) /, "", name)
		want = 1
		next
	}
	want && kind != "" {
		printf "- %s (%s)%s\n", code(name), kind, ($0 == "" ? "" : ": " code($0))
		want = 0
		found++
	}
	END { if (!found) exit 3 }
' "$1" || {
	echo 'No failed test is listed in the log. PHPUnit may not have started, or it stopped early. The last lines of the log:'
	echo
	echo '```'
	tail -n 20 "$1"
	echo '```'
}
