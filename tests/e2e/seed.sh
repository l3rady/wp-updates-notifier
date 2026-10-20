#!/bin/sh
#
# Seeds the `make a11y` site after docker/site-setup.sh (SITE_MODE=e2e) has installed it: one email channel, so the
# list, edit and delete screens have something to show, and one of the field showcase's channel, so every field type
# is rendered. Runs in the wp-cli container, in /var/www/html.

set -eu

wp option update sc_wpun_channels '{
	"e2e_email": {
		"type": "email",
		"label": "Office email",
		"enabled": true,
		"settings": { "to": "office@example.com" },
		"routing": []
	},
	"e2e_showcase": {
		"type": "field_showcase",
		"label": "E2E field showcase",
		"enabled": false,
		"settings": {},
		"routing": []
	}
}' --format=json --autoload=no --quiet
echo 'E2E site seeded: channels e2e_email and e2e_showcase'
