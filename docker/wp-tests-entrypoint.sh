#!/bin/sh
# Render wp-tests-config.php for the wordpress-develop checkout from the
# environment (and, when Infection sets TEST_TOKEN for a parallel PHPUnit process, a table prefix of its own, so the
# processes do not share tables; make mutation), then run the given command. The database healthcheck in
# docker-compose.yml gates this container, so no waiting is needed here.
set -eu

cd /opt/wordpress-develop

DB_NAME="${WP_DB_NAME:-wordpress_test}"
DB_USER="${WP_DB_USER:-root}"
DB_PASSWORD="${WP_DB_PASSWORD:-root}"
DB_HOST="${WP_DB_HOST:-db}"

sed \
    -e "s/youremptytestdbnamehere/${DB_NAME}/" \
    -e "s/yourusernamehere/${DB_USER}/" \
    -e "s/yourpasswordhere/${DB_PASSWORD}/" \
    -e "s/'localhost'/'${DB_HOST}'/" \
    -e "s/^\\\$table_prefix = 'wptests_';.*/\\\$table_prefix = 'wptests_' . ( getenv( 'TEST_TOKEN' ) ? getenv( 'TEST_TOKEN' ) . '_' : '' );/" \
    wp-tests-config-sample.php > wp-tests-config.php

exec "$@"
