#!/bin/sh
# Mutation testing of some files only, and only the surviving mutants printed. Runs inside the wp-tests-coverage
# container (make mutation-changed). Usage: sh mutation-changed.sh THREADS FILE[,FILE...]
#
# The whole-project floors of infection.json5 do not apply to a few files, so they are set to 0 and the run does not fail
# on a low score: the survivors are information. It fails only when Infection itself fails (a broken test suite, say),
# and then its output is shown. The logs are written to /infection as for docker/mutation.sh.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
threads="$1"
files="$2"
out="$(mktemp)"

# The files are positional arguments (the --filter option is deprecated). Paths hold no spaces or glob characters.
set -f
IFS=,
# shellcheck disable=SC2086 -- split on commas on purpose.
set -- $files
unset IFS

status=0
sh "$here/mutation.sh" --threads="$threads" --min-msi=0 --min-covered-msi=0 "$@" > "$out" 2>&1 || status=$?
if [ "$status" -ne 0 ]; then
	cat "$out"
	echo "Infection failed (exit $status)." >&2
	exit "$status"
fi

echo
sed -n '/mutations were generated/,/Covered Code MSI/p' "$out"

# The text log has a section for each kind of mutant, with a diff for each mutant. Keep the two that matter, and only
# when they have a mutant in them.
echo
echo "Surviving mutants (escaped: a test ran and passed; not covered: no test runs the line):"
survivors="$(awk '
	function flush() { if (head != "" && body != "") { printf "%s%s", head, body } head = ""; body = "" }
	/^[A-Za-z][A-Za-z ]* mutants:$/ { flush(); keep = ($0 ~ /^(Escaped|Not Covered) mutants:$/); if (keep) { head = $0 "\n" } next }
	keep && /^=+$/ { head = head $0 "\n"; next }
	keep { if ($0 !~ /^$/ || body != "") { body = body $0 "\n" } }
	END { flush() }
' /infection/infection.log)"
if [ -z "$survivors" ]; then
	echo "None."
else
	echo "$survivors"
fi
