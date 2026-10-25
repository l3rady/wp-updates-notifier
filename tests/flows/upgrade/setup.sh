#!/bin/sh
#
# Sets up the `make e2e-upgrade` site (the wp-cli container, in /var/www/html): WordPress, then the real 1.6.0 from
# WordPress.org (this needs the network), kept as a pristine copy that reset.php puts back before each test. The plugin of
# this repository is not mounted: the tests install it as a 2.0.0 update. docker/site-setup.sh is not used, because it
# activates the plugin by its 2.0.0 name.

set -eu

tries=0
until [ -f wp-config.php ] && wp db query 'SELECT 1' >/dev/null 2>&1; do
	tries=$((tries + 1))
	if [ "$tries" -gt 90 ]; then
		echo 'The site is not ready: no wp-config.php or no database after 90 s.' >&2
		exit 1
	fi
	sleep 1
done

wp core is-installed 2>/dev/null || wp core install --url=http://wordpress:8080 --title=Test --admin_user=admin \
	--admin_password=admin --admin_email=a@example.com --skip-email

# No cron on page loads: the update check runs when a test runs it. Errors go to the log, not the page, so a test can
# look for a fatal error in wp-content/debug.log.
wp config set DISABLE_WP_CRON true --raw --type=constant --quiet
wp config set WP_ENVIRONMENT_TYPE production --type=constant --quiet
wp config set WP_DEBUG true --raw --type=constant --quiet
wp config set WP_DEBUG_LOG true --raw --type=constant --quiet
wp config set WP_DEBUG_DISPLAY false --raw --type=constant --quiet

wp plugin install wp-updates-notifier --version=1.6.0 --activate
test -f wp-content/plugins/wp-updates-notifier/class-sc-wp-updates-notifier.php
rm -rf wp-content/wpun-1.6.0
cp -a wp-content/plugins/wp-updates-notifier wp-content/wpun-1.6.0

# The made-up plugins fake-updates.php reports, so the 2.0.0 update check has something to send.
make_plugin() {
	mkdir -p "wp-content/plugins/$1"
	printf '<?php\n/**\n * Plugin Name: %s\n * Version: %s\n * Author: Example Author\n */\n' "$2" "$3" >"wp-content/plugins/$1/$1.php"
}
make_plugin example-contact-forms 'Example Contact Forms' 3.4.1
make_plugin example-gallery 'Example Gallery' 2.1.0
make_plugin example-backup 'Example Backup' 1.8.3
wp plugin activate example-contact-forms example-gallery --quiet

echo 'Upgrade site ready: WP-Updates-Notifier 1.6.0 installed from WordPress.org'
