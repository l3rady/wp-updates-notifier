#!/bin/sh
#
# Mutes for the Muted tab (screenshot 4), after run.sh has run the update check, so the update email still lists every
# update. Muted as the admin through WP-CLI, so the tab says who muted. One more mute is set in code
# (tests/screenshots/mu-plugin.php) and shows as "Set in code".
# Runs in the wp-cli container, in /var/www/html.

set -eu

wp --user=admin updates-notifier mute plugin example-gallery
wp --user=admin updates-notifier mute plugin example-backup --until-next-version
wp --user=admin updates-notifier mute theme example-agency
echo 'Screenshots site muted: example-gallery, example-backup (this version) and example-agency'
