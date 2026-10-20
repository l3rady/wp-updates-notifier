<?php
/**
 * Telling admins that Slack notifications are no longer supported.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

use WPUN\Admin\Settings_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Version 2.0.0 sends no Slack notifications but keeps the stored Slack settings. While a
 * switched-on Slack instance is stored and nothing registers a `slack` channel, this tells
 * the site's admins: once by email, on the plugin's settings page, and in Site Health.
 *
 * @internal
 */
class Slack_Notice {
	const DISMISS_ACTION = 'sc_wpun_dismiss_slack_notice';
	const DISMISS_META   = 'sc_wpun_dismissed_slack_notice';
	const SETTINGS_SLUG  = 'wp-updates-notifier';

	/**
	 * Channel instance storage.
	 *
	 * @var Channel_Store
	 */
	private $store;

	/**
	 * Registered notification channels.
	 *
	 * @var Channel_Registry
	 */
	private $registry;

	/**
	 * Run state.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Constructor.
	 *
	 * @param Channel_Store    $store    Channel instance storage.
	 * @param Channel_Registry $registry Registered notification channels.
	 * @param State            $state    Run state.
	 */
	public function __construct( Channel_Store $store, Channel_Registry $registry, State $state ) {
		$this->store    = $store;
		$this->registry = $registry;
		$this->state    = $state;
	}

	/**
	 * Whether a switched-on Slack instance is stored while no `slack` channel is registered.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		foreach ( $this->store->all() as $instance ) {
			if ( 'slack' === $instance->get_type() && $instance->is_enabled() ) {
				return ! $this->registry->has( 'slack' );
			}
		}

		return false;
	}

	/**
	 * The URL of the plugin's settings page.
	 *
	 * @return string
	 */
	public function settings_url(): string {
		return admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG );
	}

	/**
	 * The recipients of the email notifications, if they are switched on.
	 *
	 * @return string The recipients, or an empty string if email notifications are off.
	 */
	private function email_recipients(): string {
		$instance = $this->store->get( 'email' );
		if ( null === $instance || ! $instance->is_enabled() ) {
			return '';
		}
		$to = $instance->get_setting( 'to' );

		return is_scalar( $to ) ? trim( (string) $to ) : '';
	}

	/**
	 * The message, as plain text lines.
	 *
	 * @return string
	 */
	private function message(): string {
		if ( '' !== $this->email_recipients() ) {
			$status = __( 'Email notifications continue as before.', 'wp-updates-notifier' );
		} else {
			$status = sprintf(
				// translators: %s: URL of the settings page.
				__( 'Email notifications are switched off, so this site currently sends no update notifications. You can turn them on at %s', 'wp-updates-notifier' ),
				$this->settings_url()
			);
		}

		return implode(
			"\n\n",
			array(
				__( 'Slack notifications are not supported in this version of WP Updates Notifier. Your Slack settings have been kept.', 'wp-updates-notifier' ),
				$status,
				__( 'New notification channels and features are coming in future releases.', 'wp-updates-notifier' ),
			)
		);
	}

	/**
	 * Send the one-time email, if it applies and has not been sent. Runs before the update check.
	 *
	 * The flag is only recorded once the email was accepted, so a failed send is retried next run.
	 *
	 * @return void
	 */
	public function maybe_send_email(): void {
		if ( ! $this->applies() ) {
			return;
		}
		$state = $this->state->get();
		if ( $state['slack_notice_sent'] ) {
			return;
		}

		$to = $this->email_recipients();
		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		$sent = wp_mail( $to, __( 'WP Updates Notifier: Slack notifications are no longer supported', 'wp-updates-notifier' ), $this->message() );
		if ( $sent ) {
			$state['slack_notice_sent'] = true;
			$this->state->save( $state );
		}
	}

	/**
	 * The URL that dismisses the settings page notice for the current user.
	 *
	 * @return string
	 */
	public function dismiss_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
	}

	/**
	 * Whether the current user dismissed the settings page notice.
	 *
	 * @return bool
	 */
	private function is_dismissed(): bool {
		return '' !== get_user_meta( get_current_user_id(), self::DISMISS_META, true );
	}

	/**
	 * Output the notice inside the plugin's settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->applies() || $this->is_dismissed() ) {
			return;
		}
		?>
		<div class="notice notice-warning sc-wpun-slack-notice">
			<p><strong><?php esc_html_e( 'Slack notifications are no longer supported', 'wp-updates-notifier' ); ?></strong></p>
			<?php foreach ( explode( "\n\n", $this->message() ) as $paragraph ) : ?>
				<p><?php echo esc_html( $paragraph ); ?></p>
			<?php endforeach; ?>
			<p><a href="<?php echo esc_url( $this->dismiss_url() ); ?>"><?php esc_html_e( 'Dismiss this notice', 'wp-updates-notifier' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Check a dismiss request and remember it for the current user.
	 *
	 * @return bool False if the user may not manage options or the nonce is not valid.
	 */
	public function dismiss_request(): bool {
		if ( ! current_user_can( Settings_Page::capability() ) || ! isset( $_GET['_wpnonce'] ) || ! is_string( $_GET['_wpnonce'] ) ) {
			return false;
		}
		if ( false === wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::DISMISS_ACTION ) ) {
			return false;
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, '1' );

		return true;
	}

	/**
	 * Handle the dismiss link (admin-post.php), then go back to the settings page.
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		if ( ! $this->dismiss_request() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-updates-notifier' ), '', array( 'response' => 403 ) );
		}

		wp_safe_redirect( $this->settings_url() );
		exit;
	}

	/**
	 * Add the Site Health test while the notice applies.
	 *
	 * @param mixed $tests Site Health tests.
	 *
	 * @return mixed
	 */
	public function add_site_health_test( $tests ) {
		if ( is_array( $tests ) && $this->applies() ) {
			$tests['direct']['sc_wpun_slack'] = array(
				'label' => __( 'Slack notifications are no longer supported by WP Updates Notifier', 'wp-updates-notifier' ),
				'test'  => array( $this, 'site_health_result' ),
			);
		}

		return $tests;
	}

	/**
	 * The Site Health test result.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health_result(): array {
		return array(
			'label'       => __( 'Slack notifications are no longer supported by WP Updates Notifier', 'wp-updates-notifier' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Updates Notifier', 'wp-updates-notifier' ),
				'color' => 'orange',
			),
			'description' => '<p>' . esc_html( __( 'Slack notifications are not supported in this version of WP Updates Notifier, so none are sent. Your Slack settings have been kept.', 'wp-updates-notifier' ) ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $this->settings_url() ),
				esc_html__( 'Review the notification settings', 'wp-updates-notifier' )
			),
			'test'        => 'sc_wpun_slack',
		);
	}
}
