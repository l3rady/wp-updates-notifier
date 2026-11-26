#!/bin/sh
#
# `make e2e-upgrade`: install the real 1.6.0 from WordPress.org on a throwaway site, then update it to the zip of
# `make dist` (given a 2.0.0 header, in a copy) the ways a site can be updated, and check what a user is left with.
# Needs the network (WordPress and 1.6.0 come from WordPress.org), so CI does not run it: run it before a release.
#
# Runs as its own compose project (wpun-upgrade plus the checkout token, so each worktree has its own) with no published
# ports and no shared volumes or container names, so no other site is touched. The project is removed, volumes
# included, when the script ends, whatever the outcome.
#
# It mounts tests/e2e, so it shares one lock with `make a11y` and the other suites that do (tests/lib/suite.sh): one
# of them per checkout at a time, and a second fails at once.
#
# Run from the repository root, by the Makefile, after `make dist` (the Makefile target depends on it). E2E_KEEP=1 leaves
# the site up afterwards (the script prints the `docker compose down -v` command that removes it). COMPOSE_EXTRA adds
# compose options (tests only). FLOWS_ARGS is passed to `playwright test`, for example FLOWS_ARGS=update-cli.spec.js.

set -eu

# shellcheck source=tests/lib/suite.sh
. tests/lib/suite.sh

compose() {
	# COMPOSE_EXTRA is a list of options, so it must be split.
	# shellcheck disable=SC2086
	docker compose --progress quiet -p "$SUITE_PROJECT" -f tests/flows/upgrade/compose.yml ${COMPOSE_EXTRA:-} "$@"
}

teardown() {
	compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}
SUITE_FILES='-f tests/flows/upgrade/compose.yml'
suite_start e2e 'make e2e-upgrade' wpun-upgrade "${E2E_KEEP:-}"

ls build/wp-updates-notifier-*.zip >/dev/null 2>&1 || { echo 'No zip in build/: run make dist first.' >&2; exit 1; }

HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
export HOST_UID HOST_GID

# Start from nothing, in case an earlier run was killed.
teardown

compose up -d --build db mailpit wordpress
compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-upgrade-setup
compose run --rm -T wp-cli eval-file /upgrade/package.php

status=0
compose run --rm -T playwright || status=$?

case "$status" in
0) echo 'E2E-UPGRADE: every update path passed.' ;;
*) echo "E2E-UPGRADE: failed (Playwright exit status $status). See the output above." >&2 ;;
esac
exit "$status"
