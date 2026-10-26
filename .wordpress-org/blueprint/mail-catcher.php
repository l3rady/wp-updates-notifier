<?php
/**
 * Catches the demo site's mail, so nothing is sent, and shows it as received under Tools, Demo emails.
 *
 * Loaded as a must-use plugin by the WordPress Playground blueprint. wp_mail() reports success, so the update check
 * counts the delivery as sent, and the message is kept (the last 20) in an option. The page that shows them only
 * reads, so it needs no security token.
 *
 * @package wp-updates-notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

// Where the caught emails are kept, how many, and the slug of the page that lists them.
const WPUN_DEMO_MAIL_OPTION = 'wpun_demo_emails';
const WPUN_DEMO_MAIL_LIMIT  = 20;
const WPUN_DEMO_MAIL_PAGE   = 'wpun-demo-emails';

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		$emails = get_option( WPUN_DEMO_MAIL_OPTION, array() );
		$emails = is_array( $emails ) ? $emails : array();
		$last   = $emails ? reset( $emails ) : array();

		$headers = $atts['headers'] ?? array();
		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		}
		$to = $atts['to'] ?? array();

		array_unshift(
			$emails,
			array(
				'id'      => ( isset( $last['id'] ) ? (int) $last['id'] : 0 ) + 1,
				'time'    => time(),
				'to'      => implode( ', ', array_map( 'strval', (array) $to ) ),
				'subject' => (string) ( $atts['subject'] ?? '' ),
				'headers' => implode( "\n", array_map( 'strval', $headers ) ),
				// The plugin sets the content type with this filter, not with a header.
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own hook.
				'html'    => 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' ),
				'body'    => (string) ( $atts['message'] ?? '' ),
			)
		);
		update_option( WPUN_DEMO_MAIL_OPTION, array_slice( $emails, 0, WPUN_DEMO_MAIL_LIMIT ), false );

		// True: wp_mail() returns it without sending anything.
		return true;
	},
	10,
	2
);

add_action(
	'admin_menu',
	static function () {
		add_management_page(
			'Demo emails',
			'Demo emails',
			'manage_options',
			WPUN_DEMO_MAIL_PAGE,
			'wpun_demo_render_emails'
		);
	}
);

add_action(
	'admin_notices',
	static function () {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_wp-updates-notifier' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			wp_kses(
				sprintf(
					/* The link is to the page of caught emails. */
					'This is a WordPress Playground demo. Emails are caught, not sent: see <a href="%s">Tools &rarr; Demo emails</a>.',
					esc_url( admin_url( 'tools.php?page=' . WPUN_DEMO_MAIL_PAGE ) )
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}
);

/**
 * The Demo emails page: the caught emails, newest first, and the selected one.
 *
 * @return void
 */
function wpun_demo_render_emails() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$emails = get_option( WPUN_DEMO_MAIL_OPTION, array() );
	$emails = is_array( $emails ) ? $emails : array();
	$base   = admin_url( 'tools.php?page=' . WPUN_DEMO_MAIL_PAGE );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which email to show changes nothing, so there is no security token to check.
	$wanted   = isset( $_GET['email'] ) ? absint( wp_unslash( $_GET['email'] ) ) : 0;
	$selected = null;
	foreach ( $emails as $email ) {
		if ( $email['id'] === $wanted || ( ! $wanted && null === $selected ) ) {
			$selected = $email;
		}
	}

	echo '<div class="wrap"><h1>Demo emails</h1>';
	echo '<p>This is a WordPress Playground demo. Emails are caught, not sent, and the last ' . (int) WPUN_DEMO_MAIL_LIMIT . ' are listed here.</p>';

	if ( ! $emails ) {
		echo '<p>No email has been caught yet.</p></div>';
		return;
	}

	echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Received</th><th>To</th><th>Subject</th></tr></thead><tbody>';
	foreach ( $emails as $email ) {
		printf(
			'<tr><td>%1$s</td><td>%2$s</td><td><a href="%3$s">%4$s</a></td></tr>',
			esc_html( wp_date( 'Y-m-d H:i:s', $email['time'] ) ),
			esc_html( $email['to'] ),
			esc_url( add_query_arg( 'email', $email['id'], $base ) ),
			esc_html( $email['subject'] )
		);
	}
	echo '</tbody></table>';

	if ( $selected ) {
		echo '<h2>' . esc_html( $selected['subject'] ) . '</h2>';
		echo '<p><strong>To:</strong> ' . esc_html( $selected['to'] ) . '</p>';
		if ( '' !== $selected['headers'] ) {
			echo '<pre>' . esc_html( $selected['headers'] ) . '</pre>';
		}
		if ( $selected['html'] ) {
			// Sandboxed, without allow-scripts: the message cannot run anything.
			printf(
				'<iframe sandbox title="%1$s" srcdoc="%2$s" style="width:100%%;max-width:900px;height:600px;background:#fff;border:1px solid #c3c4c7"></iframe>',
				esc_attr( $selected['subject'] ),
				esc_attr( $selected['body'] )
			);
		} else {
			echo '<pre style="white-space:pre-wrap;max-width:900px">' . esc_html( $selected['body'] ) . '</pre>';
		}
	}
	echo '</div>';
}
