#!/bin/sh
#
# `make docs-a11y`: serve the built site (build/docs) and run the Playwright checks against it in the dark and light
# schemes: axe on every page, no third-party requests, visible keyboard focus.
#
# No WordPress and no published ports: the same Playwright service as `make a11y` (tests/e2e/compose.yml), run on its own
# with the site mounted read-only and a different config. Its own compose project (wpun-docs-a11y plus the checkout
# token, so each worktree has its own), removed when the script ends. Reports go to tests/e2e/reports/docs/
# (html/index.html, and the violations on the terminal).
#
# It shares one lock with `make a11y` and the other suites that mount tests/e2e (tests/lib/suite.sh): one of them per
# checkout at a time, and a second fails at once.
#
# Run from the repository root, by the Makefile, after `make docs`.

set -eu

# shellcheck source=tests/lib/suite.sh
. tests/lib/suite.sh

[ -f build/docs/index.html ] || { echo 'build/docs is missing: run make docs first.' >&2; exit 1; }

compose() {
	docker compose --progress quiet -p "$SUITE_PROJECT" -f tests/e2e/compose.yml "$@"
}

teardown() {
	compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}
SUITE_FILES='-f tests/e2e/compose.yml'
suite_start e2e 'make docs-a11y' wpun-docs-a11y ''

# Start from nothing, in case an earlier run was killed.
teardown

# The host user owns the reports: the container runs as that user, and a directory made by Docker would be root's.
rm -rf tests/e2e/reports/docs
mkdir -p tests/e2e/reports/docs

status=0
compose run --rm -T --no-deps -v "$PWD/build/docs:/site:ro" playwright \
	sh -c 'npm ci --no-fund --no-audit && npx playwright test -c docs/playwright.config.js' || status=$?

case "$status" in
0) echo 'DOCS A11Y: every axe, request and focus check passed.' ;;
*) echo "DOCS A11Y: failed (Playwright exit status $status). The report is tests/e2e/reports/docs/html/index.html." >&2 ;;
esac
exit "$status"
