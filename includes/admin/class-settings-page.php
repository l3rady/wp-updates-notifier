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
use WPUN\Field_Types;
use WPUN\Network_Context;
use WPUN\Plugin;
use WPUN\Scheduler;
use WPUN\Settings;
use WPUN\State;

/**
 * The settings page under Settings, its form fields and the validation of what is saved.
 *
 * @internal
 */
class Settings_Page {
	/**
	 * Action of the form of every settings tab, handled on `admin_post_{action}`, in a site and in the network admin.
	 */
	const SAVE_ACTION = 'sc_wpun_save_settings';

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
	 * The Muted tab.
	 *
	 * @var Muted_Tab
	 */
	private $muted_tab;

	/**
	 * Where the plugin runs.
	 *
	 * @var Network_Context
	 */
	private $network_context;

	/**
	 * The panels of the settings groups; the plugin's own when null.
	 *
	 * @var Settings_Group_Form|null
	 */
	private $group_form;

	/**
	 * Constructor.
	 *
	 * @param Settings                 $settings  Settings.
	 * @param State                    $state     Run state.
	 * @param Config_Report            $config_report Report of configuration problems.
	 * @param Rejected_Channels        $rejected_channels Report of modules refused for needing a newer API.
	 * @param Channels_Tab             $channels_tab The Channels tab.
	 * @param Notices                  $notices Notices about what an admin action did.
	 * @param Check_Now                $check_now The Check now button.
	 * @param About_Tab                $about_tab The About tab.
	 * @param Muted_Tab                $muted_tab The Muted tab.
	 * @param Network_Context|null     $network_context Where the plugin runs.
	 * @param Settings_Group_Form|null $group_form The panels of the settings groups; the plugin's own when null.
	 */
	public function __construct( Settings $settings, State $state, Config_Report $config_report, Rejected_Channels $rejected_channels, Channels_Tab $channels_tab, Notices $notices, Check_Now $check_now, About_Tab $about_tab, Muted_Tab $muted_tab, ?Network_Context $network_context = null, ?Settings_Group_Form $group_form = null ) {
		$this->settings          = $settings;
		$this->state             = $state;
		$this->config_report     = $config_report;
		$this->rejected_channels = $rejected_channels;
		$this->channels_tab      = $channels_tab;
		$this->notices           = $notices;
		$this->check_now         = $check_now;
		$this->about_tab         = $about_tab;
		$this->muted_tab         = $muted_tab;
		$this->network_context   = $network_context ?? new Network_Context();
		$this->group_form        = $group_form;
	}

	/**
	 * The panels of the settings groups.
	 *
	 * @return Settings_Group_Form
	 */
	private function group_form(): Settings_Group_Form {
		return $this->group_form ?? sc_wpun()->settings_group_form();
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
		 * Network-activated, the screen is in the network admin, which only users who can manage the
		 * network reach, so lowering this to `manage_options` does not let the administrators of the
		 * sites in. Lower it to a capability that users who manage the network have, or leave it.
		 *
		 * Code outside the plugin reads the result with `sc_wpun_capability()`.
		 *
		 * @since 2.0.0
		 *
		 * @param string $capability Capability. Default `manage_options`, or `manage_network_options` on multisite.
		 */
		$capability = apply_filters( 'sc_wpun_capability', $default );

		return is_string( $capability ) && '' !== $capability ? $capability : $default;
	}

	/**
	 * Whether the security token posted with the request is valid for the action. Only the request body counts, not the query string.
	 *
	 * @internal
	 *
	 * @param string $action The token action.
	 *
	 * @return bool
	 */
	public static function posted_token_valid( string $action ): bool {
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ) {
			return false;
		}

		return false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), $action );
	}

	/**
	 * End an admin-post request: answer 403 when there is no address, else redirect there and stop.
	 *
	 * @internal
	 *
	 * @param string|null $url Where to go; null if the request was refused.
	 *
	 * @return void
	 */
	public static function finish( ?string $url ): void {
		if ( null === $url ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-updates-notifier' ), '', array( 'response' => 403 ) );
		} else {
			wp_safe_redirect( $url );
			exit;
		}
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
		$settings_link = '<a href="' . esc_url( $this->network_context->settings_url() ) . '">' . esc_html__( 'Settings', 'wp-updates-notifier' ) . '</a>';
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
		$title = __( 'Updates Notifier', 'wp-updates-notifier' );
		if ( $this->network_context->is_network() ) {
			// Network-activated, the screen lives in the network admin, under its Settings.
			$hook = add_submenu_page( 'settings.php', $title, $title, self::capability(), Network_Context::SETTINGS_SLUG, array( $this, 'settings_page' ) );
		} else {
			$hook = add_options_page( $title, $title, self::capability(), Network_Context::SETTINGS_SLUG, array( $this, 'settings_page' ) );
		}
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'enqueue_styles' ) );
			add_action( 'load-' . $hook, array( $this, 'add_title_filter' ) );
		}
	}

	/**
	 * Handle the form of a settings tab (admin-post.php), then go back to the screen.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		self::finish( $this->process_save() );
	}

	/**
	 * Check a submitted tab and save it, or remember what was wrong.
	 *
	 * Everything is checked before anything is written: when a value of the plugin's settings or of any group is not
	 * valid, nothing is saved, and the screen shows the submitted values again with the errors.
	 *
	 * @return string|null The address to go to; null if the user may not do this or the security token is not valid.
	 */
	public function process_save(): ?string {
		// Network-activated, the settings are saved from the network admin of the main site only, as network/edit.php did:
		// a lowered capability must not let the administrator of another site save them through its own admin-post.php.
		if ( ! current_user_can( self::capability() ) || ( $this->network_context->is_network() && get_current_blog_id() !== $this->network_context->main_site_id() ) ) {
			return null;
		}

		$tab = Form_Parts::posted_key( 'tab' );
		if ( ! self::posted_token_valid( self::SAVE_ACTION . '_' . $tab ) ) {
			return null;
		}
		if ( ! $this->group_form()->has_tab( $tab ) ) {
			return $this->network_context->settings_url();
		}

		$general = 'general' === $tab;
		$main    = $general ? $this->scalars( Form_Parts::posted_list( Settings::OPT_FIELD ) ) : array();
		$check   = $general ? $this->check_main( $main ) : array(
			'valid'  => array(),
			'errors' => array(),
		);
		$checked = $this->group_form()->check( $tab, Form_Parts::posted_list( Settings_Group_Form::POST_KEY ) );
		$errors  = array();
		foreach ( $check['errors'] as $name => $message ) {
			$errors[ 'main/' . $name ] = $message; // `main` is a reserved group id, so a key cannot be a group's.
		}
		$errors += $checked['errors'];

		if ( array() !== $errors ) {
			Form_Parts::remember(
				Settings_Group_Form::META_KEY,
				'tab:' . $tab,
				array(
					'settings' => $main,
					'groups'   => $checked['values'],
				),
				$errors
			);
		} else {
			if ( $general ) {
				$this->network_context->write( Settings::OPT_FIELD, $check['valid'] );
			}
			$this->group_form()->save( $checked['valid'] );
			$saved = __( 'Settings saved.' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own message, already translated.
			add_settings_error( 'general', 'settings_updated', $saved, 'success' );
		}

		// Core's channel for the messages: it prints them on the next page load, as it does after options.php.
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		$back = wp_get_referer();

		return add_query_arg( 'settings-updated', 'true', false !== $back && '' !== $back ? $back : $this->network_context->settings_url( $tab ) );
	}

	/**
	 * The entries of a posted list that are strings, so what is kept for the next page load stays small.
	 *
	 * @param array<string, mixed> $values The posted list.
	 *
	 * @return array<string, mixed>
	 */
	private function scalars( array $values ): array {
		return array_filter( $values, 'is_scalar' );
	}

	/**
	 * Start the form of a settings tab: it posts to admin-post.php, in a site and in the network admin.
	 *
	 * @internal
	 *
	 * @param string $tab Tab slug.
	 *
	 * @return void
	 */
	public static function form_open( string $tab ): void {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
			<?php wp_nonce_field( self::SAVE_ACTION . '_' . $tab ); // Also keeps the page the form is on, which the save goes back to. ?>
		<?php
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

		if ( 'channels' === $current ) {
			$view = $this->channels_tab->view();
			if ( null !== $view ) {
				$name   = $view['name'];
				$errors = $view['errors'];
			}
		}
		// The plugin's own settings and settings groups: on General and on the tabs of add-ons. Their errors are kept for the form, not in core's list.
		if ( $this->group_form()->has_tab( $current ) && $this->group_form()->has_errors( $current ) ) {
			$errors = true;
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
	 * Hook the General, Channels, Muted and About tabs to their drawing, so the tab list includes them.
	 * The About tab is hooked only while the `show_about` setting allows it.
	 *
	 * @return void
	 */
	private function add_tab_actions(): void {
		add_action( 'sc_wpun_settings_tab_general', array( $this, 'render_general_tab' ) );
		add_action( 'sc_wpun_settings_tab_channels', array( $this->channels_tab, 'render' ) );
		add_action( 'sc_wpun_settings_tab_muted', array( $this->muted_tab, 'render' ) );
		if ( $this->settings->show_about() ) {
			add_action( 'sc_wpun_settings_tab_about', array( $this->about_tab, 'render' ) );
		}
		foreach ( array_keys( $this->group_form()->tab_labels() ) as $slug ) {
			add_action( "sc_wpun_settings_tab_{$slug}", array( $this->group_form(), 'render_current_tab' ) );
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
			'muted'    => __( 'Muted', 'wp-updates-notifier' ),
			'about'    => __( 'About', 'wp-updates-notifier' ),
		);

		// Tabs of the settings groups of add-ons, before the filter so it can still change them.
		$defaults = array_slice( $defaults, 0, 3, true ) + $this->group_form()->tab_labels() + array_slice( $defaults, 3, null, true );

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
		 * @param array<string, string> $tabs Tab labels keyed by slug. Default `general`, `channels`, `muted` and `about`. The About tab is always moved last, and is hidden by the `show_about` setting (code only).
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
		return $this->network_context->settings_url( $tab );
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

			<?php
			if ( $this->network_context->is_network() ) {
				// Core shows these above the screens under Settings, not above the ones under the network's Settings.
				settings_errors();
			}
			?>
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
		$round      = $this->group_form()->round( 'general' );
		$options    = $this->state->get();
		$next_check = wp_next_scheduled( Scheduler::CRON_NAME );
		?>
		<section class="sc-wpun-panel">
		<h2 class="sc-wpun-panel-title"><?php esc_html_e( 'Status', 'wp-updates-notifier' ); ?></h2>
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
		</section>

		<?php
		self::form_open( 'general' );
		$rows    = '';
		$anchors = array();
		foreach ( $this->settings->schemas() as $name => $schema ) {
			if ( false === ( $schema['storage'] ?? true ) ) {
				continue; // Code-only settings have no field.
			}
			$id                         = 'sc_wpun_settings_main_' . $name;
			$anchors[ 'main/' . $name ] = Form_Parts::first_control_id( $schema, $id );
			$rows                      .= Form_Parts::field_row( (string) $name, $schema, $id, $this->main_control( (string) $name, $round ) );
		}
		$this->group_form()->render_summary( $round['errors'], $anchors );
		?>
			<fieldset class="sc-wpun-panel">
				<legend><h2><?php esc_html_e( 'Settings', 'wp-updates-notifier' ); ?></h2></legend>
				<table class="form-table" role="presentation">
					<?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Form_Parts. ?>
				</table>
			</fieldset>
			<?php $this->group_form()->render_groups( 'general', $round ); ?>
			<?php submit_button( __( 'Save settings', 'wp-updates-notifier' ) ); ?>
		</form>
		<?php
	}

	/**
	 * The cell of a setting of the plugin: its control, with its error and its notes.
	 *
	 * The field is disabled, with a note, when the setting is supplied from outside the database. A saved frequency
	 * that is no longer offered is shown as an extra, current choice.
	 *
	 * @internal
	 *
	 * @param string                                                                                                  $name  Setting name.
	 * @param array{settings: array<string, mixed>, groups: array<string, mixed>, errors: array<string, string>}|null $round What the last failed save left; nothing when null.
	 *
	 * @return string Escaped markup; empty for a name that is not a setting with a field.
	 */
	public function main_control( string $name, ?array $round = null ): string {
		$schema = $this->settings->schemas()[ $name ] ?? null;
		if ( null === $schema || false === ( $schema['storage'] ?? true ) ) {
			return '';
		}
		$round  ??= array(
			'settings' => array(),
			'groups'   => array(),
			'errors'   => array(),
		);
		$id       = 'sc_wpun_settings_main_' . $name;
		$error    = $round['errors'][ 'main/' . $name ] ?? '';
		$external = $this->settings->external_source( $name );
		$options  = $this->settings->get();
		$value    = $options[ $name ];
		// A failed save elsewhere on the tab does not undo the change: show what was submitted, unless that is what failed.
		if ( '' === $error && array_key_exists( $name, $round['settings'] ) && null === $external ) {
			$value = $round['settings'][ $name ];
		}

		$extra = array();
		if ( 'notify_core' === $name ) {
			$schema['description'] = __( 'Scheduled checks run WordPress\'s automatic updates first, so updates it installs by itself are not reported.', 'wp-updates-notifier' );
		} elseif ( 'download_links' === $name ) {
			$schema['description'] = __( 'Some paid plugins and themes put a licence key or a private token in their download address. With this on, that address is sent to everyone who receives the notifications, on every channel.', 'wp-updates-notifier' );
		} elseif ( 'frequency' === $name && is_string( $value ) && isset( $schema['options'] ) && is_array( $schema['options'] ) && ! array_key_exists( $value, $schema['options'] ) ) {
			// translators: %s: Name of the saved schedule.
			$extra[ $value ]       = sprintf( __( '%s (no longer available)', 'wp-updates-notifier' ), $value );
			$schema['description'] = __( 'The saved frequency is no longer available on this site, so checks run at the interval they were last scheduled with. Choose a frequency and save to change it.', 'wp-updates-notifier' );
		}

		return Form_Parts::control( $schema, Settings::OPT_FIELD . '[' . $name . ']', $id, $value, $error, $external, '', false, $extra );
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
		// the form's nonce itself).
		if ( ! is_array( $input ) ) {
			return $this->settings->stored(); // Never the effective settings: a value supplied from outside the database is not written to it.
		}

		$check = $this->check_main( $input );
		foreach ( $check['errors'] as $name => $message ) {
			add_settings_error( 'sc_wpun_settings_main_' . $name, 'sc_wpun_settings_main_' . $name . '_error', esc_html( $message ), 'error' );
		}

		return $check['valid'];
	}

	/**
	 * Check the submitted settings of the plugin.
	 *
	 * @param array<mixed> $input Array of unsanitized options from the page form.
	 *
	 * @return array{valid: array<mixed>, errors: array<string, string>} The settings to save, and a plain message for each value that is not valid, by setting name.
	 */
	private function check_main( array $input ): array {
		$valid  = $this->settings->stored(); // Never the effective settings: a value supplied from outside the database is not written to it.
		$errors = array();

		// Validate main settings with their field types. A missing value keeps the stored one: a read-only setting is not submitted.
		$messages = array(
			'frequency'      => __( 'Choose how often to check for updates.', 'wp-updates-notifier' ),
			'notify_core'    => __( 'Choose whether to be told about WordPress updates.', 'wp-updates-notifier' ),
			'notify_plugins' => __( 'Choose whether to be told about plugin updates.', 'wp-updates-notifier' ),
			'notify_themes'  => __( 'Choose whether to be told about theme updates.', 'wp-updates-notifier' ),
			'hide_updates'   => __( 'Choose whether to hide the update notices.', 'wp-updates-notifier' ),
			'download_links' => __( 'Choose whether to include download links.', 'wp-updates-notifier' ),
		);
		foreach ( $this->settings->schemas() as $name => $schema ) {
			if ( ! array_key_exists( $name, $input ) || false === ( $schema['storage'] ?? true ) ) {
				continue; // Not submitted, or code-only: a posted value is never written.
			}
			$checked = 'boolean' === Field_Types::canonical( $schema['type'] ?? null ) ? Boolean_Field::sanitize( $input[ $name ], $schema ) : Enum_Field::sanitize( $input[ $name ], $schema );
			if ( $checked['ok'] ) {
				$valid[ $name ] = $checked['value'];
			} else {
				// translators: %s: Label of the setting.
				$fallback        = sprintf( __( 'Choose a value for %s.', 'wp-updates-notifier' ), isset( $schema['label'] ) && is_string( $schema['label'] ) ? $schema['label'] : $name );
				$errors[ $name ] = $messages[ $name ] ?? $fallback;
			}
		}

		// A setting supplied from outside the database keeps its stored value, whatever was submitted.
		$stored = $this->settings->stored();
		foreach ( array_keys( $stored ) as $name ) {
			if ( null !== $this->settings->external_source( $name ) ) {
				$valid[ $name ] = $stored[ $name ];
			}
		}

		return array(
			'valid'  => $valid,
			'errors' => $errors,
		);
	}
}
