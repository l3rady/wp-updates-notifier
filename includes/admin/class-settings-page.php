<?php
/**
 * The settings page.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Channel_Instance;
use WPUN\Channel_Store;
use WPUN\Notifier;
use WPUN\Scheduler;
use WPUN\Settings;
use WPUN\Slack_Notice;
use WPUN\State;

/**
 * The settings page under Settings, its form fields and the validation of what is saved.
 *
 * @internal
 */
class Settings_Page {
	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Run state.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Notifier.
	 *
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * Channel instance storage, where the email settings are kept.
	 *
	 * @var Channel_Store
	 */
	private $channels;

	/**
	 * The notice about Slack no longer being supported.
	 *
	 * @var Slack_Notice
	 */
	private $slack_notice;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings  Settings.
	 * @param State         $state     Run state.
	 * @param Scheduler     $scheduler Scheduler.
	 * @param Notifier      $notifier  Notifier.
	 * @param Channel_Store $channels  Channel instance storage.
	 * @param Slack_Notice  $slack_notice Notice about Slack no longer being supported.
	 */
	public function __construct( Settings $settings, State $state, Scheduler $scheduler, Notifier $notifier, Channel_Store $channels, Slack_Notice $slack_notice ) {
		$this->settings     = $settings;
		$this->state        = $state;
		$this->scheduler    = $scheduler;
		$this->notifier     = $notifier;
		$this->channels     = $channels;
		$this->slack_notice = $slack_notice;
	}

	/**
	 * A setting of the email instance, as a string.
	 *
	 * @param string $id  Instance id.
	 * @param string $key Setting name.
	 *
	 * @return string Empty if there is no such instance or setting.
	 */
	private function instance_setting( string $id, string $key ): string {
		$instance = $this->channels->get( $id );
		$value    = null === $instance ? null : $instance->get_setting( $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether the email instance exists and is switched on.
	 *
	 * @param string $id Instance id.
	 *
	 * @return bool
	 */
	private function instance_enabled( string $id ): bool {
		$instance = $this->channels->get( $id );

		return null !== $instance && $instance->is_enabled();
	}

	/**
	 * Store the accepted values of the email form fields in the instance, creating it if needed.
	 *
	 * @param string               $id       Instance id (also its type).
	 * @param string               $label    Label for a new instance.
	 * @param bool                 $enabled  Whether the instance is switched on.
	 * @param array<string, mixed> $settings Settings to store (other settings of the instance are kept).
	 * @param bool                 $create   Whether to create the instance if it does not exist.
	 *
	 * @return void
	 */
	private function save_instance( string $id, string $label, bool $enabled, array $settings, bool $create ): void {
		$instance = $this->channels->get( $id );

		if ( null === $instance ) {
			if ( ! $create ) {
				return;
			}
			$instance = new Channel_Instance( $id, $id, $label, false );
		}

		$this->channels->save( $instance->with_enabled( $enabled )->with_settings( array_merge( $instance->get_settings(), $settings ) ) );
	}

	/**
	 * Adds the settings link under the plugin on the plugin screen.
	 *
	 * @param array<mixed> $links Links to list on the plugin screen.
	 * @param string       $file Filename.
	 *
	 * @return array<mixed> $links
	 */
	public function plugin_action_links( $links, $file ) {
		if ( plugin_basename( SC_WPUN_FILE ) === $file ) {
			$settings_link = '<a href="' . admin_url( 'options-general.php?page=wp-updates-notifier' ) . '">' . __( 'Settings', 'wp-updates-notifier' ) . '</a>';
			array_unshift( $links, $settings_link );
		}
		return $links;
	}

	/**
	 * Add admin menu.
	 *
	 * @return void
	 */
	public function admin_settings_menu() {
		add_options_page( __( 'Updates Notifier', 'wp-updates-notifier' ), __( 'Updates Notifier', 'wp-updates-notifier' ), 'manage_options', 'wp-updates-notifier', array( $this, 'settings_page' ) );
	}

	/**
	 * Output settings page and trigger sending tests.
	 *
	 * @return void
	 */
	public function settings_page() {
		// Trigger tests if they are ready to be sent.
		$sc_wpun_send_test_email = get_transient( 'sc_wpun_send_test_email' );
		if ( $sc_wpun_send_test_email ) {
			delete_transient( 'sc_wpun_send_test_email' );
			$this->send_test_email();
		}

		$options    = $this->state->get();
		$next_check = wp_next_scheduled( Scheduler::CRON_NAME );
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Updates Notifier', 'wp-updates-notifier' ); ?></h2>

			<?php $this->slack_notice->render(); ?>

			<p>
				<span class="description sc-wpun-last-check">
				<?php
				if ( empty( $options['last_check_time'] ) ) {
					esc_html_e( 'Last checked: never', 'wp-updates-notifier' );
				} else {
					// translators: %s: Date and time of the last check.
					echo esc_html( sprintf( __( 'Last checked: %s', 'wp-updates-notifier' ), $this->format_site_time( $options['last_check_time'] ) ) );
				}
				?>
				</span>
				<br />
				<span class="description sc-wpun-next-check">
				<?php
				if ( false === $next_check ) {
					esc_html_e( 'Next check: not scheduled. Save the settings to schedule it.', 'wp-updates-notifier' );
				} else {
					// translators: %s: Date and time of the next check.
					echo esc_html( sprintf( __( 'Next check: %s', 'wp-updates-notifier' ), $this->format_site_time( $next_check ) ) );
				}
				?>
				</span>
			</p>

			<form action="<?php echo esc_attr( admin_url( 'options.php' ) ); ?>" method="post">
				<?php
				settings_fields( 'sc_wpun_settings' );
				do_settings_sections( 'wp-updates-notifier' );
				?>
				<p>&nbsp;</p>
				<input class="button-primary" name="Submit" type="submit" value="<?php esc_html_e( 'Save settings', 'wp-updates-notifier' ); ?>" />
				<input class="button" name="submitwithemail" type="submit" value="<?php esc_html_e( 'Save settings with test email', 'wp-updates-notifier' ); ?>" />
			</form>
		</div>
		<?php
	}

	/**
	 * A timestamp as a date and time in the site's timezone and formats.
	 *
	 * @param int $timestamp Unix timestamp.
	 *
	 * @return string
	 */
	private function format_site_time( int $timestamp ): string {
		return sprintf(
			// translators: 1: Date. 2: Time.
			__( '%1$s at %2$s', 'wp-updates-notifier' ),
			wp_date( (string) get_option( 'date_format' ), $timestamp ),
			wp_date( (string) get_option( 'time_format' ), $timestamp )
		);
	}

	/**
	 * Add all of the settings for the settings page.
	 *
	 * @return void
	 */
	public function admin_settings_init() {
		register_setting( Settings::OPT_FIELD, Settings::OPT_FIELD, array( $this, 'sc_wpun_settings_validate' ) ); // Register Settings.

		add_settings_section( 'sc_wpun_settings_main', __( 'Settings', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_text' ), 'wp-updates-notifier' ); // Make settings main section.
		add_settings_field( 'sc_wpun_settings_main_frequency', __( 'Frequency to check', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_frequency' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
		add_settings_field( 'sc_wpun_settings_main_notify_plugins', __( 'Notify about plugin updates?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_plugins' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
		add_settings_field( 'sc_wpun_settings_main_notify_themes', __( 'Notify about theme updates?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_themes' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
		add_settings_field( 'sc_wpun_settings_main_notify_automatic', __( 'Notify automatic core updates to this address?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_notify_automatic' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );
		add_settings_field( 'sc_wpun_settings_main_hide_updates', __( 'Hide the WordPress update notice from users who cannot update WordPress?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_main_field_hide_updates' ), 'wp-updates-notifier', 'sc_wpun_settings_main' );

		// Email notification settings.
		add_settings_section( 'sc_wpun_settings_email_notifications', __( 'Email Notifications', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_text' ), 'wp-updates-notifier' );
		add_settings_field( 'sc_wpun_settings_email_notifications_email_notifications', __( 'Send email notifications?', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_field_email_notifications' ), 'wp-updates-notifier', 'sc_wpun_settings_email_notifications' );
		add_settings_field( 'sc_wpun_settings_email_notifications_notify_to', __( 'Notify email to', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_field_notify_to' ), 'wp-updates-notifier', 'sc_wpun_settings_email_notifications' );
		add_settings_field( 'sc_wpun_settings_email_notifications_notify_from', __( 'Notify email from', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_field_notify_from' ), 'wp-updates-notifier', 'sc_wpun_settings_email_notifications' );
	}

	/**
	 * Validate and sanitize all of the settings from the page form.
	 *
	 * @param array<mixed> $input Array of unsanitized options from the page form.
	 *
	 * @return array<mixed> Array of sanitized and validated settings.
	 */
	public function sc_wpun_settings_validate( $input ) {
		// Runs for every update_option() of the settings once admin_init has
		// registered them, not only for the settings form (options.php checks
		// the form's nonce itself). Start from the stored settings.
		$valid = $this->settings->get();

		if ( ! is_array( $input ) ) {
			return $valid;
		}

		// Validate main settings.
		$frequency = $input['frequency'] ?? ( $valid['frequency'] ?? '' );
		if ( array_key_exists( $frequency, $this->scheduler->get_frequencies() ) ) {
			$valid['frequency'] = $frequency;
		} else {
			add_settings_error( 'sc_wpun_settings_main_frequency', 'sc_wpun_settings_main_frequency_error', __( 'Invalid frequency entered', 'wp-updates-notifier' ), 'error' );
		}

		$sanitized_notify_plugins = absint( $input['notify_plugins'] ?? 0 );
		if ( $sanitized_notify_plugins <= 2 ) {
			$valid['notify_plugins'] = $sanitized_notify_plugins;
		} else {
			add_settings_error( 'sc_wpun_settings_main_notify_plugins', 'sc_wpun_settings_main_notify_plugins_error', __( 'Invalid plugin updates value entered', 'wp-updates-notifier' ), 'error' );
		}

		$sanitized_notify_themes = absint( $input['notify_themes'] ?? 0 );
		if ( $sanitized_notify_themes <= 2 ) {
			$valid['notify_themes'] = $sanitized_notify_themes;
		} else {
			add_settings_error( 'sc_wpun_settings_main_notify_themes', 'sc_wpun_settings_main_notify_themes_error', __( 'Invalid theme updates value entered', 'wp-updates-notifier' ), 'error' );
		}

		$sanitized_notify_automatic = absint( $input['notify_automatic'] ?? 0 );
		if ( $sanitized_notify_automatic <= 1 ) {
			$valid['notify_automatic'] = $sanitized_notify_automatic;
		} else {
			add_settings_error( 'sc_wpun_settings_main_notify_automatic', 'sc_wpun_settings_main_notify_automatic_error', __( 'Invalid automatic updates value entered', 'wp-updates-notifier' ), 'error' );
		}

		$sanitized_hide_updates = absint( $input['hide_updates'] ?? 0 );
		if ( $sanitized_hide_updates <= 1 ) {
			$valid['hide_updates'] = $sanitized_hide_updates;
		} else {
			add_settings_error( 'sc_wpun_settings_main_hide_updates', 'sc_wpun_settings_main_hide_updates_error', __( 'Invalid hide updates value entered', 'wp-updates-notifier' ), 'error' );
		}

		// Validate email notification settings. They start from what the email instance holds.
		$notify_to   = $this->instance_setting( 'email', 'to' );
		$notify_from = $this->instance_setting( 'email', 'from' );

		if ( ! empty( $input['notify_to'] ) && is_string( $input['notify_to'] ) ) {
			$sanitized_emails = array();
			$was_error        = false;
			foreach ( explode( ',', $input['notify_to'] ) as $email_to ) {
				$address = sanitize_email( trim( $email_to ) );
				if ( ! is_email( $address ) ) {
					add_settings_error( 'sc_wpun_settings_email_notifications_notify_to', 'sc_wpun_settings_email_notifications_notify_to_error', __( 'One or more email to addresses are invalid', 'wp-updates-notifier' ), 'error' );
					$was_error = true;
					break;
				}
				$sanitized_emails[] = $address;
			}
			if ( ! $was_error ) {
				$notify_to = implode( ',', $sanitized_emails );
			}
		} else {
			$notify_to = '';
		}

		if ( ! empty( $input['notify_from'] ) && is_string( $input['notify_from'] ) ) {
			$sanitized_email_from = sanitize_email( $input['notify_from'] );
			if ( is_email( $sanitized_email_from ) ) {
				$notify_from = $sanitized_email_from;
			} else {
				add_settings_error( 'sc_wpun_settings_email_notifications_notify_from', 'sc_wpun_settings_email_notifications_notify_from_error', __( 'Invalid email from entered', 'wp-updates-notifier' ), 'error' );
			}
		} else {
			$notify_from = '';
		}

		$email_notifications = absint( $input['email_notifications'] ?? 0 );
		if ( 1 < $email_notifications ) {
			add_settings_error( 'sc_wpun_settings_email_notifications_email_notifications', 'sc_wpun_settings_email_notifications_email_notifications_error', __( 'Invalid notification email value entered', 'wp-updates-notifier' ), 'error' );
		}

		if ( 1 === $email_notifications ) {
			if ( ! empty( $notify_to ) && ! empty( $notify_from ) ) {
				$email_notifications = 1;
			} else {
				add_settings_error( 'sc_wpun_settings_email_notifications_email_notifications', 'sc_wpun_settings_email_notifications_email_notifications_error', __( 'Can not enable email notifications, addresses are not valid', 'wp-updates-notifier' ), 'error' );
				$email_notifications = 0;
			}
		}

		if ( array_key_exists( 'notify_to', $input ) || array_key_exists( 'notify_from', $input ) || array_key_exists( 'email_notifications', $input ) ) {
			$this->save_instance(
				'email',
				'Email',
				1 === $email_notifications,
				array(
					'to'   => $notify_to,
					'from' => $notify_from,
				),
				true
			);
		}

		// Test sends are only queued from the settings form itself (see #67).
		if ( ! $this->is_settings_form_submission() ) {
			return $valid;
		}

		if ( isset( $_POST['submitwithemail'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in is_settings_form_submission().
			if ( '' !== $notify_to && '' !== $notify_from ) {
				set_transient( 'sc_wpun_send_test_email', 1 );
			} else {
				add_settings_error( 'sc_wpun_settings_email_notifications_email_notifications', 'sc_wpun_settings_email_notifications_email_notifications_error', __( 'Can not send test email. Email settings are invalid.', 'wp-updates-notifier' ), 'error' );
			}
		}

		return $valid;
	}

	/**
	 * Whether this request is the plugin's settings form being saved through options.php.
	 *
	 * @return bool
	 */
	private function is_settings_form_submission(): bool {
		if ( ! isset( $_POST['option_page'], $_POST['_wpnonce'] ) || Settings::OPT_FIELD !== $_POST['option_page'] ) {
			return false;
		}

		return false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), Settings::OPT_FIELD . '-options' );
	}

	/**
	 * Send a test email.
	 *
	 * @return void
	 */
	public function send_test_email() {
		$this->notifier->send_email_message( __( 'This is a test message from WP Updates Notifier.', 'wp-updates-notifier' ) );
	}

	/**
	 * Output the text at the top of the main settings section (function is required even if it outputs nothing).
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_text() {
	}

	/**
	 * Settings field for frequency.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_frequency() {
		$options     = $this->settings->get();
		$frequencies = $this->scheduler->get_frequencies();
		$unavailable = ! array_key_exists( $options['frequency'], $frequencies );
		?>
		<select id="sc_wpun_settings_main_frequency" name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[frequency]">
		<?php if ( $unavailable ) : ?>
			<option value="<?php echo esc_attr( $options['frequency'] ); ?>" selected="selected">
				<?php
				// translators: %s: Name of the saved schedule.
				echo esc_html( sprintf( __( '%s (no longer available)', 'wp-updates-notifier' ), $options['frequency'] ) );
				?>
			</option>
		<?php endif; ?>
		<?php foreach ( $frequencies as $k => $v ) : ?>
			<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $options['frequency'], $k ); ?>><?php echo esc_html( $v['display'] ); ?></option>
		<?php endforeach; ?>
		</select>
		<?php if ( $unavailable ) : ?>
			<p class="description sc-wpun-frequency-unavailable">
				<?php esc_html_e( 'The saved frequency is no longer available on this site, so checks run at the interval they were last scheduled with. Choose a frequency and save to change it.', 'wp-updates-notifier' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Settings field for notify plugins.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_notify_plugins() {
		$options = $this->settings->get();
		?>
		<label><input name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="0" <?php checked( $options['notify_plugins'], 0 ); ?> /> <?php esc_html_e( 'No', 'wp-updates-notifier' ); ?>
		</label><br />
		<label><input name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="1" <?php checked( $options['notify_plugins'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
		</label><br />
		<label><input name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_plugins]" type="radio" value="2" <?php checked( $options['notify_plugins'], 2 ); ?> /> <?php esc_html_e( 'Yes but only active plugins', 'wp-updates-notifier' ); ?>
		</label>
		<?php
	}

	/**
	 * Settings field for notify themes.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_notify_themes() {
		$options = $this->settings->get();
		?>
		<label><input name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_themes]" type="radio" value="0" <?php checked( $options['notify_themes'], 0 ); ?> /> <?php esc_html_e( 'No', 'wp-updates-notifier' ); ?>
		</label><br />
		<label><input name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_themes]" type="radio" value="1" <?php checked( $options['notify_themes'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
		</label><br />
		<label><input name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_themes]" type="radio" value="2" <?php checked( $options['notify_themes'], 2 ); ?> /> <?php esc_html_e( 'Yes but only active themes', 'wp-updates-notifier' ); ?>
		</label>
		<?php
	}

	/**
	 * Settings field for notify automatic.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_notify_automatic() {
		$options = $this->settings->get();
		?>
		<label><input name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_automatic]" type="checkbox" value="1" <?php checked( $options['notify_automatic'], 1 ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
		</label>
		<?php
	}

	/**
	 * Settings field for hiding updates.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_hide_updates() {
		$options = $this->settings->get();
		?>
		<select id="sc_wpun_settings_main_hide_updates" name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[hide_updates]">
			<option value="1" <?php selected( $options['hide_updates'], 1 ); ?>><?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?></option>
			<option value="0" <?php selected( $options['hide_updates'], 0 ); ?>><?php esc_html_e( 'No', 'wp-updates-notifier' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Output the text at the top of the email settings section (function is required even if it outputs nothing).
	 *
	 * @return void
	 */
	public function sc_wpun_settings_email_notifications_text() {
	}

	/**
	 * Settings field for email notifications.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_email_notifications_field_email_notifications() {
		?>
		<label><input name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[email_notifications]" type="checkbox" value="1" <?php checked( $this->instance_enabled( 'email' ) ); ?> /> <?php esc_html_e( 'Yes', 'wp-updates-notifier' ); ?>
		</label>
		<?php
	}

	/**
	 * Settings field for email to field.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_email_notifications_field_notify_to() {
		?>
		<input id="sc_wpun_settings_email_notifications_notify_to" class="regular-text" name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_to]" value="<?php echo esc_attr( $this->instance_setting( 'email', 'to' ) ); ?>" />
		<span class="description"><?php esc_html_e( 'Separate multiple email address with a comma (,)', 'wp-updates-notifier' ); ?></span>
		<?php
	}

	/**
	 * Settings field for email from field.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_email_notifications_field_notify_from() {
		?>
		<input id="sc_wpun_settings_email_notifications_notify_from" class="regular-text" name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_from]" value="<?php echo esc_attr( $this->instance_setting( 'email', 'from' ) ); ?>" />
		<?php
	}
}
