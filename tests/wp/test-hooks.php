<?php
/**
 * The public hooks (#96): each fires with its documented arguments and its return value is respected.
 *
 * @package wp-updates-notifier
 */

use WPUN\Admin\Settings_Page;
use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Delivery_Result;
use WPUN\Dispatcher;
use WPUN\Item_Types;
use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Notification_Renderer;

/**
 * Hooks of the plugin.
 */
class Test_Hooks extends WPUN_Test_Case {

	const OWN_PLUGIN = 'wp-updates-notifier/wp-updates-notifier.php';

	/**
	 * Notices captured by capture_notices().
	 *
	 * @var string[]
	 */
	private $notices = array();

	/**
	 * Fake plugin info.
	 */
	public function set_up() {
		parent::set_up();

		$this->fake_http(
			'api.wordpress.org/plugins/info',
			$this->fake_response(
				200,
				wp_json_encode(
					array(
						'name'    => 'X',
						'slug'    => 'x',
						'version' => '9',
						'tested'  => '99',
					)
				)
			)
		);
		unset( $_POST['sc_wpun_test_nonce'], $_POST['instance'] );
	}

	/**
	 * Forget the request.
	 */
	public function tear_down() {
		unset( $_POST['sc_wpun_test_nonce'], $_POST['instance'] );
		parent::tear_down();
	}

	/**
	 * Run the update check with a clean mailer.
	 */
	private function run_check() {
		reset_phpmailer_instance();
		sc_wpun()->update_checker()->do_update_check();
	}

	/**
	 * Run a callback while E_USER_NOTICE (what WP_DEBUG logging raises) is captured.
	 *
	 * @param callable $callback What to run.
	 * @return mixed Its return value.
	 */
	private function capture_notices( $callback ) {
		$this->notices = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Capturing the notice the dispatcher raises under WP_DEBUG.
			function ( $number, $message ) {
				$this->notices[] = $message;
				return true;
			},
			E_USER_NOTICE
		);
		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * An item.
	 *
	 * @param string $type Type.
	 * @param string $key  Key.
	 * @param string $available Version.
	 * @return Notification_Item
	 */
	private function item( $type, $key, $available = '2.0' ) {
		return new Notification_Item(
			array(
				'type'            => $type,
				'key'             => $key,
				'name'            => 'Item ' . $key,
				'current_version' => '1.0',
				'new_version'     => $available,
			)
		);
	}

	/**
	 * Register the add-on item type "php".
	 */
	private function register_php_type() {
		add_filter(
			'sc_wpun_item_types',
			static function ( $types ) {
				$types['php'] = 'PHP';
				return $types;
			}
		);
	}

	/**
	 * A notification with one item.
	 *
	 * @return Notification
	 */
	private function notification() {
		return new Notification( 'Site', 'http://example.org', 'http://example.org/wp-admin/update-core.php', array( $this->item( 'core', 'core' ) ) );
	}

	/**
	 * The boot action fires once, however often boot() is called.
	 */
	public function test_loaded_fires_once_on_boot() {
		$count = 0;
		add_action(
			'sc_wpun_loaded',
			static function () use ( &$count ) {
				++$count;
			}
		);
		$plugin = new WPUN\Plugin();

		$plugin->boot();
		$plugin->boot();

		$this->assertSame( 1, $count );
	}

	/**
	 * Filtered defaults apply to a new install; junk falls back.
	 */
	public function test_default_settings() {
		add_filter(
			'sc_wpun_default_settings',
			static function ( $defaults ) {
				$defaults['frequency']      = 'daily';
				$defaults['notify_plugins'] = 'active';
				$defaults['notify_themes']  = '2'; // Numbers are not keys.
				$defaults['unknown']        = 'ignored';
				return $defaults;
			}
		);
		delete_option( 'sc_wpun_settings' );
		delete_option( 'sc_wpun_settings_ver' );
		delete_option( 'sc_wpun_channels' );

		sc_wpun()->settings()->maybe_upgrade();

		$this->assertSame( 'daily', $this->stored_settings()['frequency'] );
		$this->assertSame( 'active', $this->stored_settings()['notify_plugins'] );
		$this->assertSame( 'all', $this->stored_settings()['notify_themes'], 'An invalid default falls back to the built-in one.' );
		$this->assertArrayNotHasKey( 'unknown', $this->stored_settings() );
		$this->assertSame( 'daily', sc_wpun()->settings()->get()['frequency'] );

		remove_all_filters( 'sc_wpun_default_settings' );
		add_filter( 'sc_wpun_default_settings', '__return_false' );
		$this->assertSame( 'hourly', sc_wpun()->settings()->default_settings()['frequency'] );
	}

	/**
	 * A custom capability opens the menu, the test send and the dismiss; without it they are refused.
	 */
	public function test_capability() {
		add_filter(
			'sc_wpun_capability',
			static function () {
				return 'edit_pages';
			}
		);
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->save_settings();
		sc_wpun()->register_admin_hooks(); // Front-end requests, like this test's boot, do not register them.

		$this->assertSame( 'edit_pages', Settings_Page::capability() );
		$this->assertSame( 'edit_pages', apply_filters( 'option_page_capability_sc_wpun_settings', 'manage_options' ) );

		wp_set_current_user( $editor );
		$GLOBALS['submenu'] = array();
		sc_wpun()->settings_page()->admin_settings_menu();
		$entries = wp_list_filter( $GLOBALS['submenu']['options-general.php'], array( 2 => 'wp-updates-notifier' ) );
		$this->assertSame( 'edit_pages', reset( $entries )[1] );

		$_POST['sc_wpun_test_nonce'] = wp_create_nonce( 'sc_wpun_send_test' );
		$_POST['instance']           = 'email';
		$this->assertTrue( sc_wpun()->settings_page()->process_test_send() );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'sc_wpun_dismiss_slack_notice' );
		$this->assertTrue( sc_wpun()->slack_notice()->dismiss_request() );

		wp_set_current_user( $author );
		$_POST['sc_wpun_test_nonce'] = wp_create_nonce( 'sc_wpun_send_test' );
		$_REQUEST['_wpnonce']        = wp_create_nonce( 'sc_wpun_dismiss_slack_notice' );
		$this->assertFalse( sc_wpun()->settings_page()->process_test_send() );
		$this->assertFalse( sc_wpun()->slack_notice()->dismiss_request() );
		unset( $_REQUEST['_wpnonce'] );

		remove_all_filters( 'sc_wpun_capability' );
		add_filter( 'sc_wpun_capability', '__return_empty_string' );
		$this->assertSame( 'manage_options', Settings_Page::capability() );
	}

	/**
	 * A false return reports no core item and keeps what was notified about core.
	 */
	public function test_core_needs_update() {
		$this->save_settings();
		$this->save_channel_state( 'email', array( 'core' => array( 'core' => '99.0.0' ) ) );
		$this->set_updates( '99.0.0' );
		$args = array();
		add_filter(
			'sc_wpun_core_needs_update',
			static function ( $needs, $new_version, $current_version ) use ( &$args ) {
				$args = array( $needs, $new_version, $current_version );
				return false;
			},
			10,
			3
		);

		$this->run_check();

		$this->assertSame( array( true, '99.0.0', wp_get_wp_version() ), $args );
		$this->assertCount( 0, sc_wpun()->update_checker()->last_notification()->get_items_of_type( 'core' ) );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ), 'History kept while the update is waiting.' );
	}

	/**
	 * Item types: custom ones are listed, invalid ones dropped, the built-in ones stay.
	 */
	public function test_item_types() {
		add_filter(
			'sc_wpun_item_types',
			static function ( $types ) {
				unset( $types['core'], $types['theme'] );
				$types['plugin']   = 'Renamed';
				$types['php']      = 'PHP';
				$types['Bad Key']  = 'x';
				$types[7]          = 'seven';
				$types['no_label'] = 5;
				return $types;
			}
		);

		$types = Item_Types::all();

		$this->assertSame( array( 'core', 'plugin', 'theme', 'php' ), array_keys( $types ) );
		$this->assertSame( 'Plugins', $types['plugin'] );
		$this->assertTrue( Item_Types::exists( 'php' ) );
		$this->assertTrue( Item_Types::exists( 'core' ) );
		$this->assertFalse( Item_Types::exists( 'Bad Key' ) );

		remove_all_filters( 'sc_wpun_item_types' );
		add_filter( 'sc_wpun_item_types', '__return_null' );
		$this->assertSame( array( 'core', 'plugin', 'theme' ), array_keys( Item_Types::all() ) );
	}

	/**
	 * An add-on item of a registered type is delivered, recorded, not resent, and forgotten when it goes.
	 */
	public function test_update_items_with_a_registered_type() {
		$this->save_settings();
		$this->set_updates();
		$this->register_php_type();
		$add = true;
		add_filter(
			'sc_wpun_update_items',
			function ( $items ) use ( &$add ) {
				if ( $add ) {
					$items[] = $this->item( 'php', 'php', '8.4' );
					$items[] = 'junk';
				}
				return $items;
			}
		);

		$this->run_check();
		$this->assertSame( 1, $this->sent_mail_count() );
		$this->assertStringContainsString( 'Item php', $this->sent_mail()->body );
		$this->assertSame( '8.4', $this->notified_version( 'php', 'php' ) );

		$this->run_check();
		$this->assertSame( 0, $this->sent_mail_count() );

		$add = false;
		$this->run_check();
		$this->assertSame( array(), $this->channel_notified()['php'] );
	}

	/**
	 * A plugin item a filter adds, which WordPress's update data does not list, is not resent every run.
	 */
	public function test_update_items_with_a_plugin_wordpress_does_not_list() {
		$this->save_settings();
		$this->set_updates();
		$add = true;
		add_filter(
			'sc_wpun_update_items',
			function ( $items ) use ( &$add ) {
				if ( $add ) {
					$items[] = $this->item( 'plugin', 'premium/premium.php', '2.0' );
				}
				return $items;
			}
		);

		$this->run_check();
		$this->assertSame( 1, $this->sent_mail_count() );

		$this->run_check();
		$this->assertSame( 0, $this->sent_mail_count(), 'Not sent again while it is still reported.' );
		$this->assertSame( '2.0', $this->notified_version( 'plugin', 'premium/premium.php' ) );

		$add = false;
		$this->run_check();
		$this->assertArrayNotHasKey( 'premium/premium.php', $this->channel_notified()['plugin'] ?? array(), 'Forgotten once no longer reported.' );
	}

	/**
	 * History of a type nobody registered is left alone.
	 */
	public function test_update_items_with_an_unregistered_type() {
		$this->save_settings();
		$this->set_updates();
		$add = true;
		add_filter(
			'sc_wpun_update_items',
			function ( $items ) use ( &$add ) {
				if ( $add ) {
					$items[] = $this->item( 'ghost', 'g', '3' );
				}
				return $items;
			}
		);

		$this->run_check();
		$this->assertSame( '3', $this->notified_version( 'ghost', 'g' ) );

		$add = false;
		$this->run_check();
		$this->assertSame( '3', $this->notified_version( 'ghost', 'g' ) );
	}

	/**
	 * The global veto blocks an item for everyone, records nothing for it and keeps its history while it waits.
	 */
	public function test_should_notify_item() {
		$this->save_settings();
		$this->save_channel_state( 'email', array( 'plugin' => array( 'hello.php' => '1.0.0' ) ) );
		$this->set_updates(
			null,
			array(
				'hello.php'      => array( 'new_version' => '2.0.0' ),
				self::OWN_PLUGIN => array( 'new_version' => '4.5.6' ),
			)
		);
		$seen = array();
		add_filter(
			'sc_wpun_should_notify_item',
			static function ( $notify, $item ) use ( &$seen ) {
				$seen[] = array( $notify, $item->get_key() );
				return 'hello.php' !== $item->get_key();
			},
			10,
			2
		);

		$this->run_check();

		$this->assertContains( array( true, 'hello.php' ), $seen );
		$this->assertStringNotContainsString( 'Hello Dolly', $this->sent_mail()->body );
		$this->assertSame( '4.5.6', $this->notified_version( 'plugin', self::OWN_PLUGIN ) );
		$this->assertSame( '1.0.0', $this->notified_version( 'plugin', 'hello.php' ), 'Not recorded, and its history is kept.' );
	}

	/**
	 * A vetoed item of a registered add-on type still counts as waiting, so its history is kept.
	 */
	public function test_a_vetoed_add_on_item_keeps_its_history() {
		$this->save_settings();
		$this->register_php_type();
		$this->save_channel_state( 'email', array( 'php' => array( 'php' => '8.3' ) ) );
		$this->set_updates();
		add_filter(
			'sc_wpun_update_items',
			function ( $items ) {
				$items[] = $this->item( 'php', 'php', '8.4' );
				return $items;
			}
		);
		add_filter( 'sc_wpun_should_notify_item', '__return_false' );

		$this->run_check();

		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( '8.3', $this->notified_version( 'php', 'php' ) );
	}

	/**
	 * The notification filter can replace the items; a junk return is reported and ignored.
	 */
	public function test_notification_filter() {
		$this->save_settings();
		$this->set_updates( '99.0.0', array( 'hello.php' => array( 'new_version' => '2.0.0' ) ) );
		add_filter(
			'sc_wpun_notification',
			static function ( $notification ) {
				return $notification->with_items( $notification->get_items_of_type( 'plugin' ) );
			}
		);

		$this->run_check();

		$this->assertStringNotContainsString( '99.0.0', $this->sent_mail()->body );
		$this->assertStringContainsString( 'Hello Dolly', $this->sent_mail()->body );
		$this->assertSame( array( 'plugin' ), array_map( static fn( $item ) => $item->get_type(), sc_wpun()->update_checker()->last_notification()->get_items() ) );
		$this->assertSame( '', $this->notified_version( 'core', 'core' ) );

		remove_all_filters( 'sc_wpun_notification' );
		add_filter( 'sc_wpun_notification', '__return_false' );
		$this->setExpectedIncorrectUsage( 'sc_wpun_notification' );
		$this->save_settings();
		$this->run_check();
		$this->assertCount( 2, sc_wpun()->update_checker()->last_notification()->get_items() );
	}

	/**
	 * The before and after actions fire in order, the after one with the notification and the results.
	 */
	public function test_before_and_after_update_check() {
		$this->save_settings();
		$this->set_updates( '99.0.0' );
		$log = array();
		add_action(
			'sc_wpun_before_update_check',
			static function () use ( &$log ) {
				$log[] = 'before';
			}
		);
		add_filter(
			'sc_wpun_update_items',
			static function ( $items ) use ( &$log ) {
				$log[] = 'items';
				return $items;
			}
		);
		add_action(
			'sc_wpun_after_update_check',
			static function ( $notification, $results ) use ( &$log ) {
				$log[] = array( get_class( $notification ), array_keys( $results ), $results['email']->is_success() );
			},
			10,
			2
		);

		$this->run_check();

		$this->assertSame( array( 'before', 'items', array( Notification::class, array( 'email' ), true ) ), $log );
	}

	/**
	 * The after action fires with no results when nothing was sent.
	 */
	public function test_after_update_check_without_results() {
		$this->save_settings();
		$this->set_updates();
		$results = null;
		add_action(
			'sc_wpun_after_update_check',
			static function ( $notification, $given ) use ( &$results ) {
				$results = $given;
			},
			10,
			2
		);

		$this->run_check();

		$this->assertSame( array(), $results );
	}

	/**
	 * The plain text filter changes the message.
	 */
	public function test_message_text() {
		add_filter(
			'sc_wpun_message_text',
			static function ( $text, $notification ) {
				return $text . "\nVia " . $notification->get_site_url();
			},
			10,
			2
		);

		$this->assertStringEndsWith( "\nVia http://example.org", Notification_Renderer::plain_text( $this->notification() ) );
	}

	/**
	 * A short-circuit success skips wp_mail and counts as delivered.
	 */
	public function test_pre_send_success() {
		$this->save_settings();
		$this->set_updates( '99.0.0' );
		$sent = 0;
		add_filter(
			'sc_wpun_pre_send',
			static function ( $pre, $notification, $instance ) {
				return 'email' === $instance->get_id() ? Delivery_Result::success( 'stubbed' ) : $pre;
			},
			10,
			3
		);
		add_action(
			'sc_wpun_notification_sent',
			static function () use ( &$sent ) {
				++$sent;
			}
		);

		$this->run_check();

		$this->assertSame( 0, $this->sent_mail_count() );
		$this->assertSame( 1, $sent );
		$this->assertSame( '99.0.0', $this->notified_version( 'core', 'core' ) );
		$this->assertSame( 'stubbed', sc_wpun()->update_checker()->last_results()['email']->get_message() );
	}

	/**
	 * A short-circuit failure is retried on the next run.
	 */
	public function test_pre_send_failure_is_retried() {
		$this->save_settings();
		$this->set_updates( '99.0.0' );
		add_filter(
			'sc_wpun_pre_send',
			static function () {
				return Delivery_Result::failure( 'Held back.', 'held' );
			}
		);

		$this->run_check();
		$this->assertSame( '', $this->notified_version( 'core', 'core' ) );

		remove_all_filters( 'sc_wpun_pre_send' );
		$this->run_check();
		$this->assertSame( 1, $this->sent_mail_count() );
	}

	/**
	 * A callback that throws becomes the usual exception failure.
	 */
	public function test_pre_send_that_throws() {
		$this->save_settings();
		$this->set_updates( '99.0.0' );
		add_filter(
			'sc_wpun_pre_send',
			static function () {
				throw new RuntimeException( 'nope' );
			}
		);

		$this->capture_notices( array( $this, 'run_check' ) );

		$this->assertSame( 'exception', sc_wpun()->update_checker()->last_results()['email']->get_code() );
		$this->assertSame( 0, $this->sent_mail_count() );
	}

	/**
	 * Sent and failed fire for success, a wp_mail failure, an unavailable channel and a disabled instance.
	 */
	public function test_sent_and_failed_events() {
		$events     = array();
		$registry   = new Channel_Registry();
		$dispatcher = new Dispatcher( $registry );
		$email      = sc_wpun()->channels()->get( 'email' );
				$this->save_settings();
		$good = new Channel_Instance( 'good', 'email', 'Good', true, array( 'to' => 'a@example.org' ) );
		$bad  = new Channel_Instance( 'bad', 'email', 'Bad', true, array( 'to' => 'b@example.org' ) );
		$none = new Channel_Instance( 'none', 'chat', 'None', true );
		$off  = new Channel_Instance( 'off', 'email', 'Off', false, array( 'to' => 'c@example.org' ) );
		foreach ( array( 'sent', 'failed' ) as $kind ) {
			add_action(
				'sc_wpun_notification_' . $kind,
				static function ( $result, $notification, $instance ) use ( &$events, $kind ) {
					$events[] = array( $kind, $instance->get_id(), $result->get_code() );
				},
				10,
				3
			);
		}
		add_filter(
			'wp_mail',
			static function ( $args ) {
				if ( 'b@example.org' === $args['to'] || array( 'b@example.org' ) === $args['to'] ) {
					$args['to'] = 'not-an-address';
				}
				return $args;
			}
		);

		$dispatcher->dispatch( $this->notification(), array( $good, $bad, $none ) );
		$dispatcher->send_one( $this->notification(), $off );

		$this->assertSame(
			array(
				array( 'sent', 'good', 'success' ),
				array( 'failed', 'bad', 'wp_mail_failed' ),
				array( 'failed', 'none', 'unavailable' ),
				array( 'failed', 'off', 'disabled' ),
			),
			$events
		);
		$this->assertNotNull( $email );
	}

	/**
	 * A listener that throws does not stop delivery to the next instance.
	 */
	public function test_a_throwing_listener_does_not_stop_the_others() {
		$registry   = new Channel_Registry();
		$dispatcher = new Dispatcher( $registry );
				add_action(
					'sc_wpun_notification_sent',
					static function () {
						throw new RuntimeException( 'listener' );
					}
				);
		$instances = array(
			new Channel_Instance( 'a', 'email', 'A', true, array( 'to' => 'a@example.org' ) ),
			new Channel_Instance( 'b', 'email', 'B', true, array( 'to' => 'b@example.org' ) ),
		);

		$results = $this->capture_notices( fn() => $dispatcher->dispatch( $this->notification(), $instances ) );

		$this->assertTrue( $results['a']->is_success() );
		$this->assertTrue( $results['b']->is_success() );
		$this->assertSame( 2, $this->sent_mail_count() );
		$this->assertCount( 2, $this->notices );
	}

	/**
	 * The after-dispatch action fires from dispatch() and from an update check that sent something, but not from one that did not.
	 */
	public function test_after_dispatch() {
		$calls = array();
		add_action(
			'sc_wpun_after_dispatch',
			static function ( $results, $notification ) use ( &$calls ) {
				$calls[] = array( array_keys( $results ), get_class( $notification ) );
			},
			10,
			2
		);
		$registry   = new Channel_Registry();
		$dispatcher = new Dispatcher( $registry );
				$dispatcher->dispatch( $this->notification(), array( new Channel_Instance( 'a', 'email', 'A', true, array( 'to' => 'a@example.org' ) ) ) );
		$this->assertSame( array( array( array( 'a' ), Notification::class ) ), $calls );

		$calls = array();
		$this->save_settings();
		$this->set_updates();
		$this->run_check();
		$this->assertSame( array(), $calls, 'Nothing was sent.' );

		$this->set_updates( '99.0.0' );
		$this->run_check();
		$this->assertSame( array( array( array( 'email' ), Notification::class ) ), $calls );
	}

	/**
	 * The payload filters change the recipients and subject; wrong types fall back and header lines with line breaks are dropped.
	 */
	public function test_channel_payload() {
		$this->save_settings();
		$this->set_updates( '99.0.0' );
		add_filter(
			'sc_wpun_channel_payload',
			static function ( $payload ) {
				$payload['to'][]      = 'copy@example.org';
				$payload['headers'][] = 'X-Good: yes';
				$payload['headers'][] = "X-Bad: no\r\nBcc: evil@example.org";
				return $payload;
			}
		);
		add_filter(
			'sc_wpun_channel_payload_email',
			static function ( $payload ) {
				$payload['subject'] = 'Changed subject';
				$payload['message'] = array( 'wrong type' );
				return $payload;
			}
		);

		$this->run_check();

		$mail = $this->sent_mail();
		$this->assertSame( 'Changed subject', $mail->subject );
		$this->assertSame( 'copy@example.org', tests_retrieve_phpmailer_instance()->get_recipient( 'to', 0, 1 )->address );
		$this->assertStringContainsString( 'There are updates available', $mail->body, 'A wrong-typed message falls back.' );
		$this->assertStringContainsString( 'X-Good: yes', $mail->header );
		$this->assertStringNotContainsString( 'evil@example.org', $mail->header );
	}
}
