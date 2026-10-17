#!/bin/sh
# The plugin header and readme.txt must agree, and readme.txt must follow the
# WordPress.org rules Plugin Check enforces. Run with `make check-headers`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

root="$(cd "$(dirname "$0")/.." && pwd)"
main="$root/wp-updates-notifier.php"
readme="$root/readme.txt"

header() { sed -n "s/^ \* $1: *//p" "$main" | head -n 1; }
field() { sed -n "s/^$1: *//p" "$readme" | head -n 1; }

name="$(header 'Plugin Name')"
[ "$(head -n 1 "$readme")" = "=== $name ===" ] || fail "readme.txt must start with '=== $name ==='"

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

echo "OK"
