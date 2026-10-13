#!/bin/sh
# Render wp-tests-config.php for the wordpress-develop checkout from the
# environment, then run the given command. The database healthcheck in
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
    wp-tests-config-sample.php > wp-tests-config.php

exec "$@"
