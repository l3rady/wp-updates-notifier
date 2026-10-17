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

use WPUN\Boolean_Field;
use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Channel_Store;
use WPUN\Config_Report;
use WPUN\Delivery_Result;
use WPUN\Dispatcher;
use WPUN\Enum_Field;
use WPUN\Notification;
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
	const TEST_ACTION      = 'sc_wpun_send_test';
	const TEST_RESULT_META = 'sc_wpun_test_result';

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
	 * Dispatcher, for the test message.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Report of configuration problems.
	 *
	 * @var Config_Report
	 */
	private $config_report;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings  Settings.
	 * @param State         $state     Run state.
	 * @param Notifier      $notifier  Notifier.
	 * @param Channel_Store $channels  Channel instance storage.
	 * @param Slack_Notice  $slack_notice Notice about Slack no longer being supported.
	 * @param Dispatcher    $dispatcher Dispatcher.
	 * @param Config_Report $config_report Report of configuration problems.
	 */
	public function __construct( Settings $settings, State $state, Notifier $notifier, Channel_Store $channels, Slack_Notice $slack_notice, Dispatcher $dispatcher, Config_Report $config_report ) {
		$this->settings      = $settings;
		$this->state         = $state;
		$this->notifier      = $notifier;
		$this->channels      = $channels;
		$this->slack_notice  = $slack_notice;
		$this->dispatcher    = $dispatcher;
		$this->config_report = $config_report;
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
		if ( $this->channels->is_code_defined( $id ) ) {
			return; // Read-only: defined in code.
		}

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
	 * The capability needed to change the settings, send a test and dismiss notices.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to use the plugin's settings page.
		 *
		 * It decides who sees the settings page, saves the settings, sends a test message
		 * and dismisses the plugin's notice. An empty or non-string value is ignored.
		 *
		 * Example, let editors manage the plugin:
		 *
		 *     add_filter( 'sc_wpun_capability', fn() => 'edit_pages' );
		 *
		 * @since 2.0.0
		 *
		 * @param string $capability Capability. Default `manage_options`.
		 */
		$capability = apply_filters( 'sc_wpun_capability', 'manage_options' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Adds the settings link under the plugin on the plugin screen.
	 *
	 * @param array<mixed> $links Links to list on the plugin screen.
	 * @param string|null  $file Plugin file the links belong to. Null when the filter is specific to this plugin.
	 *
	 * @return array<mixed> $links
	 */
	public function plugin_action_links( $links, $file = null ) {
		if ( null !== $file && plugin_basename( SC_WPUN_FILE ) !== $file ) {
			return $links;
		}
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=wp-updates-notifier' ) ) . '">' . esc_html__( 'Settings', 'wp-updates-notifier' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Add admin menu.
	 *
	 * @return void
	 */
	public function admin_settings_menu() {
		add_options_page( __( 'Updates Notifier', 'wp-updates-notifier' ), __( 'Updates Notifier', 'wp-updates-notifier' ), self::capability(), 'wp-updates-notifier', array( $this, 'settings_page' ) );
	}

	/**
	 * The tabs of the settings screen, as slug => label.
	 *
	 * A tab is listed only if something draws it, so a built-in tab nothing hooks stays hidden.
	 * The General tab is always first.
	 *
	 * @return array<string, string>
	 */
	public function tabs(): array {
		$defaults = array(
			'general'  => __( 'General', 'wp-updates-notifier' ),
			'channels' => __( 'Channels', 'wp-updates-notifier' ),
		);

		/**
		 * Filters the tabs of the settings screen.
		 *
		 * A tab is shown only if something is hooked on `sc_wpun_settings_tab_{$tab}` to draw it. Entries
		 * whose slug is not a string equal to its `sanitize_key()` form, or whose label is not a string,
		 * are ignored. The General tab can not be removed.
		 *
		 * Example, add a tab:
		 *
		 *     add_filter( 'sc_wpun_settings_tabs', fn( $tabs ) => $tabs + array( 'mine' => 'Mine' ) );
		 *     add_action( 'sc_wpun_settings_tab_mine', fn() => print '<p>Hello</p>' );
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, string> $tabs Tab labels keyed by slug. Default `general` and `channels`.
		 */
		$filtered = apply_filters( 'sc_wpun_settings_tabs', $defaults );

		$tabs = array();
		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $slug => $label ) {
				if ( is_string( $slug ) && '' !== $slug && sanitize_key( $slug ) === $slug && is_string( $label ) ) {
					$tabs[ $slug ] = $label;
				}
			}
		}
		if ( ! isset( $tabs['general'] ) ) {
			$tabs = array( 'general' => $defaults['general'] ) + $tabs;
		}

		// General comes first and is always there; the others need something to draw them.
		$visible = array( 'general' => $tabs['general'] );
		foreach ( $tabs as $slug => $label ) {
			if ( 'general' !== $slug && has_action( "sc_wpun_settings_tab_{$slug}" ) ) {
				$visible[ $slug ] = $label;
			}
		}

		return $visible;
	}

	/**
	 * The tab being shown: the requested one if it is listed, else General.
	 *
	 * @param array<string, string> $tabs Visible tabs.
	 *
	 * @return string
	 */
	private function current_tab( array $tabs ): string {
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing what to display, nothing is changed.

		return isset( $tabs[ $tab ] ) ? $tab : 'general';
	}

	/**
	 * The address of a tab.
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return string
	 */
	private function tab_url( string $tab ): string {
		return admin_url( 'options-general.php?page=wp-updates-notifier&tab=' . rawurlencode( $tab ) );
	}

	/**
	 * Output the settings page.
	 *
	 * @return void
	 */
	public function settings_page() {
		// Drawn like any other tab. Added here, not only at boot, so the page works however it is reached.
		add_action( 'sc_wpun_settings_tab_general', array( $this, 'render_general_tab' ) );

		$test_result = $this->take_test_result();
		$tabs        = $this->tabs();
		$current     = $this->current_tab( $tabs );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Updates Notifier', 'wp-updates-notifier' ); ?></h1>

			<?php $this->slack_notice->render(); ?>
			<?php $this->config_report->render(); ?>
			<?php $this->render_test_result( $test_result ); ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'wp-updates-notifier' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			/**
			 * Draws a tab of the settings screen.
			 *
			 * The dynamic part is the tab's slug. Add the tab with the `sc_wpun_settings_tabs` filter;
			 * it is listed only while something is hooked here. Output inside the screen's wrapper.
			 *
			 * @since 2.0.0
			 */
			do_action( "sc_wpun_settings_tab_{$current}" );
			?>
		</div>
		<?php
	}

	/**
	 * Output the General tab: when the check runs, the settings and the test message.
	 *
	 * @return void
	 */
	public function render_general_tab(): void {
		$options    = $this->state->get();
		$next_check = wp_next_scheduled( Scheduler::CRON_NAME );
		?>
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
			// Saving goes back to the page it was sent from, which is this tab: the tab links carry `tab=general`.
			settings_fields( 'sc_wpun_settings' );
			do_settings_sections( 'wp-updates-notifier' );
			?>
			<p>&nbsp;</p>
			<input class="button-primary" name="Submit" type="submit" value="<?php esc_html_e( 'Save settings', 'wp-updates-notifier' ); ?>" />
		</form>

		<form action="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>" />
			<input type="hidden" name="instance" value="email" />
			<?php wp_nonce_field( self::TEST_ACTION ); ?>
			<p>&nbsp;</p>
			<input class="button" type="submit" value="<?php esc_attr_e( 'Send test email', 'wp-updates-notifier' ); ?>" />
			<span class="description"><?php esc_html_e( 'Sends a test message using the saved settings.', 'wp-updates-notifier' ); ?></span>
		</form>
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
		foreach ( $this->settings->schemas() as $name => $schema ) {
			$id   = 'sc_wpun_settings_main_' . $name;
			$args = array( 'name' => $name );
			// A checkbox has its own label and radios have a legend, so only a select is labelled by the row title.
			if ( $this->is_select( $schema ) ) {
				$args['label_for'] = $id;
			}
			add_settings_field( $id, isset( $schema['label'] ) && is_string( $schema['label'] ) ? $schema['label'] : $name, array( $this, 'render_setting' ), 'wp-updates-notifier', 'sc_wpun_settings_main', $args );
		}

		// Email notification settings.
		add_settings_section( 'sc_wpun_settings_email_notifications', __( 'Email Notifications', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_text' ), 'wp-updates-notifier' );
		add_settings_field( 'sc_wpun_settings_email_notifications_email_notifications', $this->email_enabled_schema()['label'], array( $this, 'sc_wpun_settings_email_notifications_field_email_notifications' ), 'wp-updates-notifier', 'sc_wpun_settings_email_notifications' );
		add_settings_field( 'sc_wpun_settings_email_notifications_notify_to', __( 'Notify email to', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_field_notify_to' ), 'wp-updates-notifier', 'sc_wpun_settings_email_notifications' );
		add_settings_field( 'sc_wpun_settings_email_notifications_notify_from', __( 'Notify email from', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_field_notify_from' ), 'wp-updates-notifier', 'sc_wpun_settings_email_notifications' );
		add_settings_field( 'sc_wpun_settings_email_notifications_notify_reply_to', __( 'Reply-To email', 'wp-updates-notifier' ), array( $this, 'sc_wpun_settings_email_notifications_field_notify_reply_to' ), 'wp-updates-notifier', 'sc_wpun_settings_email_notifications' );
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
		$valid = $this->settings->stored(); // Never the effective settings: a value supplied from outside the database is not written to it.

		if ( ! is_array( $input ) ) {
			return $valid;
		}

		// Validate main settings with their field types. A missing value keeps the stored one: a read-only setting is not submitted.
		$messages = array(
			'frequency'        => __( 'Invalid frequency entered', 'wp-updates-notifier' ),
			'notify_plugins'   => __( 'Invalid plugin updates value entered', 'wp-updates-notifier' ),
			'notify_themes'    => __( 'Invalid theme updates value entered', 'wp-updates-notifier' ),
			'notify_automatic' => __( 'Invalid automatic updates value entered', 'wp-updates-notifier' ),
			'hide_updates'     => __( 'Invalid hide updates value entered', 'wp-updates-notifier' ),
		);
		foreach ( $this->settings->schemas() as $name => $schema ) {
			if ( ! array_key_exists( $name, $input ) ) {
				continue;
			}
			$checked = Boolean_Field::is_boolean( $schema ) ? Boolean_Field::sanitize( $input[ $name ], $schema ) : Enum_Field::sanitize( $input[ $name ], $schema );
			if ( $checked['ok'] ) {
				$valid[ $name ] = $checked['value'];
			} else {
				add_settings_error( 'sc_wpun_settings_main_' . $name, 'sc_wpun_settings_main_' . $name . '_error', $messages[ $name ] ?? $name, 'error' );
			}
		}

		// A setting supplied from outside the database keeps its stored value, whatever was submitted.
		$stored = $this->settings->stored();
		foreach ( array_keys( $stored ) as $name ) {
			if ( null !== $this->settings->external_source( $name ) ) {
				$valid[ $name ] = $stored[ $name ];
			}
		}

		// Whether the email section was submitted, from what was actually submitted.
		$email_submitted = array_key_exists( 'notify_to', $input ) || array_key_exists( 'notify_from', $input ) || array_key_exists( 'notify_reply_to', $input ) || array_key_exists( 'email_notifications', $input );

		// Email fields supplied from outside the database ignore what was submitted: they are not checked, and Channel_Store::save() never writes them.
		$external_to       = null !== $this->channels->external_source( 'email', 'to' );
		$external_from     = null !== $this->channels->external_source( 'email', 'from' );
		$external_reply_to = null !== $this->channels->external_source( 'email', 'reply_to' );
		$external_enabled  = null !== $this->channels->external_source( 'email', 'enabled' );

		// Validate email notification settings. They start from what the email instance holds.
		$notify_to       = $this->instance_setting( 'email', 'to' );
		$notify_from     = $this->instance_setting( 'email', 'from' );
		$notify_reply_to = $this->instance_setting( 'email', 'reply_to' );

		if ( $external_to ) {
			$notify_to = $this->instance_setting( 'email', 'to' );
		} elseif ( ! empty( $input['notify_to'] ) && is_string( $input['notify_to'] ) ) {
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

		if ( ! $external_reply_to && array_key_exists( 'notify_reply_to', $input ) ) {
			$notify_reply_to = '';
			if ( ! empty( $input['notify_reply_to'] ) && is_string( $input['notify_reply_to'] ) ) {
				$sanitized_reply_to = sanitize_email( $input['notify_reply_to'] );
				if ( is_email( $sanitized_reply_to ) ) {
					$notify_reply_to = $sanitized_reply_to;
				} else {
					$notify_reply_to = $this->instance_setting( 'email', 'reply_to' );
					add_settings_error( 'sc_wpun_settings_email_notifications_notify_reply_to', 'sc_wpun_settings_email_notifications_notify_reply_to_error', __( 'Invalid reply-to email entered', 'wp-updates-notifier' ), 'error' );
				}
			}
		}

		if ( $external_from ) {
			$notify_from = $this->instance_setting( 'email', 'from' );
		} elseif ( ! empty( $input['notify_from'] ) && is_string( $input['notify_from'] ) ) {
			$sanitized_email_from = sanitize_email( $input['notify_from'] );
			if ( is_email( $sanitized_email_from ) ) {
				$notify_from = $sanitized_email_from;
			} else {
				add_settings_error( 'sc_wpun_settings_email_notifications_notify_from', 'sc_wpun_settings_email_notifications_notify_from_error', __( 'Invalid email from entered', 'wp-updates-notifier' ), 'error' );
			}
		} else {
			$notify_from = '';
		}

		// Missing keeps the current state: the form submits a hidden 0 for an unticked box.
		$email_notifications = $this->instance_enabled( 'email' );
		if ( ! $external_enabled && array_key_exists( 'email_notifications', $input ) ) {
			$checked = Boolean_Field::sanitize( $input['email_notifications'], $this->email_enabled_schema() );
			if ( ! $checked['ok'] ) {
				add_settings_error( 'sc_wpun_settings_email_notifications_email_notifications', 'sc_wpun_settings_email_notifications_email_notifications_error', __( 'Invalid notification email value entered', 'wp-updates-notifier' ), 'error' );
			} elseif ( $checked['value'] && empty( $notify_to ) ) {
				add_settings_error( 'sc_wpun_settings_email_notifications_email_notifications', 'sc_wpun_settings_email_notifications_email_notifications_error', __( 'Can not enable email notifications, enter a valid address to send to', 'wp-updates-notifier' ), 'error' );
				$email_notifications = false;
			} else {
				$email_notifications = $checked['value'];
			}
		}

		if ( $email_submitted ) {
			$this->save_instance(
				'email',
				'Email',
				$email_notifications,
				array(
					'to'       => $notify_to,
					'from'     => $notify_from,
					'reply_to' => $notify_reply_to,
				),
				true
			);
		}

		return $valid;
	}

	/**
	 * Check a test send request, send the test and remember the result for the current user.
	 *
	 * @return bool False if the user may not manage options or the nonce is not valid.
	 */
	public function process_test_send(): bool {
		if ( ! current_user_can( self::capability() ) || ! isset( $_POST['_wpnonce'] ) ) {
			return false;
		}
		if ( false === wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), self::TEST_ACTION ) ) {
			return false;
		}

		$id       = isset( $_POST['instance'] ) && is_string( $_POST['instance'] ) ? sanitize_key( wp_unslash( $_POST['instance'] ) ) : '';
		$instance = $this->channels->get( $id );

		if ( null === $instance ) {
			$label  = $id;
			$result = Delivery_Result::failure( __( 'There is no such channel.', 'wp-updates-notifier' ), 'unknown_instance' );
		} else {
			$label        = $instance->get_label();
			$notification = new Notification(
				get_bloginfo(),
				home_url(),
				admin_url( 'update-core.php' ),
				array(),
				array(
					'is_test' => true,
					'source'  => 'test',
				)
			);
			$result       = $this->dispatcher->send_one( $notification, $instance );
		}

		// Kept in user meta, so only this user sees it; the site is recorded because user meta is shared by a network.
		update_user_meta(
			get_current_user_id(),
			self::TEST_RESULT_META,
			array(
				'success' => $result->is_success(),
				'message' => $result->get_message(),
				'label'   => $label,
				'site'    => get_current_blog_id(),
				'time'    => time(),
			)
		);

		return true;
	}

	/**
	 * Handle the "Send test email" form (admin-post.php), then go back to the settings page.
	 *
	 * @return void
	 */
	public function handle_test_send(): void {
		if ( ! $this->process_test_send() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-updates-notifier' ), '', array( 'response' => 403 ) );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=wp-updates-notifier' ) );
		exit;
	}

	/**
	 * Read and delete the result of the current user's last test send.
	 *
	 * @return array{success: bool, message: string, label: string}|null
	 */
	private function take_test_result(): ?array {
		$user_id = get_current_user_id();
		$stored  = get_user_meta( $user_id, self::TEST_RESULT_META, true );
		if ( ! is_array( $stored ) || ( $stored['site'] ?? 0 ) !== get_current_blog_id() ) {
			return null; // None, or it belongs to another site of the network and is shown there.
		}
		delete_user_meta( $user_id, self::TEST_RESULT_META );
		if ( ! isset( $stored['time'] ) || ! is_int( $stored['time'] ) || $stored['time'] < time() - 5 * MINUTE_IN_SECONDS ) {
			return null; // Too old to still be about the last click.
		}

		return array(
			'success' => ! empty( $stored['success'] ),
			'message' => isset( $stored['message'] ) && is_string( $stored['message'] ) ? $stored['message'] : '',
			'label'   => isset( $stored['label'] ) && is_string( $stored['label'] ) ? $stored['label'] : '',
		);
	}

	/**
	 * Output the result of a test send as a notice.
	 *
	 * @param array{success: bool, message: string, label: string}|null $result The result, if there is one.
	 *
	 * @return void
	 */
	private function render_test_result( ?array $result ): void {
		if ( null === $result ) {
			return;
		}
		if ( $result['success'] ) {
			// translators: %s: Name of the channel.
			$text = sprintf( __( 'Test message sent through %s.', 'wp-updates-notifier' ), $result['label'] );
		} else {
			// translators: 1: Name of the channel. 2: Why it failed.
			$text = sprintf( __( 'The test message could not be sent through %1$s: %2$s', 'wp-updates-notifier' ), $result['label'], $result['message'] );
		}
		?>
		<div class="notice <?php echo esc_attr( $result['success'] ? 'notice-success' : 'notice-error' ); ?> sc-wpun-test-result"><p><?php echo esc_html( $text ); ?></p></div>
		<?php
	}

	/**
	 * Send a test email.
	 *
	 * @return void
	 */
	public function send_test_email() {
		$this->notifier->send_legacy_message( __( 'This is a test message from WP Updates Notifier.', 'wp-updates-notifier' ) );
	}

	/**
	 * Whether an email field cannot be changed here: it is supplied from outside the database, or the whole instance is defined in code.
	 *
	 * @param string $field Field name, or `enabled`.
	 *
	 * @return bool
	 */
	private function email_read_only( string $field ): bool {
		return $this->channels->is_code_defined( 'email' ) || null !== $this->channels->external_source( 'email', $field );
	}

	/**
	 * Say why an email field is read-only.
	 *
	 * @param string $field Field name, or `enabled`.
	 *
	 * @return void
	 */
	private function email_note( string $field ): void {
		if ( $this->channels->is_code_defined( 'email' ) ) {
			echo ' <span class="description sc-wpun-external">' . esc_html__( "This channel is defined in code and can't be changed here.", 'wp-updates-notifier' ) . '</span>';
			return;
		}
		$source = $this->channels->external_source( 'email', $field );
		if ( null !== $source ) {
			// translators: %s: Where the value comes from, for example "constant SC_WPUN_CHANNEL_EMAIL__TO".
			echo ' <span class="description sc-wpun-external">' . esc_html( sprintf( __( 'Set by %s', 'wp-updates-notifier' ), $source ) ) . '</span>';
		}
	}

	/**
	 * The `value` attribute of a field shown read-only, which never holds the value of a secret.
	 *
	 * @internal
	 *
	 * @param array<string, mixed> $schema Field schema: a field of type `secret`, or with `secret` set, shows no value.
	 * @param string               $value  The effective value.
	 *
	 * @return string The attribute, ready to output, or an empty string.
	 */
	public function value_attribute( array $schema, string $value ): string {
		if ( ( isset( $schema['type'] ) && 'secret' === $schema['type'] ) || ! empty( $schema['secret'] ) ) {
			return '';
		}

		return 'value="' . esc_attr( $value ) . '"';
	}

	/**
	 * Whether a setting is supplied from outside the database, so its field is read-only.
	 *
	 * @param string $name Setting name.
	 *
	 * @return bool
	 */
	private function is_external( string $name ): bool {
		return null !== $this->settings->external_source( $name );
	}

	/**
	 * Say where an externally supplied setting comes from. Settings are not secrets, so the field shows the value.
	 *
	 * @param string $name Setting name.
	 *
	 * @return void
	 */
	private function external_note( string $name ): void {
		$source = $this->settings->external_source( $name );
		if ( null !== $source ) {
			// translators: %s: Where the value comes from, for example "constant SC_WPUN_SETTING_FREQUENCY".
			echo ' <span class="description sc-wpun-external">' . esc_html( sprintf( __( 'Set by %s', 'wp-updates-notifier' ), $source ) ) . '</span>';
		}
	}

	/**
	 * Output the text at the top of the main settings section (function is required even if it outputs nothing).
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_text() {
	}

	/**
	 * Whether a schema is drawn as a select.
	 *
	 * @param array<string, mixed> $schema Field schema.
	 *
	 * @return bool
	 */
	private function is_select( array $schema ): bool {
		$normalised = Boolean_Field::is_boolean( $schema ) ? Boolean_Field::normalise( $schema ) : Enum_Field::normalise( $schema );

		return 'select' === ( $normalised['display'] ?? '' ) && empty( $normalised['multiple'] );
	}

	/**
	 * Draw a global setting from its schema.
	 *
	 * The field is disabled, with a note, when the setting is supplied from outside the database. A saved
	 * frequency that is no longer offered is shown as an extra, current choice.
	 *
	 * @param array<string, mixed> $args Arguments of the field: `name`, the name of the setting.
	 *
	 * @return void
	 */
	public function render_setting( $args ) {
		$name    = isset( $args['name'] ) && is_string( $args['name'] ) ? $args['name'] : '';
		$schemas = $this->settings->schemas();
		if ( ! isset( $schemas[ $name ] ) ) {
			return;
		}
		$options     = $this->settings->get();
		$value       = $options[ $name ];
		$extra       = array();
		$unavailable = false;
		if ( 'frequency' === $name && is_string( $value ) && ! array_key_exists( $value, $schemas['frequency']['options'] ) ) {
			$unavailable = true;
			// translators: %s: Name of the saved schedule.
			$extra[ $value ] = sprintf( __( '%s (no longer available)', 'wp-updates-notifier' ), $value );
		}

		echo Field_Renderer::field( $schemas[ $name ], Settings::OPT_FIELD . '[' . $name . ']', 'sc_wpun_settings_main_' . $name, $value, $this->is_external( $name ), '', $extra ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Field_Renderer.
		if ( $unavailable ) {
			?>
			<p class="description sc-wpun-frequency-unavailable">
				<?php esc_html_e( 'The saved frequency is no longer available on this site, so checks run at the interval they were last scheduled with. Choose a frequency and save to change it.', 'wp-updates-notifier' ); ?>
			</p>
			<?php
		}
		$this->external_note( $name );
	}

	/**
	 * Settings field for frequency.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_frequency() {
		$this->render_setting( array( 'name' => 'frequency' ) );
	}

	/**
	 * Settings field for notify plugins.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_notify_plugins() {
		$this->render_setting( array( 'name' => 'notify_plugins' ) );
	}

	/**
	 * Settings field for notify themes.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_notify_themes() {
		$this->render_setting( array( 'name' => 'notify_themes' ) );
	}

	/**
	 * Settings field for notify automatic.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_notify_automatic() {
		$this->render_setting( array( 'name' => 'notify_automatic' ) );
	}

	/**
	 * Settings field for hiding updates.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_hide_updates() {
		$this->render_setting( array( 'name' => 'hide_updates' ) );
	}

	/**
	 * Output the text at the top of the email settings section (function is required even if it outputs nothing).
	 *
	 * @return void
	 */
	public function sc_wpun_settings_email_notifications_text() {
	}

	/**
	 * The schema of the email section's on/off field: the shared `enabled` field with the section's own texts.
	 *
	 * @return array<string, mixed>
	 */
	private function email_enabled_schema(): array {
		return array_merge(
			Channel::enabled_field(),
			array(
				'label'  => __( 'Email notifications', 'wp-updates-notifier' ),
				'labels' => array(
					'on' => __( 'Send email notifications', 'wp-updates-notifier' ),
				),
			)
		);
	}

	/**
	 * Settings field for email notifications.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_email_notifications_field_email_notifications() {
		echo Field_Renderer::boolean( $this->email_enabled_schema(), Settings::OPT_FIELD . '[email_notifications]', 'sc_wpun_settings_email_notifications_email_notifications', $this->instance_enabled( 'email' ), $this->email_read_only( 'enabled' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Field_Renderer.
		$this->email_note( 'enabled' );
	}

	/**
	 * Settings field for email to field.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_email_notifications_field_notify_to() {
		?>
		<input id="sc_wpun_settings_email_notifications_notify_to" class="regular-text" name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_to]" <?php echo $this->value_attribute( array( 'type' => 'text' ), $this->instance_setting( 'email', 'to' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in value_attribute(). ?> <?php disabled( $this->email_read_only( 'to' ) ); ?> />
		<span class="description"><?php esc_html_e( 'Separate multiple email address with a comma (,)', 'wp-updates-notifier' ); ?></span>
		<?php $this->email_note( 'to' ); ?>
		<?php
	}

	/**
	 * Settings field for email from field.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_email_notifications_field_notify_from() {
		?>
		<input id="sc_wpun_settings_email_notifications_notify_from" class="regular-text" name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_from]" <?php echo $this->value_attribute( array( 'type' => 'text' ), $this->instance_setting( 'email', 'from' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in value_attribute(). ?> <?php disabled( $this->email_read_only( 'from' ) ); ?> />
		<span class="description"><?php esc_html_e( 'Leave empty to use the WordPress default. Only set an address at a domain this site is allowed to send mail for.', 'wp-updates-notifier' ); ?></span>
		<?php $this->email_note( 'from' ); ?>
		<?php
	}

	/**
	 * Settings field for the reply-to address.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_email_notifications_field_notify_reply_to() {
		?>
		<input id="sc_wpun_settings_email_notifications_notify_reply_to" class="regular-text" name="<?php echo esc_attr( Settings::OPT_FIELD ); ?>[notify_reply_to]" <?php echo $this->value_attribute( array( 'type' => 'text' ), $this->instance_setting( 'email', 'reply_to' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in value_attribute(). ?> <?php disabled( $this->email_read_only( 'reply_to' ) ); ?> />
		<?php $this->email_note( 'reply_to' ); ?>
		<?php
	}
}
