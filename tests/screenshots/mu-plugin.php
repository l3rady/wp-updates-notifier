<?php
/**
 * Screenshots only (tests/screenshots): the mail settings of the throwaway site. The fake update data is
 * .wordpress-org/blueprint/fake-updates.php, which the compose file mounts as a second must-use plugin.
 *
 * @package wp-updates-notifier
 */

// Mail comes from a plain example.com address (docker/mu-plugins/mailpit.php sets example.test).
add_filter(
	'wp_mail_from',
	static function () {
		return 'updates@example.com';
	},
	20
);
