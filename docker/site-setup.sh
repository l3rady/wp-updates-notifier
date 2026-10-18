#!/bin/sh
#
# Sets up the local site (`make site-setup`, also run by `make up`). Safe to run again: every step checks first
# or overwrites with the same value, so it works on a fresh site volume and on an existing one.
#
# Runs in the wp-cli container, in /var/www/html. Settings go into wp-config.php with `wp config set`, because the
# image only writes that file once, and so WP-CLI sees the same constants as the web server.

set -eu

SITE_URL="${SITE_URL:-http://localhost:8080}"

# On a fresh volume the wordpress container's entrypoint copies WordPress in and writes wp-config.php. Wait for
# that and for the database.
tries=0
until [ -f wp-config.php ] && wp db query 'SELECT 1' >/dev/null 2>&1; do
	tries=$((tries + 1))
	if [ "$tries" -gt 90 ]; then
		echo 'The site is not ready: no wp-config.php or no database after 90 s.' >&2
		exit 1
	fi
	sleep 1
done

if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$SITE_URL" --title=Test --admin_user=admin --admin_password=admin \
		--admin_email=a@example.com --skip-email
fi

# Debugging (#175). Errors show on the page and go to wp-content/debug.log (`make site-log`).
wp config set WP_ENVIRONMENT_TYPE local --type=constant --quiet
wp config set WP_DEBUG true --raw --type=constant --quiet
wp config set WP_DEBUG_LOG true --raw --type=constant --quiet
wp config set WP_DEBUG_DISPLAY true --raw --type=constant --quiet
wp config set SCRIPT_DEBUG true --raw --type=constant --quiet
wp config set SAVEQUERIES true --raw --type=constant --quiet
wp config set WP_DEVELOPMENT_MODE plugin --type=constant --quiet

# install_plugin <slug>: install the latest version unless it is there, then activate it.
install_plugin() {
	wp plugin is-installed "$1" || wp plugin install "$1" --quiet
	wp plugin is-active "$1" || wp plugin activate "$1" --quiet
}

wp plugin is-active wp-updates-notifier || wp plugin activate wp-updates-notifier --quiet
install_plugin query-monitor
install_plugin debug-bar
install_plugin wp-crontrol

echo "Local site ready: $SITE_URL (admin / admin), mail in Mailpit on http://localhost:8025"
