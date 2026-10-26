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
use WPUN\Config_Report;
use WPUN\Rejected_Channels;
use WPUN\Enum_Field;
use WPUN\Notifier;
use WPUN\Plugin;
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
	 * Notifier.
	 *
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * The notice about Slack no longer being supported.
	 *
	 * @var Slack_Notice
	 */
	private $slack_notice;

	/**
	 * Report of configuration problems.
	 *
	 * @var Config_Report
	 */
	private $config_report;

	/**
	 * Report of modules refused for needing a newer API.
	 *
	 * @var Rejected_Channels
	 */
	private $rejected_channels;

	/**
	 * The Channels tab.
	 *
	 * @var Channels_Tab
	 */
	private $channels_tab;

	/**
	 * Notices about what an admin action did.
	 *
	 * @var Notices
	 */
	private $notices;

	/**
	 * The Check now button.
	 *
	 * @var Check_Now
	 */
	private $check_now;

	/**
	 * The About tab.
	 *
	 * @var About_Tab
	 */
	private $about_tab;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings  Settings.
	 * @param State             $state     Run state.
	 * @param Notifier          $notifier  Notifier.
	 * @param Slack_Notice      $slack_notice Notice about Slack no longer being supported.
	 * @param Config_Report     $config_report Report of configuration problems.
	 * @param Rejected_Channels $rejected_channels Report of modules refused for needing a newer API.
	 * @param Channels_Tab      $channels_tab The Channels tab.
	 * @param Notices           $notices Notices about what an admin action did.
	 * @param Check_Now         $check_now The Check now button.
	 * @param About_Tab         $about_tab The About tab.
	 */
	public function __construct( Settings $settings, State $state, Notifier $notifier, Slack_Notice $slack_notice, Config_Report $config_report, Rejected_Channels $rejected_channels, Channels_Tab $channels_tab, Notices $notices, Check_Now $check_now, About_Tab $about_tab ) {
		$this->settings          = $settings;
		$this->state             = $state;
		$this->notifier          = $notifier;
		$this->slack_notice      = $slack_notice;
		$this->config_report     = $config_report;
		$this->rejected_channels = $rejected_channels;
		$this->channels_tab      = $channels_tab;
		$this->notices           = $notices;
		$this->check_now         = $check_now;
		$this->about_tab         = $about_tab;
	}

	/**
	 * The capability needed to change the settings, send a test and dismiss notices.
	 *
	 * @return string
	 */
	public static function capability(): string {
		// Like core's update screens, a network keeps this for super admins by default (#328).
		$default = is_multisite() ? 'manage_network_options' : 'manage_options';

		/**
		 * Filters the capability needed to use the plugin's settings page.
		 *
		 * It decides who sees the settings page, saves the settings, sends a test message
		 * and dismisses the plugin's notice. An empty or non-string value is ignored and
		 * the default is used.
		 *
		 * The default is `manage_options`, or `manage_network_options` on multisite, which
		 * only super admins have, as with core's update screens.
		 *
		 * Lowering it hands every user with the capability control over where the plugin's
		 * notifications go. Such a user can:
		 *
		 * - send the update notifications, a list of the site's outdated and possibly
		 *   vulnerable plugins and themes, to any address;
		 * - send test messages to any address;
		 * - change the From and Reply-To addresses;
		 * - add, edit, switch off, duplicate and delete channels;
		 * - hide the core update notice and run the update check on demand;
		 * - see the values of channel settings set in `wp-config.php` or by the environment
		 *   when the field is not secret and its schema sets `show_external`.
		 *
		 * Example, let editors manage the plugin:
		 *
		 *     add_filter( 'sc_wpun_capability', fn() => 'edit_pages' );
		 *
		 * Example, let the administrators of the sites of a network manage the plugin:
		 *
		 *     add_filter( 'sc_wpun_capability', fn() => 'manage_options' );
		 *
		 * @since 2.0.0
		 *
		 * @param string $capability Capability. Default `manage_options`, or `manage_network_options` on multisite.
		 */
		$capability = apply_filters( 'sc_wpun_capability', $default );

		return is_string( $capability ) && '' !== $capability ? $capability : $default;
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
	 * Adds a link to the documentation to the plugin's row on the plugin screen, after core's own links.
	 *
	 * @param array<mixed> $meta Links and details shown under the plugin's description.
	 * @param string       $file Plugin file the row belongs to.
	 *
	 * @return array<mixed> $meta
	 */
	public function plugin_row_meta( $meta, $file ) {
		if ( plugin_basename( SC_WPUN_FILE ) !== $file ) {
			return $meta;
		}
		$meta[] = '<a href="' . esc_url( Plugin::SITE_URL ) . '">' . esc_html__( 'Documentation', 'wp-updates-notifier' ) . '</a>';
		return $meta;
	}

	/**
	 * Add admin menu.
	 *
	 * @return void
	 */
	public function admin_settings_menu() {
		$hook = add_options_page( __( 'Updates Notifier', 'wp-updates-notifier' ), __( 'Updates Notifier', 'wp-updates-notifier' ), self::capability(), 'wp-updates-notifier', array( $this, 'settings_page' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'enqueue_styles' ) );
			add_action( 'load-' . $hook, array( $this, 'add_title_filter' ) );
		}
	}

	/**
	 * Name the view in the page title. Hooked on `load-{$hook}`, so no other screen's title changes.
	 *
	 * @return void
	 */
	public function add_title_filter() {
		add_filter( 'admin_title', array( $this, 'admin_title' ) );
	}

	/**
	 * Put the view in front of the title of the screen, and `Error:` if the view shows errors.
	 *
	 * Runs before the page is drawn, so it must not use up what the page shows.
	 *
	 * @param string $admin_title The title core built, for example "Updates Notifier ‹ Site — WordPress".
	 *
	 * @return string
	 */
	public function admin_title( $admin_title ) {
		$this->add_tab_actions();
		$tabs    = $this->tabs();
		$current = $this->current_tab( $tabs );
		$name    = $tabs[ $current ];
		$errors  = false;

		if ( 'general' === $current ) {
			foreach ( get_settings_errors() as $error ) {
				if ( 'error' === $error['type'] ) {
					$errors = true;
				}
			}
		} elseif ( 'channels' === $current ) {
			$view = $this->channels_tab->view();
			if ( null !== $view ) {
				$name   = $view['name'];
				$errors = $view['errors'];
			}
		}

		// The separator is written like core's, as an entity: core escapes the whole title.
		// translators: 1: Name of the view, for example "Channels". 2: Title of the screen and site.
		$title = sprintf( __( '%1$s &lsaquo; %2$s', 'wp-updates-notifier' ), $name, is_string( $admin_title ) ? $admin_title : '' );
		if ( $errors ) {
			// translators: %s: Page title.
			$title = sprintf( __( 'Error: %s', 'wp-updates-notifier' ), $title );
		}

		return $title;
	}

	/**
	 * Hook the General, Channels and About tabs to their drawing, so the tab list includes them.
	 * The About tab is hooked only while the `show_about` setting allows it.
	 *
	 * @return void
	 */
	private function add_tab_actions(): void {
		add_action( 'sc_wpun_settings_tab_general', array( $this, 'render_general_tab' ) );
		add_action( 'sc_wpun_settings_tab_channels', array( $this->channels_tab, 'render' ) );
		if ( $this->settings->show_about() ) {
			add_action( 'sc_wpun_settings_tab_about', array( $this->about_tab, 'render' ) );
		}
	}

	/**
	 * Load the stylesheet of the settings screen. Hooked on `load-{$hook}`, so no other screen gets it.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		wp_enqueue_style( 'sc-wpun-admin', plugins_url( 'assets/css/admin.css', SC_WPUN_FILE ), array(), SC_WPUN_VERSION );
	}

	/**
	 * The tabs of the settings screen, as slug => label.
	 *
	 * A tab is listed only if something draws it, so a built-in tab nothing hooks stays hidden.
	 * The General tab is always first and the About tab always last.
	 *
	 * @return array<string, string>
	 */
	public function tabs(): array {
		$defaults = array(
			'general'  => __( 'General', 'wp-updates-notifier' ),
			'channels' => __( 'Channels', 'wp-updates-notifier' ),
			'about'    => __( 'About', 'wp-updates-notifier' ),
		);

		/**
		 * Filters the tabs of the settings screen.
		 *
		 * A tab is shown only if something is hooked on `sc_wpun_settings_tab_{$tab}` to draw it. Entries
		 * whose slug is not a string equal to its `sanitize_key()` form, or whose label is not a string,
		 * are ignored. The General tab cannot be removed.
		 *
		 * Example, add a tab:
		 *
		 *     add_filter( 'sc_wpun_settings_tabs', fn( $tabs ) => $tabs + array( 'mine' => 'Mine' ) );
		 *     add_action( 'sc_wpun_settings_tab_mine', fn() => print '<p>Hello</p>' );
		 *
		 * @since 2.0.0
		 *
		 * @param array<string, string> $tabs Tab labels keyed by slug. Default `general`, `channels` and `about`. The About tab is always moved last, and is hidden by the `show_about` setting (code only).
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

		// About is always last, even after add-ons appended their own tabs.
		if ( isset( $visible['about'] ) ) {
			$about = $visible['about'];
			unset( $visible['about'] );
			$visible['about'] = $about;
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
		// Core only shows the page to users with the menu capability; check again rather than rely on that alone.
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-updates-notifier' ), '', array( 'response' => 403 ) );
		}

		// Drawn like any other tab. Added here, not only at boot, so the page works however it is reached.
		$this->add_tab_actions();

		$tabs    = $this->tabs();
		$current = $this->current_tab( $tabs );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Updates Notifier', 'wp-updates-notifier' ); ?></h1>

			<?php $this->slack_notice->render(); ?>
			<?php $this->config_report->render(); ?>
			<?php $this->rejected_channels->render(); ?>
			<?php $this->notices->render(); ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'wp-updates-notifier' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $this->tab_url( $slug ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			/**
			 * Draws a tab of the settings screen.
			 *
			 * The dynamic part, `$tab`, is the tab's slug. Add the tab with the `sc_wpun_settings_tabs` filter;
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
	 * Output the General tab: when the check runs and the settings.
	 *
	 * @return void
	 */
	public function render_general_tab(): void {
		$options    = $this->state->get();
		$next_check = wp_next_scheduled( Scheduler::CRON_NAME );
		?>
		<h2><?php esc_html_e( 'Status', 'wp-updates-notifier' ); ?></h2>
		<p>
			<span class="description sc-wpun-last-check">
			<?php
			if ( empty( $options['last_check_time'] ) ) {
				esc_html_e( 'Last checked: never', 'wp-updates-notifier' );
			} else {
				// translators: %s: Date and time of the last check.
				echo esc_html( sprintf( __( 'Last checked: %s', 'wp-updates-notifier' ), self::format_site_time( $options['last_check_time'] ) ) );
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
				echo esc_html( sprintf( __( 'Next check: %s', 'wp-updates-notifier' ), self::format_site_time( $next_check ) ) );
			}
			?>
			</span>
		</p>

		<?php $this->check_now->render(); ?>

		<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
			<?php
			// Saving goes back to the page it was sent from, which is this tab: the tab links carry `tab=general`.
			settings_fields( 'sc_wpun_settings' );
			do_settings_sections( 'wp-updates-notifier' );
			?>
			<?php submit_button( __( 'Save settings', 'wp-updates-notifier' ) ); ?>
		</form>
		<?php
	}

	/**
	 * A timestamp as a date and time in the site's timezone and formats.
	 *
	 * @internal
	 *
	 * @param int $timestamp Unix timestamp.
	 *
	 * @return string
	 */
	public static function format_site_time( int $timestamp ): string {
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

		// Make settings main section.
		add_settings_section( 'sc_wpun_settings_main', esc_html__( 'Settings', 'wp-updates-notifier' ), null, 'wp-updates-notifier' );  // @phpstan-ignore argument.type (Core accepts no callback: the sections have no intro text.)
		foreach ( $this->settings->schemas() as $name => $schema ) {
			if ( false === ( $schema['storage'] ?? true ) ) {
				continue; // Code-only settings have no field.
			}
			$id   = 'sc_wpun_settings_main_' . $name;
			$args = array( 'name' => $name );
			// A checkbox has its own label and radios have a legend, so only a select is labelled by the row title.
			if ( $this->is_select( $schema ) ) {
				$args['label_for'] = $id;
			}
			// Core prints the title as it is, so the label is escaped and the marker (trusted markup) is added here.
			$title = esc_html( isset( $schema['label'] ) && is_string( $schema['label'] ) ? $schema['label'] : $name ) . ( Field_Renderer::is_required( $schema ) ? Field_Renderer::required_marker() : '' );
			add_settings_field( $id, $title, array( $this, 'render_setting' ), 'wp-updates-notifier', 'sc_wpun_settings_main', $args );
		}
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
			'frequency'      => __( 'Choose how often to check for updates.', 'wp-updates-notifier' ),
			'notify_core'    => __( 'Choose whether to be told about WordPress updates.', 'wp-updates-notifier' ),
			'notify_plugins' => __( 'Choose whether to be told about plugin updates.', 'wp-updates-notifier' ),
			'notify_themes'  => __( 'Choose whether to be told about theme updates.', 'wp-updates-notifier' ),
			'hide_updates'   => __( 'Choose whether to hide the update notices.', 'wp-updates-notifier' ),
		);
		foreach ( $this->settings->schemas() as $name => $schema ) {
			if ( ! array_key_exists( $name, $input ) || false === ( $schema['storage'] ?? true ) ) {
				continue; // Not submitted, or code-only: a posted value is never written.
			}
			$checked = Boolean_Field::is_boolean( $schema ) ? Boolean_Field::sanitize( $input[ $name ], $schema ) : Enum_Field::sanitize( $input[ $name ], $schema );
			if ( $checked['ok'] ) {
				$valid[ $name ] = $checked['value'];
			} else {
				// translators: %s: Label of the setting.
				$fallback = sprintf( __( 'Choose a value for %s.', 'wp-updates-notifier' ), isset( $schema['label'] ) && is_string( $schema['label'] ) ? $schema['label'] : $name );
				add_settings_error( 'sc_wpun_settings_main_' . $name, 'sc_wpun_settings_main_' . $name . '_error', $messages[ $name ] ?? $fallback, 'error' );
			}
		}

		// A setting supplied from outside the database keeps its stored value, whatever was submitted.
		$stored = $this->settings->stored();
		foreach ( array_keys( $stored ) as $name ) {
			if ( null !== $this->settings->external_source( $name ) ) {
				$valid[ $name ] = $stored[ $name ];
			}
		}

		return $valid;
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
	 * @param string $id   Id for the note, which the control points to.
	 *
	 * @return void
	 */
	private function external_note( string $name, string $id ): void {
		$source = $this->settings->external_source( $name );
		if ( null !== $source ) {
			// translators: %s: Where the value comes from, for example "constant SC_WPUN_SETTING_FREQUENCY".
			echo ' <span class="description sc-wpun-external" id="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( 'Set by %s', 'wp-updates-notifier' ), $source ) ) . '</span>';
		}
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

		// Core keeps the errors of the last save for this page load, whoever asked for them first.
		$error = '';
		foreach ( get_settings_errors( 'sc_wpun_settings_main_' . $name ) as $found ) {
			if ( 'error' === $found['type'] ) {
				$error = $found['message'];
				break;
			}
		}

		// The notes drawn after the control describe it; the field adds the id of its error after these.
		$id           = 'sc_wpun_settings_main_' . $name;
		$described_by = array();
		if ( $unavailable ) {
			$described_by[] = $id . '-unavailable';
		}
		if ( $this->is_external( $name ) ) {
			$described_by[] = $id . '-external';
		}

		echo Field_Renderer::field( $schemas[ $name ], Settings::OPT_FIELD . '[' . $name . ']', $id, $value, $this->is_external( $name ), implode( ' ', $described_by ), $extra, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Field_Renderer.
		if ( $unavailable ) {
			?>
			<p class="description sc-wpun-frequency-unavailable" id="<?php echo esc_attr( $id . '-unavailable' ); ?>">
				<?php esc_html_e( 'The saved frequency is no longer available on this site, so checks run at the interval they were last scheduled with. Choose a frequency and save to change it.', 'wp-updates-notifier' ); ?>
			</p>
			<?php
		}
		$this->external_note( $name, $id . '-external' );
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
	 * Settings field for hiding updates.
	 *
	 * @return void
	 */
	public function sc_wpun_settings_main_field_hide_updates() {
		$this->render_setting( array( 'name' => 'hide_updates' ) );
	}
}
