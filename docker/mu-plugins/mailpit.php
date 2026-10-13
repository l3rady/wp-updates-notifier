<?php
/**
 * Local development only: send all WordPress mail to Mailpit (http://localhost:8025).
 *
 * @package wp-updates-notifier
 */

add_action(
	'phpmailer_init',
	static function ( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host     = 'mailpit';
		$phpmailer->Port     = 1025;
		$phpmailer->SMTPAuth = false;
	}
);

// WordPress defaults to wordpress@localhost here, which PHPMailer rejects.
add_filter(
	'wp_mail_from',
	static function () {
		return 'wordpress@example.test';
	}
);
