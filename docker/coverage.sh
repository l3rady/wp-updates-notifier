#!/bin/sh
# Run the WordPress test suite with pcov, as a single site and as a multisite, and fail if line coverage of the plugin,
# both runs together, is below COVERAGE_MIN. Runs inside the wp-tests-coverage container (make coverage). Writes
# build/coverage/clover.xml and build/coverage/html/. With COVERAGE_DIFF (a `git diff -U0` the Makefile wrote) it also
# fails when a changed line was not run.
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
sh "$plugin/tests/bin/test-coverage-diff.sh"

cd /opt/wordpress-develop
runs="$(mktemp -d)"

# The suite once as a single site and once as a multisite: the code for networks only runs in the second, the code for
# single sites only in the first. The coverage counted is that of both runs together.
WP_MULTISITE=0 php ./vendor/bin/phpunit -c "$plugin/phpunit.xml.dist" --coverage-php "$runs/single.cov" "$@"
WP_MULTISITE=1 php ./vendor/bin/phpunit -c "$plugin/phpunit.xml.dist" --coverage-php "$runs/multisite.cov" "$@"
# Writing the HTML report of the merged runs needs more than the 512 MB of docker/php.ini.
php -d memory_limit=2G "$plugin/bin/coverage-merge.php" ./vendor/autoload.php /coverage "$runs/single.cov" "$runs/multisite.cov"

# Both checks run and both results are printed. The second one is for the lines a branch changed (make coverage BASE=...).
status=0
php "$plugin/bin/coverage-check.php" /coverage/clover.xml "$min" || status=$?
if [ -n "${COVERAGE_DIFF:-}" ]; then
	echo
	echo "Changed lines (make coverage BASE=...):"
	php "$plugin/bin/coverage-diff.php" /coverage/clover.xml "$COVERAGE_DIFF" "$plugin" || status=$?
fi
exit "$status"
