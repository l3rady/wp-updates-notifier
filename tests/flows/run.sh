#!/bin/sh
#
# `make e2e`: build a throwaway site with fake pending updates and a mail catcher, then drive it the way a person does
# (a real browser, a real WP-CLI cron run, real mail in Mailpit) and check what happens.
#
# FLOWS_MULTISITE=1 (`make e2e-ms`) runs the network flows instead, on a multisite with the plugin network-activated
# (tests/flows/compose.multisite.yml), as the project wpun-flows-ms.
#
# Runs as its own compose project (wpun-flows, or wpun-flows-ms, plus the checkout token, so each worktree has its own)
# with no published ports and no shared volumes or container names, so a running `make up` site is not touched. The
# project is removed, volumes included, when the script ends, whatever the outcome.
#
# It mounts tests/e2e, so it shares one lock with `make a11y` and the other suites that do (tests/lib/suite.sh): one
# of them per checkout at a time, and a second fails at once.
#
# Run from the repository root, by the Makefile. E2E_KEEP=1 leaves the site up afterwards, to look at it (the script
# prints the `docker compose down -v` command that removes it). COMPOSE_EXTRA adds compose options
# (tests only). FLOWS_ARGS is passed to `playwright test`, for example FLOWS_ARGS=channels.spec.js to run one file.

set -eu

# shellcheck source=tests/lib/suite.sh
. tests/lib/suite.sh

PROJECT=wpun-flows
LABEL='make e2e'
FILES='-f tests/flows/compose.yml'
if [ -n "${FLOWS_MULTISITE:-}" ]; then
	PROJECT=wpun-flows-ms
	LABEL='make e2e-ms'
	FILES="$FILES -f tests/flows/compose.multisite.yml"
fi

compose() {
	# FILES and COMPOSE_EXTRA are lists of options, so they must be split.
	# shellcheck disable=SC2086
	docker compose --progress quiet -p "$SUITE_PROJECT" $FILES ${COMPOSE_EXTRA:-} "$@"
}

teardown() {
	compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}
SUITE_FILES="$FILES"
suite_start e2e "$LABEL" "$PROJECT" "${E2E_KEEP:-}"

HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
export HOST_UID HOST_GID

# Start from nothing, in case an earlier run was killed.
teardown

compose up -d --build db mailpit wordpress
compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-site-setup
compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-flows-seed

# playwright depends on wpcli (the WP-CLI door the specs call), so compose starts it.
status=0
compose run --rm -T playwright || status=$?

case "$status" in
0) echo 'E2E: every flow passed.' ;;
*) echo "E2E: failed (Playwright exit status $status). See the output above." >&2 ;;
esac
exit "$status"
