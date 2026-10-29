#!/bin/sh
#
# `make screenshots`: build a throwaway site with made-up data, send it one update email, mute a few items
# (seed-mutes.sh) and photograph the settings screens and the email into .wordpress-org/screenshot-1.png to
# screenshot-5.png, then break the site on purpose (seed-health.sh) and photograph Site Health into screenshot-6.png.
#
# Runs as its own compose project (wpun-shots) with no published ports and no shared volumes or container names, so
# neither a running `make up` site nor `make a11y` is touched. The project is removed, volumes included, when the
# script ends, whatever the outcome.
#
# Run from the repository root, by the Makefile. E2E_KEEP=1 leaves the site up afterwards, to look at it (remove it
# with `docker compose -p wpun-shots down -v`). COMPOSE_EXTRA adds compose options (tests only).

set -eu

compose() {
	# COMPOSE_EXTRA is a list of options, so it must be split.
	# shellcheck disable=SC2086
	docker compose --progress quiet -p wpun-shots -f tests/screenshots/compose.yml ${COMPOSE_EXTRA:-} "$@"
}

teardown() {
	compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}
[ -n "${E2E_KEEP:-}" ] || trap teardown EXIT INT TERM

# The browser container writes the PNGs as the host user.
HOST_UID="$(id -u)"
HOST_GID="$(id -g)"
export HOST_UID HOST_GID

# Start from nothing (and so with an empty Mailpit), in case an earlier run was killed.
teardown

compose up -d --build db mailpit wordpress
compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-site-setup
compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-shots-seed

# The update check runs here, once, before the browser loads any page: an admin page load could otherwise start it in
# the background while a screen is being photographed. It must send the email, or there is nothing to photograph.
compose run --rm -T wp-cli cron event run sc_wpun_update_check

# The mutes come after the check, so the email still lists every update.
compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-shots-seed-mutes

# Screenshots 1 to 5, which need the delivery to have worked.
status=0
compose run --rm -T -e PLAYWRIGHT_ARGS='--grep-invert=screenshot.6' playwright || status=$?

# Then the failures Site Health reports. No page has been loaded since the seed, and it switches WP-Cron off, so the
# overdue check cannot run in the background.
if [ "$status" -eq 0 ]; then
	compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-shots-seed-health
	compose run --rm -T -e PLAYWRIGHT_ARGS='--grep=screenshot.6' playwright || status=$?
fi

case "$status" in
0) echo 'SCREENSHOTS: .wordpress-org/screenshot-1.png to screenshot-6.png written.' ;;
*) echo "SCREENSHOTS: failed (Playwright exit status $status). See the output above." >&2 ;;
esac
exit "$status"
