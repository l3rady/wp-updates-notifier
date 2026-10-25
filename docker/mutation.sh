#!/bin/sh
# Run Infection (mutation testing) on the WordPress suite with pcov. Runs inside the wp-tests-coverage container
# (make mutation). Writes build/infection/infection.log, summary.log and summary.json.
# Code no test covers is mutated too (--with-uncovered), so the MSI is the share of all mutants that tests kill; the mutants
# it cannot kill without running tests are counted as not covered, and are not run.
#
# Like docker/coverage.sh it works from the plugin directory of the WordPress checkout, the path WordPress loads the
# plugin from, so the paths pcov reports are the ones Infection maps its mutations to. Each PHPUnit process Infection
# starts gets its own TEST_TOKEN, which docker/wp-tests-entrypoint.sh turns into a table prefix of its own.
set -eu

plugin=/opt/wordpress-develop/src/wp-content/plugins/wp-updates-notifier

# The container is root: hand the logs back to the host user, however the run ends.
trap '[ -z "${HOST_UID:-}" ] || chown -R "$HOST_UID:${HOST_GID:-$HOST_UID}" /infection' EXIT

rm -f /infection/infection.log /infection/summary.log

cd "$plugin"
infection --configuration="$plugin/infection.json5" --no-interaction --no-progress --with-uncovered --show-mutations=0 "$@"
