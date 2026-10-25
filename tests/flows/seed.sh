#!/bin/sh
#
# Seeds the `make e2e` site after docker/site-setup.sh (SITE_MODE=e2e) has installed it: made-up plugins whose updates
# .wordpress-org/blueprint/fake-updates.php reports, and no scheduled cron on page loads. Everything here is fake.
# The channels and settings of each test are set by the test (specs/helpers.js, resetSite).
# Runs in the wp-cli container, in /var/www/html.

set -eu

# The update check runs only when a test runs it (`wp cron event run`): a page load would start a due event in the
# background and send mail in the middle of a test.
wp config set DISABLE_WP_CRON true --raw --type=constant --quiet

# make_plugin <slug> <name> <version> <active|inactive>: a plugin that does nothing, named in fake-updates.php.
make_plugin() {
	mkdir -p "wp-content/plugins/$1"
	printf '<?php\n/**\n * Plugin Name: %s\n * Version: %s\n * Author: Example Author\n */\n' "$2" "$3" >"wp-content/plugins/$1/$1.php"
	if [ "$4" = active ]; then
		wp plugin is-active "$1" || wp plugin activate "$1" --quiet
	fi
}
make_plugin example-contact-forms 'Example Contact Forms' 3.4.1 active
make_plugin example-gallery 'Example Gallery' 2.1.0 active
make_plugin example-backup 'Example Backup' 1.8.3 inactive

echo 'Flows site seeded'
