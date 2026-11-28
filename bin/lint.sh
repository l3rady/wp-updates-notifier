#!/bin/sh
# Coding standards (`make lint`): PHPCS on the plugin, the example add-on and the field showcase. Runs in the php
# container. Fails on errors and warnings.
#
# Locally the three runs stop at the first failure and print the normal report. With GITHUB_ACTIONS set (CI) all three
# run, each also writes a Checkstyle report to build/phpcs/, and bin/checkstyle-annotations.php turns the findings
# into GitHub annotations after the normal report, so they show on the changed lines.
set -u

cd "$(dirname "$0")/.."

if [ -z "${GITHUB_ACTIONS:-}" ]; then
	vendor/bin/phpcs && vendor/bin/phpcs --standard=examples/wpun-example-channel/phpcs.xml.dist && vendor/bin/phpcs --standard=docker/dev-plugins/wpun-field-showcase/phpcs.xml.dist
	exit $?
fi

rm -rf build/phpcs
mkdir -p build/phpcs
status=0
vendor/bin/phpcs --report=full --report-checkstyle=build/phpcs/plugin.xml || status=1
vendor/bin/phpcs --standard=examples/wpun-example-channel/phpcs.xml.dist --report=full --report-checkstyle=build/phpcs/example.xml || status=1
vendor/bin/phpcs --standard=docker/dev-plugins/wpun-field-showcase/phpcs.xml.dist --report=full --report-checkstyle=build/phpcs/showcase.xml || status=1
php bin/checkstyle-annotations.php build/phpcs/plugin.xml build/phpcs/example.xml build/phpcs/showcase.xml
exit "$status"
