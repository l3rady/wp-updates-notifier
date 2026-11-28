#!/bin/sh
# Tests what CI prints on a failure: bin/checkstyle-annotations.php (PHPCS findings as GitHub annotations) on a report
# PHPCS wrote, and bin/phpunit-summary.sh (the failed tests for the job summary) on a log PHPUnit wrote. Run by
# `make test-ci-annotations`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
fixtures="$here/tests/bin/ci-annotations"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
cd "$here"

# ----- Annotations -----

# The report has absolute paths: the fixture's @ROOT@ is the repository root, as in the container (/app).
sed "s#@ROOT@#$here#" "$fixtures/phpcs-checkstyle.xml" > "$work/report.xml"
php bin/checkstyle-annotations.php "$work/report.xml" > "$work/out"

[ "$(wc -l < "$work/out")" = 13 ] || fail "expected 13 annotations, got $(wc -l < "$work/out")"
grep -qxF '::error file=includes/zz-temp-broken.php,line=2,col=22,title=Generic.Formatting.DisallowMultipleStatements.SameLine::Each PHP statement must be on a line by itself' "$work/out" || fail "an error annotation is wrong: $(cat "$work/out")"
grep -qxF '::warning file=includes/zz-temp-broken.php,line=2,col=12,title=WordPress.Security.NonceVerification.Recommended::Processing form data without nonce verification.' "$work/out" || fail "a warning annotation is wrong"
! grep -q "$here" "$work/out" || fail "an annotation holds an absolute path"
! grep -qv '^::\(error\|warning\) file=' "$work/out" || fail "a line is not an annotation"
echo "ok: annotations"

# A message cannot inject a command: the percent sign, line breaks, colons and commas are escaped.
cat > "$work/evil.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<checkstyle version="3.13.6">
<file name="/elsewhere/a,b:c.php">
 <error line="3" column="4" severity="error" message="100% bad&#13;&#10;::error file=x::injected" source="A.B,C:D"/>
</file>
</checkstyle>
XML
php bin/checkstyle-annotations.php "$work/evil.xml" > "$work/out"
[ "$(wc -l < "$work/out")" = 1 ] || fail "a message started a second command: $(cat "$work/out")"
[ "$(cat "$work/out")" = '::error file=/elsewhere/a%2Cb%3Ac.php,line=3,col=4,title=A.B%2CC%3AD::100%25 bad%0D%0A::error file=x::injected' ] || fail "escaping is wrong: $(cat "$work/out")"
echo "ok: escaping"

# A report that does not exist or is not XML prints nothing and does not fail.
php bin/checkstyle-annotations.php "$work/none.xml" > "$work/out"
echo 'not xml' > "$work/bad.xml"
php bin/checkstyle-annotations.php "$work/bad.xml" > "$work/out" 2> /dev/null
[ ! -s "$work/out" ] || fail "a bad report printed something"
echo "ok: missing and bad reports"

# ----- The PHPUnit summary -----

env -u PHP_VERSION -u WP_VERSION -u WP_MULTISITE sh bin/phpunit-summary.sh "$fixtures/phpunit-failed.log" > "$work/out"
grep -qxF '### Failed tests' "$work/out" || fail "no title: $(cat "$work/out")"
grep -qxF -- '- `Test_Zz_Temp::test_errors` (error): `RuntimeException: Boom: it broke`' "$work/out" || fail "the error is not listed: $(cat "$work/out")"
grep -qxF -- '- `Test_Zz_Temp::test_fails` (failure): `Failed asserting that two strings are identical.`' "$work/out" || fail "the failure is not listed"
[ "$(grep -c '^- ' "$work/out")" = 2 ] || fail "expected two tests: $(cat "$work/out")"

PHP_VERSION=7.4 WP_VERSION=6.7.9 WP_MULTISITE=1 sh bin/phpunit-summary.sh "$fixtures/phpunit-failed.log" | grep -qxF '### Failed tests (PHP 7.4, WordPress 6.7.9, multisite)' || fail "the title has no versions"

# A backtick in a test name (a data set) does not end the code span.
printf 'There was 1 failure:\n\n1) A::b with data set "x`y"\nmessage `here`\n\n/f.php:1\n\nFAILURES!\nTests: 1, Failures: 1.\n' > "$work/log"
sh bin/phpunit-summary.sh "$work/log" | grep -qxF -- "- \`A::b with data set \"x'y\"\` (failure): \`message 'here'\`" || fail "a backtick broke the code span"

# A log without a failed test, such as a crash before PHPUnit runs, says so and shows the tail.
printf 'PHP Fatal error: it crashed\n' > "$work/log"
sh bin/phpunit-summary.sh "$work/log" > "$work/out"
grep -q 'No failed test is listed' "$work/out" && grep -q 'it crashed' "$work/out" || fail "a log without tests: $(cat "$work/out")"
echo "ok: the PHPUnit summary"
echo "OK"
