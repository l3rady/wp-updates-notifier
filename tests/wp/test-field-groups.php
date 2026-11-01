<?php
/**
 * Field groups and conditional fields in the channel form schema (#347).
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties are camelCase.

use WPUN\Admin\Channel_Form;
use WPUN\Field_Types;

/**
 * The groups of the form (fieldsets and details), the note of a conditional field and what `applies_when` does to validation.
 */
class Test_Field_Groups extends WPUN_Test_Case {

	/**
	 * Tests the plugin as one site's, so on a multisite it is not network-activated.
	 *
	 * @var bool
	 */
	protected $network_activated = false;

	/**
	 * An administrator.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Create the user.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * An administrator, and no notices remembered.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->config()->flush();
		$this->save_settings();
		wp_set_current_user( self::$admin_id );
		Field_Types::forget_warnings();
	}

	/**
	 * Remove what tests registered.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( 'grp' );
		sc_wpun()->config()->flush();
		$_POST = array();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'], $_GET['type'] );
		parent::tear_down();
	}

	/**
	 * The groups of the grouped channel.
	 *
	 * @return array
	 */
	private function groups(): array {
		return array(
			'alpha' => array(
				'label'       => 'Alpha group',
				'description' => 'About alpha.',
			),
			'beta'  => array(
				'label'     => 'Beta group',
				'collapsed' => true,
			),
			'empty' => array( 'label' => 'Empty group' ),
		);
	}

	/**
	 * The fields of the grouped channel, ungrouped ones around the grouped ones.
	 *
	 * @return array
	 */
	private function grouped_fields(): array {
		return array(
			'one'   => array(
				'type'  => 'text',
				'label' => 'One',
			),
			'a1'    => array(
				'type'  => 'text',
				'label' => 'A one',
				'group' => 'alpha',
			),
			'b1'    => array(
				'type'     => 'text',
				'label'    => 'B one',
				'group'    => 'beta',
				'required' => true,
			),
			'two'   => array(
				'type'  => 'text',
				'label' => 'Two',
			),
			'a2'    => array(
				'type'  => 'text',
				'label' => 'A two',
				'group' => 'alpha',
			),
			'three' => array(
				'type'  => 'text',
				'label' => 'Three',
			),
		);
	}

	/**
	 * Register a channel with these fields and groups.
	 *
	 * @param array $fields Fields.
	 * @param array $groups Groups.
	 * @return Test_Fields_Channel
	 */
	private function register( array $fields, array $groups = array() ): Test_Fields_Channel {
		$channel = new Test_Fields_Channel( 'grp', $fields, $groups );
		sc_wpun()->channels()->register( $channel );

		return $channel;
	}

	/**
	 * The new form of the channel, parsed.
	 *
	 * @param string $type Channel type.
	 * @return DOMXPath
	 */
	private function form( string $type = 'grp' ): DOMXPath {
		$_GET['tab']    = 'channels';
		$_GET['action'] = 'new';
		$_GET['type']   = $type;
		ob_start();
		sc_wpun()->channels_tab()->render();
		$html = (string) ob_get_clean();

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();

		return new DOMXPath( $doc );
	}

	/**
	 * Text of the first node of a query.
	 *
	 * @param DOMXPath $xpath Document.
	 * @param string   $query Query.
	 * @return string
	 */
	private function text( DOMXPath $xpath, string $query ): string {
		$node = $xpath->query( $query );

		return $node->length > 0 ? trim( preg_replace( '/\s+/', ' ', $node->item( 0 )->textContent ) ) : '';
	}

	/**
	 * Names of the settings in the rows a query finds.
	 *
	 * @param DOMXPath $xpath Document.
	 * @param string   $query Query for the controls, relative to the document.
	 * @return string[]
	 */
	private function names( DOMXPath $xpath, string $query ): array {
		$names = array();
		foreach ( $xpath->query( $query ) as $node ) {
			$names[] = $node->getAttribute( 'name' );
		}

		return $names;
	}

	/**
	 * Post the form of a new channel of the type.
	 *
	 * @param array $settings Settings.
	 */
	private function post( array $settings ) {
		$_POST = array(
			'action'   => Channel_Form::SAVE_ACTION,
			'mode'     => 'new',
			'type'     => 'grp',
			'label'    => 'Grouped',
			'enabled'  => '0',
			'routing'  => array(
				'scope' => 'all',
				'core'  => 'all',
				'types' => array( '' ),
			),
			'settings' => $settings,
			'_wpnonce' => wp_create_nonce( Channel_Form::SAVE_ACTION . '_new_grp' ),
		);
	}

	// Groups.

	/**
	 * The default has no groups, and the form is the one table it always was.
	 */
	public function test_no_groups_is_one_table() {
		$fields = $this->grouped_fields();
		foreach ( $fields as $name => $field ) {
			unset( $fields[ $name ]['group'] );
		}
		$this->register( $fields );
		$xpath = $this->form();

		$this->assertSame( array(), ( new Test_Fields_Channel( 'x', array() ) )->get_field_groups() );
		$this->assertSame( 0, $xpath->query( '//fieldset[contains(@class,"sc-wpun-field-group")]' )->length );
		$this->assertSame( 0, $xpath->query( '//details' )->length );
	}

	/**
	 * Ungrouped fields come first in their order, then a fieldset with a legend for each group that has fields, in the order declared.
	 */
	public function test_groups_are_fieldsets_after_the_ungrouped_fields() {
		$this->register( $this->grouped_fields(), $this->groups() );
		$xpath = $this->form();

		$first = $xpath->query( '//h3[normalize-space()="Settings"]/following-sibling::*[1]' )->item( 0 );
		$this->assertSame( 'table', $first->nodeName );
		$this->assertSame( 'presentation', $first->getAttribute( 'role' ) );
		$this->assertSame(
			array( 'settings[one]', 'settings[two]', 'settings[three]' ),
			$this->names( $xpath, '//h3[normalize-space()="Settings"]/following-sibling::table[1]//input' )
		);

		$sets = $xpath->query( '//fieldset[@class="sc-wpun-field-group"]' );
		$this->assertSame( 2, $sets->length, 'The empty group is not drawn.' );
		$this->assertSame( 'Alpha group', $this->text( $xpath, '(//fieldset[@class="sc-wpun-field-group"])[1]/legend' ) );
		$this->assertSame( 'Beta group', $this->text( $xpath, '(//fieldset[@class="sc-wpun-field-group"])[2]/legend' ) );
		$this->assertStringNotContainsString( 'Empty group', $this->text( $xpath, '//body' ) );

		$this->assertSame( array( 'settings[a1]', 'settings[a2]' ), $this->names( $xpath, '(//fieldset[@class="sc-wpun-field-group"])[1]/table//input' ) );
		$this->assertSame( array( 'settings[b1]' ), $this->names( $xpath, '(//fieldset[@class="sc-wpun-field-group"])[2]/table//input' ) );
		$this->assertSame( 0, $xpath->query( '//tbody//fieldset[contains(@class,"sc-wpun-field-group")]' )->length, 'A fieldset is not inside a table.' );
		$this->assertSame( 0, $xpath->query( '//fieldset[@class="sc-wpun-field-group"]/table/following::table[count(ancestor::fieldset) = 0][.//input[@name="settings[one]"]]' )->length );
	}

	/**
	 * The description of a group is linked to its fieldset.
	 */
	public function test_the_description_of_a_group_describes_the_fieldset() {
		$this->register( $this->grouped_fields(), $this->groups() );
		$xpath = $this->form();

		$set = $xpath->query( '(//fieldset[@class="sc-wpun-field-group"])[1]' )->item( 0 );
		$id  = $set->getAttribute( 'aria-describedby' );
		$this->assertNotSame( '', $id );
		$this->assertSame( 'About alpha.', $this->text( $xpath, '//p[@class="description"][@id="' . $id . '"]' ) );
		$this->assertSame( 1, $xpath->query( '(//fieldset[@class="sc-wpun-field-group"])[1]/p[@id="' . $id . '"]' )->length );
		$this->assertSame( '', $xpath->query( '(//fieldset[@class="sc-wpun-field-group"])[2]' )->item( 0 )->getAttribute( 'aria-describedby' ) );
	}

	/**
	 * A collapsed group is a closed details element with a summary, and a legend only screen readers get.
	 */
	public function test_a_collapsed_group_is_a_closed_details_element() {
		$this->register( $this->grouped_fields(), $this->groups() );
		$xpath = $this->form();

		$details = $xpath->query( '//details[@class="sc-wpun-field-group-details"]' );
		$this->assertSame( 1, $details->length );
		$this->assertFalse( $details->item( 0 )->hasAttribute( 'open' ) );
		$this->assertSame( 'Beta group', $this->text( $xpath, '//details/summary' ) );
		$this->assertSame( 1, $xpath->query( '//details/fieldset/legend[@class="screen-reader-text"][normalize-space()="Beta group"]' )->length );
		$this->assertSame( 1, $xpath->query( '//details//input[@name="settings[b1]"]' )->length );
		$this->assertSame( 0, $xpath->query( '//details//input[@name="settings[a1]"]' )->length );
	}

	/**
	 * After a failed save, a collapsed group with an error in one of its fields opens, and the error summary links into it.
	 */
	public function test_a_collapsed_group_opens_on_an_error() {
		$this->register( $this->grouped_fields(), $this->groups() );

		$this->post( array( 'b1' => '' ) );
		$url = sc_wpun()->channel_form()->process_save();
		$this->assertStringContainsString( 'action=new&type=grp', (string) $url );

		$xpath = $this->form();
		$this->assertTrue( $xpath->query( '//details[@class="sc-wpun-field-group-details"]' )->item( 0 )->hasAttribute( 'open' ) );
		$this->assertSame( 1, $xpath->query( '//a[@href="#sc_wpun_channel_settings_b1"]' )->length );
		$this->assertSame( 1, $xpath->query( '//details//input[@id="sc_wpun_channel_settings_b1"][@aria-invalid="true"]' )->length );
	}

	/**
	 * An error outside the collapsed group does not open it.
	 */
	public function test_an_error_elsewhere_keeps_it_closed() {
		$fields       = $this->grouped_fields();
		$fields['a1'] = array(
			'type'     => 'text',
			'label'    => 'A one',
			'group'    => 'alpha',
			'required' => true,
		);
		$fields['b1'] = array(
			'type'  => 'text',
			'label' => 'B one',
			'group' => 'beta',
		);
		$this->register( $fields, $this->groups() );

		$this->post( array( 'a1' => '' ) );
		sc_wpun()->channel_form()->process_save();

		$xpath = $this->form();
		$this->assertFalse( $xpath->query( '//details[@class="sc-wpun-field-group-details"]' )->item( 0 )->hasAttribute( 'open' ) );
		$this->assertSame( 1, $xpath->query( '//a[@href="#sc_wpun_channel_settings_a1"]' )->length );
	}

	/**
	 * A field with an unknown group is drawn without a group, with one notice for the group however many fields name it.
	 */
	public function test_an_unknown_group_falls_back_to_ungrouped() {
		$this->setExpectedIncorrectUsage( 'WPUN\Field_Types::unknown_group' );
		$count = 0;
		add_action(
			'doing_it_wrong_run',
			static function ( $function_name ) use ( &$count ) {
				if ( 'WPUN\Field_Types::unknown_group' === $function_name ) {
					++$count;
				}
			}
		);

		$this->register(
			array(
				'x' => array(
					'type'  => 'text',
					'label' => 'X',
					'group' => 'nowhere',
				),
				'y' => array(
					'type'  => 'text',
					'label' => 'Y',
					'group' => 'nowhere',
				),
				'z' => array(
					'type'  => 'text',
					'label' => 'Z',
					'group' => array( 'not', 'a string' ),
				),
			),
			$this->groups()
		);
		$xpath = $this->form();

		$this->assertSame(
			array( 'settings[x]', 'settings[y]', 'settings[z]' ),
			$this->names( $xpath, '//h3[normalize-space()="Settings"]/following-sibling::table[1]//input' )
		);
		$this->assertSame( 0, $xpath->query( '//fieldset[@class="sc-wpun-field-group"]' )->length );
		$this->assertSame( 2, $count, 'One for "nowhere" and one for the list.' );
	}

	/**
	 * The filter changes the groups and the fields join them, and what is not an array, has no label or has no id is ignored.
	 */
	public function test_the_groups_filter() {
		$this->setExpectedIncorrectUsage( 'WPUN\Field_Types::unknown_group' ); // The field of the removed group has none to join.
		$channel = $this->register( $this->grouped_fields(), $this->groups() );

		add_filter(
			'sc_wpun_channel_field_groups',
			static function ( $groups, $given ) {
				$groups['extra']   = array(
					'label'     => 'Extra group',
					'collapsed' => true,
				);
				$groups['nolabel'] = array( 'description' => 'No label.' );
				$groups['junk']    = 'text';
				$groups['blank']   = array( 'label' => '' );
				$groups['']        = array( 'label' => 'No id' );
				unset( $groups['beta'] );
				return $given instanceof WPUN\Channel ? $groups : array();
			},
			10,
			2
		);
		add_filter(
			'sc_wpun_channel_fields',
			static function ( $fields ) {
				$fields['two']['group'] = 'extra';
				return $fields;
			}
		);

		$this->assertSame( array( 'alpha', 'empty', 'extra' ), array_keys( $channel->get_form_field_groups() ) );

		$xpath = $this->form();
		$this->assertSame( array( 'settings[two]' ), $this->names( $xpath, '//details[summary="Extra group"]//input' ) );
		$this->assertSame( 0, $xpath->query( '//fieldset[normalize-space(legend)="Beta group"]' )->length );
	}

	/**
	 * A filter that returns something else than an array leaves the groups as declared.
	 */
	public function test_a_filter_returning_a_non_array_is_ignored() {
		$channel = $this->register( $this->grouped_fields(), $this->groups() );
		add_filter( 'sc_wpun_channel_field_groups', '__return_null' );

		$this->assertSame( array( 'alpha', 'beta', 'empty' ), array_keys( $channel->get_form_field_groups() ) );
	}

	/**
	 * The email form has its two groups.
	 */
	public function test_the_email_form_has_two_groups() {
		$xpath = $this->form( 'email' );

		$this->assertSame( 'Recipients', $this->text( $xpath, '(//fieldset[@class="sc-wpun-field-group"])[1]/legend' ) );
		$this->assertSame( 'Message', $this->text( $xpath, '(//fieldset[@class="sc-wpun-field-group"])[2]/legend' ) );
		$this->assertSame( 2, $xpath->query( '//fieldset[@class="sc-wpun-field-group"]' )->length );
		$this->assertSame(
			array( 'settings[to]' ),
			$this->names( $xpath, '(//fieldset[@class="sc-wpun-field-group"])[1]/table//input' )
		);
		$this->assertSame(
			array( 'settings[from]', 'settings[reply_to]', 'settings[subject_prefix]' ),
			$this->names( $xpath, '(//fieldset[@class="sc-wpun-field-group"])[2]/table//input[@type="text"]' )
		);
		$this->assertSame( 1, $xpath->query( '(//fieldset[@class="sc-wpun-field-group"])[2]//fieldset[@id="sc_wpun_channel_settings_format"]' )->length );
		$this->assertSame( 1, $xpath->query( '(//fieldset[@class="sc-wpun-field-group"])[2]//input[@name="settings[subject_prefix]"]' )->length );

		$fields = sc_wpun()->channels()->get( 'email' )->get_fields();
		$this->assertSame( array( 'to', 'from', 'reply_to', 'format', 'subject_prefix' ), array_keys( $fields ) );
	}

	/**
	 * A module that draws its own markup has no groups.
	 */
	public function test_a_custom_render_has_no_groups() {
		sc_wpun()->channels()->register( new Test_Custom_Render_Channel() );
		$xpath = $this->form( 'fixture_custom' );
		sc_wpun()->channels()->unregister( 'fixture_custom' );

		$this->assertSame( 0, $xpath->query( '//fieldset[@class="sc-wpun-field-group"]' )->length );
	}

	// Conditional fields: the note.

	/**
	 * The fields of the conditional channel.
	 *
	 * @param array $conditions What `token` applies when.
	 * @param array $extra      More fields.
	 * @return array
	 */
	private function conditional( array $conditions, array $extra = array() ): array {
		return array(
			'key'   => array(
				'type'         => 'text',
				'label'        => 'Key',
				'description'  => 'The key.',
				'required'     => true,
				'applies_when' => $conditions,
			),
			'mode'  => array(
				'type'    => 'enum',
				'label'   => 'Mode',
				'default' => 'bot',
				'options' => array(
					'app' => 'App',
					'bot' => 'Bot',
					'web' => 'Web',
				),
			),
			'sign'  => array(
				'type'   => 'boolean',
				'label'  => 'Signing',
				'labels' => array(
					'on'  => 'Signed',
					'off' => 'Unsigned',
				),
			),
			'plain' => array(
				'type'  => 'boolean',
				'label' => 'Plain',
			),
		) + $extra;
	}

	/**
	 * The note of the key field.
	 *
	 * @param array $conditions What the token applies when.
	 * @return string
	 */
	private function note( array $conditions ): string {
		Field_Types::forget_warnings();
		sc_wpun()->channels()->unregister( 'grp' );
		$this->register( $this->conditional( $conditions ) );

		return $this->text( $this->form(), '//p[@id="sc_wpun_channel_settings_key-applies-when"]' );
	}

	/**
	 * The note names the field by its label, the value by its option label, and is linked to the control after its description.
	 */
	public function test_the_note_is_linked_to_the_control() {
		$this->register( $this->conditional( array( 'mode' => 'app' ) ) );
		$xpath = $this->form();

		$note = $xpath->query( '//p[@id="sc_wpun_channel_settings_key-applies-when"]' );
		$this->assertSame( 1, $note->length );
		$this->assertSame( 'description sc-wpun-applies-when', $note->item( 0 )->getAttribute( 'class' ) );
		$this->assertSame( 'Used only when Mode is App.', $this->text( $xpath, '//p[@id="sc_wpun_channel_settings_key-applies-when"]' ) );

		$input = $xpath->query( '//input[@name="settings[key]"]' )->item( 0 );
		$this->assertSame( 'sc_wpun_channel_settings_key-description sc_wpun_channel_settings_key-applies-when', $input->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 'true', $input->getAttribute( 'aria-required' ) );
		$this->assertSame( 1, $xpath->query( '//th/label[@for="sc_wpun_channel_settings_key"]/span[contains(.,"(required)")]' )->length, 'Still marked as required.' );
		$this->assertSame( 0, $xpath->query( '//p[contains(@id,"mode-applies-when")]' )->length, 'A field without a condition has no note.' );
	}

	/**
	 * Boolean values use the on and off texts, or Yes and No.
	 */
	public function test_the_note_uses_boolean_labels() {
		$this->assertSame( 'Used only when Signing is Signed.', $this->note( array( 'sign' => true ) ) );
		$this->assertSame( 'Used only when Signing is Unsigned.', $this->note( array( 'sign' => '0' ) ) );
		$this->assertSame( 'Used only when Plain is Yes.', $this->note( array( 'plain' => 1 ) ) );
		$this->assertSame( 'Used only when Plain is No.', $this->note( array( 'plain' => false ) ) );
	}

	/**
	 * Several values are joined with "or" and several conditions with "and"; a value that is no option is shown as it is.
	 */
	public function test_the_note_joins_values_and_conditions() {
		$this->assertSame( 'Used only when Mode is App or Bot.', $this->note( array( 'mode' => array( 'app', 'bot' ) ) ) );
		$this->assertSame( 'Used only when Mode is App, Bot or Web.', $this->note( array( 'mode' => array( 'app', 'bot', 'web' ) ) ) );
		$this->assertSame(
			'Used only when Mode is App and Signing is Signed.',
			$this->note(
				array(
					'mode' => 'app',
					'sign' => true,
				)
			)
		);
		$this->assertSame( 'Used only when Mode is odd.', $this->note( array( 'mode' => 'odd' ) ) );
	}

	/**
	 * A field without a label is named by its name.
	 */
	public function test_the_note_falls_back_to_the_name() {
		$fields = $this->conditional( array( 'raw' => 'x' ), array( 'raw' => array( 'type' => 'text' ) ) );
		$this->register( $fields );

		$this->assertSame( 'Used only when raw is x.', $this->text( $this->form(), '//p[@id="sc_wpun_channel_settings_key-applies-when"]' ) );
	}

	/**
	 * A condition on a field that does not exist is reported once, has no note, and the field applies.
	 */
	public function test_an_unknown_controlling_field_is_reported() {
		$this->setExpectedIncorrectUsage( 'WPUN\Field_Types::conditions' );
		$this->register( $this->conditional( array( 'ghost' => 'x' ) ) );

		$xpath = $this->form();
		$this->assertSame( 0, $xpath->query( '//p[contains(@class,"sc-wpun-applies-when")]' )->length );
		$input = $xpath->query( '//input[@name="settings[key]"]' )->item( 0 );
		$this->assertSame( 'sc_wpun_channel_settings_key-description', $input->getAttribute( 'aria-describedby' ) );

		$result = sc_wpun()->channels()->get( 'grp' )->validate_settings( array( 'mode' => 'bot' ), array() );
		$this->assertWPError( $result, 'It applies, so it is required.' );
	}

	/**
	 * An applies_when that is not an array is reported and ignored.
	 */
	public function test_applies_when_that_is_not_an_array_is_reported() {
		$this->setExpectedIncorrectUsage( 'WPUN\Field_Types::conditions' );
		$fields                        = $this->conditional( array() );
		$fields['key']['applies_when'] = 'mode';
		$this->register( $fields );

		$this->assertSame( '', $this->text( $this->form(), '//p[contains(@class,"sc-wpun-applies-when")]' ) );
		$this->assertWPError( sc_wpun()->channels()->get( 'grp' )->validate_settings( array(), array() ) );
	}

	// Conditional fields: validation.

	/**
	 * Validate settings with the channel.
	 *
	 * @param array $fields   Fields.
	 * @param array $settings Submitted.
	 * @param array $stored   Stored.
	 * @return array|WP_Error
	 */
	private function validate( array $fields, array $settings, array $stored = array() ) {
		return ( new Test_Fields_Channel( 'grp', $fields ) )->validate_settings( $settings, $stored );
	}

	/**
	 * The name of the field an error is about.
	 *
	 * @param mixed $result A result.
	 * @return string
	 */
	private function error_field( $result ): string {
		$this->assertWPError( $result );

		return (string) ( $result->get_error_data()['field'] ?? '' );
	}

	/**
	 * Required is enforced when the condition holds, with the controlling value submitted.
	 */
	public function test_required_is_enforced_only_when_the_condition_holds() {
		$fields = $this->conditional( array( 'mode' => 'app' ) );

		$this->assertSame( 'key', $this->error_field( $this->validate( $fields, array( 'mode' => 'app' ) ) ) );
		$this->assertSame( 'Key is required.', $this->validate( $fields, array( 'mode' => 'app' ) )->get_error_message() );
		$this->assertSame(
			'key',
			$this->error_field(
				$this->validate(
					$fields,
					array(
						'mode' => 'app',
						'key'  => '',
					)
				)
			)
		);
		$this->assertSame( array( 'mode' => 'bot' ), $this->validate( $fields, array( 'mode' => 'bot' ) ), 'Missing stays missing.' );
		$this->assertSame(
			array(
				'key'  => 'abc',
				'mode' => 'app',
			),
			$this->validate(
				$fields,
				array(
					'mode' => 'app',
					'key'  => 'abc',
				)
			)
		);
	}

	/**
	 * The controlling value is the stored one, or its default, when nothing is submitted for it.
	 */
	public function test_the_controlling_value_can_be_stored_or_default() {
		$fields = $this->conditional( array( 'mode' => 'app' ) );

		$this->assertSame( 'key', $this->error_field( $this->validate( $fields, array(), array( 'mode' => 'app' ) ) ) );
		$this->assertSame( array(), $this->validate( $fields, array(), array( 'mode' => 'bot' ) ) );
		// A submitted value wins over a stored one.
		$this->assertSame( 'key', $this->error_field( $this->validate( $fields, array( 'mode' => 'app' ), array( 'mode' => 'bot' ) ) ) );
		$this->assertSame( array( 'mode' => 'bot' ), $this->validate( $fields, array( 'mode' => 'bot' ), array( 'mode' => 'app' ) ) );

		// Default: bot does not hold, an app default does.
		$this->assertSame( array(), $this->validate( $fields, array() ) );
		$fields['mode']['default'] = 'app';
		$this->assertSame( 'key', $this->error_field( $this->validate( $fields, array() ) ) );
	}

	/**
	 * A stored value of the dependent field satisfies required, as it always did.
	 */
	public function test_a_stored_value_satisfies_required() {
		$fields = $this->conditional( array( 'mode' => 'app' ) );

		$this->assertSame( array( 'mode' => 'app' ), $this->validate( $fields, array( 'mode' => 'app' ), array( 'key' => 'kept' ) ) );
	}

	/**
	 * The order of the fields does not matter: the controlling field comes last in the schema and is still read.
	 */
	public function test_the_controlling_field_can_come_after() {
		$fields = $this->conditional( array( 'sign' => true ) );
		$this->assertSame( array_keys( $fields ), array( 'key', 'mode', 'sign', 'plain' ) );

		$this->assertSame( 'key', $this->error_field( $this->validate( $fields, array( 'sign' => '1' ) ) ) );
		$this->assertSame( array( 'sign' => false ), $this->validate( $fields, array( 'sign' => '0' ) ) );
		$this->assertSame(
			array(
				'key'  => 'x',
				'sign' => true,
			),
			$this->validate(
				$fields,
				array(
					'sign' => 1,
					'key'  => 'x',
				)
			)
		);
	}

	/**
	 * Lists of values are alternatives, conditions are all needed, and a list of choices matches when any chosen one does.
	 */
	public function test_lists_and_several_conditions() {
		$either = $this->conditional( array( 'mode' => array( 'app', 'web' ) ) );
		$this->assertSame( 'key', $this->error_field( $this->validate( $either, array( 'mode' => 'web' ) ) ) );
		$this->assertSame( 'key', $this->error_field( $this->validate( $either, array( 'mode' => 'app' ) ) ) );
		$this->assertSame( array( 'mode' => 'bot' ), $this->validate( $either, array( 'mode' => 'bot' ) ) );

		$both = $this->conditional(
			array(
				'mode' => 'app',
				'sign' => true,
			)
		);
		$this->assertSame( array( 'mode' => 'app' ), $this->validate( $both, array( 'mode' => 'app' ) ) );
		$this->assertSame( array( 'sign' => true ), $this->validate( $both, array( 'sign' => '1' ) ) );
		$this->assertSame(
			'key',
			$this->error_field(
				$this->validate(
					$both,
					array(
						'mode' => 'app',
						'sign' => '1',
					)
				)
			)
		);

		$many                     = $this->conditional( array( 'mode' => 'web' ) );
		$many['mode']['multiple'] = true;
		$many['mode']['default']  = array();
		$this->assertSame( 'key', $this->error_field( $this->validate( $many, array( 'mode' => array( 'app', 'web' ) ) ) ) );
		$this->assertSame( array( 'mode' => array( 'app' ) ), $this->validate( $many, array( 'mode' => array( 'app' ) ) ) );
		$this->assertSame( array( 'mode' => array() ), $this->validate( $many, array( 'mode' => array() ) ) );
	}

	/**
	 * Text, number and a callback are compared after they are cleaned; a value that is not valid never matches.
	 */
	public function test_values_are_compared_after_cleaning() {
		$fields = array(
			'dep'  => array(
				'type'         => 'text',
				'required'     => true,
				'applies_when' => array( 'ctrl' => 'go' ),
			),
			'ctrl' => array(
				'type'              => 'text',
				'sanitize_callback' => 'strtolower',
			),
		);
		$this->assertSame( 'dep', $this->error_field( $this->validate( $fields, array( 'ctrl' => 'GO' ) ) ) );
		$this->assertSame( array( 'ctrl' => 'stop' ), $this->validate( $fields, array( 'ctrl' => 'STOP' ) ) );

		$fields['ctrl']                = array(
			'type' => 'number',
			'max'  => 5,
		);
		$fields['dep']['applies_when'] = array( 'ctrl' => 3 );
		$this->assertSame( 'dep', $this->error_field( $this->validate( $fields, array( 'ctrl' => '3' ) ) ) );
		$this->assertSame( array( 'ctrl' => 4 ), $this->validate( $fields, array( 'ctrl' => '4' ) ) );
		// Not valid for the controlling field: the condition does not hold, and its own error is the one reported.
		$this->assertSame( 'ctrl', $this->error_field( $this->validate( $fields, array( 'ctrl' => '9' ) ) ) );
	}

	/**
	 * A value is cleaned and checked when the field does not apply, and kept.
	 */
	public function test_a_value_that_does_not_apply_is_still_checked() {
		$fields                = $this->conditional( array( 'mode' => 'app' ) );
		$fields['key']['type'] = 'url';
		$result                = $this->validate(
			$fields,
			array(
				'mode' => 'bot',
				'key'  => 'https://example.org/x',
			)
		);
		$this->assertSame(
			array(
				'key'  => 'https://example.org/x',
				'mode' => 'bot',
			),
			$result
		);

		$this->assertSame(
			'key',
			$this->error_field(
				$this->validate(
					$fields,
					array(
						'mode' => 'bot',
						'key'  => 'javascript:alert(1)',
					)
				)
			)
		);
	}

	/**
	 * A secret that does not apply may be left empty or cleared, and one that does apply is required as before.
	 */
	public function test_a_conditional_required_secret() {
		$fields = array(
			'sec'  => array(
				'type'         => 'secret',
				'label'        => 'Secret',
				'required'     => true,
				'applies_when' => array( 'mode' => 'app' ),
			),
			'mode' => array(
				'type'    => 'enum',
				'label'   => 'Mode',
				'default' => 'bot',
				'options' => array(
					'app' => 'App',
					'bot' => 'Bot',
				),
			),
		);

		// Not applying: nothing, a stored value kept, a clear, a blank.
		$this->assertSame( array( 'mode' => 'bot' ), $this->validate( $fields, array( 'mode' => 'bot' ) ) );
		$this->assertSame(
			array(
				'sec'  => 'kept',
				'mode' => 'bot',
			),
			$this->validate(
				$fields,
				array(
					'mode' => 'bot',
					'sec'  => '',
				),
				array( 'sec' => 'kept' )
			)
		);
		$this->assertSame(
			array(
				'sec'  => '',
				'mode' => 'bot',
			),
			$this->validate(
				$fields,
				array(
					'mode'       => 'bot',
					'sec__clear' => '1',
				),
				array( 'sec' => 'kept' )
			)
		);
		$this->assertSame(
			array(
				'sec'  => '',
				'mode' => 'bot',
			),
			$this->validate(
				$fields,
				array(
					'mode' => 'bot',
					'sec'  => '   ',
				)
			)
		);
		$this->assertSame(
			array(
				'sec'  => 'typed',
				'mode' => 'bot',
			),
			$this->validate(
				$fields,
				array(
					'mode' => 'bot',
					'sec'  => 'typed',
				)
			)
		);

		// Applying: required.
		$this->assertSame( 'sec', $this->error_field( $this->validate( $fields, array( 'mode' => 'app' ) ) ) );
		$this->assertSame(
			'sec',
			$this->error_field(
				$this->validate(
					$fields,
					array(
						'mode' => 'app',
						'sec'  => '   ',
					)
				)
			)
		);
		$this->assertSame(
			'sec',
			$this->error_field(
				$this->validate(
					$fields,
					array(
						'mode'       => 'app',
						'sec__clear' => '1',
					),
					array( 'sec' => 'kept' )
				)
			)
		);
		$this->assertSame( 'sec', $this->error_field( $this->validate( $fields, array( 'mode' => 'app' ), array( 'sec' => '' ) ) ) );
		$this->assertSame(
			array(
				'sec'  => 'kept',
				'mode' => 'app',
			),
			$this->validate( $fields, array( 'mode' => 'app' ), array( 'sec' => 'kept' ) )
		);
	}

	/**
	 * Through the form: a conditional required field that does not apply saves, and a stored value stays when nothing is submitted for it.
	 */
	public function test_the_form_saves_a_field_that_does_not_apply() {
		$this->register( $this->conditional( array( 'mode' => 'app' ) ) );

		$this->post( array( 'mode' => 'bot' ) );
		$this->assertStringNotContainsString( 'action=new', (string) sc_wpun()->channel_form()->process_save() );
		$saved = sc_wpun_channels()->all();
		$this->assertCount( 2, $saved, 'The email channel and the new one.' );

		$this->post( array( 'mode' => 'app' ) );
		$this->assertStringContainsString( 'action=new&type=grp', (string) sc_wpun()->channel_form()->process_save() );
		$meta = get_user_meta( self::$admin_id, 'sc_wpun_channel_form', true );
		$this->assertSame( 'Key is required.', $meta['errors']['settings.key'] );
	}
}
