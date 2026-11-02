<?php
/**
 * Local development only: let channels reach the `webhook` service, which catches webhook requests
 * (http://localhost:8026, see docker-compose.yml).
 *
 * `wp_safe_remote_post()`, which channels use for addresses the site owner typed in, refuses hosts that resolve to
 * a private address. The `webhook` service (http://webhook:8080) is one, so this lets that one host through.
 *
 * The service speaks plain http, so the built-in webhook channel's address field is allowed to use http as well,
 * with the `sc_wpun_channel_fields` filter the user guide documents for a receiver on a trusted network.
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

add_filter(
	'sc_wpun_channel_fields',
	static function ( $fields, $type ) {
		if ( 'local' === wp_get_environment_type() && 'webhook' === $type && isset( $fields['url'] ) ) {
			$fields['url']['https_only'] = false;
		}
		return $fields;
	},
	10,
	2
);
