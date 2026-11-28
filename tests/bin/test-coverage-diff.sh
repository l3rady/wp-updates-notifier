#!/bin/sh
# Tests bin/coverage-diff.php with a small Clover report, diffs and sources: it lists exactly the changed lines no test
# ran, and leaves out covered lines, comments, a lone continue, and lines with an ignore marker that has a reason.
# Run by docker/coverage.sh (make coverage) and make test-coverage-diff; needs only sh and php.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/src/includes"

cat > "$work/src/includes/a.php" <<'PHP'
<?php
// Comment.
$a = 1;
if ( $a ) {
	$b = 2;
} else {
	$c = 3;
	foreach ( $x as $y ) {
		continue;
	}
	$d = 4; // coverage-ignore -- cannot be reached in a test
	$e = 5; // coverage-ignore
	$f = 6;
}
PHP

cat > "$work/clover.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1"><project timestamp="1">
<file name="/x/wp-updates-notifier/includes/a.php">
<line num="3" type="stmt" count="5"/>
<line num="4" type="stmt" count="5"/>
<line num="5" type="stmt" count="5"/>
<line num="7" type="stmt" count="0"/>
<line num="8" type="stmt" count="0"/>
<line num="9" type="stmt" count="0"/>
<line num="11" type="stmt" count="0"/>
<line num="12" type="stmt" count="0"/>
<line num="13" type="method" name="f" count="0"/>
</file>
<metrics statements="1" coveredstatements="1"/>
</project></coverage>
XML

run() { php "$here/bin/coverage-diff.php" "$work/clover.xml" "$work/changes.diff" "$work/src" > "$work/out" 2> "$work/err"; }
status() { run && echo 0 || echo $?; }

: > "$work/changes.diff"
[ "$(status)" = 0 ] || fail "an empty diff should pass"
grep -q 'No uncovered changed lines' "$work/out" || fail "the pass message is not printed"
echo "ok: empty diff"

cat > "$work/changes.diff" <<'DIFF'
diff --git a/includes/a.php b/includes/a.php
index 111..222 100644
--- a/includes/a.php
+++ b/includes/a.php
@@ -1,0 +2 @@
+// Comment.
@@ -3,0 +4,2 @@
+if ( $a ) {
+	$b = 2;
@@ -6,0 +7,3 @@
+	$c = 3;
+	foreach ( $x as $y ) {
+		continue;
@@ -9,0 +11,3 @@
+	$d = 4; // coverage-ignore -- cannot be reached in a test
+	$e = 5; // coverage-ignore
+	$f = 6;
\ No newline at end of file
diff --git a/includes/gone.php b/includes/gone.php
deleted file mode 100644
--- a/includes/gone.php
+++ /dev/null
@@ -1,2 +0,0 @@
-<?php
-$x = 1;
diff --git a/README.md b/README.md
--- a/README.md
+++ b/README.md
@@ -1,0 +2 @@
+Not PHP.
diff --git a/docs/x.php b/docs/x.php
--- a/docs/x.php
+++ b/docs/x.php
@@ -1,0 +2 @@
+$outside = 1;
DIFF
[ "$(status)" = 1 ] || fail "uncovered changed lines should exit 1"
grep -q '^  includes/a.php:7: no test runs this line' "$work/out" || fail "line 7 is not listed"
grep -q '^  includes/a.php:8: no test runs this line' "$work/out" || fail "line 8 is not listed"
grep -q '^  includes/a.php:12: coverage-ignore needs a reason' "$work/out" || fail "a marker without a reason is not listed"
grep -q '^  includes/a.php:13: no test runs this line' "$work/out" || fail "line 13 is not listed"
[ "$(grep -c '^  [a-z]' "$work/out")" = 4 ] || fail "exactly four findings were expected: $(cat "$work/out")"
echo "ok: listed lines"

# A renamed file with a change, and a new file the report does not know.
cat > "$work/changes.diff" <<'DIFF'
diff --git a/includes/old.php b/includes/new.php
similarity index 90%
rename from includes/old.php
rename to includes/new.php
--- a/includes/old.php
+++ b/includes/new.php
@@ -1,0 +2 @@
+$a = 1;
DIFF
[ "$(status)" = 1 ] || fail "a file missing from the report should exit 1"
grep -q 'includes/new.php is not in the coverage report' "$work/out" || fail "a file missing from the report is not listed"

# Only covered lines changed.
cat > "$work/changes.diff" <<'DIFF'
--- a/includes/a.php
+++ b/includes/a.php
@@ -3,0 +4,2 @@
+if ( $a ) {
+	$b = 2;
DIFF
[ "$(status)" = 0 ] || fail "covered lines should pass"
echo "ok: files and covered lines"

echo 'not xml' > "$work/bad.xml"
if php "$here/bin/coverage-diff.php" "$work/bad.xml" "$work/changes.diff" "$work/src" > /dev/null 2>&1; then fail "a broken report should fail"; else [ $? -eq 2 ] || fail "a broken report should exit 2"; fi
if php "$here/bin/coverage-diff.php" "$work/clover.xml" "$work/missing.diff" > /dev/null 2>&1; then fail "a missing diff should fail"; else [ $? -eq 2 ] || fail "a missing diff should exit 2"; fi
if php "$here/bin/coverage-diff.php" "$work/missing.xml" "$work/changes.diff" > /dev/null 2>&1; then fail "a missing report should fail"; else [ $? -eq 2 ] || fail "a missing report should exit 2"; fi
echo "ok: bad input"

echo "OK"
