#!/bin/sh
# Keep the bundled translations (languages/*.l10n.php) in step with the strings in the code.
# Runs in the php container (`make translations-edit`, `make translations-todo`, `make translations`,
# `make check-translations`), which has WP-CLI and GNU gettext. It needs no database and no site.
#
# The `.l10n.php` files are the only translation files in the repository. Gettext files (the template, and one PO file
# per locale to edit) are made when they are needed and live in the git-ignored build/translations/.
#
#   sh bin/translations.sh edit  [LOCALE]   write build/translations/<locale>.po from the .l10n.php and the code
#   sh bin/translations.sh todo  [LOCALE]   print the untranslated and fuzzy entries, with a count per locale
#   sh bin/translations.sh build [LOCALE]   build languages/*.l10n.php from the PO files
#   sh bin/translations.sh check [LOCALE]   fail unless every file is complete, valid and current
#
# The translations themselves are written by hand (in a Claude Code session), never by this script: it only exports,
# merges, lists, checks and builds. See docs/developers/translations.md.
#
# Which PO file `todo` and `build` read: build/translations/<locale>.po when it is newer than the locale's .l10n.php
# (a session is editing it), otherwise a fresh export of the .l10n.php. So a leftover edited file can never overwrite
# newer committed translations. `edit` always overwrites it. `build` leaves it in place, so a session can build again.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
dir="$root/languages"
edits="$root/build/translations"
slug="wp-updates-notifier"
# The one list of what is not scanned for strings.
exclude="tests,docker,examples,bin,vendor,node_modules,build,docs,site,.semgrep,languages"

# The one list of the bundled locales and their plural forms (the values GlotPress / translate.wordpress.org use).
# Format: locale|Plural-Forms. Adding a locale is adding a line here, then `make translations-edit`.
locales='de_DE|nplurals=2; plural=n != 1;
fr_FR|nplurals=2; plural=n > 1;
es_ES|nplurals=2; plural=n != 1;
it_IT|nplurals=2; plural=n != 1;
nl_NL|nplurals=2; plural=n != 1;
pt_BR|nplurals=2; plural=n > 1;
ja|nplurals=1; plural=0;
ru_RU|nplurals=3; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);
pl_PL|nplurals=3; plural=(n == 1) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);
zh_CN|nplurals=1; plural=0;
he_IL|nplurals=2; plural=n != 1;'

HOME="${TMPDIR:-/tmp}"
export HOME

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

action="${1:-}"
only="${2:-${LOCALE:-}}"
pot="$work/$slug.pot"

fail() {
	echo "$*" >&2
	exit 1
}

# The locales to work on: all of them, or the one asked for.
selected() {
	if [ -z "$only" ]; then
		echo "$locales" | cut -d'|' -f1
		return
	fi
	echo "$locales" | cut -d'|' -f1 | grep -x -- "$only" || fail "Unknown locale '$only'. The bundled locales are: $(echo "$locales" | cut -d'|' -f1 | tr '\n' ' ')(list: bin/translations.sh)."
}

plural_forms() {
	echo "$locales" | grep "^$1|" | cut -d'|' -f2-
}

# Everything after the header block (up to the first blank line) of a PO or POT file.
body() {
	awk 'seen { print } /^$/ && !seen { seen = 1 }' "$1"
}

# The header of a locale's PO file. Written whole on every run, so it never drifts and holds no date: the built files
# stay the same when nothing is translated.
header() {
	cat <<HEADER
# This file is distributed under the GPL-2.0-or-later.
msgid ""
msgstr ""
"Project-Id-Version: WP Updates Notifier\\n"
"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/wp-updates-notifier\\n"
"Language-Team: $1\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"POT-Creation-Date: 2026-01-01 00:00+0000\\n"
"PO-Revision-Date: 2026-01-01 00:00+0000\\n"
"Language: $1\\n"
"Plural-Forms: $(plural_forms "$1")\\n"
"X-Domain: $slug\\n"

HEADER
}

# Write the template from the code into $pot. A `Warning:` from make-pot (for example a translators comment that
# differs between two uses of one string) is an error.
make_pot() {
	wp i18n make-pot "$root" "$pot" --slug="$slug" --domain="$slug" --exclude="$exclude" > "$work/make-pot.log" 2>&1 || {
		cat "$work/make-pot.log" >&2
		fail "wp i18n make-pot failed."
	}
	if grep -q 'Warning:' "$work/make-pot.log"; then
		cat "$work/make-pot.log" >&2
		fail "make-pot printed the warning above: fix the string."
	fi
	# One line per string, for the exporter.
	msgcat --no-wrap -o "$work/flat.pot" "$pot"
}

# Export a locale's .l10n.php to a PO file ($2), header only when the locale has no file yet. The template tells the
# exporter which entries are plural, and their plural original.
export_po() {
	if [ -f "$dir/$slug-$1.l10n.php" ]; then
		php "$root/bin/l10n-to-po.php" "$dir/$slug-$1.l10n.php" "$work/flat.pot" > "$2"
	else
		header "$1" > "$2"
	fi
}

# Merge a PO file ($2) with the template into $3, keeping obsolete entries as `#~` so `check` can see them. New strings
# come in empty, and a changed string is never guessed from a similar one (that would be a fuzzy translation to
# verify). Locations are left out, so moving code changes nothing.
merge() {
	msgmerge --quiet --no-fuzzy-matching --no-location --no-wrap "$1" "$pot" -o "$2"
}

# The merged PO file of a locale, with the fixed header and without obsolete entries: $1 locale, $2 PO file in, $3 out.
clean() {
	msgattrib --no-obsolete --no-location --no-wrap "$2" > "$work/clean.po"
	{ header "$1"; body "$work/clean.po"; } > "$3"
}

# The PO file `todo` and `build` start from (see the top): $1 locale, $2 where to copy it.
working_po() {
	if [ -f "$edits/$1.po" ] && { [ ! -f "$dir/$slug-$1.l10n.php" ] || [ "$edits/$1.po" -nt "$dir/$slug-$1.l10n.php" ]; }; then
		cp -f "$edits/$1.po" "$2"
	else
		export_po "$1" "$2"
	fi
}

# Build the .l10n.php of one PO file into $3: $1 PO file, $2 locale.
compile() {
	rm -rf "$work/compile"
	mkdir "$work/compile"
	cp -f "$1" "$work/compile/$slug-$2.po"
	wp i18n make-php "$work/compile/$slug-$2.po" "$work/compile" >/dev/null
	mv -f "$work/compile/$slug-$2.l10n.php" "$3/"
}

# Count the entries of a PO file that msgattrib selects (the header is not counted).
count() {
	msgattrib "$@" | awk '/^msgid "/ && !/^msgid ""$/ { n++ } /^msgid ""$/ { getline nxt; if (nxt !~ /^msgstr/) n++ } END { print n + 0 }'
}

edit() {
	make_pot
	mkdir -p "$edits"
	for locale in $(selected); do
		export_po "$locale" "$work/export.po"
		merge "$work/export.po" "$work/merged.po"
		clean "$locale" "$work/merged.po" "$edits/$locale.po"
		echo "build/translations/$locale.po: $(count --untranslated "$edits/$locale.po") untranslated, $(count --only-fuzzy "$edits/$locale.po") fuzzy"
	done
}

todo() {
	make_pot
	for locale in $(selected); do
		working_po "$locale" "$work/in.po"
		merge "$work/in.po" "$work/merged.po"
		clean "$locale" "$work/merged.po" "$work/todo-all.po"
		untranslated="$(count --untranslated "$work/todo-all.po")"
		fuzzy="$(count --only-fuzzy "$work/todo-all.po")"
		echo "# ===== $locale: $untranslated untranslated, $fuzzy fuzzy ($(plural_forms "$locale"))"
		if [ "$untranslated" -gt 0 ]; then
			echo "# ----- untranslated"
			msgattrib --untranslated --no-location --no-wrap "$work/todo-all.po" > "$work/todo.po"
			body "$work/todo.po"
		fi
		if [ "$fuzzy" -gt 0 ]; then
			echo "# ----- fuzzy (translate again, then remove the fuzzy flag)"
			msgattrib --only-fuzzy --no-location --no-wrap "$work/todo-all.po" > "$work/todo.po"
			body "$work/todo.po"
		fi
	done
}

build() {
	make_pot
	for locale in $(selected); do
		working_po "$locale" "$work/in.po"
		merge "$work/in.po" "$work/merged.po"
		clean "$locale" "$work/merged.po" "$work/final.po"
		msgfmt -c --check-format -o /dev/null "$work/final.po" || fail "$locale: msgfmt rejects the PO file (placeholders, plural forms or syntax). Nothing was built for it."
		compile "$work/final.po" "$locale" "$dir"
		echo "$locale: $(count --untranslated "$work/final.po") untranslated, $(count --only-fuzzy "$work/final.po") fuzzy"
	done
}

check() {
	problems=0
	problem() {
		echo "$1: $2" >&2
		problems=$((problems + 1))
	}

	make_pot

	# Files that should not be there.
	if [ -z "$only" ]; then
		expected=""
		for locale in $(selected); do
			expected="$expected $slug-$locale.l10n.php"
		done
		for file in "$dir"/* "$dir"/.[!.]*; do
			[ -e "$file" ] || continue
			case " $expected " in
				*" $(basename "$file") "*) ;;
				*) problem "languages/$(basename "$file")" "unexpected file. Only the .l10n.php files of the bundled locales belong in languages/ (list: bin/translations.sh). Gettext files are made in build/translations/ by \`make translations-edit\`." ;;
			esac
		done
	fi

	for locale in $(selected); do
		name="languages/$slug-$locale.l10n.php"
		if [ ! -f "$dir/$slug-$locale.l10n.php" ]; then
			problem "$name" "is missing: run \`make translations-edit LOCALE=$locale\`, translate it, then \`make translations LOCALE=$locale\`."
			continue
		fi

		export_po "$locale" "$work/export.po"
		grep -qxF "\"Plural-Forms: $(plural_forms "$locale")\\n\"" "$work/export.po" || problem "$name" "Plural-Forms is not the one in bin/translations.sh: run \`make translations LOCALE=$locale\`."
		grep -qxF "\"Language: $locale\\n\"" "$work/export.po" || problem "$name" "Language is not $locale: run \`make translations LOCALE=$locale\`."

		merge "$work/export.po" "$work/merged.po"
		clean "$locale" "$work/merged.po" "$work/final.po"

		msgfmt -c --check-format -o /dev/null "$work/final.po" 2> "$work/msgfmt.log" || problem "$name" "msgfmt rejects it (placeholders, plural forms or syntax):
$(cat "$work/msgfmt.log")"

		untranslated="$(count --untranslated "$work/final.po")"
		fuzzy="$(count --only-fuzzy "$work/final.po")"
		obsolete="$(grep -c '^#~ msgid "[^"]' "$work/merged.po" || true)"
		[ "$untranslated" -eq 0 ] || problem "$name" "$untranslated strings in the code have no translation: run \`make translations-edit LOCALE=$locale\`, translate them (\`make translations-todo LOCALE=$locale\` lists them), then \`make translations LOCALE=$locale\`."
		[ "$fuzzy" -eq 0 ] || problem "$name" "$fuzzy fuzzy entries: run \`make translations-edit LOCALE=$locale\`, fix them and remove the fuzzy flag."
		[ "$obsolete" -eq 0 ] || problem "$name" "$obsolete translations are of strings that are no longer in the code: run \`make translations LOCALE=$locale\`."

		rm -rf "$work/fresh"
		mkdir "$work/fresh"
		compile "$work/final.po" "$locale" "$work/fresh"
		cmp -s "$dir/$slug-$locale.l10n.php" "$work/fresh/$slug-$locale.l10n.php" || problem "$name" "is not what the translations and the code build (edited by hand, or the strings changed): run \`make translations LOCALE=$locale\`."
	done

	if [ "$problems" -gt 0 ]; then
		echo "$problems problem(s) in the bundled translations. \`make translations-edit\` writes PO files to translate in build/translations/; \`make translations\` builds languages/*.l10n.php from them." >&2
		exit 1
	fi
	echo "The bundled translations are complete and current."
}

case "$action" in
	edit) edit ;;
	todo) todo ;;
	build) build ;;
	check) check ;;
	*) fail "Usage: sh bin/translations.sh edit|todo|build|check [LOCALE]" ;;
esac
