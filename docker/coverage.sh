#!/bin/sh
# Run the WordPress test suite with pcov and fail if line coverage of the plugin is below COVERAGE_MIN. Runs inside the
# wp-tests-coverage container (make coverage). Writes build/coverage/clover.xml and build/coverage/html/.
#
# phpunit.xml.dist is read from the plugin directory of the WordPress checkout, the path WordPress loads the plugin
# from, so its relative coverage paths are the ones pcov reports.
set -eu

min="${COVERAGE_MIN:-0}"
plugin=/opt/wordpress-develop/src/wp-content/plugins/wp-updates-notifier

# The container is root: hand the report back to the host user, however the run ends.
trap '[ -z "${HOST_UID:-}" ] || chown -R "$HOST_UID:${HOST_GID:-$HOST_UID}" /coverage' EXIT

rm -rf /coverage/html /coverage/clover.xml

sh "$plugin/tests/bin/test-coverage-check.sh"

cd /opt/wordpress-develop
php ./vendor/bin/phpunit -c "$plugin/phpunit.xml.dist" \
	--coverage-text --coverage-clover /coverage/clover.xml --coverage-html /coverage/html "$@"

php "$plugin/bin/coverage-check.php" /coverage/clover.xml "$min"
