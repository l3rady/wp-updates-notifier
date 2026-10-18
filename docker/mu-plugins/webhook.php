<?php
/**
 * Local development only: let channels reach the `webhook` echo service.
 *
 * `wp_safe_remote_post()`, which channels use for addresses the site owner typed in, refuses hosts that resolve to
 * a private address. The echo service (http://webhook:8080, see docker-compose.yml) is one, so this lets that one
 * host through. Read what it received with `docker compose logs webhook`.
 *
 * @package wp-updates-notifier
 */

add_filter(
	'http_request_host_is_external',
	static function ( $external, $host ) {
		return 'local' === wp_get_environment_type() && 'webhook' === $host ? true : $external;
	},
	10,
	2
);
