#!/bin/sh
#
# `make blueprint-smoke`: run the generated Playground blueprint, with the plugin taken from the zip of `make dist`
# (WordPress.org has no 2.0.0 yet, and the blueprint installs the release), in WordPress Playground CLI, and check in a
# browser that the demo works: the settings screen with the demo notice, both channels sent, and the update email under
# Tools, Demo emails.
#
# Runs as its own compose project (wpun-playground plus the checkout token, so each worktree has its own) with no
# published ports, so a running `make up` site is not touched. The project is removed when the script ends, whatever
# the outcome. Playground downloads WordPress from the network.
#
# It mounts tests/e2e, so it shares one lock with `make a11y` and the other suites that do (tests/lib/suite.sh): one
# of them per checkout at a time, and a second fails at once.
#
# Run from the repository root, by the Makefile (which builds the zip first). E2E_KEEP=1 leaves it up afterwards (the
# script prints the `docker compose down -v` command that removes it). COMPOSE_EXTRA adds compose options (tests only).

set -eu

# shellcheck source=tests/lib/suite.sh
. tests/lib/suite.sh

compose() {
	# COMPOSE_EXTRA is a list of options, so it must be split.
	# shellcheck disable=SC2086
	docker compose --progress quiet -p "$SUITE_PROJECT" -f tests/blueprint-smoke/compose.yml ${COMPOSE_EXTRA:-} "$@"
}

teardown() {
	compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}
SUITE_FILES='-f tests/blueprint-smoke/compose.yml'
suite_start e2e 'make blueprint-smoke' wpun-playground "${E2E_KEEP:-}"

HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
export HOST_UID HOST_GID

version="$(sed -n 's/^ \* Version: //p' wp-updates-notifier.php | head -n 1)"
zip="build/wp-updates-notifier-$version.zip"
[ -f "$zip" ] || { echo "BLUEPRINT SMOKE: $zip does not exist: run make dist first." >&2; exit 1; }

# Start from nothing, in case an earlier run was killed, then fill the directory the containers mount.
teardown
rm -rf build/blueprint-smoke
mkdir -p build/blueprint-smoke
cp "$zip" build/blueprint-smoke/wp-updates-notifier.zip
docker compose --progress quiet run --build --rm -T php php bin/blueprint.php build/blueprint-smoke/blueprint.json --plugin-vfs=/smoke/wp-updates-notifier.zip

status=0
compose up --build --abort-on-container-exit --exit-code-from playwright playwright || status=$?

case "$status" in
0) echo 'BLUEPRINT SMOKE: the blueprint runs in WordPress Playground and the demo works.' ;;
*)
	echo "BLUEPRINT SMOKE: failed (exit status $status). Playground's output:" >&2
	compose logs --no-color --tail 60 playground >&2 || true
	;;
esac
exit "$status"
