#!/bin/sh
# The plugin header and the generated readme.txt must agree, and readme.txt must follow the WordPress.org rules
# Plugin Check enforces. Run with `make check-headers`.
#
# readme.txt is not committed, so this generates it (bin/readme.php) into a temporary file first. With a path as the
# argument it checks that file instead; the readme tests use this to try a broken one.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

root="$(cd "$(dirname "$0")/.." && pwd)"
main="$root/wp-updates-notifier.php"

if [ "$#" -ge 1 ]; then
	readme="$1"
else
	tmp="$(mktemp -d)"
	trap 'rm -rf "$tmp"' EXIT
	readme="$tmp/readme.txt"
	php "$root/bin/readme.php" "$readme" || fail "readme.txt could not be generated"
fi

header() { sed -n "s/^ \* $1: *//p" "$main" | head -n 1; }
field() { sed -n "s/^$1: *//p" "$readme" | head -n 1; }

name="$(header 'Plugin Name')"
[ "$(head -n 1 "$readme")" = "=== $name ===" ] || fail "readme.txt must start with '=== $name ==='"

const="$(sed -n "s/^defined( 'SC_WPUN_VERSION' ) || define( 'SC_WPUN_VERSION', '\\([^']*\\)' );.*/\\1/p" "$main")"
[ "$const" = "$(header 'Version')" ] || fail "SC_WPUN_VERSION ($const) differs from Version ($(header 'Version'))"
[ "$(field 'Stable tag')" = "$(header 'Version')" ] || fail "Stable tag ($(field 'Stable tag')) differs from Version ($(header 'Version'))"
[ "$(field 'Requires at least')" = "$(header 'Requires at least')" ] || fail "Requires at least differs between the header and readme.txt"
[ "$(field 'Requires PHP')" = "$(header 'Requires PHP')" ] || fail "Requires PHP differs between the header and readme.txt"
[ "$(field 'License')" = "$(header 'License')" ] || fail "License differs between the header and readme.txt"
[ "$(field 'License URI')" = "$(header 'License URI')" ] || fail "License URI differs between the header and readme.txt"
[ -n "$(field 'Tested up to')" ] || fail "readme.txt has no Tested up to"
echo "ok: header and readme.txt agree (version $(header 'Version'))"

tags="$(field 'Tags' | tr ',' '\n' | sed 's/^ *//; s/ *$//' | grep -c .)"
[ "$tags" -le 5 ] || fail "readme.txt has $tags tags; WordPress.org allows 5"

# The short description is the first non-empty line after the header block.
short="$(awk 'NR > 1 && /^$/ { blank = 1; next } blank && NF { print; exit }' "$readme")"
[ -n "$short" ] || fail "readme.txt has no short description"
[ "${#short}" -le 150 ] || fail "short description is ${#short} characters; WordPress.org allows 150"
echo "ok: $tags tags, short description ${#short} characters"

# Only the sections WordPress.org knows.
sections="$(sed -n 's/^== \(.*\) ==$/\1/p' "$readme")"
echo "$sections" | grep -qx 'Description' || fail "readme.txt has no Description section"
echo "$sections" | while IFS= read -r section; do
	case "$section" in
		Description|Installation|'Frequently Asked Questions'|Screenshots|Changelog|'Upgrade Notice') ;;
		*) echo "FAIL: readme.txt has an unknown section '== $section =='" >&2; exit 1 ;;
	esac
done || exit 1

# Each Upgrade Notice entry is at most 300 characters.
long="$(awk '
	/^== / { in_notice = ($0 == "== Upgrade Notice ==") ; next }
	!in_notice { next }
	/^= / { if (version != "" && length(text) > 300) print version " (" length(text) ")"; version = $0; text = ""; next }
	NF { text = text (text == "" ? "" : " ") $0 }
	END { if (version != "" && length(text) > 300) print version " (" length(text) ")" }
' "$readme")"
[ -z "$long" ] || fail "Upgrade Notice entries over 300 characters: $long"
echo "ok: sections and upgrade notices"

if grep -q '{{' "$readme"; then fail "readme.txt has a placeholder that was not replaced"; fi

echo "OK"
