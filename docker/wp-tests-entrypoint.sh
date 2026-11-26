#!/bin/sh
# Render wp-tests-config.php for the wordpress-develop checkout from the environment, then run the given command and
# drop the tables of the run when it ends (decision 0032).
#
# The run token is the base the Makefile passes in WPUN_RUN_TOKEN (checkout token and suite letter) and a random part
# made here, exported so every process of the run sees the same value: Infection's workers, WordPress's install
# script. The table prefix is wptests_<run token>_ and, when Infection sets TEST_TOKEN for a parallel PHPUnit process,
# wptests_<run token>_<worker>_ (the rules are in docker/wp-tests-prefix.php). So no two runs, in any checkout, share
# a table. The database healthcheck in docker/compose.testdb.yml gates the start of the database, so no waiting is
# needed here.
set -eu

cd /opt/wordpress-develop

DB_NAME="${WP_DB_NAME:-wordpress_test}"
DB_USER="${WP_DB_USER:-root}"
DB_PASSWORD="${WP_DB_PASSWORD:-root}"
DB_HOST="${WP_DB_HOST:-db}"

WPUN_RUN_TOKEN="${WPUN_RUN_TOKEN:-x}_$(od -An -N3 -tx1 /dev/urandom | tr -d ' \n')"
export WPUN_RUN_TOKEN

sed \
    -e "s/youremptytestdbnamehere/${DB_NAME}/" \
    -e "s/yourusernamehere/${DB_USER}/" \
    -e "s/yourpasswordhere/${DB_PASSWORD}/" \
    -e "s/'localhost'/'${DB_HOST}'/" \
    -e "s#^\\\$table_prefix = 'wptests_';.*#require_once '/opt/wp-updates-notifier/docker/wp-tests-prefix.php'; \\\$table_prefix = wpun_tests_table_prefix( getenv( 'WPUN_RUN_TOKEN' ), getenv( 'TEST_TOKEN' ) );#" \
    wp-tests-config-sample.php > wp-tests-config.php

# Drop the tables of this run, however it ends. A failure is reported by the script and never changes the exit code.
# A run that is killed leaves its tables until the database container restarts (its data is in memory).
drop_tables() {
    php /opt/wp-updates-notifier/docker/wp-tests-drop.php || true
}
trap drop_tables EXIT

# The command runs as a child, so this script can drop the tables after it. A child started with & ignores SIGINT
# (no job control), so Ctrl-C and docker stop are turned into SIGTERM for every other process of the container
# (kill -1), and the run ends with the signal's exit code.
"$@" &
child=$!
signalled=0
stop() {
    signalled="$1"
    kill -TERM -1 2>/dev/null || true
}
trap 'stop 130' INT
trap 'stop 143' TERM

code=0
wait "$child" || code=$?
# wait returns early when a signal arrives: wait again until the child is gone.
while kill -0 "$child" 2>/dev/null; do
    wait "$child" || code=$?
done
[ "$signalled" -eq 0 ] || code="$signalled"
exit "$code"
