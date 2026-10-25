#!/bin/sh
#
# `make blueprint-smoke`: run the generated Playground blueprint, with the plugin taken from the zip of `make dist`
# (WordPress.org has no 2.0.0 yet, and the blueprint installs the release), in WordPress Playground CLI, and check in a
# browser that the demo works: the settings screen with the demo notice, both channels sent, and the update email under
# Tools, Demo emails.
#
# Runs as its own compose project (wpun-playground) with no published ports, so a running `make up` site is not touched.
# The project is removed when the script ends, whatever the outcome. Playground downloads WordPress from the network.
#
# Run from the repository root, by the Makefile (which builds the zip first). E2E_KEEP=1 leaves it up afterwards (remove
# it with `docker compose -p wpun-playground -f tests/blueprint-smoke/compose.yml down -v`). COMPOSE_EXTRA adds compose
# options (tests only).

set -eu

compose() {
	# COMPOSE_EXTRA is a list of options, so it must be split.
	# shellcheck disable=SC2086
	docker compose --progress quiet -p wpun-playground -f tests/blueprint-smoke/compose.yml ${COMPOSE_EXTRA:-} "$@"
}

teardown() {
	compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}
[ -n "${E2E_KEEP:-}" ] || trap teardown EXIT INT TERM

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
