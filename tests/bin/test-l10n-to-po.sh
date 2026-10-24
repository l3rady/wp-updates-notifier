#!/bin/sh
# Tests the .l10n.php to PO exporter against a small known file: context, plural forms and escaping.
# Run by `make check-translations`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$here"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

cat > "$work/x.l10n.php" <<'PHP'
<?php
return ['domain'=>'d','plural-forms'=>'nplurals=3; plural=0;','language'=>'xx','messages'=>[
	'Plain'=>'Einfach',
	"Quote \" back\\ new\nline\ttab"=>"Q \" b\\ n\nl\tt",
	"Menu\4Open"=>'Oeffnen',
	'One item'=>"Ein\0Zwei\0Drei",
	"Menu\4One more"=>"A\0B\0C",
]];
PHP

php bin/l10n-to-po.php "$work/x.l10n.php" > "$work/x.po" || fail "the exporter failed"

for expected in \
	'"Language: xx\n"' \
	'"Plural-Forms: nplurals=3; plural=0;\n"' \
	'msgid "Plain"
msgstr "Einfach"' \
	'msgid "Quote \" back\\ new\nline\ttab"
msgstr "Q \" b\\ n\nl\tt"' \
	'msgctxt "Menu"
msgid "Open"
msgstr "Oeffnen"' \
	'msgid "One item"
msgid_plural "One item"
msgstr[0] "Ein"
msgstr[1] "Zwei"
msgstr[2] "Drei"' \
	'msgctxt "Menu"
msgid "One more"
msgid_plural "One more"
msgstr[0] "A"'; do
	grep -qF -- "$expected" "$work/x.po" || fail "the PO file lacks: $expected"
done

msgfmt -c -o /dev/null "$work/x.po" 2>/dev/null || fail "msgfmt rejects the exported file"

# With the template, the plural original comes from it, and so does the plural-ness of a one-form language.
cat > "$work/x.pot" <<'POT'
msgid ""
msgstr ""

msgid "One item"
msgid_plural "Many items"
msgstr[0] ""
msgstr[1] ""

msgctxt "Menu"
msgid "Open"
msgid_plural "Open all"
msgstr[0] ""
msgstr[1] ""
POT
php bin/l10n-to-po.php "$work/x.l10n.php" "$work/x.pot" > "$work/y.po" || fail "the exporter failed with a template"
grep -qF 'msgid "One item"
msgid_plural "Many items"' "$work/y.po" || fail "the plural original of the template was not used"
grep -qF 'msgctxt "Menu"
msgid "Open"
msgid_plural "Open all"
msgstr[0] "Oeffnen"' "$work/y.po" || fail "a one-form plural with a context was not exported as a plural"
echo "The .l10n.php exporter works."
