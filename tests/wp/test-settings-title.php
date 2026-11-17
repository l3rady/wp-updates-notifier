<?php
/**
 * The page title names the view and flags errors (#207).
 *
 * @package wp-updates-notifier
 */

/**
 * The admin title of the settings screen.
 */
class Test_Settings_Title extends WPUN_Test_Case {

	/**
	 * Core's title for the screen, as it reaches the filter.
	 */
	const CORE = 'Updates Notifier &lsaquo; Site &#8212; WordPress';

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * The plugin's objects a test swapped, to put back.
	 *
	 * @var array<string, mixed>
	 */
	private $shared = array();

	/**
	 * Create the user.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * An administrator on the settings screen, which has loaded.
	 */
	public function set_up() {
		parent::set_up();
		unset( $GLOBALS['wp_settings_errors'] );
		wp_set_current_user( self::$admin_id );
		sc_wpun()->settings_page()->admin_settings_menu();
		$this->load_screen();
	}

	/**
	 * Forget the request and the errors.
	 */
	public function tear_down() {
		foreach ( $this->shared as $name => $value ) {
			$this->set_property( $name, $value );
		}
		delete_user_meta( self::$admin_id, WPUN\Admin\Settings_Group_Form::META_KEY );
		$_GET = array();
		unset( $GLOBALS['wp_settings_errors'] );
		delete_transient( 'settings_errors' );
		parent::tear_down();
	}

	/**
	 * Set a property of the plugin.
	 *
	 * @param string $name  Name.
	 * @param mixed  $value Value.
	 */
	private function set_property( $name, $value ) {
		$property = new ReflectionProperty( sc_wpun(), $name );
		$property->setAccessible( true );
		$property->setValue( sc_wpun(), $value );
	}

	/**
	 * Give the plugin a fresh registry with one settings group, and load the screen again with it.
	 *
	 * @param array $args Arguments of the group `acme`.
	 */
	private function with_group( array $args ) {
		foreach ( array( 'settings_groups', 'settings_group_form', 'settings_page' ) as $name ) {
			$property = new ReflectionProperty( sc_wpun(), $name );
			$property->setAccessible( true );
			$this->shared[ $name ] = $property->getValue( sc_wpun() );
		}
		$this->shared['settings_groups']->all();
		$groups = new WPUN\Settings_Groups( sc_wpun()->config(), sc_wpun()->channels(), sc_wpun()->settings(), sc_wpun()->network_context() );
		$this->set_property( 'settings_groups', $groups );
		$this->set_property( 'settings_group_form', new WPUN\Admin\Settings_Group_Form( $groups ) );
		$this->set_property( 'settings_page', null );
		add_action(
			'sc_wpun_register_settings',
			static function () use ( $args ) {
				sc_wpun_register_settings_group( 'acme', $args, array( 'name' => array( 'type' => 'text' ) ) );
			}
		);
		sc_wpun()->settings_page()->admin_settings_menu();
		$this->load_screen();
		// The screen loaded for the old page object too: only the new one may build the title.
		remove_all_filters( 'admin_title' );
		add_filter( 'admin_title', array( sc_wpun()->settings_page(), 'admin_title' ) );
	}

	/**
	 * Keep a failed save of the group form for a tab, as process_save() does.
	 *
	 * @param string $tab     Tab slug.
	 * @param int    $user_id User.
	 */
	private function failed_group_save( $tab, $user_id ) {
		update_user_meta(
			$user_id,
			WPUN\Admin\Settings_Group_Form::META_KEY,
			array(
				'site'   => get_current_blog_id(),
				'time'   => time(),
				'form'   => 'tab:' . $tab,
				'values' => array( 'groups' => array( 'acme' => array( 'name' => 'Typed' ) ) ),
				'errors' => array( 'acme/name' => 'Broken name.' ),
			)
		);
	}

	/**
	 * Fire the load action of the screen, which adds the filter.
	 */
	private function load_screen(): void {
		do_action( 'load-' . get_plugin_page_hookname( 'wp-updates-notifier', 'options-general.php' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * The title for a request, as core's header builds it.
	 *
	 * @param array $get Query arguments.
	 * @return string
	 */
	private function title( array $get = array() ): string {
		$_GET = $get;

		return apply_filters( 'admin_title', self::CORE, 'Updates Notifier' );
	}

	/**
	 * The page, drawn.
	 *
	 * @return string
	 */
	private function html(): string {
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();

		return (string) ob_get_clean();
	}

	/**
	 * Each view names itself in front of core's title.
	 */
	public function test_each_view_is_named() {
		$tail = ' &lsaquo; ' . self::CORE;

		$this->assertSame( 'General' . $tail, $this->title() );
		$this->assertSame( 'General' . $tail, $this->title( array( 'tab' => 'unknown' ) ) );
		$this->assertSame( 'Channels' . $tail, $this->title( array( 'tab' => 'channels' ) ) );
		$this->assertSame(
			'Add channel' . $tail,
			$this->title(
				array(
					'tab'    => 'channels',
					'action' => 'add',
				)
			)
		);
		$this->assertSame(
			'Add Email channel' . $tail,
			$this->title(
				array(
					'tab'    => 'channels',
					'action' => 'new',
					'type'   => 'email',
				)
			)
		);
		$this->assertSame(
			'Edit Email' . $tail,
			$this->title(
				array(
					'tab'    => 'channels',
					'action' => 'edit',
					'id'     => 'email',
				)
			)
		);
		$this->assertSame(
			'Delete Email?' . $tail,
			$this->title(
				array(
					'tab'    => 'channels',
					'action' => 'delete',
					'id'     => 'email',
				)
			)
		);
	}

	/**
	 * A route that shows the list instead has the tab name.
	 */
	public function test_fallback_routes_use_the_tab_name() {
		$tail = ' &lsaquo; ' . self::CORE;

		$this->assertSame(
			'Channels' . $tail,
			$this->title(
				array(
					'tab'    => 'channels',
					'action' => 'edit',
					'id'     => 'nope',
				)
			)
		);
		$this->assertSame(
			'Channels' . $tail,
			$this->title(
				array(
					'tab'    => 'channels',
					'action' => 'new',
					'type'   => 'nope',
				)
			)
		);
		$this->assertSame(
			'Channels' . $tail,
			$this->title(
				array(
					'tab'    => 'channels',
					'action' => 'delete',
					'id'     => 'nope',
				)
			)
		);
	}

	/**
	 * A tab added through the filter uses its label.
	 */
	public function test_a_custom_tab_uses_its_label() {
		add_filter( 'sc_wpun_settings_tabs', fn( $tabs ) => $tabs + array( 'mine' => 'My tab' ) );
		add_action( 'sc_wpun_settings_tab_mine', '__return_true' );

		$this->assertSame( 'My tab &lsaquo; ' . self::CORE, $this->title( array( 'tab' => 'mine' ) ) );
	}

	/**
	 * Other admin screens keep core's title.
	 */
	public function test_other_screens_are_unchanged() {
		remove_all_filters( 'admin_title' );
		do_action( 'load-index.php' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		$this->assertSame( 'Dashboard &lsaquo; Site &#8212; WordPress', apply_filters( 'admin_title', 'Dashboard &lsaquo; Site &#8212; WordPress', 'Dashboard' ) );
	}

	/**
	 * A failed save gives an Error title, and the form still shows its errors after the title looked.
	 */
	public function test_a_failed_channel_save_flags_the_title_and_keeps_the_errors() {
		$get = array(
			'tab'    => 'channels',
			'action' => 'edit',
			'id'     => 'email',
		);
		update_user_meta(
			self::$admin_id,
			'sc_wpun_channel_form',
			array(
				'site'   => get_current_blog_id(),
				'time'   => time(),
				'form'   => 'edit:email',
				'values' => array( 'label' => 'Typed' ),
				'errors' => array( 'label' => 'Broken.' ),
			)
		);

		$this->assertSame( 'Error: Edit Email &lsaquo; ' . self::CORE, $this->title( $get ) );
		$this->assertNotEmpty( get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true ), 'The title does not use it up.' );

		$html = $this->html();
		$this->assertStringContainsString( 'sc-wpun-form-errors', $html );
		$this->assertStringContainsString( 'Broken.', $html );
		$this->assertStringContainsString( 'value="Typed"', $html );
		$this->assertEmpty( get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true ), 'The form used it up.' );
		$this->assertSame( 'Edit Email &lsaquo; ' . self::CORE, $this->title( $get ) );
	}

	/**
	 * Errors for another form do not flag the title.
	 */
	public function test_errors_of_another_form_do_not_flag_the_title() {
		update_user_meta(
			self::$admin_id,
			'sc_wpun_channel_form',
			array(
				'site'   => get_current_blog_id(),
				'time'   => time(),
				'form'   => 'edit:other',
				'values' => array(),
				'errors' => array( 'label' => 'Broken.' ),
			)
		);

		$this->assertSame(
			'Edit Email &lsaquo; ' . self::CORE,
			$this->title(
				array(
					'tab'    => 'channels',
					'action' => 'edit',
					'id'     => 'email',
				)
			)
		);
	}

	/**
	 * An invalid General save gives an Error title, and the page still shows the message.
	 */
	public function test_an_invalid_general_save_flags_the_title() {
		add_settings_error( 'sc_wpun_settings_main_x', 'sc_wpun_settings_main_x_error', 'Bad value.', 'error' );
		set_transient( 'settings_errors', $GLOBALS['wp_settings_errors'], 30 );
		unset( $GLOBALS['wp_settings_errors'] );

		$this->assertSame( 'Error: General &lsaquo; ' . self::CORE, $this->title( array( 'settings-updated' => 'true' ) ) );
		$this->assertStringContainsString( 'Bad value.', get_echo( 'settings_errors' ) );
	}

	/**
	 * A save that failed only because of a settings group on General gives an Error title, and the form still shows
	 * the errors and the typed value after the title looked.
	 */
	public function test_a_failed_settings_group_save_on_general_flags_the_title() {
		$this->with_group( array( 'label' => 'Acme' ) );
		$this->failed_group_save( 'general', self::$admin_id );

		$this->assertSame( 'Error: General &lsaquo; ' . self::CORE, $this->title() );
		$this->assertNotEmpty( get_user_meta( self::$admin_id, WPUN\Admin\Settings_Group_Form::META_KEY, true ), 'The title does not use it up.' );

		$html = $this->html();
		$this->assertStringContainsString( 'Broken name.', $html );
		$this->assertStringContainsString( 'value="Typed"', $html );
	}

	/**
	 * The same on an add-on's own tab; errors kept for another tab or another user do not flag the title.
	 */
	public function test_a_failed_settings_group_save_on_its_own_tab_flags_the_title() {
		$this->with_group(
			array(
				'label'     => 'Acme',
				'tab'       => 'acme-tab',
				'tab_label' => 'Acme tab',
			)
		);

		$this->failed_group_save( 'general', self::$admin_id );
		$this->assertSame( 'Acme tab &lsaquo; ' . self::CORE, $this->title( array( 'tab' => 'acme-tab' ) ), 'Another tab.' );

		$this->failed_group_save( 'acme-tab', self::factory()->user->create() );
		$this->assertSame( 'Acme tab &lsaquo; ' . self::CORE, $this->title( array( 'tab' => 'acme-tab' ) ), 'Another user.' );

		$this->failed_group_save( 'acme-tab', self::$admin_id );
		$this->assertSame( 'Error: Acme tab &lsaquo; ' . self::CORE, $this->title( array( 'tab' => 'acme-tab' ) ) );
	}

	/**
	 * A successful General save is not an error.
	 */
	public function test_a_successful_general_save_is_not_flagged() {
		add_settings_error( 'general', 'settings_updated', 'Saved.', 'success' );

		$this->assertSame( 'General &lsaquo; ' . self::CORE, $this->title() );
	}
}
