<?php
/**
 * The deprecated SC_WP_Updates_Notifier class.
 *
 * It is deprecated in 3.0.0 and removed in 4.0.0 (#603). Until then every method raises a deprecation
 * notice, and the plugin itself never calls or hooks the class.
 *
 * @package wp-updates-notifier
 */

/**
 * Deprecation of the legacy facade.
 */
class Test_Legacy_Facade extends WPUN_Test_Case {

	/**
	 * Deprecations raised by the code under test: method name => version.
	 *
	 * @var array<string, string>
	 */
	private $raised = array();

	/**
	 * Start listening for deprecated function notices.
	 */
	public function set_up() {
		parent::set_up();

		$this->raised = array();
		add_action(
			'deprecated_function_run',
			function ( $function_name, $replacement, $version ) {
				unset( $replacement );
				$this->raised[ (string) $function_name ] = (string) $version;
			},
			10,
			3
		);
	}

	/**
	 * The public methods the class declares itself.
	 *
	 * @return string[]
	 */
	private function declared_methods(): array {
		$names = array();
		foreach ( ( new ReflectionClass( 'SC_WP_Updates_Notifier' ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			if ( 'SC_WP_Updates_Notifier' === $method->getDeclaringClass()->getName() && ! $method->isStatic() ) {
				$names[] = $method->getName();
			}
		}

		return $names;
	}

	/**
	 * Harmless arguments for a method, so calling it changes nothing a test cares about.
	 *
	 * @param string $name Method name.
	 * @return array
	 */
	private function arguments_for( string $name ): array {
		$arguments = array(
			'plugin_action_links'            => array( array( 'x' ), 'other/other.php' ),
			'settings_updated'               => array( array( 'frequency' => 'daily' ), array( 'frequency' => 'daily' ) ),
			'sc_wpun_settings_validate'      => array( $this->settings() ),
			'send_email_message'             => array( 'Hello' ),
			'send_slack_message'             => array( 'Hello' ),
			'check_plugins_against_notified' => array( array() ),
			'check_themes_against_notified'  => array( array() ),
			'filter_auto_core_update_email'  => array( array( 'to' => 'admin@example.org' ) ),
		);

		return $arguments[ $name ] ?? array();
	}

	/**
	 * Every public method of the class raises a deprecation notice with a 2.0.0 or 3.0.0 version.
	 *
	 * A method added without a notice fails here. The methods are called, not read from the source.
	 */
	public function test_every_public_method_raises_a_deprecation_notice() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->save_settings();
		$this->set_updates();

		$facade  = $this->legacy_facade();
		$methods = $this->declared_methods();
		$this->assertGreaterThan( 30, count( $methods ), 'The class lost its methods: is this test still looking at the right class?' );

		foreach ( $methods as $name ) {
			$this->setExpectedDeprecated( 'SC_WP_Updates_Notifier::' . $name );
		}

		// The settings page draws only after the settings are registered.
		$facade->admin_settings_init();

		foreach ( $methods as $name ) {
			unset( $this->raised[ 'SC_WP_Updates_Notifier::' . $name ] );

			ob_start();
			$facade->{$name}( ...$this->arguments_for( $name ) );
			ob_end_clean();

			$this->assertArrayHasKey( 'SC_WP_Updates_Notifier::' . $name, $this->raised, $name . '() does not call _deprecated_function().' );
			$this->assertContains( $this->raised[ 'SC_WP_Updates_Notifier::' . $name ], array( '2.0.0', '3.0.0' ), $name );
		}
	}

	/**
	 * A normal request does not touch the class: no deprecation, and no hook calls a method of it.
	 */
	public function test_the_plugin_does_not_use_the_class_itself() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->save_settings();
		$this->set_updates( '99.0.0' );

		// The front end, the admin, and the scheduled check.
		do_action( 'init' );
		set_current_screen( 'dashboard' );
		remove_action( 'admin_init', 'send_frame_options_header' ); // Headers are already sent in a test.
		remove_action( 'admin_init', 'wp_admin_headers' );
		remove_action( 'admin_init', '_wp_admin_bar_init' ); // Loads core scripts that a WordPress 6.7 test checkout lacks.
		do_action( 'admin_init' );
		do_action( 'admin_menu' );
		do_action( \WPUN\Scheduler::CRON_NAME );
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();
		ob_end_clean();

		// Activating and deactivating.
		sc_wpun()->activate();
		sc_wpun()->deactivate( true );

		$this->assertSame( array(), preg_grep( '/^SC_WP_Updates_Notifier::/', array_keys( $this->raised ) ), 'A normal request raised a deprecation notice.' );
		$this->assertSame( array(), $this->hooked_facade_callbacks() );
	}

	/**
	 * Names of the hooks that call a method of the class.
	 *
	 * @return string[]
	 */
	private function hooked_facade_callbacks(): array {
		global $wp_filter;

		$found = array();
		foreach ( $wp_filter as $hook => $handlers ) {
			foreach ( $handlers->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && ( $function[0] instanceof SC_WP_Updates_Notifier || 'SC_WP_Updates_Notifier' === $function[0] ) ) {
						$found[] = $hook;
					}
				}
			}
		}

		return $found;
	}
}
