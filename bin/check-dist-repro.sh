#!/bin/sh
# Builds the zip twice and fails if the SHA-256 values differ. The two builds
# use different inputs on purpose: each is a fresh clone with its own umask,
# file times and time zone. Run with `make dist-repro`.
#
# It builds from `git clone` of HEAD, so only committed changes are tested.
# DIST_REPRO_SCRIPT=path copies that build script into each clone first, to
# try an uncommitted bin/build-dist.sh.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="wp-updates-notifier"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# The repository is owned by the host user, git refuses it otherwise.
git config --global --add safe.directory "$root"

build() { # name umask time-zone touch-date
	git clone -q "$root" "$work/$1"
	[ -z "${DIST_REPRO_SCRIPT:-}" ] || cp "$DIST_REPRO_SCRIPT" "$work/$1/bin/build-dist.sh"
	find "$work/$1" -path "$work/$1/.git" -prune -o -exec touch -d "$4" {} +
	(
		umask "$2"
		cd "$work/$1"
		TZ="$3" sh bin/build-dist.sh > /dev/null
	)
	sha256sum "$work/$1"/build/"$slug"-*.zip | cut -d ' ' -f 1
}

first="$(build one 022 UTC now)"
second="$(build two 077 Pacific/Auckland '2001-02-03 04:05:06 UTC')"

echo "build 1 (umask 022, UTC, new files):                $first"
echo "build 2 (umask 077, Pacific/Auckland, old files):   $second"

[ "$first" = "$second" ] || { echo "FAIL: the two builds differ" >&2; exit 1; }
echo "ok: both builds are byte-identical"
