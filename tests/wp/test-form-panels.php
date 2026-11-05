<?php
/**
 * Panels on the add and edit channel form (#389), and the preview panel of the email channel.
 *
 * @package wp-updates-notifier
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM properties are camelCase.

use WPUN\Admin\Channel_Form;
use WPUN\Channel_Instance;
use WPUN\Modules\Email\Email_Channel;
use WPUN\Modules\Webhook\Webhook_Channel;

/**
 * Channel::get_form_panels(), the filter, the drawing, the draft instance and the email preview.
 */
class Test_Form_Panels extends WPUN_Test_Case {

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
	 * The channel under test.
	 *
	 * @var Test_Fields_Channel
	 */
	private $channel;

	/**
	 * Create the user.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * An administrator and a registered channel with two fields.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->config()->flush();
		$this->save_settings();
		wp_set_current_user( self::$admin_id );

		$this->channel = new Test_Fields_Channel(
			'pnl',
			array(
				'note'  => array(
					'type'  => 'text',
					'label' => 'Note',
					'group' => 'same',
				),
				'token' => array(
					'type'  => 'text',
					'label' => 'Token',
				),
			),
			array(
				'same' => array(
					'label'       => 'Same id group',
					'description' => 'Group text.',
				),
			)
		);
		sc_wpun()->channels()->register( $this->channel );
	}

	/**
	 * Remove what tests registered.
	 */
	public function tear_down() {
		sc_wpun()->channels()->unregister( 'pnl' );
		sc_wpun()->config()->flush();
		$_POST = array();
		unset( $_GET['tab'], $_GET['action'], $_GET['id'], $_GET['type'] );
		parent::tear_down();
	}

	/**
	 * The form of the channel, parsed.
	 *
	 * @param string $mode `new` or `edit`.
	 * @param string $type Channel type, or the id of the instance to edit.
	 * @return DOMXPath
	 */
	private function form( string $mode = 'new', string $type = 'pnl' ): DOMXPath {
		$_GET['tab']    = 'channels';
		$_GET['action'] = $mode;
		unset( $_GET['id'], $_GET['type'] );
		$_GET[ 'new' === $mode ? 'type' : 'id' ] = $type;
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
	 * The markup of the form, as text.
	 *
	 * @param string $mode `new` or `edit`.
	 * @param string $type Channel type, or the id of the instance to edit.
	 * @return string
	 */
	private function html( string $mode = 'new', string $type = 'pnl' ): string {
		$_GET['tab']    = 'channels';
		$_GET['action'] = $mode;
		unset( $_GET['id'], $_GET['type'] );
		$_GET[ 'new' === $mode ? 'type' : 'id' ] = $type;
		ob_start();
		sc_wpun()->channels_tab()->render();

		return (string) ob_get_clean();
	}

	/**
	 * Store an instance of the channel.
	 */
	private function store() {
		sc_wpun_channels()->save( new Channel_Instance( 'pnl_one', 'pnl', 'Stored label', false, array( 'note' => 'stored note' ) ) );
	}

	/**
	 * Post the form with a token the channel refuses, so the save fails and the values come back.
	 *
	 * @param string $mode `new` or `edit`.
	 */
	private function failed_save( string $mode ) {
		$key                                      = 'new' === $mode ? 'pnl' : 'pnl_one';
		$_POST                                    = array(
			'action'   => Channel_Form::SAVE_ACTION,
			'mode'     => $mode,
			'label'    => 'Typed label',
			'enabled'  => '0',
			'routing'  => array(
				'scope' => 'all',
				'core'  => 'all',
				'types' => array( '' ),
			),
			'settings' => array(
				'note'  => 'typed note',
				'token' => 'bad',
			),
			'_wpnonce' => wp_create_nonce( Channel_Form::SAVE_ACTION . '_' . $mode . '_' . $key ),
		);
		$_POST[ 'new' === $mode ? 'type' : 'id' ] = $key;
		sc_wpun()->channel_form()->process_save();
		$_POST = array();
	}

	/**
	 * Panels to draw.
	 *
	 * @return array
	 */
	private function panels(): array {
		return array(
			'first'  => array(
				'label'       => 'First panel',
				'content'     => '<p class="first-content">Hello</p>',
				'description' => 'About the first.',
			),
			'second' => array(
				'label'     => 'Second panel',
				'content'   => '<p>Folded</p>',
				'collapsed' => true,
			),
		);
	}

	// The API.

	/**
	 * The default is no panels, and the form has none of their markup.
	 */
	public function test_no_panels_by_default() {
		$this->assertSame( array(), $this->channel->get_form_panels_filtered( new Channel_Instance( 'a', 'pnl', 'A', true ) ) );

		$html = $this->html();
		$this->assertStringNotContainsString( 'sc-wpun-form-panel', $html );
		$this->assertStringNotContainsString( 'sc_wpun_channel_panel_', $html );
	}

	/**
	 * Panels are drawn in order after the field groups and before the after-form action, with heading, description and details.
	 */
	public function test_panels_are_drawn_in_order_after_the_groups() {
		$this->channel->panels = $this->panels();
		add_action(
			'sc_wpun_after_channel_form',
			static function () {
				echo '<i id="after-hook"></i>';
			}
		);
		$xpath = $this->form();

		$sets = $xpath->query( '//fieldset[contains(@class,"sc-wpun-form-panel")]' );
		$this->assertSame( 2, $sets->length );
		$this->assertSame( 'First panel', trim( $xpath->query( '(//fieldset[contains(@class,"sc-wpun-form-panel")])[1]/legend/h3' )->item( 0 )->textContent ) );
		$this->assertSame( 'About the first.', trim( $xpath->query( '//p[@id="sc_wpun_channel_panel_first-description"]' )->item( 0 )->textContent ) );
		$this->assertSame( 'sc_wpun_channel_panel_first-description', $sets->item( 0 )->getAttribute( 'aria-describedby' ) );
		$this->assertSame( 1, $xpath->query( '//div[@class="sc-wpun-form-panel-content"]/p[@class="first-content"]' )->length, 'The content is output as it is.' );

		$details = $xpath->query( '//details[contains(@class,"sc-wpun-form-panel-details")]' );
		$this->assertSame( 1, $details->length );
		$this->assertFalse( $details->item( 0 )->hasAttribute( 'open' ), 'A panel never opens by itself.' );
		$this->assertSame( 'Second panel', trim( $xpath->query( '//details/summary' )->item( 0 )->textContent ) );
		$this->assertSame( 1, $xpath->query( '//details/fieldset/legend[@class="screen-reader-text"][normalize-space()="Second panel"]' )->length );

		$this->assertSame( 1, $xpath->query( '(//fieldset[contains(@class,"sc-wpun-form-panel")])[1]/preceding::fieldset[@class="sc-wpun-field-group"]' )->length, 'After the field group.' );
		$this->assertSame( 1, $xpath->query( '(//fieldset[contains(@class,"sc-wpun-form-panel")])[1]/following::*[@id="after-hook"]' )->length, 'Before the after-form action.' );
		$this->assertSame( 1, $xpath->query( '//details[contains(@class,"sc-wpun-form-panel-details")]/following::*[@id="after-hook"]' )->length );
		$this->assertSame( 0, $xpath->query( '//form//fieldset[contains(@class,"sc-wpun-form-panel")]//input[@name]' )->length, 'Nothing in a panel is submitted.' );
	}

	/**
	 * Panels are drawn when the module draws its own settings too.
	 */
	public function test_panels_are_drawn_with_a_custom_render() {
		$this->channel->custom = '<p id="custom-settings">Mine</p>';
		$this->channel->panels = $this->panels();
		$xpath                 = $this->form();

		$this->assertSame( 1, $xpath->query( '//p[@id="custom-settings"]' )->length );
		$this->assertSame( 2, $xpath->query( '//fieldset[contains(@class,"sc-wpun-form-panel")]' )->length );
		$this->assertSame( 2, $xpath->query( '//p[@id="custom-settings"]/following::fieldset[contains(@class,"sc-wpun-form-panel")]' )->length, 'After the settings.' );
	}

	/**
	 * A panel and a group with the same id do not share a DOM id.
	 */
	public function test_a_panel_and_a_group_with_the_same_id_have_distinct_dom_ids() {
		$this->channel->panels = array(
			'same' => array(
				'label'       => 'Same id panel',
				'description' => 'Panel text.',
				'content'     => '',
			),
		);
		$xpath                 = $this->form();

		$ids = array();
		foreach ( $xpath->query( '//*[@id]' ) as $node ) {
			$ids[] = $node->getAttribute( 'id' );
		}
		$this->assertSame( array_values( array_unique( $ids ) ), $ids, 'No duplicate ids.' );
		$this->assertContains( 'sc_wpun_channel_panel_same-description', $ids );
		$this->assertContains( 'sc_wpun_channel_group_same-description', $ids );
	}

	// The instance.

	/**
	 * The add form gets a draft with an empty id; the edit form gets the stored instance.
	 */
	public function test_the_add_form_gets_a_draft_and_the_edit_form_the_stored_instance() {
		$this->channel->panels = $this->panels();
		$this->html();

		$draft = end( $this->channel->panel_instances );
		$this->assertSame( '', $draft->get_id() );
		$this->assertSame( 'pnl', $draft->get_type() );

		$this->store();
		$this->channel->panel_instances = array();
		$this->html( 'edit', 'pnl_one' );
		$stored = end( $this->channel->panel_instances );
		$this->assertSame( 'pnl_one', $stored->get_id() );
		$this->assertSame( 'stored note', $stored->get_setting( 'note' ) );
	}

	/**
	 * The before and after hooks keep the provisional instance with an id on the add form.
	 */
	public function test_the_hooks_keep_the_provisional_instance() {
		$seen = null;
		add_action(
			'sc_wpun_after_channel_form',
			static function ( $instance ) use ( &$seen ) {
				$seen = $instance;
			}
		);
		$this->html();

		$this->assertNotSame( '', $seen->get_id() );
	}

	/**
	 * After a failed save, the panels get the submitted values, on the add form and on the edit form.
	 */
	public function test_after_a_failed_save_the_panels_get_the_submitted_values() {
		$this->store();

		$this->failed_save( 'new' );
		$this->html();
		$draft = end( $this->channel->panel_instances );
		$this->assertSame( '', $draft->get_id() );
		$this->assertSame( 'Typed label', $draft->get_label() );
		$this->assertSame( 'typed note', $draft->get_setting( 'note' ) );
		$this->assertSame( 'bad', $draft->get_setting( 'token' ) );

		$this->failed_save( 'edit' );
		$this->html( 'edit', 'pnl_one' );
		$edited = end( $this->channel->panel_instances );
		$this->assertSame( 'pnl_one', $edited->get_id() );
		$this->assertSame( 'Typed label', $edited->get_label() );
		$this->assertSame( 'typed note', $edited->get_setting( 'note' ) );
	}

	// Cleaning and the filter.

	/**
	 * Entries that are not usable are dropped with a notice, and kept ones have exactly four keys.
	 */
	public function test_bad_entries_are_dropped() {
		$this->setExpectedIncorrectUsage( 'WPUN\Channel::get_form_panels_filtered' );
		$this->channel->panels = array(
			'good'       => array(
				'label'       => 'Good',
				'description' => 42,
				'collapsed'   => 'yes',
				'extra'       => 'ignored',
			),
			'no_label'   => array( 'content' => 'x' ),
			'empty'      => array( 'label' => '' ),
			'not_string' => array(
				'label'   => 'Bad content',
				'content' => array( 'x' ),
			),
			'scalar'     => 'text',
			''           => array( 'label' => 'No id' ),
		);

		$panels = $this->channel->get_form_panels_filtered( new Channel_Instance( 'a', 'pnl', 'A', true ) );

		$this->assertSame(
			array(
				'good' => array(
					'label'       => 'Good',
					'content'     => '',
					'description' => '',
					'collapsed'   => false,
				),
			),
			$panels
		);
	}

	/**
	 * The filter adds and removes panels, and receives the instance and the channel.
	 */
	public function test_the_filter_adds_and_removes_panels() {
		$this->channel->panels = $this->panels();
		$args                  = array();
		add_filter(
			'sc_wpun_channel_form_panels',
			static function ( $panels, $instance, $channel ) use ( &$args ) {
				$args = array( $instance, $channel );
				unset( $panels['first'] );
				$panels['added'] = array(
					'label'   => 'Added',
					'content' => '<p>Runbook</p>',
				);
				return $panels;
			},
			10,
			3
		);
		$xpath = $this->form();

		$this->assertInstanceOf( Channel_Instance::class, $args[0] );
		$this->assertSame( $this->channel, $args[1] );
		$this->assertSame( array( 'Second panel', 'Added' ), array_map( static fn( $n ) => trim( $n->textContent ), iterator_to_array( $xpath->query( '//fieldset[contains(@class,"sc-wpun-form-panel")]/legend' ) ) ) );
	}

	/**
	 * A filter result that is not an array leaves the panels as they were.
	 */
	public function test_a_filter_result_that_is_not_an_array_is_ignored() {
		$this->channel->panels = $this->panels();
		add_filter( 'sc_wpun_channel_form_panels', '__return_false' );

		$this->assertSame( array( 'first', 'second' ), array_keys( $this->channel->get_form_panels_filtered( new Channel_Instance( 'a', 'pnl', 'A', true ) ) ) );
	}

	// The draft.

	/**
	 * A draft has an empty id, keeps its values, and the with_ methods keep working and stay immutable.
	 */
	public function test_a_draft_has_no_id() {
		$draft = Channel_Instance::draft( 'pnl', 'Label', true, array( 'a' => 1 ), array( 'scope' => 'all' ) );

		$this->assertSame( '', $draft->get_id() );
		$this->assertSame( 'pnl', $draft->get_type() );
		$this->assertTrue( $draft->is_enabled() );
		$this->assertSame( array( 'a' => 1 ), $draft->get_settings() );
		$this->assertSame( array( 'scope' => 'all' ), $draft->get_routing() );

		$copy = $draft->with_label( 'Other' )->with_enabled( false )->with_settings( array( 'b' => 2 ) )->with_routing( array() );
		$this->assertSame( '', $copy->get_id() );
		$this->assertSame( 'Other', $copy->get_label() );
		$this->assertFalse( $copy->is_enabled() );
		$this->assertSame( array( 'b' => 2 ), $copy->get_settings() );
		$this->assertSame( array(), $copy->get_routing() );
		$this->assertSame( 'Label', $draft->get_label(), 'The draft is unchanged.' );
		$this->assertSame( array( 'a' => 1 ), $draft->get_settings() );
	}

	/**
	 * The constructor still refuses an empty id.
	 */
	public function test_the_constructor_still_refuses_an_empty_id() {
		$this->expectException( InvalidArgumentException::class );
		new Channel_Instance( '', 'pnl', 'Label', true );
	}

	/**
	 * The store refuses to save a draft.
	 */
	public function test_the_store_refuses_a_draft() {
		$this->setExpectedIncorrectUsage( 'WPUN\Channel_Store::save' );
		$this->setExpectedIncorrectUsage( 'WPUN\Channel_Store::save_raw' );
		$draft = Channel_Instance::draft( 'pnl', 'Label', true );

		$this->assertFalse( sc_wpun_channels()->save( $draft ) );
		sc_wpun_channels()->save_raw( $draft );
		$this->assertNull( sc_wpun_channels()->get( '' ) );
		$this->assertArrayNotHasKey( '', get_option( 'sc_wpun_channels', array() ) );
	}

	// The email preview.

	/**
	 * The preview panel of an email instance, as a map.
	 *
	 * @param array $settings Settings to override.
	 * @return array
	 */
	private function preview( array $settings = array() ): array {
		$panels = ( new Email_Channel() )->get_form_panels_filtered( new Channel_Instance( 'email', 'email', 'Email', true, array_merge( array( 'to' => 'ops@example.org, dev@example.org' ), $settings ) ) );
		$this->assertSame( array( 'preview' ), array_keys( $panels ) );
		$this->assertTrue( $panels['preview']['collapsed'] );
		$this->assertSame( 'Preview', $panels['preview']['label'] );

		return $panels['preview'];
	}

	/**
	 * Plain text shows the recipients, the prefixed subject and a text body.
	 */
	public function test_the_preview_in_plain_text() {
		update_option( 'blogname', 'Bob & Sons' );
		$content = $this->preview( array( 'subject_prefix' => '[Ops]' ) )['content'];

		$this->assertStringContainsString( 'ops@example.org, dev@example.org', $content );
		$this->assertStringContainsString( '[Ops] WP Updates Notifier: updates available for Bob &amp; Sons', $content );
		$this->assertStringContainsString( 'Example plugin', $content );
		$this->assertStringContainsString( '1.0.0', $content );
		$this->assertStringContainsString( '1.1.0', $content );
		$this->assertStringNotContainsString( '<h', $content, 'No heading: the legend of the panel is the heading.' );
		$this->assertStringNotContainsString( '&lt;ul', $content );
	}

	/**
	 * The HTML body is shown as escaped source, never as markup.
	 */
	public function test_the_preview_of_html_is_escaped() {
		$content = $this->preview( array( 'format' => 'html' ) )['content'];

		$this->assertStringContainsString( '<pre>', $content );
		$this->assertStringContainsString( '&lt;', $content, 'The markup is shown as text.' );
		$this->assertSame( 1, preg_match_all( '/<pre>/', $content ) );
		$this->assertDoesNotMatchRegularExpression( '/<(ul|li|table|p|a)[ >]/', $content );
	}

	/**
	 * Without recipients the preview says so, and is still drawn.
	 */
	public function test_the_preview_without_recipients() {
		$content = $this->preview( array( 'to' => '' ) )['content'];

		$this->assertStringContainsString( 'No recipients set yet.', $content );
		$this->assertStringContainsString( 'WP Updates Notifier: updates available', $content );
	}

	/**
	 * The email filters apply with the source `preview`, and the filtered text is escaped.
	 */
	public function test_the_preview_applies_the_email_filters() {
		$sources = array();
		add_filter(
			'sc_wpun_email_subject',
			static function ( $subject, $notification ) use ( &$sources ) {
				$sources[] = $notification->get_source();
				return $subject . ' <b>subject</b>';
			},
			10,
			2
		);
		add_filter(
			'sc_wpun_email_content',
			static function ( $body, $notification ) use ( &$sources ) {
				$sources[] = $notification->get_source();
				return $body . '<script>alert(1)</script>';
			},
			10,
			2
		);
		$content = $this->preview()['content'];

		$this->assertSame( array( 'preview', 'preview' ), $sources );
		$this->assertStringContainsString( '&lt;b&gt;subject&lt;/b&gt;', $content );
		$this->assertStringContainsString( '&lt;script&gt;', $content );
		$this->assertStringNotContainsString( '<script>', $content );
		$this->assertStringNotContainsString( '<b>', $content );
	}

	/**
	 * Building the preview sends nothing.
	 */
	public function test_the_preview_sends_nothing() {
		$sent = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$sent ) {
				++$sent;
				return true;
			}
		);
		$this->preview();

		$this->assertSame( 0, $sent );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * The email form draws the preview, collapsed, on the add form.
	 */
	public function test_the_email_form_has_the_preview() {
		$xpath = $this->form( 'new', 'email' );

		$this->assertSame( 1, $xpath->query( '//details[contains(@class,"sc-wpun-form-panel-details")]/summary[normalize-space()="Preview"]' )->length );
		$this->assertSame( 1, $xpath->query( '//details//dl[@class="sc-wpun-preview"]' )->length );
		$this->assertSame( 1, $xpath->query( '//details//dd[contains(.,"No recipients set yet.")]' )->length );
	}

	// The webhook preview.

	/**
	 * The webhook address of the tests, with a secret path.
	 */
	const HOOK_URL = 'https://hooks.example.org/in/SECRET-PATH-123?token=SECRET-QUERY-456';

	/**
	 * The key of the published Standard Webhooks test vector.
	 */
	const VECTOR = 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

	/**
	 * A signing secret that is valid: the published Standard Webhooks one.
	 */
	const HOOK_SECRET = 'whsec_' . self::VECTOR;

	/**
	 * The preview panel of a webhook instance.
	 *
	 * @param array  $settings Settings to override.
	 * @param string $id       Instance id; empty for a draft.
	 * @return array
	 */
	private function hook_preview( array $settings = array(), string $id = 'hook' ): array {
		$settings = array_merge(
			array(
				'url'            => self::HOOK_URL,
				'signing_secret' => self::HOOK_SECRET,
			),
			$settings
		);
		$instance = '' === $id ? Channel_Instance::draft( 'webhook', 'Hook', true, $settings ) : new Channel_Instance( $id, 'webhook', 'Hook', true, $settings );
		$panels   = ( new Webhook_Channel() )->get_form_panels_filtered( $instance );
		$this->assertSame( array( 'preview' ), array_keys( $panels ) );
		$this->assertTrue( $panels['preview']['collapsed'] );
		$this->assertSame( 'Preview', $panels['preview']['label'] );

		return $panels['preview'];
	}

	/**
	 * The headers and the body show, signed, with the secret values hidden.
	 */
	public function test_the_webhook_preview_shows_public_headers_and_hides_the_rest() {
		$content = $this->hook_preview( array( 'headers' => 'X-Api-Key: EXTRA-SECRET-789' ) )['content'];

		$this->assertStringContainsString( 'Content-Type: application/json', $content );
		$this->assertStringContainsString( 'User-Agent: WP-Updates-Notifier/', $content );
		$this->assertMatchesRegularExpression( '/webhook-id: msg_[0-9a-f]+/', $content );
		$this->assertMatchesRegularExpression( '/webhook-timestamp: \d+/', $content );
		$this->assertStringContainsString( 'webhook-signature: (hidden)', $content );
		$this->assertStringContainsString( 'X-Api-Key: (hidden)', $content );
		$this->assertStringNotContainsString( 'v1,', $content, 'No signature.' );
		$this->assertStringNotContainsString( 'EXTRA-SECRET-789', $content );
		$this->assertStringNotContainsString( 'SECRET-PATH-123', $content );
		$this->assertStringNotContainsString( 'SECRET-QUERY-456', $content );
		$this->assertStringNotContainsString( 'hooks.example.org', $content );
		$this->assertStringNotContainsString( 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', $content );
		$this->assertStringNotContainsString( 'Not signed', $content );
		$this->assertSame( 2, preg_match_all( '/<pre>/', $content ) );
	}

	/**
	 * Custom names of the signature headers are followed.
	 */
	public function test_the_webhook_preview_follows_custom_header_names() {
		$content = $this->hook_preview(
			array(
				'header_names'     => 'custom',
				'id_header'        => 'X-Msg-Id',
				'timestamp_header' => 'X-Msg-Time',
				'signature_header' => 'X-Msg-Sig',
			)
		)['content'];

		$this->assertMatchesRegularExpression( '/X-Msg-Id: msg_/', $content );
		$this->assertMatchesRegularExpression( '/X-Msg-Time: \d+/', $content );
		$this->assertStringContainsString( 'X-Msg-Sig: (hidden)', $content );
		$this->assertStringNotContainsString( 'webhook-id', $content );
	}

	/**
	 * Without a signing secret, or with a bad one, the preview says the message is not signed.
	 */
	public function test_the_webhook_preview_says_when_it_is_not_signed() {
		$content = $this->hook_preview( array( 'signing_secret' => '' ) )['content'];
		$this->assertStringContainsString( 'Not signed: no signing secret is set.', $content );
		$this->assertStringNotContainsString( 'webhook-signature', $content );

		$content = $this->hook_preview( array( 'signing_secret' => 'BAD-SECRET-555' ) )['content'];
		$this->assertStringContainsString( 'The signing secret is not valid, so no message is sent until it is fixed.', $content );
		$this->assertStringNotContainsString( 'BAD-SECRET-555', $content );
		$this->assertStringNotContainsString( 'webhook-signature', $content );
	}

	/**
	 * Extra headers that do not parse are left out, with a line saying so.
	 */
	public function test_the_webhook_preview_leaves_out_bad_extra_headers() {
		$content = $this->hook_preview( array( 'headers' => 'Not a header BAD-LINE-666' ) )['content'];

		$this->assertStringContainsString( 'The extra headers are not valid, so no message is sent until they are fixed. They are left out here.', $content );
		$this->assertStringNotContainsString( 'BAD-LINE-666', $content );
	}

	/**
	 * The body is the pretty-printed payload of a sample update.
	 */
	public function test_the_webhook_preview_body_is_the_sample_payload() {
		$content = $this->hook_preview()['content'];

		$this->assertSame( 1, preg_match( '#<dt>Body</dt><dd><pre>(.*?)</pre>#s', $content, $match ) );
		$this->assertStringContainsString( "\n", $match[1], 'Indented.' );
		$data = json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
		$this->assertSame( 'updates.available', $data['type'] );
		$this->assertSame( 'preview', $data['data']['notification']['source'] );
		$this->assertSame( 'Example plugin', $data['data']['items'][0]['name'] );
		$this->assertSame( '1.0.0', $data['data']['items'][0]['current_version'] );
		$this->assertSame( '1.1.0', $data['data']['items'][0]['new_version'] );
	}

	/**
	 * The payload and headers filters run with the source `preview`, their changes show, and nothing is posted.
	 */
	public function test_the_webhook_preview_runs_the_filters_and_posts_nothing() {
		$sources = array();
		$posts   = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$posts ) {
				++$posts;
				return new WP_Error( 'no', 'no' );
			}
		);
		add_filter(
			'sc_wpun_channel_payload_webhook',
			static function ( $payload, $notification ) use ( &$sources ) {
				$sources[]          = $notification->get_source();
				$payload['x_extra'] = 'added by filter';
				return $payload;
			},
			10,
			2
		);
		add_filter(
			'sc_wpun_webhook_headers',
			static function ( $headers, $notification ) use ( &$sources ) {
				$sources[]             = $notification->get_source();
				$headers['X-Filtered'] = 'FILTER-SECRET-999';
				return $headers;
			},
			10,
			2
		);
		$content = $this->hook_preview()['content'];

		$this->assertSame( array( 'preview', 'preview' ), $sources );
		$this->assertSame( 0, $posts );
		$this->assertStringContainsString( 'added by filter', $content );
		$this->assertStringContainsString( 'X-Filtered: (hidden)', $content );
		$this->assertStringNotContainsString( 'FILTER-SECRET-999', $content );
	}

	/**
	 * The add form shows the preview from a draft with no address and no id.
	 */
	public function test_the_webhook_preview_works_for_a_draft() {
		$content = $this->hook_preview( array( 'url' => '', 'signing_secret' => '' ), '' )['content']; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertStringContainsString( 'Content-Type: application/json', $content );
		$this->assertStringContainsString( 'Not signed: no signing secret is set.', $content );

		if ( ! sc_wpun()->channels()->has( 'webhook' ) ) {
			sc_wpun()->channels()->register( new Webhook_Channel() );
		}
		$xpath = $this->form( 'new', 'webhook' );
		$this->assertSame( 1, $xpath->query( '//details[contains(@class,"sc-wpun-form-panel-details")]/summary[normalize-space()="Preview"]' )->length );
		$this->assertSame( 1, $xpath->query( '//details//dl[@class="sc-wpun-preview"]' )->length );
	}
}
