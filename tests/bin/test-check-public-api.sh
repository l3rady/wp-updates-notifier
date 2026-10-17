#!/bin/sh
# Tests the public API checker against code that is known to be bad and code that is known to be good,
# so the checker cannot pass the real scan by finding nothing. Run by `make check-public-api`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$here"
fixtures=tests/bin/check-public-api

# The good code passes.
php bin/check-public-api.php "$fixtures/good.txt" > /dev/null || fail "the good fixture was reported"

# The bad code fails, and every expected problem is reported on the line that has it.
report="$(mktemp)"
work="$(mktemp -d)"
trap 'rm -rf "$work" "$report"' EXIT
if php bin/check-public-api.php "$fixtures/bad.txt" 2> "$report" > /dev/null; then
	fail "the bad fixture passed"
fi

# Each "// expect: text" is a grep pattern that the problem reported on that line must match.
expected=0
grep -n '// expect: ' "$fixtures/bad.txt" | sed -E 's/^([0-9]+):.*\/\/ expect: (.*)$/\1 \2/' > "$work/expect"
while IFS= read -r entry; do
	line="${entry%% *}"
	pattern="${entry#* }"
	expected=$((expected + 1))
	grep -q "bad.txt:$line: .*$pattern" "$report" || fail "line $line of the bad fixture: expected a problem matching \"$pattern\""
done < "$work/expect"
[ "$expected" -ge 20 ] || fail "the bad fixture lost its expectations"

# Nothing is reported on a line without an expectation.
while IFS= read -r reported; do
	line="$(echo "$reported" | sed -E 's/^[^:]*:([0-9]+):.*$/\1/')"
	sed -n "${line}p" "$fixtures/bad.txt" | grep -q '// expect: ' || fail "unexpected problem: $reported"
done < "$report"

# A module that breaks the rule fails a real scan.
mkdir -p "$work/module"
printf '<?php\nnamespace Some\\Module;\nsc_wpun()->settings();\n' > "$work/module/module.php"
if php bin/check-public-api.php "$work/module" 2> /dev/null > /dev/null; then
	fail "a module calling sc_wpun() passed"
fi

# Internals are read from the plugin: the checker refuses to run on paths with none.
if WPUN_INTERNAL_PATHS="$fixtures" php bin/check-public-api.php "$fixtures/good.txt" 2> /dev/null > /dev/null; then
	fail "the checker ran without finding any @internal"
fi

echo "ok: public API checker"
