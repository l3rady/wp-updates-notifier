#!/bin/sh
# Security hygiene checks that Semgrep cannot express, over the code `make sast` scans (or the paths given):
#  - every phpcs:ignore / phpcs:disable naming a WordPress.Security sniff, and every nosemgrep, says why on the same
#    line, after " -- " ("// nosemgrep: wpun-no-unserialize -- Reads our own export.");
#  - every PHP file has an ABSPATH guard near the top (uninstall.php: WP_UNINSTALL_PLUGIN, WordPress's own rule).
# Run by `make sast`.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

if [ "$#" -gt 0 ]; then
	paths="$*"
else
	paths="wp-updates-notifier.php uninstall.php includes modules examples"
fi

status=0
report() { echo "$1" >&2; status=1; }

# shellcheck disable=SC2086 # The paths are a list on purpose.
files="$(find $paths -type f -name '*.php' | sort)"

# A suppression with no reason after " -- ".
missing="$(echo "$files" | xargs grep -HnE 'phpcs:(ignore|disable)[^-]*WordPress\.Security|nosemgrep' \
	| grep -Ev -- ' -- +[^ ]' || true)"
if [ -n "$missing" ]; then
	report "These suppressions need a reason on the same line (' -- <reason>'):"
	report "$missing"
fi

# The guard within the first 60 lines.
for f in $files; do
	case "$(basename "$f")" in
		uninstall.php) guard="WP_UNINSTALL_PLUGIN" ;;
		*) guard="ABSPATH" ;;
	esac
	head -n 60 "$f" | grep -qE "defined\( *'$guard' *\)" || report "$f: no $guard guard in the first 60 lines"
done

[ "$status" -eq 0 ] && echo "ok: suppressions carry a reason, and every PHP file has its guard"
exit "$status"
