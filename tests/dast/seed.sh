#!/bin/sh
#
# Seeds the DAST site (`make dast`) after docker/site-setup.sh (SITE_MODE=dast) has installed it: one email channel,
# so the list, edit and delete screens have something to show, and one of the field showcase's channel, so every
# field type is rendered and fuzzed. Runs in the wp-cli container, in /var/www/html.

set -eu

# Hello Dolly shows a random lyric on every admin page. Answers that differ on each request make ZAP's boolean SQL
# injection check report a false positive, so it stays off here (docker/site-setup.sh activates it for the local site).
! wp plugin is-active hello || wp plugin deactivate hello --quiet

wp option update sc_wpun_channels '{
	"dast_email": {
		"type": "email",
		"label": "DAST email",
		"enabled": true,
		"settings": { "to": "dast@example.com" },
		"routing": []
	},
	"dast_showcase": {
		"type": "field_showcase",
		"label": "DAST field showcase",
		"enabled": false,
		"settings": {},
		"routing": []
	}
}' --format=json --autoload=no --quiet
echo 'DAST site seeded: channels dast_email and dast_showcase'
