#!/bin/sh
#
# `make a11y`: build an isolated copy of the site, run the Playwright checks (axe and keyboard) against the settings
# screen and throw it away.
#
# Runs as its own compose project (wpun-e2e) with no published ports and no shared volumes or container names, so a
# running `make up` site is never touched. The project is removed, volumes included, when the script ends, whatever
# the outcome. Reports go to tests/e2e/reports/ (html/index.html, and the violations on the terminal).
#
# Run from the repository root, by the Makefile. E2E_KEEP=1 leaves the site up afterwards, to look at it (remove it with
# `docker compose -p wpun-e2e down -v`). COMPOSE_EXTRA adds compose options (tests only).

set -eu

compose() {
	# COMPOSE_EXTRA is a list of options, so it must be split.
	# shellcheck disable=SC2086
	docker compose --progress quiet -p wpun-e2e -f tests/e2e/compose.yml ${COMPOSE_EXTRA:-} "$@"
}

teardown() {
	compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}
[ -n "${E2E_KEEP:-}" ] || trap teardown EXIT INT TERM

# The host user owns the reports: the container runs as that user, and a directory made by Docker would be root's.
rm -rf tests/e2e/reports
mkdir -p tests/e2e/reports

# Start from nothing, in case an earlier run was killed.
teardown

compose up -d --build db wordpress
compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-site-setup
compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-e2e-seed

status=0
compose run --rm -T playwright || status=$?

case "$status" in
0) echo 'A11Y: every axe and keyboard check passed.' ;;
*) echo "A11Y: failed (Playwright exit status $status). The violations are listed above; the report is tests/e2e/reports/html/index.html." >&2 ;;
esac
exit "$status"
