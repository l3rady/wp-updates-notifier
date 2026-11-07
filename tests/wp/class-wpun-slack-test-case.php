<?php
/**
 * Helpers of the Slack tests: a webhook, instances, notifications and the answers of Slack.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Instance;
use WPUN\Notification;
use WPUN\Notification_Item;
use WPUN\Modules\Slack\Slack_Channel;

/**
 * Base class of the Slack tests.
 */
abstract class WPUN_Slack_Test_Case extends WPUN_Test_Case {

	/**
	 * The secret address of the webhook.
	 */
	const URL = 'https://hooks.slack.com/services/T000/B000/XXXX';

	/**
	 * Start with the Slack channel in the plugin's registry, which tests of the registry may have left out.
	 */
	public function set_up() {
		parent::set_up();

		$this->ensure_registered();
	}

	/**
	 * Leave the registry with the Slack channel, which a test may have removed.
	 */
	public function tear_down() {
		$this->ensure_registered();

		parent::tear_down();
	}

	/**
	 * Register the Slack channel in the plugin's registry unless it is there.
	 */
	private function ensure_registered() {
		if ( ! sc_wpun()->channels()->has( 'slack' ) ) {
			sc_wpun()->channels()->register( new Slack_Channel() );
		}
	}

	/**
	 * The channel under test. The real registry has one too; a plain instance does for calling send().
	 *
	 * @return Slack_Channel
	 */
	protected function channel() {
		return new Slack_Channel();
	}

	/**
	 * An instance of the channel, as it would be stored.
	 *
	 * @param array $settings Settings to add to, or replace in, the webhook address.
	 * @return Channel_Instance
	 */
	protected function instance( array $settings = array() ) {
		return new Channel_Instance( 'slack', 'slack', 'Slack', true, array_merge( array( 'webhook_url' => self::URL ), $settings ) );
	}

	/**
	 * A notification of plugin updates.
	 *
	 * @param int    $count   Number of plugins.
	 * @param string $prefix  Start of the name of each.
	 * @param array  $context Context of the notification: is_test, is_reminder.
	 * @param string $site    Site name.
	 * @return Notification
	 */
	protected function notification( $count = 2, $prefix = 'Plugin ', array $context = array(), $site = 'My Site' ) {
		$items = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$items[] = new Notification_Item(
				array(
					'type'            => 'plugin',
					'key'             => 'plugin-' . $i . '/plugin-' . $i . '.php',
					'name'            => $prefix . $i,
					'current_version' => '1.0',
					'new_version'     => '2.0',
				)
			);
		}

		return $this->notification_of( $items, $context, $site );
	}

	/**
	 * A notification of items.
	 *
	 * @param Notification_Item[] $items   Items.
	 * @param array               $context Context of the notification: is_test, is_reminder.
	 * @param string              $site    Site name.
	 * @return Notification
	 */
	protected function notification_of( array $items, array $context = array(), $site = 'My Site' ) {
		return new Notification( $site, 'https://example.org', 'https://example.org/wp-admin/update-core.php', $items, $context );
	}

	/**
	 * Fake the answer of Slack.
	 *
	 * @param int|WP_Error $response Status code, or an error.
	 * @param string       $body     Body of the answer.
	 * @param array        $headers  Headers of the answer.
	 * @param string       $host     Host of the webhook.
	 */
	protected function slack_answers( $response, $body = 'ok', array $headers = array(), $host = 'hooks.slack.com' ) {
		if ( is_int( $response ) ) {
			$response = array(
				'headers'  => $headers,
				'body'     => $body,
				'response' => array(
					'code'    => $response,
					'message' => '',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		$this->fake_http( $host, $response );
	}

	/**
	 * The request made to the webhook, the last one.
	 *
	 * @return array [ 'url' => string, 'args' => array ].
	 */
	protected function last_request() {
		$this->assertNotEmpty( $this->http_requests, 'A request was made.' );

		return $this->http_requests[ count( $this->http_requests ) - 1 ];
	}

	/**
	 * The payload of the last request, decoded.
	 *
	 * @return array
	 */
	protected function sent_payload() {
		$payload = json_decode( $this->last_request()['args']['body'], true );
		$this->assertIsArray( $payload );

		return $payload;
	}

	/**
	 * Assert that nothing a person could read in a result gives away the webhook.
	 *
	 * @param WPUN\Delivery_Result $result The result.
	 * @param string               $url    The address that must not appear.
	 */
	protected function assert_result_hides( $result, $url = self::URL ) {
		$all = $result->get_message() . ' ' . $result->get_code() . ' ' . wp_json_encode( $result->get_context() );
		foreach ( array( $url, 'XXXX', '/services/', 'T000' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $all );
		}
	}
}
