<?php
/**
 * Modules declare the API version they need and the registry enforces it (#190).
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Channels_Tab;
use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Notification;
use WPUN\Notification_Item;

/**
 * A test channel that needs a given API version.
 */
class Test_Gated_Channel extends Test_Channel {

	/**
	 * What the channel needs.
	 *
	 * @var string
	 */
	private $constraint;

	/**
	 * Constructor.
	 *
	 * @param string $type       Channel type.
	 * @param string $constraint The API constraint.
	 */
	public function __construct( $type, $constraint ) {
		parent::__construct( $type );
		$this->constraint = $constraint;
	}

	/**
	 * What the channel needs.
	 *
	 * @return string
	 */
	public function get_api_constraint(): string {
		return $this->constraint;
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return 'Gated <module>';
	}
}

/**
 * Registration, rejection, delivery, the Channels tab, the notice and Site Health.
 */
class Test_Channel_Api_Constraint extends WPUN_Test_Case {

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
	 * An administrator and the default settings.
	 */
	public function set_up() {
		parent::set_up();

		sc_wpun()->config()->flush();
		$this->save_settings();
		wp_set_current_user( self::$admin_id );
	}

	/**
	 * Clear the rejections the tests caused in the shared registry.
	 */
	public function tear_down() {
		foreach ( array( 'gated', 'gated_two' ) as $type ) {
			// A successful registration removes the rejection.
			sc_wpun()->channels()->register( new Test_Channel( $type ) );
			sc_wpun()->channels()->unregister( $type );
		}
		sc_wpun()->config()->flush();
		unset( $_GET['tab'] );
		parent::tear_down();
	}

	/**
	 * Store an instance of a type, then leave the type unregistered.
	 *
	 * @param string $id   Instance id.
	 * @param string $type Channel type.
	 */
	private function store_instance( $id, $type ) {
		sc_wpun()->channels()->register( new Test_Channel( $type ) );
		sc_wpun_channels()->save( new Channel_Instance( $id, $type, $id . ' label', true, array() ) );
		sc_wpun()->channels()->unregister( $type );
	}

	/**
	 * A notification.
	 *
	 * @return Notification
	 */
	private function notification() {
		$item = new Notification_Item(
			array(
				'type'            => 'core',
				'key'             => 'core',
				'name'            => 'WordPress',
				'current_version' => '6.0',
				'new_version'     => '6.1',
			)
		);

		return new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $item ) );
	}

	/**
	 * The settings page, as HTML.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private function page( $tab = '' ) {
		if ( '' !== $tab ) {
			$_GET['tab'] = $tab;
		}
		sc_wpun()->settings_page()->admin_settings_init();
		ob_start();
		sc_wpun()->settings_page()->settings_page();

		return (string) ob_get_clean();
	}

	/**
	 * A module that needs what the site has, and one that needs nothing, register.
	 */
	public function test_a_met_or_empty_constraint_registers() {
		$registry = new Channel_Registry();

		$this->assertTrue( $registry->register( new Test_Gated_Channel( 'gated', '^1.0' ) ) );
		$this->assertTrue( $registry->register( new Test_Gated_Channel( 'gated_two', '' ) ) );
		$this->assertTrue( $registry->has( 'gated' ) );
		$this->assertTrue( $registry->has( 'gated_two' ) );
		$this->assertSame( array(), $registry->get_rejected() );
		$this->assertSame( '', ( new Test_Channel() )->get_api_constraint() );
	}

	/**
	 * An unmet constraint is refused, recorded and announced once.
	 */
	public function test_an_unmet_constraint_is_refused() {
		$calls = array();
		add_action(
			'sc_wpun_channel_rejected',
			static function ( $type, $reason, $channel ) use ( &$calls ) {
				$calls[] = array( $type, $reason, $channel );
			},
			10,
			3
		);

		$registry = new Channel_Registry();
		$channel  = new Test_Gated_Channel( 'gated', '^99.0' );

		$this->assertFalse( $registry->register( $channel ) );
		$this->assertFalse( $registry->has( 'gated' ) );

		$rejected = $registry->get_rejected();
		$this->assertSame( array( 'gated' ), array_keys( $rejected ) );
		$this->assertSame( 'Gated <module>', $rejected['gated']['label'] );
		$this->assertSame( '^99.0', $rejected['gated']['constraint'] );
		$this->assertSame( 'Needs WP Updates Notifier API ^99.0; this site has ' . sc_wpun_api_version() . '. Update WP Updates Notifier.', $rejected['gated']['reason'] );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'gated', $calls[0][0] );
		$this->assertSame( $rejected['gated']['reason'], $calls[0][1] );
		$this->assertSame( $channel, $calls[0][2] );
	}

	/**
	 * A constraint that cannot be read is refused and reported to the developer.
	 */
	public function test_an_invalid_constraint_is_refused() {
		$this->setExpectedIncorrectUsage( 'WPUN\Version_Constraint::satisfies' );

		$registry = new Channel_Registry();

		$this->assertFalse( $registry->register( new Test_Gated_Channel( 'gated', 'not a constraint 190' ) ) );
		$this->assertSame( 'Its API requirement "not a constraint 190" could not be read.', $registry->get_rejected()['gated']['reason'] );
	}

	/**
	 * Registering the same type again, now allowed, removes the rejection.
	 */
	public function test_a_later_valid_registration_clears_the_rejection() {
		$registry = new Channel_Registry();

		$this->assertFalse( $registry->register( new Test_Gated_Channel( 'gated', '^99.0' ) ) );
		$this->assertTrue( $registry->register( new Test_Gated_Channel( 'gated', '^1.0' ) ) );

		$this->assertSame( array(), $registry->get_rejected() );
		$this->assertTrue( $registry->has( 'gated' ) );
	}

	/**
	 * The rejections are available even if nothing else read the registry first.
	 */
	public function test_get_rejected_loads_the_registry() {
		$registry = new Channel_Registry();
		add_action(
			'sc_wpun_register_channels',
			static function ( Channel_Registry $registry ) {
				$registry->register( new Test_Gated_Channel( 'gated', '^99.0' ) );
			}
		);

		$this->assertArrayHasKey( 'gated', $registry->get_rejected() );
	}

	/**
	 * Stored instances of a refused module receive nothing and say why.
	 */
	public function test_instances_of_a_refused_module_are_not_delivered_to() {
		$this->store_instance( 'gated_1', 'gated' );
		$channel = new Test_Gated_Channel( 'gated', '^99.0' );
		$this->assertFalse( sc_wpun()->channels()->register( $channel ) );

		$instance   = sc_wpun_channels()->get( 'gated_1' );
		$dispatcher = sc_wpun()->dispatcher();

		$this->assertFalse( $dispatcher->is_available( $instance ) );
		$this->assertSame( array(), $channel->received );

		$result = $dispatcher->send_one( $this->notification(), $instance );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'unavailable', $result->get_code() );
		$this->assertSame( 'The "gated" channel is not available: Needs WP Updates Notifier API ^99.0; this site has ' . sc_wpun_api_version() . '. Update WP Updates Notifier.', $result->get_message() );
		$this->assertSame( array(), $channel->received );
	}

	/**
	 * A module that is not installed keeps the generic message.
	 */
	public function test_a_module_that_is_not_installed_keeps_the_generic_message() {
		$this->store_instance( 'gone_1', 'gated' );

		$result = sc_wpun()->dispatcher()->send_one( $this->notification(), sc_wpun_channels()->get( 'gone_1' ) );

		$this->assertSame( 'The "gated" channel is not available.', $result->get_message() );
	}

	/**
	 * The Channels tab lists them apart, read-only, and still hides modules that are just not installed.
	 */
	public function test_the_channels_tab_lists_unavailable_channels() {
		$this->store_instance( 'gated_1', 'gated' );
		$this->store_instance( 'gone_1', 'gated_two' );
		sc_wpun()->channels()->register( new Test_Gated_Channel( 'gated', '^99.0' ) );

		$html = $this->page( 'channels' );
		$doc  = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		$xpath = new DOMXPath( $doc );

		$rows = $xpath->query( '//table[contains(@class,"sc-wpun-unavailable-channels")]//tbody/tr' );
		$this->assertSame( 1, $rows->length );
		$text = preg_replace( '/\s+/', ' ', $rows->item( 0 )->textContent );
		$this->assertStringContainsString( 'gated_1 label', $text );
		$this->assertStringContainsString( 'Gated <module>', $text );
		$this->assertStringContainsString( 'Unavailable: Needs WP Updates Notifier API ^99.0', $text );
		$this->assertSame( 0, $xpath->query( '//table[contains(@class,"sc-wpun-unavailable-channels")]//form | //table[contains(@class,"sc-wpun-unavailable-channels")]//a | //table[contains(@class,"sc-wpun-unavailable-channels")]//button' )->length );
		$this->assertStringNotContainsString( 'Gated <module>', str_replace( 'Gated &lt;module&gt;', '', $html ) );
		$this->assertStringNotContainsString( 'gone_1 label', $html );
		$this->assertSame( 0, $xpath->query( '//table[contains(@class,"sc-wpun-channels")]//tr[@data-id="gated_1"]' )->length );
	}

	/**
	 * Nothing is added to the Channels tab when nothing was refused.
	 */
	public function test_the_channels_tab_has_no_section_without_rejections() {
		$this->store_instance( 'gone_1', 'gated' );

		$this->assertStringNotContainsString( 'sc-wpun-unavailable-channels', $this->page( 'channels' ) );
	}

	/**
	 * The section is shown even when no channel can be listed.
	 */
	public function test_the_section_is_shown_with_an_empty_list() {
		sc_wpun_channels()->delete( 'email' );
		$this->store_instance( 'gated_1', 'gated' );
		sc_wpun()->channels()->register( new Test_Gated_Channel( 'gated', '^99.0' ) );

		$html = $this->page( 'channels' );

		$this->assertStringContainsString( 'sc-wpun-no-channels', $html );
		$this->assertStringContainsString( 'sc-wpun-unavailable-channels', $html );
	}

	/**
	 * The settings page has one notice, listing each refused module once.
	 */
	public function test_the_settings_page_notice_lists_each_module_once() {
		$this->store_instance( 'gated_1', 'gated' );
		$this->store_instance( 'gated_2', 'gated' );
		sc_wpun()->channels()->register( new Test_Gated_Channel( 'gated', '^99.0' ) );

		$html = $this->page();

		$this->assertSame( 1, substr_count( $html, 'sc-wpun-rejected-channels' ) );
		$this->assertSame( 1, substr_count( $html, 'Gated &lt;module&gt;: Needs WP Updates Notifier API ^99.0' ) );
		$this->assertFalse( has_action( 'admin_notices', array( sc_wpun()->rejected_channels(), 'render' ) ) );
	}

	/**
	 * No refused module, no notice.
	 */
	public function test_there_is_no_notice_without_rejections() {
		$this->assertStringNotContainsString( 'sc-wpun-rejected-channels', $this->page() );
	}

	/**
	 * The Site Health test exists only while a module is refused, and lists them.
	 */
	public function test_site_health_has_a_test_while_a_module_is_refused() {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertArrayNotHasKey( 'sc_wpun_rejected_channels', $tests['direct'] );

		sc_wpun()->channels()->register( new Test_Gated_Channel( 'gated', '^99.0' ) );

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'sc_wpun_rejected_channels', $tests['direct'] );

		$result = call_user_func( $tests['direct']['sc_wpun_rejected_channels']['test'] );
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertStringContainsString( 'Gated &lt;module&gt;: Needs WP Updates Notifier API ^99.0', $result['description'] );
	}
}
