#!/bin/sh
# Sourced by bin/test-dist.sh and bin/plugin-check.sh: a fresh WordPress in a
# temporary directory, on the database from docker-compose, driven by WP-CLI.
#
#   . "$(dirname "$0")/fresh-wordpress.sh"
#   fresh_wordpress            # sets $path; then use the wp function
#
# The database is wordpress_dist_<run token>: WPUN_RUN_TOKEN from the Makefile (checkout token and suite letter) and a
# random part, cleaned by docker/wp-tests-prefix.php, so two runs never share it (decision 0032). The caller sets $root
# (the repository). The database and $path are removed when the script exits, whatever the result.

# PHP notices go to stderr: they stay in the log, but out of the values read
# from WP-CLI (its own dependencies raise deprecations on the newest PHP).
wp() { php -d display_errors=stderr /usr/local/bin/wp --path="$path" --quiet "$@"; }

fresh_wordpress_cleanup() {
	rm -rf "$path"
	php -r '$db = new mysqli( getenv( "WP_DB_HOST" ) ?: "db", "root", "root" ); $db->query( "DROP DATABASE IF EXISTS `" . $argv[1] . "`" );' "$dbname" 2>/dev/null || true
}

fresh_wordpress() {
	path="$(mktemp -d)"
	run="${WPUN_RUN_TOKEN:-x}_$(od -An -N3 -tx1 /dev/urandom | tr -d ' \n')"
	dbname="wordpress_dist_$(php -r 'require $argv[1]; echo wpun_tests_clean( $argv[2] );' "$root/docker/wp-tests-prefix.php" "$run")"
	trap fresh_wordpress_cleanup EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	echo "WordPress ${WP_VERSION:-7.1.3} on PHP $(php -r 'echo PHP_VERSION;')"

	wp core download --version="${WP_VERSION:-7.1.3}" --skip-content
	wp config create --dbname="$dbname" --dbuser=root --dbpass=root --dbhost="${WP_DB_HOST:-db}" --skip-check

	# A database of its own for this run, without needing the mysql client.
	php -r '$db = new mysqli( getenv( "WP_DB_HOST" ) ?: "db", "root", "root" ); $db->query( "CREATE DATABASE `" . $argv[1] . "`" ) || exit( 1 );' "$dbname"

	wp core install --url=http://localhost --title=dist --admin_user=admin --admin_password=admin --admin_email=admin@example.org --skip-email
}
