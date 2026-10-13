#!/bin/sh
# Sourced by bin/test-dist.sh and bin/plugin-check.sh: a fresh WordPress in a
# temporary directory, on the database from docker-compose, driven by WP-CLI.
#
#   . "$(dirname "$0")/fresh-wordpress.sh"
#   fresh_wordpress            # sets $path; then use the wp function

# PHP notices go to stderr: they stay in the log, but out of the values read
# from WP-CLI (its own dependencies raise deprecations on the newest PHP).
wp() { php -d display_errors=stderr /usr/local/bin/wp --path="$path" --quiet "$@"; }

fresh_wordpress() {
	path="$(mktemp -d)"
	echo "WordPress ${WP_VERSION:-7.1.3} on PHP $(php -r 'echo PHP_VERSION;')"

	wp core download --version="${WP_VERSION:-7.1.3}" --skip-content
	wp config create --dbname=wordpress_dist --dbuser=root --dbpass=root --dbhost="${WP_DB_HOST:-db}" --skip-check

	# A fresh database each run, without needing the mysql client.
	php -r '$db = new mysqli( getenv( "WP_DB_HOST" ) ?: "db", "root", "root" ); $db->query( "DROP DATABASE IF EXISTS wordpress_dist" ); $db->query( "CREATE DATABASE wordpress_dist" ) || exit( 1 );'

	wp core install --url=http://localhost --title=dist --admin_user=admin --admin_password=admin --admin_email=admin@example.org --skip-email
}
