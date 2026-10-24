#!/bin/sh
# Keep the bundled translations (languages/*.po, .mo and .l10n.php) in step with languages/wp-updates-notifier.pot.
# Runs in the php container (`make translations`, `make translations-todo`, `make check-translations`), which has
# WP-CLI and GNU gettext. It needs no database and no site.
#
#   sh bin/translations.sh build [LOCALE]   create or update the .po files from the .pot, then build .mo and .l10n.php
#   sh bin/translations.sh todo  [LOCALE]   print the untranslated and fuzzy entries, with a count per locale
#   sh bin/translations.sh check [LOCALE]   fail unless every file is complete, valid and current
#
# The translations themselves are written by hand (in a Claude Code session), never by this script: it only merges,
# lists, checks and builds. See docs/developers/translations.md.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
dir="$root/languages"
slug="wp-updates-notifier"
pot="$dir/$slug.pot"

# The one list of the bundled locales and their plural forms (the values GlotPress / translate.wordpress.org use).
# Format: locale|Plural-Forms. Adding a locale is adding a line here, then `make translations`.
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

# The marker every file carries until a native speaker has reviewed it. Removing it is a deliberate, reviewed change to
# this script, not to a .po file: `make translations` writes it back.
marker_field='X-Machine-Translation: yes'
marker_comment='Machine translation (AI), not yet reviewed by a native speaker.'

HOME="${TMPDIR:-/tmp}"
export HOME

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

command="${1:-}"
only="${2:-${LOCALE:-}}"

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

# The header of a locale's file. Written whole on every run, so it never drifts and holds no date: the files stay the
# same when nothing is translated.
header() {
	cat <<HEADER
# $marker_comment
# This file is distributed under the GPL-2.0-or-later.
msgid ""
msgstr ""
"Project-Id-Version: WP Updates Notifier\\n"
"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/wp-updates-notifier\\n"
"Last-Translator: Machine translation\\n"
"Language-Team: $1\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"POT-Creation-Date: 2026-01-01 00:00+0000\\n"
"PO-Revision-Date: 2026-01-01 00:00+0000\\n"
"Language: $1\\n"
"Plural-Forms: $(plural_forms "$1")\\n"
"X-Domain: $slug\\n"
"$marker_field\\n"

HEADER
}

# Build the .mo and .l10n.php of one .po file into a directory.
compile() {
	rm -rf "$work/compile"
	mkdir "$work/compile"
	cp "$1" "$work/compile/$slug-$2.po"
	wp i18n make-mo "$work/compile/$slug-$2.po" "$work/compile" >/dev/null
	wp i18n make-php "$work/compile/$slug-$2.po" "$work/compile" >/dev/null
	mv "$work/compile/$slug-$2.mo" "$work/compile/$slug-$2.l10n.php" "$3/"
}

# Count the entries of a PO file that msgattrib selects (the header is not counted).
count() {
	msgattrib "$@" | awk '/^msgid "/ && !/^msgid ""$/ { n++ } /^msgid ""$/ { getline nxt; if (nxt !~ /^msgstr/) n++ } END { print n + 0 }'
}

# One line per entry: context, id and plural id. Headers, locations, comments, flags and translations are left out.
keys() {
	msgcat --no-wrap --no-location "$1" | awk '
		function flush() { if (have) print ctx "\t" id "\t" plural; have = 0; ctx = ""; id = ""; plural = "" }
		/^msgctxt / { flush(); ctx = $0; sub(/^msgctxt /, "", ctx); have = 1; next }
		/^msgid_plural / { plural = $0; sub(/^msgid_plural /, "", plural); next }
		/^msgid / { if (!have || id != "") flush(); id = $0; sub(/^msgid /, "", id); have = 1; next }
		/^msgstr/ { if (id == "\"\"" && ctx == "") { id = ""; plural = ""; have = 0 } ; next }
		END { flush() }
	' | sort
}

build() {
	[ -f "$pot" ] || fail "languages/$slug.pot is missing: run \`make pot\`."
	for locale in $(selected); do
		po="$dir/$slug-$locale.po"
		old="$work/old.po"
		if [ -f "$po" ]; then
			cp "$po" "$old"
		else
			header "$locale" > "$old"
			echo "Creating languages/$slug-$locale.po"
		fi

		# Merge: new strings come in empty, strings that left the template are dropped (not kept as `#~`), and a
		# changed string is never guessed from a similar one (that would be a fuzzy translation to verify).
		# Locations are left out, so moving code changes no .po file.
		msgmerge --quiet --no-fuzzy-matching --no-location --no-wrap "$old" "$pot" -o "$work/merged.po"
		msgattrib --no-obsolete --no-location --no-wrap "$work/merged.po" > "$work/clean.po"
		{ header "$locale"; body "$work/clean.po"; } > "$po"

		compile "$po" "$locale" "$dir"
		echo "$locale: $(count --untranslated "$po") untranslated, $(count --only-fuzzy "$po") fuzzy"
	done
}

todo() {
	for locale in $(selected); do
		po="$dir/$slug-$locale.po"
		[ -f "$po" ] || fail "languages/$slug-$locale.po is missing: run \`make translations\`."
		untranslated="$(count --untranslated --no-obsolete "$po")"
		fuzzy="$(count --only-fuzzy --no-obsolete "$po")"
		echo "# ===== $locale: $untranslated untranslated, $fuzzy fuzzy ($(plural_forms "$locale"))"
		if [ "$untranslated" -gt 0 ]; then
			echo "# ----- untranslated"
			msgattrib --untranslated --no-obsolete --no-location --no-wrap "$po" > "$work/todo.po"
			body "$work/todo.po"
		fi
		if [ "$fuzzy" -gt 0 ]; then
			echo "# ----- fuzzy (translate again, then remove the fuzzy flag)"
			msgattrib --only-fuzzy --no-obsolete --no-location --no-wrap "$po" > "$work/todo.po"
			body "$work/todo.po"
		fi
	done
}

check() {
	problems=0
	problem() {
		echo "$1: $2" >&2
		problems=$((problems + 1))
	}

	[ -f "$pot" ] || fail "languages/$slug.pot is missing: run \`make pot\`."
	keys "$pot" > "$work/pot.keys"

	# Files that should not be there.
	if [ -z "$only" ]; then
		expected="$slug.pot"
		for locale in $(selected); do
			expected="$expected $slug-$locale.po $slug-$locale.mo $slug-$locale.l10n.php"
		done
		for file in "$dir"/* "$dir"/.[!.]*; do
			[ -e "$file" ] || continue
			case " $expected " in
				*" $(basename "$file") "*) ;;
				*) problem "languages/$(basename "$file")" "unexpected file. Only the template and the .po, .mo and .l10n.php of the bundled locales belong in languages/ (list: bin/translations.sh)." ;;
			esac
		done
	fi

	for locale in $(selected); do
		po="$dir/$slug-$locale.po"
		name="languages/$slug-$locale.po"
		if [ ! -f "$po" ]; then
			problem "$name" "is missing: run \`make translations\`."
			continue
		fi

		msgfmt -c --check-format -o /dev/null "$po" 2> "$work/msgfmt.log" || problem "$name" "msgfmt rejects it (placeholders, plural forms or syntax):
$(cat "$work/msgfmt.log")"

		untranslated="$(count --untranslated --no-obsolete "$po")"
		fuzzy="$(count --only-fuzzy --no-obsolete "$po")"
		[ "$untranslated" -eq 0 ] || problem "$name" "$untranslated untranslated entries: run \`make translations-todo LOCALE=$locale\`, translate them, then \`make translations LOCALE=$locale\`."
		[ "$fuzzy" -eq 0 ] || problem "$name" "$fuzzy fuzzy entries: run \`make translations-todo LOCALE=$locale\`, fix them and remove the fuzzy flag."

		keys "$po" > "$work/po.keys"
		if ! diff "$work/pot.keys" "$work/po.keys" > "$work/keys.diff"; then
			problem "$name" "does not hold exactly the strings of the template: run \`make translations LOCALE=$locale\`. (< template, > file)
$(head -n 10 "$work/keys.diff")"
		fi

		grep -qxF "\"Plural-Forms: $(plural_forms "$locale")\\n\"" "$po" || problem "$name" "Plural-Forms is not the one in bin/translations.sh: run \`make translations LOCALE=$locale\`."
		grep -qxF "\"$marker_field\\n\"" "$po" || problem "$name" "the machine translation marker is missing: run \`make translations LOCALE=$locale\`."

		rm -rf "$work/fresh"
		mkdir "$work/fresh"
		compile "$po" "$locale" "$work/fresh"
		for ext in mo l10n.php; do
			file="$dir/$slug-$locale.$ext"
			if [ ! -f "$file" ]; then
				problem "languages/$slug-$locale.$ext" "is missing: run \`make translations LOCALE=$locale\`."
			elif ! cmp -s "$file" "$work/fresh/$slug-$locale.$ext"; then
				problem "languages/$slug-$locale.$ext" "is not what the .po builds: run \`make translations LOCALE=$locale\`."
			fi
		done
	done

	if [ "$problems" -gt 0 ]; then
		echo "$problems problem(s) in the bundled translations. \`make translations\` merges and builds; \`make translations-todo\` lists what is left to translate." >&2
		exit 1
	fi
	echo "The bundled translations are complete and current."
}

case "$command" in
	build) build ;;
	todo) todo ;;
	check) check ;;
	*) fail "Usage: sh bin/translations.sh build|todo|check [LOCALE]" ;;
esac
