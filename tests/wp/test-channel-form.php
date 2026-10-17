<?php
/**
 * The add and edit channel forms (#166).
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties are camelCase.

use WPUN\Admin\Channel_Form;
use WPUN\Channel;
use WPUN\Channel_Instance;

/**
 * The picker, the forms, the id of a new channel, saving, errors, secrets, outside values and the hooks.
 */
class Test_Channel_Form extends WPUN_Test_Case {

	const SECRET = 'stored-SECRET-111';
	const TYPED  = 'typed-SECRET-222';

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * An editor.
	 *
	 * @var int
	 */
	private static $editor_id;

	/**
	 * Create the users.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * An administrator, the default email instance and the fixture modules.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->config()->flush();
		$this->save_settings();
		wp_set_current_user( self::$admin_id );
		sc_wpun()->channels()->register( new Test_Form_Channel() );
		sc_wpun()->channels()->register( new Test_Custom_Render_Channel() );
		Test_Form_Channel::register_colour_type();
	}

	/**
	 * Remove what tests registered.
	 */
	public function tear_down() {
		foreach ( array( 'fixture', 'fixture_custom', 'slack' ) as $type ) {
			sc_wpun()->channels()->unregister( $type );
		}
		sc_wpun()->config()->flush();
		$_POST = array();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'], $_GET['type'] );
		parent::tear_down();
	}

	/**
	 * The markup of the tab for a request.
	 *
	 * @param array $get Query arguments.
	 * @return string
	 */
	private function html( array $get = array() ): string {
		$_GET['tab'] = 'channels';
		unset( $_GET['action'], $_GET['id'], $_GET['type'] );
		foreach ( $get as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		ob_start();
		sc_wpun()->channels_tab()->render();

		return (string) ob_get_clean();
	}

	/**
	 * Parse markup.
	 *
	 * @param string $html Markup.
	 * @return DOMXPath
	 */
	private function parse( string $html ): DOMXPath {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return new DOMXPath( $doc );
	}

	/**
	 * The tab for a request, parsed.
	 *
	 * @param array $get Query arguments.
	 * @return DOMXPath
	 */
	private function page( array $get = array() ): DOMXPath {
		return $this->parse( $this->html( $get ) );
	}

	/**
	 * The edit form of an instance, parsed.
	 *
	 * @param string $id Instance id.
	 * @return DOMXPath
	 */
	private function edit_page( string $id = 'email' ): DOMXPath {
		return $this->page(
			array(
				'action' => 'edit',
				'id'     => $id,
			)
		);
	}

	/**
	 * Make the request of the email form.
	 *
	 * @param array       $overrides Values to replace; a null value removes the key. `settings` and `routing` are merged.
	 * @param string      $mode      `edit` or `new`.
	 * @param string|null $key       Id or type; `email` by default.
	 * @param string|null $nonce_for The part of the nonce action after the action name, if not the right one.
	 */
	private function post_email( array $overrides = array(), string $mode = 'edit', ?string $key = 'email', ?string $nonce_for = null ) {
		$key                                     = $key ?? 'email';
		$data                                    = array(
			'action'  => Channel_Form::SAVE_ACTION,
			'mode'    => $mode,
			'label'   => 'Email',
			'enabled' => '1',
			'routing' => array(
				'scope' => 'all',
				'core'  => 'all',
				'types' => array( '' ),
			),
		);
		$data[ 'new' === $mode ? 'type' : 'id' ] = $key;
		$settings                                = array(
			'to'             => 'ops@example.org',
			'from'           => '',
			'reply_to'       => '',
			'format'         => 'text',
			'subject_prefix' => '',
		);
		$this->set_post( $data, $settings, $overrides, $mode, $key, $nonce_for );
	}

	/**
	 * Make the request of the fixture form.
	 *
	 * @param array       $overrides Values to replace; `settings` and `routing` are merged.
	 * @param string      $mode      `edit` or `new`.
	 * @param string      $key       Id or type.
	 * @param string|null $nonce_for The part of the nonce action after the action name, if not the right one.
	 */
	private function post_fixture( array $overrides = array(), string $mode = 'new', string $key = 'fixture', ?string $nonce_for = null ) {
		$data                                    = array(
			'action'  => Channel_Form::SAVE_ACTION,
			'mode'    => $mode,
			'label'   => 'Fixture',
			'enabled' => '0',
			'routing' => array(
				'scope' => 'all',
				'core'  => 'all',
				'types' => array( '' ),
			),
		);
		$data[ 'new' === $mode ? 'type' : 'id' ] = $key;
		$settings                                = array(
			'colour' => '#00aa00',
			'token'  => '',
			'name'   => 'Name',
			'note'   => '',
		);
		$this->set_post( $data, $settings, $overrides, $mode, $key, $nonce_for );
	}

	/**
	 * Fill $_POST.
	 *
	 * @param array       $data      Base data.
	 * @param array       $settings  Base settings.
	 * @param array       $overrides Overrides.
	 * @param string      $mode      Mode.
	 * @param string      $key       Id or type.
	 * @param string|null $nonce_for Nonce suffix, if not the right one.
	 */
	private function set_post( array $data, array $settings, array $overrides, string $mode, string $key, ?string $nonce_for ) {
		$data['settings'] = array_merge( $settings, $overrides['settings'] ?? array() );
		if ( isset( $overrides['routing'] ) ) {
			$data['routing'] = array_merge( $data['routing'], $overrides['routing'] );
		}
		unset( $overrides['settings'], $overrides['routing'] );
		foreach ( $overrides as $name => $value ) {
			if ( null === $value ) {
				unset( $data[ $name ] );
			} else {
				$data[ $name ] = $value;
			}
		}
		$data['_wpnonce'] = wp_create_nonce( Channel_Form::SAVE_ACTION . '_' . ( $nonce_for ?? $mode . '_' . $key ) );
		$_POST            = $data;
	}

	/**
	 * Everything the save could change.
	 *
	 * @return array
	 */
	private function snapshot(): array {
		return array( get_option( 'sc_wpun_channels' ), get_option( 'sc_wpun_state' ) );
	}

	/**
	 * Define an instance in code.
	 */
	private function define_in_code() {
		add_filter(
			'sc_wpun_channels',
			static function ( $channels ) {
				$channels[] = array(
					'id'       => 'ops_code',
					'type'     => 'email',
					'label'    => 'Ops',
					'enabled'  => true,
					'settings' => array( 'to' => 'code@example.org' ),
				);
				return $channels;
			}
		);
		sc_wpun()->config()->flush();
	}

	/**
	 * Supply values from outside the database.
	 *
	 * @param array $values Values by constant name.
	 */
	private function add_source( array $values ) {
		$source = new Test_Config_Source( $values );
		add_filter(
			'sc_wpun_config_sources',
			static function ( $sources ) use ( $source ) {
				$sources[] = $source;
				return $sources;
			}
		);
		sc_wpun()->config()->flush();
	}

	/**
	 * Store an instance of the fixture module.
	 *
	 * @param string $id       Id.
	 * @param array  $settings Settings.
	 * @param bool   $enabled  Whether enabled.
	 */
	private function store_fixture( string $id = 'fixture', array $settings = array(), bool $enabled = false ) {
		sc_wpun_channels()->save(
			new Channel_Instance(
				$id,
				'fixture',
				'Fixture',
				$enabled,
				array_merge(
					array(
						'colour' => '#112233',
						'token'  => self::SECRET,
						'name'   => 'Stored name',
					),
					$settings
				)
			)
		);
	}

	/**
	 * What is stored for an instance, bypassing the effective overlay.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	private function raw( string $id ) {
		$all = get_option( 'sc_wpun_channels' );

		return $all[ $id ] ?? null;
	}

	/**
	 * Whether the run state asks to skip what is pending for an instance.
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	private function skips( string $id ): bool {
		return ! empty( sc_wpun()->state()->get()['channels'][ $id ]['skip_pending'] );
	}

	/**
	 * The text of a node, with white space collapsed.
	 *
	 * @param DOMXPath $xpath Page.
	 * @param string   $query Query.
	 * @return string
	 */
	private function text( DOMXPath $xpath, string $query ): string {
		$node = $xpath->query( $query );

		return $node->length > 0 ? trim( preg_replace( '/\s+/', ' ', $node->item( 0 )->textContent ) ) : '';
	}

	// The picker and the list.

	/**
	 * Every registered module is listed with its description and a link to its form.
	 */
	public function test_the_picker_lists_the_registered_modules() {
		$xpath = $this->page( array( 'action' => 'add' ) );

		$items = $xpath->query( '//ul[contains(@class,"sc-wpun-modules")]/li' );
		$this->assertSame( count( sc_wpun()->channels()->all() ), $items->length );

		$link = $xpath->query( '//li[@data-type="fixture"]/a' );
		$this->assertSame( 1, $link->length );
		$this->assertSame( 'Fixture', trim( $link->item( 0 )->textContent ) );
		$this->assertStringContainsString( 'tab=channels&action=new&type=fixture', $link->item( 0 )->getAttribute( 'href' ) );
		$this->assertStringContainsString( 'A channel for tests.', $this->text( $xpath, '//li[@data-type="fixture"]' ) );
		$this->assertStringContainsString( 'Sends the notification to one or more email addresses.', $this->text( $xpath, '//li[@data-type="email"]' ) );
		$this->assertSame( 1, $xpath->query( '//a[contains(@class,"button")][text()="Cancel"]' )->length );
		$this->assertSame( 0, $xpath->query( '//table' )->length, 'Only the picker is drawn.' );
	}

	/**
	 * The list links to the picker, even when it is empty, and to the edit form of every row that is not defined in code.
	 */
	public function test_the_list_has_the_add_link_and_the_edit_links() {
		$this->define_in_code();
		$xpath = $this->page();

		$add = $xpath->query( '//a[contains(@class,"button")][normalize-space()="Add channel"]' );
		$this->assertSame( 1, $add->length );
		$this->assertStringContainsString( 'action=add', $add->item( 0 )->getAttribute( 'href' ) );

		$edit = $xpath->query( '//tr[@data-id="email"]//a[normalize-space()="Edit"]' );
		$this->assertSame( 1, $edit->length );
		$this->assertSame( 'Edit Email', $edit->item( 0 )->getAttribute( 'aria-label' ) );
		$this->assertStringContainsString( 'action=edit&id=email', $edit->item( 0 )->getAttribute( 'href' ) );
		$this->assertSame( 1, $xpath->query( '//tr[@data-id="ops_code"]' )->length );
		$this->assertSame( 0, $xpath->query( '//tr[@data-id="ops_code"]//a[normalize-space()="Edit"]' )->length );

		delete_option( 'sc_wpun_channels' );
		remove_all_filters( 'sc_wpun_channels' );
		sc_wpun()->config()->flush();
		$xpath = $this->page();
		$this->assertSame( 1, $xpath->query( '//p[contains(@class,"sc-wpun-no-channels")]' )->length );
		$this->assertSame( 1, $xpath->query( '//a[contains(@class,"button")][normalize-space()="Add channel"]' )->length );
	}

	// The forms.

	/**
	 * The email form has real labels, grouped choices, the id and the constant prefix, the nonce and no script.
	 */
	public function test_the_email_form() {
		$html  = $this->html(
			array(
				'action' => 'edit',
				'id'     => 'email',
			)
		);
		$xpath = $this->parse( $html );

		$form = $xpath->query( '//form[@class="sc-wpun-channel-form"]' );
		$this->assertSame( 1, $form->length );
		$this->assertSame( 'post', $form->item( 0 )->getAttribute( 'method' ) );
		$this->assertStringEndsWith( 'admin-post.php', $form->item( 0 )->getAttribute( 'action' ) );
		$this->assertSame( Channel_Form::SAVE_ACTION, $xpath->query( '//input[@name="action"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'edit', $xpath->query( '//input[@name="mode"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'email', $xpath->query( '//input[@name="id"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( '//input[@name="type"]' )->length );
		$this->assertSame( wp_create_nonce( 'sc_wpun_save_channel_edit_email' ), $xpath->query( '//input[@name="_wpnonce"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( '//script' )->length );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertSame( 'Edit Email', $this->text( $xpath, '//h2' ) );

		$this->assertSame( 'email', $this->text( $xpath, '//code[contains(@class,"sc-wpun-channel-id")]' ) );
		$this->assertSame( 0, $xpath->query( '//input[@name="id"][@type="text"]' )->length, 'The id is not an input to edit.' );
		$this->assertStringContainsString( 'Constants for this channel start with SC_WPUN_CHANNEL_EMAIL__', $this->text( $xpath, '//body' ) );

		$this->assertSame( 'Email', $xpath->query( '//input[@name="label"]' )->item( 0 )->getAttribute( 'value' ) );
		foreach ( array( 'sc_wpun_channel_label', 'sc_wpun_channel_settings_to', 'sc_wpun_channel_settings_from', 'sc_wpun_channel_settings_subject_prefix' ) as $id ) {
			$this->assertSame( 1, $xpath->query( '//th/label[@for="' . $id . '"]' )->length, $id );
			$this->assertSame( 1, $xpath->query( '//*[@id="' . $id . '"]' )->length, $id );
		}
		$this->assertSame( 'ops@example.org', $xpath->query( '//input[@name="settings[to]"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'sc_wpun_channel_settings_to-description', $xpath->query( '//input[@name="settings[to]"]' )->item( 0 )->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 1, $xpath->query( '//p[@id="sc_wpun_channel_settings_to-description"][@class="description"]' )->length );

		// Radio and checkbox groups are fieldsets named by a legend; the row header is plain text.
		foreach ( array( 'sc_wpun_channel_routing_scope', 'sc_wpun_channel_routing_core', 'sc_wpun_channel_routing_types', 'sc_wpun_channel_settings_format' ) as $id ) {
			$this->assertSame( 1, $xpath->query( '//fieldset[@id="' . $id . '"]/legend' )->length, $id );
			$this->assertSame( 0, $xpath->query( '//th/label[@for="' . $id . '"]' )->length, $id );
		}
		$this->assertSame( 1, $xpath->query( '//input[@type="radio"][@name="routing[scope]"][@checked]' )->length );
		$this->assertSame( 'Plugins and themes', $this->text( $xpath, '//fieldset[@id="sc_wpun_channel_routing_scope"]/preceding::th[1]' ) );

		// The enabled checkbox has its own label.
		$this->assertSame( 1, $xpath->query( '//label[@for="sc_wpun_channel_enabled"]/input[@type="checkbox"][@checked]' )->length );
		$this->assertSame( 0, $xpath->query( '//th/label[@for="sc_wpun_channel_enabled"]' )->length );
		$this->assertSame( 1, $xpath->query( '//button[@type="submit"][normalize-space()="Save channel"]' )->length );
		$this->assertSame( 1, $xpath->query( '//form//a[normalize-space()="Cancel"]' )->length );
		// An enabled instance has no use for "skip what is waiting".
		$this->assertSame( 0, $xpath->query( '//input[@name="skip_pending"]' )->length );
	}

	/**
	 * The new form has the prefilled label, the type, its own nonce and tells how the id is made.
	 */
	public function test_the_new_form() {
		$xpath = $this->page(
			array(
				'action' => 'new',
				'type'   => 'email',
			)
		);

		$this->assertSame( 'Add Email channel', $this->text( $xpath, '//h2' ) );
		$this->assertSame( 'new', $xpath->query( '//input[@name="mode"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'email', $xpath->query( '//input[@name="type"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( '//input[@name="id"]' )->length );
		$this->assertSame( wp_create_nonce( 'sc_wpun_save_channel_new_email' ), $xpath->query( '//input[@name="_wpnonce"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'Email', $xpath->query( '//input[@name="label"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( '', $xpath->query( '//input[@name="settings[to]"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( '//*[@checked][@name="enabled"]' )->length );
		$this->assertStringContainsString( 'can not be changed afterwards', $this->text( $xpath, '//body' ) );
		$this->assertSame( 0, $xpath->query( '//code[contains(@class,"sc-wpun-channel-id")]' )->length );
		$this->assertSame( 1, $xpath->query( '//button[@type="submit"][normalize-space()="Add channel"]' )->length );

		$skip = $xpath->query( '//label[@for="sc_wpun_channel_skip_pending"]/input[@name="skip_pending"][@value="1"]' );
		$this->assertSame( 1, $skip->length );
		$this->assertStringContainsString( "Don't send the updates that are already waiting", $this->text( $xpath, '//label[@for="sc_wpun_channel_skip_pending"]' ) );
	}

	/**
	 * Unknown, code-defined and unavailable targets show the message and the list, not a form.
	 */
	public function test_forms_are_not_shown_for_what_can_not_be_edited() {
		$this->define_in_code();
		sc_wpun()->channels()->register( new Test_Channel( 'slack' ) );
		sc_wpun_channels()->save( new Channel_Instance( 'slack', 'slack', 'Slack', true ) );
		sc_wpun()->channels()->unregister( 'slack' );

		$cases = array(
			array( array( 'action' => 'new', 'type' => 'nope' ), 'There is no such channel module.' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'action' => 'edit', 'id' => 'nope' ), 'There is no such channel.' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'action' => 'edit', 'id' => 'slack' ), 'There is no such channel.' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'action' => 'edit', 'id' => 'ops_code' ), 'Ops is defined in code and can not be changed here.' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
		foreach ( $cases as $case ) {
			$xpath = $this->page( $case[0] );
			$this->assertSame( 0, $xpath->query( '//form[@class="sc-wpun-channel-form"]' )->length, $case[1] );
			$this->assertSame( $case[1], $this->text( $xpath, '//div[contains(@class,"sc-wpun-inline-notice")]' ) );
			$this->assertSame( 1, $xpath->query( '//table[contains(@class,"sc-wpun-channels")]' )->length, 'The list follows.' );
		}
	}

	/**
	 * A field type registered by an add-on is drawn by its own callback.
	 */
	public function test_a_custom_field_type_is_drawn_by_its_render_callback() {
		$xpath = $this->page(
			array(
				'action' => 'new',
				'type'   => 'fixture',
			)
		);

		$this->assertSame( 1, $xpath->query( '//input[@type="color"][@class="test-colour"][@name="settings[colour]"]' )->length );
		$this->assertSame( 1, $xpath->query( '//th/label[@for="sc_wpun_channel_settings_colour"]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@type="password"][@name="settings[token]"]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@name="settings[name]"]' )->length );
		$this->assertSame( 1, $xpath->query( '//p[@id="sc_wpun_channel_settings_note-description"]' )->length );
	}

	/**
	 * A channel that draws its settings keeps the core form around them.
	 */
	public function test_a_custom_render_replaces_only_the_settings() {
		$this->store_fixture( 'custom_one', array( 'name' => 'Custom <b>name' ) );
		sc_wpun_channels()->save( sc_wpun_channels()->get( 'custom_one' )->with_label( 'Custom' ) );
		$all                       = get_option( 'sc_wpun_channels' );
		$all['custom_one']['type'] = 'fixture_custom';
		update_option( 'sc_wpun_channels', $all );

		$html  = $this->html(
			array(
				'action' => 'edit',
				'id'     => 'custom_one',
			)
		);
		$xpath = $this->parse( $html );

		$this->assertSame( 1, $xpath->query( '//p[@class="test-custom-render"]/input[@name="settings[name]"]' )->length );
		$this->assertSame( 'Custom <b>name', $xpath->query( '//p[@class="test-custom-render"]/input' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( '//input[@type="color"]' )->length );
		$this->assertSame( 0, $xpath->query( '//input[@name="settings[token]"]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@name="label"]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@name="enabled"][@type="checkbox"]' )->length );
		$this->assertSame( 2, $xpath->query( '//input[@name="routing[scope]"]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@name="_wpnonce"]' )->length );
		$this->assertStringNotContainsString( self::SECRET, $html );
	}

	/**
	 * An error of a module that draws its own fields is listed as plain text: the form has no field of its own to link to.
	 */
	public function test_an_error_of_a_custom_render_is_not_linked() {
		$this->post_fixture( array( 'settings' => array( 'name' => '' ) ), 'new', 'fixture_custom' );
		sc_wpun()->channel_form()->process_save();

		$xpath = $this->page(
			array(
				'action' => 'new',
				'type'   => 'fixture_custom',
			)
		);
		$items = $xpath->query( '//div[contains(@class,"sc-wpun-form-errors")]//li' );
		$this->assertSame( 1, $items->length );
		$this->assertSame( 0, $xpath->query( '//div[contains(@class,"sc-wpun-form-errors")]//a' )->length );
		$this->assertNotSame( '', trim( $items->item( 0 )->textContent ) );
	}

	/**
	 * A field added by the filter is drawn, checked and saved.
	 */
	public function test_the_fields_filter_adds_a_field() {
		add_filter(
			'sc_wpun_channel_fields',
			static function ( $fields, $type ) {
				if ( 'fixture' === $type ) {
					$fields['extra'] = array(
						'type'     => 'number',
						'label'    => 'Extra',
						'required' => true,
					);
				}
				return $fields;
			},
			10,
			2
		);

		$xpath = $this->page(
			array(
				'action' => 'new',
				'type'   => 'fixture',
			)
		);
		$this->assertSame( 1, $xpath->query( '//th/label[@for="sc_wpun_channel_settings_extra"]' )->length );
		$this->assertSame( 1, $xpath->query( '//input[@type="number"][@name="settings[extra]"]' )->length );

		// Validated: required, and a number.
		$this->post_fixture();
		$url = sc_wpun()->channel_form()->process_save();
		$this->assertStringContainsString( 'action=new&type=fixture', $url );
		$this->assertNull( $this->raw( 'fixture' ) );
		$meta = get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true );
		$this->assertSame( 'Extra is required.', $meta['errors']['settings.extra'] );
		delete_user_meta( self::$admin_id, 'sc_wpun_channel_form' );

		$this->post_fixture( array( 'settings' => array( 'extra' => '7' ) ) );
		sc_wpun()->channel_form()->process_save();
		$this->assertSame( 7, $this->raw( 'fixture' )['settings']['extra'] );
	}

	// The id of a new channel.

	/**
	 * Ids are words joined with underscores, and never repeat.
	 */
	public function test_generate_id() {
		$this->assertSame( 'ops_team', Channel_Form::generate_id( 'Ops Team!', 'email', array() ) );
		$this->assertSame( 'ops_team_2', Channel_Form::generate_id( 'Ops Team!', 'email', array( 'ops_team' ) ) );
		$this->assertSame( 'ops_team_3', Channel_Form::generate_id( 'Ops   Team', 'email', array( 'ops_team', 'ops_team_2' ) ) );
		$this->assertSame( 'a_b_c', Channel_Form::generate_id( '  A - B__C  ', 'email', array() ) );
		$this->assertSame( 'email', Channel_Form::generate_id( '!!!', 'email', array() ) );
		$this->assertSame( 'email_2', Channel_Form::generate_id( '日本語', 'email', array( 'email' ) ) );
		$this->assertSame( 'my_type', Channel_Form::generate_id( '', 'My-Type', array() ) );
		$this->assertSame( 'channel', Channel_Form::generate_id( '', '', array() ) );
		$this->assertSame( 'new_2', Channel_Form::generate_id( 'New', 'email', array( 'new' ) ) );
	}

	/**
	 * Adding a second email channel gives it email_2; labels give ids; `new` is not given out.
	 */
	public function test_new_instances_get_a_unique_id() {
		$this->post_email( array(), 'new', 'email' );
		$url = sc_wpun()->channel_form()->process_save();
		$this->assertSame( admin_url( 'options-general.php?page=wp-updates-notifier&tab=channels' ), $url );
		$this->assertNotNull( $this->raw( 'email_2' ) );
		$this->assertSame( 'Email added.', sc_wpun()->notices()->take()['message'] );

		$this->post_email( array( 'label' => 'Ops Team!' ), 'new', 'email' );
		sc_wpun()->channel_form()->process_save();
		$this->assertNotNull( $this->raw( 'ops_team' ) );

		$this->post_email( array( 'label' => 'ops team' ), 'new', 'email' );
		sc_wpun()->channel_form()->process_save();
		$this->post_email( array( 'label' => 'Ops-Team' ), 'new', 'email' );
		sc_wpun()->channel_form()->process_save();
		$this->assertNotNull( $this->raw( 'ops_team_2' ) );
		$this->assertNotNull( $this->raw( 'ops_team_3' ) );

		$this->post_email( array( 'label' => '日本語' ), 'new', 'email' );
		sc_wpun()->channel_form()->process_save();
		$this->assertNotNull( $this->raw( 'email_3' ), 'An id the label does not give falls back to the type.' );

		$this->post_email( array( 'label' => 'New' ), 'new', 'email' );
		sc_wpun()->channel_form()->process_save();
		$this->assertNull( $this->raw( 'new' ) );
		$this->assertNotNull( $this->raw( 'new_2' ) );

		$this->define_in_code();
		$this->post_email( array( 'label' => 'ops code' ), 'new', 'email' );
		sc_wpun()->channel_form()->process_save();
		$this->assertNotNull( $this->raw( 'ops_code_2' ), 'Code-defined ids are taken too.' );
	}

	// Saving.

	/**
	 * An edit saves everything, keeps unknown keys and never changes the id.
	 */
	public function test_an_edit_is_saved() {
		$all                                 = get_option( 'sc_wpun_channels' );
		$all['email']['routing']             = array( 'addon_rule' => 'keep', 'scope' => 'all' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$all['email']['settings']['addon_x'] = 'kept';
		update_option( 'sc_wpun_channels', $all );

		$this->post_email(
			array(
				'label'    => 'Renamed team',
				'enabled'  => '0',
				'routing'  => array(
					'scope' => 'active',
					'core'  => 'major',
					'types' => array( '', 'core', 'plugin' ),
				),
				'settings' => array(
					'to'     => 'a@example.org, b@example.org',
					'format' => 'html',
				),
			)
		);
		$url = sc_wpun()->channel_form()->process_save();

		$this->assertSame( admin_url( 'options-general.php?page=wp-updates-notifier&tab=channels' ), $url );
		$this->assertSame( 'Renamed team saved.', sc_wpun()->notices()->take()['message'] );
		$this->assertSame( array( 'email' ), array_keys( get_option( 'sc_wpun_channels' ) ), 'The id did not change.' );
		$raw = $this->raw( 'email' );
		$this->assertSame( 'Renamed team', $raw['label'] );
		$this->assertFalse( $raw['enabled'] );
		$this->assertSame( 'a@example.org,b@example.org', $raw['settings']['to'] );
		$this->assertSame( 'html', $raw['settings']['format'] );
		$this->assertSame( 'kept', $raw['settings']['addon_x'] );
		$this->assertSame( 'active', $raw['routing']['scope'] );
		$this->assertSame( 'major', $raw['routing']['core'] );
		$this->assertSame( array( 'core', 'plugin' ), $raw['routing']['types'] );
		$this->assertSame( 'keep', $raw['routing']['addon_rule'] );
		$this->assertSame( 'email', $raw['type'] );
	}

	/**
	 * A missing enabled value is off.
	 */
	public function test_a_missing_enabled_is_off() {
		$this->post_email( array( 'enabled' => null ) );
		sc_wpun()->channel_form()->process_save();

		$this->assertFalse( $this->raw( 'email' )['enabled'] );
	}

	/**
	 * A value for enabled that is not a boolean is an error.
	 */
	public function test_a_bad_enabled_value_is_an_error() {
		$this->post_email( array( 'enabled' => 'maybe' ) );
		$before = $this->snapshot();
		$url    = sc_wpun()->channel_form()->process_save();

		$this->assertStringContainsString( 'action=edit&id=email', $url );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertArrayHasKey( 'enabled', get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true )['errors'] );
	}

	// Errors.

	/**
	 * A bad address is kept for the next render, which marks the field and keeps what was typed.
	 */
	public function test_a_validation_error_comes_back_on_the_form() {
		$this->post_email( array( 'settings' => array( 'to' => 'not-an-address' ) ) );
		$before = $this->snapshot();
		$url    = sc_wpun()->channel_form()->process_save();

		$this->assertSame( admin_url( 'options-general.php?page=wp-updates-notifier&tab=channels&action=edit&id=email' ), $url );
		$this->assertSame( $before, $this->snapshot() );
		$meta = get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true );
		$this->assertSame( get_current_blog_id(), $meta['site'] );
		$this->assertSame( 'edit:email', $meta['form'] );
		$this->assertSame( 'not-an-address', $meta['values']['settings']['to'] );
		$this->assertSame( 'One or more email addresses to send to are invalid.', $meta['errors']['settings.to'] );

		$xpath = $this->edit_page();
		$to    = $xpath->query( '//input[@name="settings[to]"]' )->item( 0 );
		$this->assertSame( 'true', $to->getAttribute( 'aria-invalid' ) );
		$this->assertStringContainsString( 'sc_wpun_channel_settings_to-error', $to->getAttribute( 'aria-describedby' ) );
		$this->assertStringContainsString( 'sc_wpun_channel_settings_to-description', $to->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 'not-an-address', $to->getAttribute( 'value' ) );
		$this->assertSame( 'One or more email addresses to send to are invalid.', $this->text( $xpath, '//p[@id="sc_wpun_channel_settings_to-error"]' ) );

		$summary = $xpath->query( '//div[contains(@class,"sc-wpun-form-errors")][@role="alert"]//li/a[@href="#sc_wpun_channel_settings_to"]' );
		$this->assertSame( 1, $summary->length );
		$this->assertSame( 'One or more email addresses to send to are invalid.', trim( $summary->item( 0 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//input[@name="settings[from]"][@aria-invalid]' )->length );

		$this->assertFalse( (bool) get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true ), 'It is deleted when read.' );
		$this->assertSame( 0, $this->edit_page()->query( '//div[contains(@class,"sc-wpun-form-errors")]' )->length );
	}

	/**
	 * A label left empty is an error on the label, and the errors of every part are collected.
	 */
	public function test_errors_are_collected_from_every_part() {
		$this->post_email(
			array(
				'label'    => '  ',
				'enabled'  => 'maybe',
				'routing'  => array( 'scope' => 'sideways' ),
				'settings' => array( 'to' => 'bad' ),
			)
		);
		sc_wpun()->channel_form()->process_save();
		$meta = get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true );
		$this->assertSame( array( 'label', 'enabled', 'routing.scope', 'settings.to' ), array_keys( $meta['errors'] ) );

		$xpath = $this->edit_page();
		$this->assertSame( 'true', $xpath->query( '//input[@name="label"]' )->item( 0 )->getAttribute( 'aria-invalid' ) );
		$this->assertSame( 4, $xpath->query( '//div[contains(@class,"sc-wpun-form-errors")]//li' )->length );
		foreach ( array( 'sc_wpun_channel_label', 'sc_wpun_channel_enabled', 'sc_wpun_channel_routing_scope', 'sc_wpun_channel_settings_to' ) as $id ) {
			$this->assertSame( 1, $xpath->query( '//div[contains(@class,"sc-wpun-form-errors")]//a[@href="#' . $id . '"]' )->length, $id );
		}
		$this->assertSame( 'true', $xpath->query( '//fieldset[@id="sc_wpun_channel_routing_scope"]' )->item( 0 )->getAttribute( 'aria-invalid' ) );
	}

	/**
	 * An error that names no known field is shown as plain text.
	 */
	public function test_an_error_without_a_field_is_plain_text() {
		add_filter(
			'sc_wpun_channel_fields',
			static function ( $fields ) {
				return $fields;
			}
		);
		$this->post_email( array( 'settings' => array( 'format' => 'pdf' ) ) );
		sc_wpun()->channel_form()->process_save();
		$errors = get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true )['errors'];
		$this->assertSame( array( 'settings.format' ), array_keys( $errors ) );

		// A channel error that names a field the module does not have has no field of its own.
		delete_user_meta( self::$admin_id, 'sc_wpun_channel_form' );
		add_filter(
			'sc_wpun_channel_fields',
			static function ( $fields, $type ) {
				if ( 'fixture' === $type ) {
					$fields['name']['validate_callback'] = static function () {
						return new WP_Error( 'odd', 'Something is odd.', array( 'field' => 'ghost' ) );
					};
				}
				return $fields;
			},
			10,
			2
		);
		$this->post_fixture();
		sc_wpun()->channel_form()->process_save();
		$meta = get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true );
		$this->assertSame( array( 'settings.name' ), array_keys( $meta['errors'] ), 'The validator is run by the field, which names it.' );

		$meta['errors'] = array( '' => 'Something general.' );
		update_user_meta( self::$admin_id, 'sc_wpun_channel_form', $meta );
		$xpath = $this->page(
			array(
				'action' => 'new',
				'type'   => 'fixture',
			)
		);
		$this->assertSame( 'Something general.', $this->text( $xpath, '//div[contains(@class,"sc-wpun-form-errors")]//li' ) );
		$this->assertSame( 0, $xpath->query( '//div[contains(@class,"sc-wpun-form-errors")]//li/a' )->length );
	}

	/**
	 * What was left is ignored for another site or form, and when stale.
	 */
	public function test_stale_or_foreign_errors_are_ignored() {
		$meta = array(
			'site'   => get_current_blog_id(),
			'time'   => time(),
			'form'   => 'edit:email',
			'values' => array( 'label' => 'From meta' ),
			'errors' => array( 'label' => 'Broken.' ),
		);

		foreach (
			array(
				'other form' => array( 'form' => 'edit:other' ),
				'other site' => array( 'site' => get_current_blog_id() + 5 ),
				'stale'      => array( 'time' => time() - 6 * MINUTE_IN_SECONDS ),
			) as $name => $change
		) {
			update_user_meta( self::$admin_id, 'sc_wpun_channel_form', array_merge( $meta, $change ) );
			$xpath = $this->edit_page();
			$this->assertSame( 0, $xpath->query( '//div[contains(@class,"sc-wpun-form-errors")]' )->length, $name );
			$this->assertSame( 'Email', $xpath->query( '//input[@name="label"]' )->item( 0 )->getAttribute( 'value' ), $name );
		}

		update_user_meta( self::$admin_id, 'sc_wpun_channel_form', $meta );
		$xpath = $this->edit_page();
		$this->assertSame( 'From meta', $xpath->query( '//input[@name="label"]' )->item( 0 )->getAttribute( 'value' ) );
	}

	// Secrets.

	/**
	 * The stored secret is never output, an empty submission keeps it and the clear box empties it.
	 */
	public function test_secrets_are_never_output_and_can_be_kept_or_cleared() {
		$this->store_fixture();

		$html  = $this->html(
			array(
				'action' => 'edit',
				'id'     => 'fixture',
			)
		);
		$xpath = $this->parse( $html );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertSame( '', $xpath->query( '//input[@name="settings[token]"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 1, $xpath->query( '//input[@type="checkbox"][@name="settings[token__clear]"]' )->length );

		$this->post_fixture( array(), 'edit', 'fixture' );
		sc_wpun()->channel_form()->process_save();
		$this->assertSame( self::SECRET, $this->raw( 'fixture' )['settings']['token'], 'Empty keeps it.' );

		$this->post_fixture( array( 'settings' => array( 'token' => 'new-secret-333' ) ), 'edit', 'fixture' );
		sc_wpun()->channel_form()->process_save();
		$this->assertSame( 'new-secret-333', $this->raw( 'fixture' )['settings']['token'], 'A typed value replaces it.' );

		$this->post_fixture( array( 'settings' => array( 'token__clear' => '1' ) ), 'edit', 'fixture' );
		sc_wpun()->channel_form()->process_save();
		$this->assertSame( '', $this->raw( 'fixture' )['settings']['token'] );
		$this->assertArrayNotHasKey( 'token__clear', $this->raw( 'fixture' )['settings'] );
	}

	/**
	 * A failed save does not put the typed or the stored secret anywhere.
	 */
	public function test_secrets_are_not_kept_for_the_error_round_trip() {
		$this->store_fixture();
		$this->post_fixture(
			array(
				'settings' => array(
					'name'         => '',
					'token'        => self::TYPED,
					'token__clear' => '1',
				),
			),
			'edit',
			'fixture'
		);
		$url = sc_wpun()->channel_form()->process_save();
		$this->assertStringContainsString( 'action=edit&id=fixture', $url );

		$meta = get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true );
		$this->assertStringNotContainsString( self::TYPED, wp_json_encode( $meta ) );
		$this->assertStringNotContainsString( self::SECRET, wp_json_encode( $meta ) );
		$this->assertArrayNotHasKey( 'token', $meta['values']['settings'] );
		$this->assertArrayNotHasKey( 'token__clear', $meta['values']['settings'] );
		$this->assertSame( '#00aa00', $meta['values']['settings']['colour'] );

		$html  = $this->html(
			array(
				'action' => 'edit',
				'id'     => 'fixture',
			)
		);
		$xpath = $this->parse( $html );
		$this->assertStringNotContainsString( self::TYPED, $html );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertSame( 'true', $xpath->query( '//input[@name="settings[name]"]' )->item( 0 )->getAttribute( 'aria-invalid' ) );
		$this->assertSame( '#00aa00', $xpath->query( '//input[@name="settings[colour]"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 1, $xpath->query( '//input[@name="settings[token__clear]"]' )->length, 'It shows as saved.' );
		$this->assertSame( self::SECRET, $this->raw( 'fixture' )['settings']['token'] );
	}

	/**
	 * A new channel that fails shows no saved secret.
	 */
	public function test_a_failed_new_channel_has_no_saved_secret() {
		$this->post_fixture( array( 'settings' => array( 'name' => '', 'token' => self::TYPED ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		sc_wpun()->channel_form()->process_save();
		$this->assertNull( $this->raw( 'fixture' ) );

		$html  = $this->html(
			array(
				'action' => 'new',
				'type'   => 'fixture',
			)
		);
		$xpath = $this->parse( $html );
		$this->assertStringNotContainsString( self::TYPED, $html );
		$this->assertSame( 0, $xpath->query( '//input[@name="settings[token__clear]"]' )->length );
		$this->assertSame( 1, $xpath->query( '//div[contains(@class,"sc-wpun-form-errors")]' )->length );
	}

	// Nonce and capability.

	/**
	 * Every way of getting the nonce or the capability wrong is refused and changes nothing.
	 */
	public function test_nonce_and_capability_are_required() {
		$this->store_fixture( 'other' );
		$before = $this->snapshot();

		$refused = array(
			'bad nonce, edit'       => function () {
				$this->post_email();
				$_POST['_wpnonce'] = 'nonsense';
			},
			'bad nonce, new'        => function () {
				$this->post_email( array(), 'new' );
				$_POST['_wpnonce'] = 'nonsense';
			},
			'no nonce'              => function () {
				$this->post_email();
				unset( $_POST['_wpnonce'] );
			},
			'nonce of another id'   => function () {
				$this->post_email( array(), 'edit', 'email', 'edit_other' );
			},
			'new nonce on edit'     => function () {
				$this->post_email( array(), 'edit', 'email', 'new_email' );
			},
			'edit nonce on new'     => function () {
				$this->post_email( array(), 'new', 'email', 'edit_email' );
			},
			'nonce of another type' => function () {
				$this->post_email( array(), 'new', 'email', 'new_fixture' );
			},
			'no mode'               => function () {
				$this->post_email( array( 'mode' => null ) );
			},
			'other mode'            => function () {
				$this->post_email( array( 'mode' => 'delete' ) );
			},
		);
		foreach ( $refused as $name => $setup ) {
			$_POST = array();
			$setup();
			$this->assertNull( sc_wpun()->channel_form()->process_save(), $name );
			$this->assertSame( $before, $this->snapshot(), $name );
		}

		wp_set_current_user( self::$editor_id );
		$this->post_email( array( 'label' => 'Hacked' ) );
		$this->assertNull( sc_wpun()->channel_form()->process_save(), 'An editor may not.' );
		$this->post_email( array( 'label' => 'Hacked' ), 'new' );
		$this->assertNull( sc_wpun()->channel_form()->process_save(), 'An editor may not.' );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertNull( sc_wpun()->notices()->take() );
	}

	/**
	 * Code-defined, unavailable and unknown targets, and an unregistered type, are refused with a notice.
	 */
	public function test_refusals_change_nothing() {
		$this->define_in_code();
		sc_wpun()->channels()->register( new Test_Channel( 'slack' ) );
		sc_wpun_channels()->save( new Channel_Instance( 'slack', 'slack', 'Slack', true ) );
		sc_wpun()->channels()->unregister( 'slack' );
		$before = $this->snapshot();
		$list   = admin_url( 'options-general.php?page=wp-updates-notifier&tab=channels' );

		$cases = array(
			array( 'edit', 'ops_code', 'Ops is defined in code and can not be changed here.' ),
			array( 'edit', 'slack', 'There is no such channel.' ),
			array( 'edit', 'ghost', 'There is no such channel.' ),
			array( 'new', 'slack', 'There is no such channel module.' ),
		);
		foreach ( $cases as $case ) {
			$this->post_email( array( 'label' => 'Hacked' ), $case[0], $case[1] );
			$this->assertSame( $list, sc_wpun()->channel_form()->process_save(), $case[1] );
			$notice = sc_wpun()->notices()->take();
			$this->assertFalse( $notice['success'] );
			$this->assertSame( $case[2], $notice['message'] );
			$this->assertSame( $before, $this->snapshot(), $case[1] );
		}
	}

	/**
	 * The handler answers 403 when refused.
	 */
	public function test_the_handler_answers_403_when_refused() {
		$_POST = array( 'action' => Channel_Form::SAVE_ACTION );
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message, $title, $args ) {
					throw new RuntimeException( 'died ' . $args['response'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				};
			}
		);
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'died 403' );
		sc_wpun()->channel_form()->handle_save();
	}

	/**
	 * The admin-post action is hooked.
	 */
	public function test_the_save_action_is_hooked() {
		sc_wpun()->register_admin_hooks();

		$this->assertNotFalse( has_action( 'admin_post_' . Channel_Form::SAVE_ACTION, array( sc_wpun()->channel_form(), 'handle_save' ) ) );
	}

	// Skip pending.

	/**
	 * "Skip what is waiting" applies to a channel that is now switched on after it was off or new.
	 */
	public function test_skip_pending() {
		// New and enabled, ticked.
		$this->post_email( array( 'skip_pending' => '1' ), 'new' );
		sc_wpun()->channel_form()->process_save();
		$this->assertTrue( $this->skips( 'email_2' ) );

		// New and enabled, not ticked.
		$this->post_email( array( 'label' => 'Unticked' ), 'new' );
		sc_wpun()->channel_form()->process_save();
		$this->assertFalse( $this->skips( 'unticked' ) );

		// New but switched off, ticked: nothing to skip.
		$this->post_email( array( 'label' => 'Off', 'enabled' => '0', 'skip_pending' => '1' ), 'new' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		sc_wpun()->channel_form()->process_save();
		$this->assertFalse( $this->skips( 'off' ) );

		// Off to on, ticked.
		$this->post_email( array( 'enabled' => '1', 'skip_pending' => '1' ), 'edit', 'off' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		sc_wpun()->channel_form()->process_save();
		$this->assertTrue( $this->raw( 'off' )['enabled'] );
		$this->assertTrue( $this->skips( 'off' ) );

		// Off to on, not ticked.
		$this->post_email(
			array(
				'label'   => 'Later',
				'enabled' => '0',
			),
			'new'
		);
		sc_wpun()->channel_form()->process_save();
		$this->post_email( array( 'enabled' => '1' ), 'edit', 'later' );
		sc_wpun()->channel_form()->process_save();
		$this->assertFalse( $this->skips( 'later' ) );

		// Already enabled, ticked: the box is not even shown, and the request is ignored.
		$this->post_email( array( 'skip_pending' => '1' ), 'edit', 'email' );
		sc_wpun()->channel_form()->process_save();
		$this->assertFalse( $this->skips( 'email' ) );
	}

	/**
	 * The box is shown for a disabled instance.
	 */
	public function test_the_skip_box_is_shown_for_a_disabled_instance() {
		$this->save_settings( array( 'email_notifications' => 0 ) );

		$this->assertSame( 1, $this->edit_page()->query( '//input[@name="skip_pending"]' )->length );
	}

	// Values from outside the database.

	/**
	 * A field supplied from outside is read-only with a note, saving works without it, and nothing is written for it.
	 */
	public function test_values_from_outside_the_database() {
		$this->save_settings( array( 'email_notifications' => 0 ) );
		$this->add_source(
			array(
				'SC_WPUN_CHANNEL_EMAIL__TO'      => 'vault@example.org',
				'SC_WPUN_CHANNEL_EMAIL__ENABLED' => 'true',
			)
		);

		$xpath = $this->edit_page();
		$to    = $xpath->query( '//input[@name="settings[to]"]' )->item( 0 );
		$this->assertSame( 'disabled', $to->getAttribute( 'disabled' ) );
		$this->assertSame( 'vault@example.org', $to->getAttribute( 'value' ) );
		$this->assertSame( 'Set by Test vault', $this->text( $xpath, '//input[@name="settings[to]"]/following-sibling::span[contains(@class,"sc-wpun-external")]' ) );
		$this->assertSame( 'disabled', $xpath->query( '//input[@name="enabled"][@type="checkbox"]' )->item( 0 )->getAttribute( 'disabled' ) );
		$this->assertSame( 1, $xpath->query( '//input[@name="enabled"][@type="checkbox"][@checked]' )->length, 'It shows the value in effect.' );
		$this->assertSame( 2, $xpath->query( '//span[contains(@class,"sc-wpun-external")]' )->length, 'A note for the switch and one for the address.' );
		$this->assertSame( 0, $xpath->query( '//input[@name="skip_pending"]' )->length );
		$this->assertSame( '', $xpath->query( '//input[@name="settings[from]"]' )->item( 0 )->getAttribute( 'disabled' ) );

		$this->post_email(
			array(
				'label'    => 'Via vault',
				'enabled'  => null,
				'settings' => array(
					'from' => 'me@example.org',
					'to'   => null,
				),
			)
		);
		unset( $_POST['settings']['to'] );
		$_POST['skip_pending'] = '1';
		$this->assertStringNotContainsString( 'action=edit', (string) sc_wpun()->channel_form()->process_save() );

		$raw = $this->raw( 'email' );
		$this->assertSame( 'Via vault', $raw['label'] );
		$this->assertSame( 'ops@example.org', $raw['settings']['to'], 'The outside value was not written.' );
		$this->assertSame( 'me@example.org', $raw['settings']['from'] );
		$this->assertFalse( $raw['enabled'], 'The outside switch was not written.' );
		$this->assertFalse( $this->skips( 'email' ), 'The box is ignored for an outside switch.' );
	}

	/**
	 * A secret supplied from outside is never output.
	 */
	public function test_an_external_secret_is_never_output() {
		$this->store_fixture();
		$this->add_source( array( 'SC_WPUN_CHANNEL_FIXTURE__TOKEN' => 'vault-SECRET-999' ) );

		$html  = $this->html(
			array(
				'action' => 'edit',
				'id'     => 'fixture',
			)
		);
		$xpath = $this->parse( $html );
		$this->assertStringNotContainsString( 'vault-SECRET-999', $html );
		$this->assertStringNotContainsString( self::SECRET, $html );
		$token = $xpath->query( '//input[@name="settings[token]"]' )->item( 0 );
		$this->assertSame( 'disabled', $token->getAttribute( 'disabled' ) );
		$this->assertSame( '', $token->getAttribute( 'value' ) );
		$this->assertSame( 0, $xpath->query( '//input[@name="settings[token__clear]"]' )->length );
		$this->assertStringContainsString( 'Set by Test vault', $this->text( $xpath, '//body' ) );

		$this->post_fixture(
			array(
				'label'    => 'Renamed',
				'settings' => array( 'token' => null ),
			),
			'edit',
			'fixture'
		);
		unset( $_POST['settings']['token'] );
		sc_wpun()->channel_form()->process_save();
		$this->assertSame( 'Renamed', $this->raw( 'fixture' )['label'] );
		$this->assertSame( self::SECRET, $this->raw( 'fixture' )['settings']['token'], 'The database value stays.' );
	}

	// Hooks.

	/**
	 * The hooks run inside the form with the instance and the channel; a new form gets a provisional instance.
	 */
	public function test_the_before_and_after_hooks() {
		$seen = array();
		foreach ( array( 'sc_wpun_before_channel_form', 'sc_wpun_after_channel_form' ) as $hook ) {
			add_action(
				$hook,
				static function ( $instance, $channel ) use ( &$seen, $hook ) {
					$seen[ $hook ][] = array( $instance, $channel );
					echo '<span class="hook-' . esc_attr( $hook ) . '"></span>';
				},
				10,
				2
			);
		}

		$xpath = $this->edit_page();
		$this->assertCount( 1, $seen['sc_wpun_before_channel_form'] );
		$this->assertCount( 1, $seen['sc_wpun_after_channel_form'] );
		$this->assertInstanceOf( Channel_Instance::class, $seen['sc_wpun_before_channel_form'][0][0] );
		$this->assertInstanceOf( Channel::class, $seen['sc_wpun_before_channel_form'][0][1] );
		$this->assertSame( 'email', $seen['sc_wpun_before_channel_form'][0][0]->get_id() );
		$this->assertSame( 'email', $seen['sc_wpun_after_channel_form'][0][1]->get_type() );
		$this->assertSame( 1, $xpath->query( '//form//span[@class="hook-sc_wpun_before_channel_form"]' )->length );
		$this->assertSame( 1, $xpath->query( '//form//span[@class="hook-sc_wpun_after_channel_form"]' )->length );
		$this->assertGreaterThan( 0, $xpath->query( '//span[@class="hook-sc_wpun_before_channel_form"]/following::table' )->length );
		$this->assertSame( 0, $xpath->query( '//span[@class="hook-sc_wpun_after_channel_form"]/following::table' )->length );

		$seen = array();
		$this->page(
			array(
				'action' => 'new',
				'type'   => 'email',
			)
		);
		$provisional = $seen['sc_wpun_before_channel_form'][0][0];
		$this->assertSame( 'email_2', $provisional->get_id() );
		$this->assertSame( 'email', $provisional->get_type() );
		$this->assertSame( 'Email', $provisional->get_label() );
		$this->assertFalse( $provisional->is_enabled() );
		$this->assertNull( sc_wpun_channels()->get( 'email_2' ), 'It is not saved.' );
	}

	/**
	 * After a failed save the hooks and a custom render get the instance with the submitted values.
	 */
	public function test_the_form_instance_has_the_submitted_values() {
		$seen = null;
		add_action(
			'sc_wpun_after_channel_form',
			static function ( $instance ) use ( &$seen ) {
				$seen = $instance;
			}
		);
		$this->post_email( array( 'label' => 'Typed label', 'settings' => array( 'to' => 'bad' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		sc_wpun()->channel_form()->process_save();
		$this->edit_page();

		$this->assertSame( 'Typed label', $seen->get_label() );
		$this->assertSame( 'bad', $seen->get_setting( 'to' ) );
		$this->assertSame( 'email', $seen->get_id() );
		$this->assertSame( 'Email', $this->stored_instance( 'email' )->get_label(), 'Nothing was saved.' );
	}
}
