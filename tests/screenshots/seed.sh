#!/bin/sh
#
# Seeds the `make screenshots` site after docker/site-setup.sh (SITE_MODE=e2e) has installed it: made-up plugins whose
# updates mu-plugin.php reports, and three email channels. Everything here is fake: example.com addresses, generic names.
# Runs in the wp-cli container, in /var/www/html. Nothing is checked here: run.sh runs the update check afterwards.

set -eu

wp option update blogname 'Example Site' --quiet
wp option update blogdescription 'Just another example' --quiet

# The field showcase is a developer tool, not something to show.
wp plugin deactivate wpun-field-showcase --quiet
# Hello Dolly writes a line of a song at the top of every admin screen.
wp plugin deactivate hello --quiet

# make_plugin <slug> <name> <version> <active|inactive>: a plugin that does nothing.
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

# A value set in code shows as read-only on the channel's form (screenshot 3).
wp config set SC_WPUN_CHANNEL_OFFICE__FROM updates@example.com --type=constant --quiet

wp option update sc_wpun_channels '{
	"office": {
		"type": "email",
		"label": "Office email",
		"enabled": true,
		"settings": { "to": "office@example.com", "format": "html", "subject_prefix": "[Example Site]" },
		"routing": { "remind": "daily" }
	},
	"client": {
		"type": "email",
		"label": "Client: WordPress updates",
		"enabled": true,
		"settings": { "to": "client@example.com" },
		"routing": { "types": [ "core" ] }
	},
	"archive": {
		"type": "email",
		"label": "Archive",
		"enabled": false,
		"settings": { "to": "archive@example.com" },
		"routing": { "types": [ "plugin", "theme" ], "scope": "active" }
	}
}' --format=json --autoload=no --quiet
# Mail sent while the site was being set up is not wanted: only the email of the check that run.sh starts is.
wp eval 'wp_remote_request( "http://mailpit:8025/api/v1/messages", array( "method" => "DELETE" ) );'
echo 'Screenshots site seeded: channels office, client and archive'
