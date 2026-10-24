#!/bin/sh
# Write languages/wp-updates-notifier.pot with WP-CLI, or check that the committed one is current.
# Runs in the php container (`make pot`, `make check-pot`), which has WP-CLI. It needs no database and no site:
# make-pot reads the source and never loads WordPress.
#
#   sh bin/make-pot.sh          write the file
#   sh bin/make-pot.sh --check  regenerate to a temporary file and compare
#
# --check ignores the header block (creation date, plugin version, generator) and the `#:` location lines, so it passes
# when only line numbers move or release-please bumps the version. It fails on any change to a msgid, context, plural or
# translators comment, and on any `Warning:` that make-pot prints.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
plugin="$root"
pot="$plugin/languages/wp-updates-notifier.pot"
# The one list of what is not scanned for strings.
exclude="tests,docker,examples,bin,vendor,node_modules,build,docs,site,.semgrep"

HOME="${TMPDIR:-/tmp}"
export HOME

generate() {
	wp i18n make-pot "$plugin" "$1" --slug=wp-updates-notifier --domain=wp-updates-notifier --exclude="$exclude" 2>&1
}

if [ "${1:-}" != "--check" ]; then
	generate "$pot"
	exit 0
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

generate "$work/new.pot" | tee "$work/log"
if grep -q 'Warning:' "$work/log"; then
	echo "make-pot printed the warning above: fix the string, then run \`make pot\`." >&2
	exit 1
fi

if [ ! -f "$pot" ]; then
	echo "languages/wp-updates-notifier.pot is missing: run \`make pot\` and commit it." >&2
	exit 1
fi

# Drop the header (everything up to the first blank line) and the location lines.
strip() {
	awk 'body { if ($0 !~ /^#:/) print } /^$/ && !body { body = 1 }' "$1"
}
strip "$pot" > "$work/old.txt"
strip "$work/new.pot" > "$work/new.txt"

if ! diff -u "$work/old.txt" "$work/new.txt"; then
	echo "languages/wp-updates-notifier.pot is out of date: run \`make pot\` and commit the result." >&2
	exit 1
fi
echo "languages/wp-updates-notifier.pot is current."
