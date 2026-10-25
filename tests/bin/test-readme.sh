#!/bin/sh
# Tests the readme generator (bin/readme.php) and the readme checks (bin/check-headers.sh): the output is
# deterministic and agrees with the plugin header, the changelog and FAQ conversions match their fixtures, input the
# generator cannot render fails, and each rule of check-headers.sh fails on a readme that breaks it.
# Run with `make test-readme`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$here"
fixtures=tests/bin/readme
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

version="$(sed -n 's/^ \* Version: //p' wp-updates-notifier.php | head -n 1)"

# ----- The generated readme -----

php bin/readme.php "$work/one.txt"
TZ=Pacific/Auckland LC_ALL=C LANG=C php bin/readme.php "$work/two.txt"
cmp -s "$work/one.txt" "$work/two.txt" || fail "two runs gave different readmes"
echo "ok: deterministic"

[ "$(sed -n 's/^Stable tag: //p' "$work/one.txt")" = "$version" ] || fail "Stable tag is not the plugin version ($version)"
grep -q '{{' "$work/one.txt" && fail "a placeholder is left in the readme"
grep -q '^== Filters ==' "$work/one.txt" && fail "the Filters section is back"
grep -q '^= Why did Slack notifications stop? =$' "$work/one.txt" || fail "the FAQ was not generated"
grep -q '^= How do I report a security problem? =$' "$work/one.txt" || fail "the readme-only FAQ entries are missing"
[ "$(sed -n '/^== Changelog ==$/,/^== Upgrade Notice ==$/p' "$work/one.txt" | grep -c '^= ')" -le 3 ] || fail "the changelog lists more than 3 releases"
grep -q 'CHANGELOG.md' "$work/one.txt" || fail "no link to the full changelog"
echo "ok: header values, FAQ and changelog in the readme"

# ----- Conversions -----

# convert changelog FILE, or convert faq FILE QUESTIONS-FILE [PAGE]: print the converted text.
convert() {
	php -r '
		require $argv[1] . "/bin/readme.php";
		$text = file_get_contents( $argv[3] );
		try {
			if ( "changelog" === $argv[2] ) {
				echo wpun_readme_changelog( $text ) . "\n";
			} else {
				$q = array_values( array_filter( array_map( "trim", file( $argv[4] ) ) ) );
				echo wpun_readme_faq( $text, $q, "users/guide.md", "https://wpun.cariss.dev/latest/" ) . "\n";
			}
		} catch ( RuntimeException $e ) {
			fwrite( STDERR, $e->getMessage() . "\n" );
			exit( 1 );
		}
	' "$here" "$@"
}

convert changelog "$fixtures/changelog.md" > "$work/changelog.txt"
diff -u "$fixtures/changelog-expected.txt" "$work/changelog.txt" || fail "the changelog conversion differs from the fixture"
grep -q '\[[0-9a-f]\{7\}\]\|closes\|\*\*admin:\*\*' "$work/changelog.txt" && fail "commit links, closes trailers or scopes are left in the changelog"
echo "ok: changelog conversion"

convert faq "$fixtures/faq.md" "$fixtures/faq-questions.txt" > "$work/faq.txt"
diff -u "$fixtures/faq-expected.txt" "$work/faq.txt" || fail "the FAQ conversion differs from the fixture"
echo "ok: FAQ conversion"

# ----- Input that fails -----

expect_failure() { # name, message that must appear, command...
	name="$1"; message="$2"; shift 2
	if "$@" > /dev/null 2> "$work/error"; then fail "$name was accepted"; fi
	grep -q "$message" "$work/error" || fail "$name: expected the message \"$message\", got: $(cat "$work/error")"
}

printf 'No such question\n' > "$work/missing.txt"
expect_failure "a missing heading" 'No such question' convert faq "$fixtures/faq.md" "$work/missing.txt"

printf 'Not listed\n' > "$work/html.txt"
expect_failure "HTML in an answer" 'cannot show' convert faq "$fixtures/faq.md" "$work/html.txt"

printf '# T\n\n## Q\n\n> A quote\n' > "$work/quote.md"
printf 'Q\n' > "$work/q.txt"
expect_failure "a blockquote" 'cannot show' convert faq "$work/quote.md" "$work/q.txt"
printf '# T\n\n## Q\n\n![alt](a.png)\n' > "$work/image.md"
expect_failure "an image" 'cannot show' convert faq "$work/image.md" "$work/q.txt"
printf '# T\n\n## Q\n\n[file](a.pdf)\n' > "$work/file.md"
expect_failure "a link to a file that is not a page" 'not to a Markdown page' convert faq "$work/file.md" "$work/q.txt"
printf '# T\n\n## Q\n\n- one\n    - nested\n' > "$work/nested.md"
expect_failure "a nested list" 'nested list' convert faq "$work/nested.md" "$work/q.txt"
printf '## 2.0.0\n\nA stray line.\n' > "$work/stray.md"
expect_failure "a changelog line it cannot convert" 'cannot convert' convert changelog "$work/stray.md"

# Whole generator: a missing FAQ heading is an error with a non-zero exit, and no readme is written.
copy="$work/copy"
mkdir -p "$copy/bin" "$copy/includes/admin" "$copy/docs/users" "$copy/.wordpress-org/readme"
cp bin/readme.php "$copy/bin/"
cp wp-updates-notifier.php CHANGELOG.md "$copy/"
cp includes/class-plugin.php "$copy/includes/"
cp includes/admin/class-about-tab.php "$copy/includes/admin/"
cp docs/users/troubleshooting.md "$copy/docs/users/"
cp .wordpress-org/readme/template.txt "$copy/.wordpress-org/readme/"
cp .wordpress-org/readme/faq.txt "$copy/.wordpress-org/readme/"
php "$copy/bin/readme.php" "$copy/ok.txt" || fail "the copy of the sources did not generate"
echo 'A question that is not there' >> "$copy/.wordpress-org/readme/faq.txt"
expect_failure "a FAQ heading that is not in troubleshooting.md" 'A question that is not there' php "$copy/bin/readme.php" "$copy/out.txt"
[ ! -e "$copy/out.txt" ] || fail "a readme was written although the generator failed"
cp .wordpress-org/readme/faq.txt "$copy/.wordpress-org/readme/"
echo '{{unknown}}' >> "$copy/.wordpress-org/readme/template.txt"
expect_failure "a placeholder nothing fills in" 'unknown' php "$copy/bin/readme.php" "$copy/out.txt"
echo "ok: input that cannot be converted fails"

# ----- check-headers.sh -----

sh bin/check-headers.sh "$work/one.txt" > /dev/null || fail "check-headers.sh rejected the generated readme"

# check_breaks NAME MESSAGE SED-SCRIPT: the generated readme with the edit applied must fail with that message.
check_breaks() {
	sed "$3" "$work/one.txt" > "$work/broken.txt"
	cmp -s "$work/one.txt" "$work/broken.txt" && fail "$1: the edit changed nothing"
	expect_failure "$1" "$2" sh bin/check-headers.sh "$work/broken.txt"
}

check_breaks "a wrong title" 'must start with' '1s/.*/=== Another plugin ===/'
check_breaks "a stable tag that is not the version" 'Stable tag' 's/^Stable tag: .*/Stable tag: 0.0.1/'
check_breaks "other requirements" 'Requires PHP' 's/^Requires PHP: .*/Requires PHP: 5.2/'
check_breaks "another licence" 'License' 's/^License: .*/License: MIT/'
check_breaks "no Tested up to" 'Tested up to' '/^Tested up to:/d'
check_breaks "6 tags" 'allows 5' 's/^Tags: .*/Tags: a, b, c, d, e, f/'
check_breaks "a long short description" 'allows 150' "s/^Get an email when .*/$(printf 'x%.0s' $(seq 1 151))/"
check_breaks "an unknown section" "unknown section" 's/^== Screenshots ==$/== Pictures ==/'
check_breaks "a long upgrade notice" 'Upgrade Notice entries over 300' "/^From 1.5 or 1.6:/s/\$/ $(printf 'word %.0s' $(seq 1 70))/"
check_breaks "a placeholder left over" 'placeholder' 's/^Contributors: .*/Contributors: {{who}}/'
echo "ok: check-headers.sh rules"

echo "OK"
