#!/bin/sh
# Run the plugin's own PHPUnit suite (tests/) with core's test bootstrap and a
# real database. Runs inside the wp-tests container; set WP_MULTISITE=1 for the
# multisite run (see `make test-wp` / `make test-wp-ms`).
set -eu

cd /opt/wordpress-develop

exec php ./vendor/bin/phpunit -c /opt/wp-updates-notifier/phpunit.xml.dist "$@"
