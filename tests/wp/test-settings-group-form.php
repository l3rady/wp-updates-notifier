<?php
/**
 * The panels and forms of the settings groups add-ons register (#455).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Settings_Group_Form;
use WPUN\Admin\Settings_Page;
use WPUN\Settings_Groups;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties are camelCase.

/**
 * Drawing a group on the General tab and on its own tab, and saving it.
 *
 * Constants cannot be undefined, so every test that defines one uses a group id of its own.
 */
class Test_Settings_Group_Form extends WPUN_Test_Case {

	const SECRET = 'bad-SECRET-VALUE-123';

	/**
	 * Tests the plugin as one site's, so on a multisite it is not network-activated.
	 *
	 * @var bool
	 */
	protected $network_activated = false;

	/**
	 * The plugin's own objects, put back after each test.
	 *
	 * @var array
	 */
	private $shared = array();

	/**
	 * The calls of the saved hook.
	 *
	 * @var array
	 */
	private $saved = array();

	/**
	 * Fresh groups, form and page for each test, because the registration action fires once per object.
	 */
	public function set_up() {
		parent::set_up();

		foreach ( array( 'settings_groups', 'settings_group_form', 'settings_page' ) as $name ) {
			$this->shared[ $name ] = $this->property( $name );
		}
		// The page's configuration report holds the plugin's own groups: load them before the test adds its registrations.
		$this->shared['settings_groups']->all();
		$groups = new Settings_Groups( sc_wpun()->config(), sc_wpun()->channels(), sc_wpun()->settings(), sc_wpun()->network_context() );
		$this->set_property( 'settings_groups', $groups );
		$this->set_property( 'settings_group_form', new Settings_Group_Form( $groups ) );
		$this->set_property( 'settings_page', null );
		WPUN\Field_Types::forget_warnings();
		sc_wpun()->config()->flush();
		$this->save_settings();

		$this->saved = array();
		add_action(
			'sc_wpun_settings_group_saved',
			function ( $group, $stored_now, $old ) {
				$this->saved[] = array( $group, $stored_now, $old );
			},
			10,
			3
		);
	}

	/**
	 * Put back the plugin's objects and the request.
	 */
	public function tear_down() {
		foreach ( $this->shared as $name => $value ) {
			$this->set_property( $name, $value );
		}
		$_POST    = array();
		$_REQUEST = array();
		unset( $_GET['tab'] );
		$GLOBALS['wp_settings_errors'] = array();
		delete_transient( 'settings_errors' );
		sc_wpun()->config()->flush();
		parent::tear_down();
	}

	/**
	 * A property of the plugin.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	private function property( $name ) {
		$property = new ReflectionProperty( sc_wpun(), $name );
		$property->setAccessible( true );

		return $property->getValue( sc_wpun() );
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
	 * Register groups through the action.
	 *
	 * @param array[] $groups Arguments by group id: `args` and `fields`.
	 */
	private function register( array $groups ) {
		add_action(
			'sc_wpun_register_settings',
			static function () use ( $groups ) {
				foreach ( $groups as $id => $group ) {
					sc_wpun_register_settings_group( $id, $group['args'] ?? array( 'label' => 'Group ' . $id ), $group['fields'] );
				}
			}
		);
	}

	/**
	 * Fields with a text, a secret, a number and a boolean.
	 *
	 * @param array $extra Fields to add.
	 * @return array
	 */
	private function fields( array $extra = array() ) {
		return array(
			'name'    => array(
				'type'        => 'text',
				'label'       => 'Name',
				'description' => 'The name.',
				'default'     => 'fallback',
			),
			'token'   => array(
				'type'  => 'secret',
				'label' => 'Token',
			),
			'limit'   => array(
				'type'  => 'number',
				'label' => 'Limit',
				'min'   => 1,
				'max'   => 10,
			),
			'verbose' => array(
				'type'  => 'boolean',
				'label' => 'Verbose',
			),
		) + $extra;
	}

	/**
	 * A user who may use the screen.
	 *
	 * @return int
	 */
	private function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );

		return $id;
	}

	/**
	 * The settings page HTML.
	 *
	 * @param string $tab Tab to show.
	 * @return string
	 */
	private function html( $tab = 'general' ) {
		$_GET['tab'] = $tab;
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();

		return (string) ob_get_clean();
	}

	/**
	 * The page, parsed.
	 *
	 * @param string $html Page HTML.
	 * @return DOMXPath
	 */
	private function xpath( $html ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return new DOMXPath( $doc );
	}

	/**
	 * Make the request of the form of a tab.
	 *
	 * @param array       $groups    Posted values by group id, then by field.
	 * @param array       $settings  The plugin's own settings posted; only the General tab has them.
	 * @param string      $tab       Tab the form is on.
	 * @param string|null $nonce_for Tab the security token is made for; null for the tab itself, '' for none.
	 */
	private function post( array $groups, array $settings = array(), $tab = 'general', $nonce_for = null ) {
		$_POST     = array(
			'action'                      => Settings_Page::SAVE_ACTION,
			'tab'                         => $tab,
			Settings_Group_Form::POST_KEY => $groups,
		);
		$nonce_for = $nonce_for ?? $tab;
		if ( array() !== $settings ) {
			$_POST[ WPUN\Settings::OPT_FIELD ] = $settings;
		}
		if ( '' !== $nonce_for ) {
			$_POST['_wpnonce'] = wp_create_nonce( Settings_Page::SAVE_ACTION . '_' . $nonce_for );
		}
	}

	/**
	 * Save the posted form.
	 *
	 * @return string|null
	 */
	private function save() {
		sc_wpun()->settings_page()->admin_settings_init();

		return sc_wpun()->settings_page()->process_save();
	}

	/**
	 * The stored values of a group.
	 *
	 * @param string $group Group id.
	 * @return mixed
	 */
	private function stored( $group ) {
		return sc_wpun()->network_context()->get_option( 'sc_wpun_settings_' . $group );
	}

	/**
	 * The stored frequency of the plugin's settings.
	 *
	 * @return mixed
	 */
	private function frequency() {
		return sc_wpun()->network_context()->get_option( 'sc_wpun_settings' )['frequency'];
	}

	/**
	 * The save form of the page: the only form that posts the settings.
	 */
	const FORM = '//form[input[@name="action"][@value="sc_wpun_save_settings"]]';

	// Drawing.

	/**
	 * A group on the General tab is a panel in the plugin's form: after its Settings panel, before the one button.
	 */
	public function test_a_general_group_is_a_panel_in_the_settings_form() {
		$this->admin();
		$this->register( array( 'acme' => array( 'fields' => $this->fields() ) ) );

		$xpath = $this->xpath( $this->html() );

		$this->assertSame( 1, $xpath->query( self::FORM )->length );
		$this->assertSame( 0, $xpath->query( '//form[contains(@action,"options.php")]' )->length );
		$this->assertSame( 0, $xpath->query( '//form[@class="sc-wpun-group-form"]' )->length );
		$form = $xpath->query( self::FORM )->item( 0 );
		$this->assertStringContainsString( 'admin-post.php', $form->getAttribute( 'action' ) );
		$this->assertSame( 'post', $form->getAttribute( 'method' ) );
		$this->assertSame( 'general', $xpath->query( './/input[@name="tab"]', $form )->item( 0 )->getAttribute( 'value' ) );
		$this->assertNotSame( '', $xpath->query( './/input[@name="_wpnonce"]', $form )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( './/input[@name="option_page"] | .//input[@name="action"][@value="update"]', $form )->length );

		$panels = array();
		foreach ( $xpath->query( './fieldset[@class="sc-wpun-panel"]/legend/h2 | ./div[@class="sc-wpun-group"]/fieldset[@class="sc-wpun-panel"]/legend/h2', $form ) as $title ) {
			$panels[] = trim( $title->textContent );
		}
		$this->assertSame( array( 'Settings', 'Group acme' ), $panels );
		$this->assertSame( 1, $xpath->query( './/div[@id="sc_wpun_group_acme"]/following::input[@type="submit"]', $form )->length, 'The button is after the group.' );
		$this->assertSame( 1, $xpath->query( self::FORM . '//input[@type="submit"]' )->length, 'One button in the form.' );
		$this->assertSame( 1, $xpath->query( '//input[@type="submit"][@value="Save settings"]' )->length );
	}

	/**
	 * Fields have the ids and names of the group, and a label.
	 */
	public function test_field_ids_names_and_labels() {
		$this->admin();
		$this->register( array( 'acme' => array( 'fields' => $this->fields() ) ) );

		$xpath = $this->xpath( $this->html() );

		$input = $xpath->query( '//input[@id="sc_wpun_group_acme_name"]' );
		$this->assertSame( 1, $input->length );
		$this->assertSame( 'sc_wpun_group[acme][name]', $input->item( 0 )->getAttribute( 'name' ) );
		$this->assertSame( 'fallback', $input->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'sc_wpun_group_acme_name-description', $input->item( 0 )->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 'The name.', trim( $xpath->query( '//p[@id="sc_wpun_group_acme_name-description"]' )->item( 0 )->textContent ) );
		$this->assertSame( 'Name', trim( $xpath->query( '//label[@for="sc_wpun_group_acme_name"]' )->item( 0 )->textContent ) );
		$this->assertSame( 'sc_wpun_group[acme][limit]', $xpath->query( '//input[@id="sc_wpun_group_acme_limit"]' )->item( 0 )->getAttribute( 'name' ) );
	}

	/**
	 * A group's description is the panel's description.
	 */
	public function test_the_description_describes_the_panel() {
		$this->admin();
		$this->register(
			array(
				'acme' => array(
					'args'   => array(
						'label'       => 'Acme',
						'description' => 'About Acme.',
					),
					'fields' => $this->fields(),
				),
			)
		);

		$xpath = $this->xpath( $this->html() );

		$this->assertSame( 'sc_wpun_group_acme-description', $xpath->query( '//fieldset[@class="sc-wpun-panel"][legend/h2="Acme"]' )->item( 0 )->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 'About Acme.', trim( $xpath->query( '//p[@id="sc_wpun_group_acme-description"]' )->item( 0 )->textContent ) );
	}

	/**
	 * A group on another tab adds the tab, and is drawn only there.
	 */
	public function test_a_group_on_its_own_tab() {
		$this->admin();
		$this->register(
			array(
				'acme' => array(
					'args'   => array(
						'label'     => 'Acme',
						'tab'       => 'acme-tab',
						'tab_label' => 'Acme tab',
					),
					'fields' => $this->fields(),
				),
			)
		);

		$tabs = array();
		foreach ( $this->xpath( $this->html( 'general' ) )->query( '//nav[@class="nav-tab-wrapper"]/a' ) as $link ) {
			$tabs[] = trim( $link->textContent );
		}
		$this->assertSame( array( 'General', 'Channels', 'Muted', 'Acme tab', 'About' ), $tabs );
		$this->assertSame( 0, $this->xpath( $this->html( 'general' ) )->query( '//div[@class="sc-wpun-group"]' )->length );

		$xpath = $this->xpath( $this->html( 'acme-tab' ) );
		$this->assertSame( 1, $xpath->query( self::FORM )->length );
		$this->assertSame( 'acme-tab', $xpath->query( self::FORM . '//input[@name="tab"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( '//*[@name="sc_wpun_settings[frequency]"]' )->length, 'The plugin\'s settings are on General.' );
		$this->assertSame( 1, $xpath->query( self::FORM . '//input[@type="submit"]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[contains(@class,"nav-tab-active")][.="Acme tab"]' )->length );
	}

	/**
	 * The tab is labelled by the first group that gave a tab label, else by the first group.
	 */
	public function test_the_tab_label_falls_back() {
		$this->register(
			array(
				'one'   => array(
					'args'   => array(
						'label' => 'First',
						'tab'   => 'shared',
					),
					'fields' => $this->fields(),
				),
				'two'   => array(
					'args'   => array(
						'label'     => 'Second',
						'tab'       => 'shared',
						'tab_label' => 'Chosen',
					),
					'fields' => $this->fields(),
				),
				'three' => array(
					'args'   => array(
						'label' => 'Third',
						'tab'   => 'plain',
					),
					'fields' => $this->fields(),
				),
				'four'  => array(
					'args'   => array(
						'label' => 'Fourth',
						'tab'   => 'plain',
					),
					'fields' => $this->fields(),
				),
			)
		);

		$labels = sc_wpun()->settings_group_form()->tab_labels();

		$this->assertSame(
			array(
				'shared' => 'Chosen',
				'plain'  => 'Third',
			),
			$labels
		);
		$this->admin();
		$xpath = $this->xpath( $this->html( 'shared' ) );
		$this->assertSame( 1, $xpath->query( self::FORM )->length, 'One form.' );
		$this->assertSame( 2, $xpath->query( self::FORM . '//div[@class="sc-wpun-group"]' )->length, 'Every group on the tab.' );
		$this->assertSame( 1, $xpath->query( '//input[@type="submit"]' )->length, 'One button for them all.' );
	}

	/**
	 * A value supplied from configuration is disabled and says where it comes from; a secret is not printed.
	 */
	public function test_supplied_fields_are_read_only() {
		define( 'SC_WPUN_SETTING_SUPPA__NAME', 'from-constant' );
		define( 'SC_WPUN_SETTING_SUPPA__TOKEN', self::SECRET );
		$this->admin();
		$this->register( array( 'suppa' => array( 'fields' => $this->fields() ) ) );

		$html  = $this->html();
		$xpath = $this->xpath( $html );

		$name = $xpath->query( '//input[@id="sc_wpun_group_suppa_name"]' )->item( 0 );
		$this->assertTrue( $name->hasAttribute( 'disabled' ) );
		$this->assertSame( '', $name->getAttribute( 'value' ), 'A text value from configuration is not printed.' );
		$this->assertStringContainsString( 'sc_wpun_group_suppa_name-external', $name->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 'Set by constant SC_WPUN_SETTING_SUPPA__NAME', trim( $xpath->query( '//span[@id="sc_wpun_group_suppa_name-external"]' )->item( 0 )->textContent ) );
		$this->assertTrue( $xpath->query( '//input[@id="sc_wpun_group_suppa_token"]' )->item( 0 )->hasAttribute( 'disabled' ) );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringNotContainsString( 'from-constant', $html );
		$this->assertFalse( $xpath->query( '//input[@id="sc_wpun_group_suppa_limit"]' )->item( 0 )->hasAttribute( 'disabled' ) );
	}

	/**
	 * A field whose supplied value could not be used and was replaced by on_invalid is locked too.
	 */
	public function test_a_field_with_on_invalid_applied_is_locked() {
		define( 'SC_WPUN_SETTING_INVAL__ENABLED', 'maybe' );
		$this->admin();
		$this->register(
			array(
				'inval' => array(
					'fields' => array(
						'enabled' => array(
							'type'       => 'boolean',
							'default'    => true,
							'on_invalid' => false,
						),
					),
				),
			)
		);

		$xpath = $this->xpath( $this->html() );

		$this->assertTrue( $xpath->query( '//input[@id="sc_wpun_group_inval_enabled"]' )->item( 0 )->hasAttribute( 'disabled' ) );
		$this->assertSame( 1, $xpath->query( '//span[@id="sc_wpun_group_inval_enabled-external"]' )->length );
	}

	/**
	 * A code-only field is a line only while something supplies it.
	 */
	public function test_a_code_only_field_is_shown_only_when_supplied() {
		define( 'SC_WPUN_SETTING_CODEB__ENABLED', false );
		$this->admin();
		$code_only = array(
			'enabled' => array(
				'type'    => 'boolean',
				'default' => true,
				'storage' => false,
			),
		);
		$this->register(
			array(
				'codea' => array( 'fields' => $this->fields( $code_only ) ),
				'codeb' => array( 'fields' => $this->fields( $code_only ) ),
			)
		);

		$xpath = $this->xpath( $this->html() );

		$this->assertSame( 0, $xpath->query( '//*[@id="sc_wpun_group_codea_enabled"]' )->length );
		$this->assertSame( 1, $xpath->query( '//*[@id="sc_wpun_group_codeb_enabled"]' )->length );
		$this->assertTrue( $xpath->query( '//*[@id="sc_wpun_group_codeb_enabled"]' )->item( 0 )->hasAttribute( 'disabled' ) );
		$this->assertSame( 'Set by constant SC_WPUN_SETTING_CODEB__ENABLED', trim( $xpath->query( '//span[@id="sc_wpun_group_codeb_enabled-external"]' )->item( 0 )->textContent ) );
	}

	/**
	 * Field groups are fieldsets; a collapsed one is a details element; applies_when has its note.
	 */
	public function test_field_groups_and_applies_when_notes() {
		$this->admin();
		$fields                          = $this->fields();
		$fields['limit']['group']        = 'advanced';
		$fields['token']['applies_when'] = array( 'verbose' => true );
		$this->register(
			array(
				'acme' => array(
					'args'   => array(
						'label'        => 'Acme',
						'field_groups' => array(
							'advanced' => array(
								'label'       => 'Advanced',
								'description' => 'Rarely needed.',
								'collapsed'   => true,
							),
						),
					),
					'fields' => $fields,
				),
			)
		);

		$xpath = $this->xpath( $this->html() );

		$details = $xpath->query( '//details[@class="sc-wpun-field-group-details"]' );
		$this->assertSame( 1, $details->length );
		$this->assertSame( 'Advanced', trim( $xpath->query( './summary', $details->item( 0 ) )->item( 0 )->textContent ) );
		$this->assertSame( 1, $xpath->query( './/input[@id="sc_wpun_group_acme_limit"]', $details->item( 0 ) )->length );
		$this->assertSame( 0, $xpath->query( '//div[@id="sc_wpun_group_acme"]/fieldset[@class="sc-wpun-panel"]//details | //div[@id="sc_wpun_group_acme"]/fieldset[@class="sc-wpun-panel"]//fieldset' )->length, 'Never a box in a box.' );
		$this->assertSame( 1, $xpath->query( '//div[@id="sc_wpun_group_acme"]/details' )->length, 'A sibling of the group panel.' );
		$this->assertSame( 0, $xpath->query( '//details//input[@id="sc_wpun_group_acme_name"]' )->length );
		$this->assertStringContainsString( 'Used only when Verbose is Yes', $xpath->query( '//p[@id="sc_wpun_group_acme_token-applies-when"]' )->item( 0 )->textContent );
		$this->assertStringContainsString( 'sc_wpun_group_acme_token-applies-when', $xpath->query( '//input[@id="sc_wpun_group_acme_token"]' )->item( 0 )->getAttribute( 'aria-describedby' ) );
	}

	/**
	 * A group whose fields are all in field groups, with no description, has no panel of its own.
	 */
	public function test_a_group_with_only_field_groups_has_no_panel_of_its_own() {
		$this->admin();
		$this->register(
			array(
				'acme' => array(
					'args'   => array(
						'label'        => 'Acme',
						'field_groups' => array( 'adv' => array( 'label' => 'Advanced' ) ),
					),
					'fields' => array(
						'name' => array(
							'type'  => 'text',
							'group' => 'adv',
						),
					),
				),
			)
		);

		$xpath = $this->xpath( $this->html() );

		$this->assertSame( 0, $xpath->query( '//fieldset[@class="sc-wpun-panel"][legend/h2="Acme"]' )->length );
		$this->assertSame( 1, $xpath->query( '//div[@id="sc_wpun_group_acme"]/fieldset[@class="sc-wpun-field-group"][legend/h3="Advanced"]//input[@id="sc_wpun_group_acme_name"]' )->length );
	}

	/**
	 * Two groups with a field of the same name post under their own group.
	 */
	public function test_fields_of_the_same_name_in_two_groups_do_not_collide() {
		$this->admin();
		$this->register(
			array(
				'one' => array( 'fields' => $this->fields() ),
				'two' => array( 'fields' => $this->fields() ),
			)
		);

		$xpath = $this->xpath( $this->html() );

		$this->assertSame( 'sc_wpun_group[one][name]', $xpath->query( '//input[@id="sc_wpun_group_one_name"]' )->item( 0 )->getAttribute( 'name' ) );
		$this->assertSame( 'sc_wpun_group[two][name]', $xpath->query( '//input[@id="sc_wpun_group_two_name"]' )->item( 0 )->getAttribute( 'name' ) );

		sc_wpun_settings_groups();
		$this->post(
			array(
				'one' => array( 'name' => 'first' ),
				'two' => array( 'name' => 'second' ),
			)
		);
		$this->save();

		$this->assertSame( 'first', $this->stored( 'one' )['name'] );
		$this->assertSame( 'second', $this->stored( 'two' )['name'] );
	}

	// Capabilities.

	/**
	 * A user with the screen capability but not the group's sees disabled controls and a note; the form still has its button.
	 */
	public function test_a_stricter_group_capability_makes_the_panel_read_only() {
		$this->admin();
		$this->register(
			array(
				'strict' => array(
					'args'   => array(
						'label'      => 'Strict',
						'capability' => 'manage_acme_settings',
					),
					'fields' => $this->fields(),
				),
				'lax'    => array( 'fields' => $this->fields() ),
			)
		);

		$xpath = $this->xpath( $this->html() );

		$this->assertTrue( $xpath->query( '//input[@id="sc_wpun_group_strict_name"]' )->item( 0 )->hasAttribute( 'disabled' ) );
		$this->assertSame( 1, $xpath->query( '//input[@type="submit"]' )->length );
		$this->assertSame( 1, $xpath->query( '//p[contains(@class,"sc-wpun-group-readonly")]' )->length );
		$this->assertFalse( $xpath->query( '//input[@id="sc_wpun_group_lax_name"]' )->item( 0 )->hasAttribute( 'disabled' ) );
	}

	/**
	 * A tab of its own with only groups the user may not save has no button.
	 */
	public function test_a_tab_with_nothing_to_save_has_no_button() {
		$this->admin();
		$this->register(
			array(
				'strict' => array(
					'args'   => array(
						'label'      => 'Strict',
						'tab'        => 'strict-tab',
						'capability' => 'manage_acme_settings',
					),
					'fields' => $this->fields(),
				),
			)
		);

		$xpath = $this->xpath( $this->html( 'strict-tab' ) );

		$this->assertSame( 1, $xpath->query( '//input[@id="sc_wpun_group_strict_name"][@disabled]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@type="submit"]' )->length );

		wp_get_current_user()->add_cap( 'manage_acme_settings' );
		$this->assertSame( 1, $this->xpath( $this->html( 'strict-tab' ) )->query( '//input[@type="submit"]' )->length );
	}

	/**
	 * A group the user may not save is ignored, and the rest of the form still saves.
	 */
	public function test_saving_ignores_a_group_the_user_may_not_save() {
		$this->admin();
		$this->register(
			array(
				'strict' => array(
					'args'   => array(
						'label'      => 'Strict',
						'capability' => 'manage_acme_settings',
					),
					'fields' => $this->fields(),
				),
				'lax'    => array( 'fields' => $this->fields() ),
			)
		);
		sc_wpun_settings_groups();
		sc_wpun()->settings_groups()->save( 'strict', array( 'name' => 'kept' ) );

		$this->post(
			array(
				'strict' => array(
					'name'  => 'x',
					'limit' => '99',
				),
				'lax'    => array( 'name' => 'y' ),
			),
			array( 'frequency' => 'daily' )
		);
		$url = $this->save();

		$this->assertStringContainsString( 'settings-updated=true', (string) $url );
		$this->assertSame( array( 'name' => 'kept' ), $this->stored( 'strict' ), 'Not saved and not validated (the limit is out of range).' );
		$this->assertSame( 'y', $this->stored( 'lax' )['name'] );
		$this->assertSame( 'daily', $this->frequency() );
		$this->assertCount( 1, $this->saved );
		$this->assertSame( 'lax', $this->saved[0][0] );
	}

	/**
	 * Saving needs the screen capability.
	 */
	public function test_saving_needs_the_screen_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->register( array( 'acme' => array( 'fields' => $this->fields() ) ) );
		sc_wpun_settings_groups();

		$this->post( array( 'acme' => array( 'name' => 'x' ) ) );

		$this->assertNull( $this->save() );
		$this->assertFalse( $this->stored( 'acme' ) );
	}

	/**
	 * A missing, wrong or other tab's security token is refused.
	 */
	public function test_the_security_token_is_checked() {
		$this->admin();
		$this->register( array( 'acme' => array( 'fields' => $this->fields() ) ) );
		sc_wpun_settings_groups();
		$values = array( 'acme' => array( 'name' => 'x' ) );

		$this->post( $values, array( 'frequency' => 'daily' ), 'general', '' );
		$this->assertNull( $this->save(), 'None.' );

		$this->post( $values, array( 'frequency' => 'daily' ), 'general', 'other' );
		$this->assertNull( $this->save(), 'Made for another tab.' );

		$this->post( $values, array( 'frequency' => 'daily' ) );
		$_POST['_wpnonce'] = 'nope';
		$this->assertNull( $this->save(), 'Wrong.' );

		$this->post( $values, array( 'frequency' => 'daily' ) );
		$_GET['_wpnonce'] = $_POST['_wpnonce'];
		unset( $_POST['_wpnonce'] );
		$this->assertNull( $this->save(), 'Only the request body counts.' );
		unset( $_GET['_wpnonce'] );

		$this->assertFalse( $this->stored( 'acme' ) );
		$this->assertNotSame( 'daily', $this->frequency() );
		$this->assertSame( array(), $this->saved );
	}

	/**
	 * A tab nothing draws goes back to the screen without saving.
	 */
	public function test_an_unknown_tab_goes_back_to_the_screen() {
		$this->admin();
		$this->post( array(), array( 'frequency' => 'daily' ), 'nosuch' );

		$url = (string) $this->save();

		$this->assertSame( sc_wpun()->network_context()->settings_url(), $url );
		$this->assertNotSame( 'daily', $this->frequency() );
	}

	/**
	 * The handler answers 403 when the request is refused.
	 */
	public function test_the_handler_refuses_with_403() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$died = null;
		add_filter(
			'wp_die_handler',
			static function () use ( &$died ) {
				return static function ( $message, $title, $args ) use ( &$died ) {
					$died = (int) ( $args['response'] ?? 0 );
					throw new WPDieException( 'wp_die' );
				};
			}
		);
		$this->post( array() );

		try {
			sc_wpun()->settings_page()->handle_save();
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $died );
			return;
		}
		$this->fail( 'The request was not refused.' );
	}

	// Saving.

	/**
	 * A valid form stores the plugin's settings and every group, fires the hook for each, and says so once.
	 */
	public function test_a_valid_save_stores_everything_fires_the_hook_and_notifies() {
		$this->admin();
		$this->register(
			array(
				'acme'  => array( 'fields' => $this->fields() ),
				'other' => array( 'fields' => $this->fields() ),
			)
		);
		sc_wpun_settings_groups();
		sc_wpun()->settings_groups()->save( 'acme', array( 'name' => 'before' ) );

		$this->post(
			array(
				'acme'  => array(
					'name'    => 'after',
					'token'   => self::SECRET,
					'limit'   => '5',
					'verbose' => '1',
				),
				'other' => array( 'name' => 'second' ),
			),
			array(
				'frequency'   => 'daily',
				'notify_core' => 'none',
			)
		);
		$url = $this->save();

		$this->assertStringContainsString( 'tab=general', (string) $url );
		$this->assertStringContainsString( 'settings-updated=true', (string) $url );
		$this->assertSame( 'daily', $this->frequency() );
		$this->assertSame( 'none', sc_wpun()->network_context()->get_option( 'sc_wpun_settings' )['notify_core'] );
		$stored = $this->stored( 'acme' );
		$this->assertSame(
			array(
				'name'    => 'after',
				'token'   => self::SECRET,
				'limit'   => 5,
				'verbose' => true,
			),
			$stored
		);
		$this->assertSame( 'second', $this->stored( 'other' )['name'] );
		$this->assertCount( 2, $this->saved );
		$this->assertSame( 'acme', $this->saved[0][0] );
		$this->assertSame( $stored, $this->saved[0][1] );
		$this->assertSame( array( 'name' => 'before' ), $this->saved[0][2] );
		$this->assertSame( 'other', $this->saved[1][0] );

		$errors = get_transient( 'settings_errors' );
		$this->assertCount( 1, $errors, 'One message, core\'s.' );
		$this->assertSame( 'settings_updated', $errors[0]['code'] );
		$this->assertSame( 'success', $errors[0]['type'] );
		$this->assertNull( sc_wpun()->notices()->take(), 'No notice of the plugin\'s own as well.' );
	}

	/**
	 * The hook fires when the values did not change.
	 */
	public function test_the_hook_fires_when_nothing_changed() {
		$this->admin();
		$this->register( array( 'acme' => array( 'fields' => $this->fields() ) ) );
		sc_wpun_settings_groups();

		$this->post( array( 'acme' => array( 'name' => 'same' ) ) );
		$this->save();
		$this->save();

		$this->assertCount( 2, $this->saved );
		$this->assertSame( $this->saved[1][1], $this->saved[1][2], 'New and old are the same.' );
	}

	/**
	 * The redirect goes back to the page the form was on, else to the tab.
	 */
	public function test_the_redirect_goes_back_to_the_referer_or_the_tab() {
		$this->admin();
		$this->register(
			array(
				'acme' => array(
					'args'   => array(
						'label' => 'Acme',
						'tab'   => 'acme-tab',
					),
					'fields' => $this->fields(),
				),
			)
		);
		sc_wpun_settings_groups();

		$this->post( array( 'acme' => array( 'name' => 'x' ) ), array(), 'acme-tab' );
		$this->assertSame( add_query_arg( 'settings-updated', 'true', sc_wpun()->network_context()->settings_url( 'acme-tab' ) ), $this->save() );

		$this->post( array( 'acme' => array( 'name' => 'x' ) ), array(), 'acme-tab' );
		$_POST['_wp_http_referer'] = '/wp-admin/options-general.php?page=wp-updates-notifier&tab=acme-tab&x=1';
		$_REQUEST                  = $_POST;
		$this->assertStringContainsString( '&x=1&settings-updated=true', (string) $this->save() );
	}

	/**
	 * A tab of its own saves its groups only: the plugin's settings and the groups of other tabs are left alone.
	 */
	public function test_a_tab_of_its_own_saves_its_own_groups_only() {
		$this->admin();
		$this->register(
			array(
				'acme'  => array(
					'args'   => array(
						'label' => 'Acme',
						'tab'   => 'acme-tab',
					),
					'fields' => $this->fields(),
				),
				'other' => array( 'fields' => $this->fields() ),
			)
		);
		sc_wpun_settings_groups();

		$this->post(
			array(
				'acme'  => array( 'name' => 'mine' ),
				'other' => array( 'name' => 'not mine' ),
			),
			array( 'frequency' => 'daily' ),
			'acme-tab'
		);
		$this->save();

		$this->assertSame( 'mine', $this->stored( 'acme' )['name'] );
		$this->assertFalse( $this->stored( 'other' ) );
		$this->assertNotSame( 'daily', $this->frequency() );
		$this->assertCount( 1, $this->saved );
	}

	/**
	 * A secret is kept when left empty and emptied with its clear box.
	 */
	public function test_secrets_are_kept_and_cleared() {
		$this->admin();
		$this->register( array( 'acme' => array( 'fields' => $this->fields() ) ) );
		sc_wpun_settings_groups();
		sc_wpun()->settings_groups()->save( 'acme', array( 'token' => self::SECRET ) );

		$this->post(
			array(
				'acme' => array(
					'name'  => 'x',
					'token' => '',
				),
			)
		);
		$this->save();
		$this->assertSame( self::SECRET, $this->stored( 'acme' )['token'] );
		$this->assertStringNotContainsString( self::SECRET, $this->html() );

		$this->post(
			array(
				'acme' => array(
					'name'         => 'x',
					'token'        => '',
					'token__clear' => '1',
				),
			)
		);
		$this->save();
		$this->assertSame( '', $this->stored( 'acme' )['token'] );
	}

	/**
	 * A field supplied from configuration is not posted, validated or stored over.
	 */
	public function test_a_supplied_field_keeps_its_stored_value() {
		define( 'SC_WPUN_SETTING_SUPPB__NAME', 'from-constant' );
		$this->admin();
		$this->register( array( 'suppb' => array( 'fields' => $this->fields() ) ) );
		sc_wpun_settings_groups();
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings_suppb', array( 'name' => 'old' ) );

		$this->post(
			array(
				'suppb' => array(
					'name'  => 'posted',
					'limit' => '3',
				),
			)
		);
		$this->save();

		$stored = $this->stored( 'suppb' );
		$this->assertSame( 'old', $stored['name'] );
		$this->assertSame( 3, $stored['limit'] );
	}

	/**
	 * A code-only field posted is ignored.
	 */
	public function test_a_code_only_field_posted_is_ignored() {
		$this->admin();
		$this->register(
			array(
				'acme' => array(
					'fields' => $this->fields(
						array(
							'flag' => array(
								'type'    => 'text',
								'storage' => false,
							),
						)
					),
				),
			)
		);
		sc_wpun_settings_groups();

		$this->post(
			array(
				'acme' => array(
					'name' => 'x',
					'flag' => 'y',
				),
			)
		);
		$this->save();

		$this->assertArrayNotHasKey( 'flag', $this->stored( 'acme' ) );
	}

	// Errors.

	/**
	 * A field error saves nothing, the plugin's settings included; the submitted values (no secrets) are drawn once with the errors.
	 */
	public function test_a_group_error_saves_nothing_and_keeps_the_submitted_values() {
		$user = $this->admin();
		$this->register(
			array(
				'acme'  => array( 'fields' => $this->fields() ),
				'other' => array( 'fields' => $this->fields() ),
			)
		);
		sc_wpun_settings_groups();
		sc_wpun()->settings_groups()->save( 'acme', array( 'name' => 'before' ) );

		$this->post(
			array(
				'acme'  => array(
					'name'  => 'typed',
					'token' => self::SECRET,
					'limit' => '99',
				),
				'other' => array( 'name' => 'fine' ),
			),
			array( 'frequency' => 'weekly' )
		);
		$url = $this->save();

		$this->assertStringContainsString( 'tab=general', (string) $url );
		$this->assertSame( array( 'name' => 'before' ), $this->stored( 'acme' ) );
		$this->assertFalse( $this->stored( 'other' ), 'The valid group is not saved either.' );
		$this->assertNotSame( 'weekly', $this->frequency(), 'Nor the plugin\'s settings.' );
		$this->assertSame( array(), $this->saved );
		$meta = get_user_meta( $user, Settings_Group_Form::META_KEY, true );
		$this->assertSame( 'tab:general', $meta['form'] );
		$this->assertSame( array( 'acme/limit' ), array_keys( $meta['errors'] ) );
		$this->assertSame( 'weekly', $meta['values']['settings']['frequency'] );
		$this->assertSame( 'typed', $meta['values']['groups']['acme']['name'] );
		$this->assertSame( 'fine', $meta['values']['groups']['other']['name'] );
		$this->assertArrayNotHasKey( 'token', $meta['values']['groups']['acme'] );
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( $meta ) );

		$html  = $this->html();
		$xpath = $this->xpath( $html );
		$this->assertSame( 'typed', $xpath->query( '//input[@id="sc_wpun_group_acme_name"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'fine', $xpath->query( '//input[@id="sc_wpun_group_other_name"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( '99', $xpath->query( '//input[@id="sc_wpun_group_acme_limit"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'weekly', $xpath->query( '//input[@name="sc_wpun_settings[frequency]"][@checked]' )->item( 0 )->getAttribute( 'value' ), 'The plugin\'s own setting is drawn as submitted.' );
		$this->assertStringNotContainsString( self::SECRET, $html );

		$summary = $xpath->query( '//div[contains(@class,"sc-wpun-form-errors")]' );
		$this->assertSame( 1, $summary->length );
		$this->assertSame( 'alert', $summary->item( 0 )->getAttribute( 'role' ) );
		$this->assertSame( 1, $xpath->query( './/a[@href="#sc_wpun_group_acme_limit"]', $summary->item( 0 ) )->length );
		$this->assertSame( 1, $xpath->query( self::FORM . '/div[contains(@class,"sc-wpun-form-errors")]' )->length, 'At the top of the form.' );
		$limit = $xpath->query( '//input[@id="sc_wpun_group_acme_limit"]' )->item( 0 );
		$this->assertSame( 'true', $limit->getAttribute( 'aria-invalid' ) );
		$this->assertStringContainsString( 'sc_wpun_group_acme_limit-error', $limit->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 1, $xpath->query( '//p[@id="sc_wpun_group_acme_limit-error"]' )->length );

		$again = $this->xpath( $this->html() );
		$this->assertSame( 0, $again->query( '//div[contains(@class,"sc-wpun-form-errors")]' )->length, 'Shown once.' );
		$this->assertNotSame( 'weekly', $again->query( '//input[@name="sc_wpun_settings[frequency]"][@checked]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( '', get_user_meta( $user, Settings_Group_Form::META_KEY, true ) );
	}

	/**
	 * An error in the plugin's own settings saves nothing either, groups included, and the group values are drawn again.
	 */
	public function test_a_main_setting_error_saves_the_groups_nowhere() {
		$user = $this->admin();
		$this->register( array( 'acme' => array( 'fields' => $this->fields() ) ) );
		sc_wpun_settings_groups();

		$this->post( array( 'acme' => array( 'name' => 'typed' ) ), array( 'frequency' => 'bogus' ) );
		$this->save();

		$this->assertFalse( $this->stored( 'acme' ) );
		$this->assertSame( array(), $this->saved );
		$errors = get_transient( 'settings_errors' );
		$this->assertSame( 'sc_wpun_settings_main_frequency_error', $errors[0]['code'] );
		$this->assertCount( 1, $errors );
		$meta = get_user_meta( $user, Settings_Group_Form::META_KEY, true );
		$this->assertSame( array(), $meta['errors'] );
		$this->assertSame( 'typed', $meta['values']['groups']['acme']['name'] );

		$this->assertSame( 'typed', $this->xpath( $this->html() )->query( '//input[@id="sc_wpun_group_acme_name"]' )->item( 0 )->getAttribute( 'value' ) );
	}

	/**
	 * A stale or other tab's remembered form is not shown.
	 */
	public function test_a_remembered_form_of_another_tab_is_not_shown() {
		$user = $this->admin();
		$this->register( array( 'acme' => array( 'fields' => $this->fields() ) ) );
		update_user_meta(
			$user,
			Settings_Group_Form::META_KEY,
			array(
				'site'   => get_current_blog_id(),
				'time'   => time(),
				'form'   => 'tab:other',
				'values' => array( 'groups' => array( 'acme' => array( 'name' => 'typed' ) ) ),
				'errors' => array( 'acme/name' => 'Bad.' ),
			)
		);

		$xpath = $this->xpath( $this->html() );

		$this->assertSame( 'fallback', $xpath->query( '//input[@id="sc_wpun_group_acme_name"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( '//div[contains(@class,"sc-wpun-form-errors")]' )->length );
	}

	/**
	 * The validate_callback of the group runs after the fields, gets the clean and stored values, and its errors land on fields.
	 */
	public function test_validate_callback_errors_land_on_fields() {
		$user = $this->admin();
		$seen = array();
		$this->register(
			array(
				'acme' => array(
					'args'   => array(
						'label'             => 'Acme',
						'validate_callback' => static function ( $clean, $stored ) use ( &$seen ) {
							$seen = array( $clean, $stored );
							if ( 'bad' === ( $clean['name'] ?? '' ) ) {
								return new WP_Error( 'bad_name', 'That name is taken.', array( 'field' => 'name' ) );
							}
							if ( 'whole' === ( $clean['name'] ?? '' ) ) {
								return new WP_Error( 'whole', 'The whole group is wrong.' );
							}
							$clean['name'] = strtoupper( $clean['name'] );
							return $clean;
						},
					),
					'fields' => $this->fields(),
				),
			)
		);
		sc_wpun_settings_groups();
		sc_wpun()->network_context()->update_option( 'sc_wpun_settings_acme', array( 'limit' => 2 ) );

		$this->post(
			array(
				'acme' => array(
					'name'  => 'bad',
					'token' => self::SECRET,
				),
			)
		);
		$this->save();

		$meta = get_user_meta( $user, Settings_Group_Form::META_KEY, true );
		$this->assertSame( array( 'acme/name' => 'That name is taken.' ), $meta['errors'] );
		$this->assertSame( 'bad', $meta['values']['groups']['acme']['name'] );
		$this->assertSame( array( 'limit' => 2 ), $this->stored( 'acme' ) );
		$this->assertSame( array( 'limit' => 2 ), $seen[1] );
		$this->assertSame( 'bad', $seen[0]['name'] );
		$xpath = $this->xpath( $this->html() );
		$this->assertSame( 1, $xpath->query( '//a[@href="#sc_wpun_group_acme_name"]' )->length );

		$this->post( array( 'acme' => array( 'name' => 'whole' ) ) );
		$this->save();
		$meta = get_user_meta( $user, Settings_Group_Form::META_KEY, true );
		$this->assertSame( array( 'acme/' => 'The whole group is wrong.' ), $meta['errors'] );
		$this->assertSame( 0, $this->xpath( $this->html() )->query( '//div[contains(@class,"sc-wpun-form-errors")]//a' )->length, 'An error without a field has no link.' );

		$this->post( array( 'acme' => array( 'name' => 'fine' ) ) );
		$this->save();
		$this->assertSame( 'FINE', $this->stored( 'acme' )['name'], 'The callback may change the values.' );
		$this->assertCount( 1, $this->saved );
	}

	// Network mode.

	/**
	 * In network mode the form still posts to admin-post.php, saves the site options, and goes back to the network screen.
	 */
	public function test_network_mode() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		$this->set_network_activated( true );
		$this->admin();
		grant_super_admin( get_current_user_id() );
		$this->register( array( 'acme' => array( 'fields' => $this->fields() ) ) );
		sc_wpun_settings_groups();
		$this->assertTrue( sc_wpun_is_network() );

		$html = $this->html();
		$form = $this->xpath( $html )->query( self::FORM )->item( 0 );
		$this->assertNotNull( $form );
		$this->assertStringContainsString( 'admin-post.php', $form->getAttribute( 'action' ) );

		$this->post( array( 'acme' => array( 'name' => 'net' ) ), array( 'frequency' => 'daily' ) );
		$url = (string) $this->save();

		$this->assertStringContainsString( 'network/', $url );
		$this->assertSame( array( 'name' => 'net' ), get_site_option( 'sc_wpun_settings_acme' ) );
		$this->assertSame( 'daily', $this->frequency() );
	}
}
