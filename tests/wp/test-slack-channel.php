<?php
/**
 * The Slack channel: registration, settings, the host allowlist, the request and the results.
 *
 * @package wp-updates-notifier
 */

use WPUN\Channel_Registry;
use WPUN\Modules\Slack\Slack_Channel;

/**
 * Slack_Channel.
 */
class Test_Slack_Channel extends WPUN_Slack_Test_Case {

	// Registration.

	/**
	 * The module registers Slack on the action of the registry, and the plugin accepts it.
	 */
	public function test_slack_is_registered_by_the_module() {
		// Other tests remove every listener of the action, so start from the module alone, as the plugin loads it.
		remove_all_actions( 'sc_wpun_register_channels' );
		require WP_PLUGIN_DIR . '/wp-updates-notifier/modules/slack/slack.php';
		$registry = new Channel_Registry();

		$this->assertTrue( $registry->has( 'slack' ) );
		$this->assertInstanceOf( Slack_Channel::class, $registry->get( 'slack' ) );
		$this->assertArrayNotHasKey( 'slack', $registry->get_rejected() );
	}

	/**
	 * The type is the one the 1.x migration stores, so migrated instances find their channel.
	 */
	public function test_the_type_label_and_features() {
		$channel = $this->channel();

		$this->assertSame( 'slack', $channel->get_type() );
		$this->assertSame( 'Slack', $channel->get_label() );
		$this->assertNotSame( '', $channel->get_description() );
		$this->assertTrue( $channel->supports( 'test_message' ) );
		$this->assertFalse( $channel->supports( 'something_else' ) );
	}

	// Settings.

	/**
	 * The address is a secret https url and sits outside the group; the other settings are in a collapsed one.
	 */
	public function test_the_fields() {
		$channel = $this->channel();
		$fields  = $channel->get_fields();

		$this->assertSame( array( 'webhook_url', 'channel', 'username', 'icon' ), array_keys( $fields ) );
		$this->assertSame( 'url', $fields['webhook_url']['type'] );
		$this->assertTrue( $fields['webhook_url']['required'] );
		$this->assertTrue( $fields['webhook_url']['secret'] );
		$this->assertTrue( $fields['webhook_url']['https_only'] );
		$this->assertArrayNotHasKey( 'group', $fields['webhook_url'] );
		foreach ( array( 'channel', 'username', 'icon' ) as $name ) {
			$this->assertSame( 'legacy', $fields[ $name ]['group'], $name );
		}

		$groups = $channel->get_field_groups();
		$this->assertTrue( $groups['legacy']['collapsed'] );
		$this->assertStringContainsString( 'Only legacy webhooks honour these.', $groups['legacy']['description'] );
	}

	/**
	 * Saving: a good address is kept, with the optional settings.
	 */
	public function test_valid_settings_are_accepted() {
		$clean = $this->channel()->validate_settings(
			array(
				'webhook_url' => self::URL,
				'channel'     => '#ops',
				'username'    => 'Updates',
				'icon'        => ':robot_face:',
			)
		);

		$this->assertIsArray( $clean );
		$this->assertSame( self::URL, $clean['webhook_url'] );
		$this->assertSame( '#ops', $clean['channel'] );
		$this->assertSame( 'Updates', $clean['username'] );
		$this->assertSame( ':robot_face:', $clean['icon'] );
	}

	/**
	 * The address must be https.
	 */
	public function test_an_http_address_is_refused() {
		$result = $this->channel()->validate_settings( array( 'webhook_url' => 'http://hooks.slack.com/services/T000/B000/XXXX' ) );

		$this->assertWPError( $result );
		$this->assertSame( array( 'field' => 'webhook_url' ), $result->get_error_data() );
		$this->assertStringNotContainsString( 'XXXX', $result->get_error_message() );
	}

	/**
	 * Only hosts of the allowlist are accepted, and the message does not repeat the address.
	 */
	public function test_another_host_is_refused() {
		foreach ( array( 'https://example.org/services/T000/B000/XXXX', 'https://hooks.slack.com.evil.example/services/T/B/XXXX', 'https://hooks.slack.com@evil.example/XXXX', 'https://evil.example/#@hooks.slack.com' ) as $url ) {
			$result = $this->channel()->validate_settings( array( 'webhook_url' => $url ) );

			$this->assertWPError( $result, $url );
			$this->assertSame( array( 'field' => 'webhook_url' ), $result->get_error_data(), $url );
			$this->assertStringContainsString( 'hooks.slack.com', $result->get_error_message() );
			$this->assertStringNotContainsString( 'XXXX', $result->get_error_message() );
			$this->assertStringNotContainsString( 'evil', $result->get_error_message() );
		}
	}

	/**
	 * GovSlack is allowed by default, in any case.
	 */
	public function test_the_govslack_host_is_accepted() {
		foreach ( array( 'https://hooks.slack-gov.com/services/T000/B000/X', 'https://HOOKS.Slack.com/services/T000/B000/X', 'https://hooks.slack.com:443/services/T000/B000/X' ) as $url ) {
			$this->assertIsArray( $this->channel()->validate_settings( array( 'webhook_url' => $url ) ), $url );
		}
	}

	/**
	 * A site can add a host with the filter, and the default hosts stay unless it removes them.
	 */
	public function test_the_filter_adds_a_host() {
		$url = 'https://slack-proxy.example.org/services/T000/B000/X';
		$this->assertWPError( $this->channel()->validate_settings( array( 'webhook_url' => $url ) ) );

		add_filter(
			'sc_wpun_slack_allowed_hosts',
			static function ( $hosts ) {
				$hosts[] = 'Slack-Proxy.Example.org';
				return $hosts;
			}
		);

		$this->assertIsArray( $this->channel()->validate_settings( array( 'webhook_url' => $url ) ) );
		$this->assertSame( array( 'hooks.slack.com', 'hooks.slack-gov.com', 'slack-proxy.example.org' ), Slack_Channel::allowed_hosts() );
	}

	/**
	 * A filter that returns something else than a list of names leaves no host allowed, instead of failing.
	 */
	public function test_a_broken_filter_allows_nothing() {
		add_filter( 'sc_wpun_slack_allowed_hosts', '__return_false' );

		$this->assertSame( array(), Slack_Channel::allowed_hosts() );
		$this->assertWPError( $this->channel()->validate_settings( array( 'webhook_url' => self::URL ) ) );
	}

	/**
	 * The icon is empty, an emoji or an https address.
	 */
	public function test_icons() {
		foreach ( array( '', ':robot_face:', ':+1:', ':thumbsup::skin-tone-3:', 'https://example.org/icon.png' ) as $icon ) {
			$this->assertIsArray(
				$this->channel()->validate_settings(
					array(
						'webhook_url' => self::URL,
						'icon'        => $icon,
					)
				),
				"'$icon'"
			); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		}

		foreach ( array( 'robot_face', ':robot face:', 'http://example.org/icon.png', 'ftp://example.org/icon.png', 'javascript:alert(1)', '::', ':emoji' ) as $icon ) {
			$result = $this->channel()->validate_settings(
				array(
					'webhook_url' => self::URL,
					'icon'        => $icon,
				)
			); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

			$this->assertWPError( $result, $icon );
			$this->assertSame( array( 'field' => 'icon' ), $result->get_error_data(), $icon );
		}
	}

	// Sending.

	/**
	 * The request: a JSON POST to the webhook with a timeout of 10 seconds, made with the safe function.
	 */
	public function test_the_request() {
		$this->slack_answers( 200 );

		$result = $this->channel()->send( $this->notification(), $this->instance() );

		$this->assertTrue( $result->is_success() );
		$this->assertNotSame( '', $result->get_message() );
		$request = $this->last_request();
		$this->assertSame( self::URL, $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 10, $request['args']['timeout'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		$this->assertTrue( $request['args']['reject_unsafe_urls'], 'wp_safe_remote_post().' );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame( $this->sent_payload(), json_decode( $request['args']['body'], true ) );
		$this->assertArrayHasKey( 'blocks', $this->sent_payload() );
	}

	/**
	 * GovSlack is a webhook like any other.
	 */
	public function test_a_govslack_webhook_delivers() {
		$this->slack_answers( 200, 'ok', array(), 'hooks.slack-gov.com' );

		$result = $this->channel()->send( $this->notification(), $this->instance( array( 'webhook_url' => 'https://hooks.slack-gov.com/services/T000/B000/X' ) ) );

		$this->assertTrue( $result->is_success() );
		$this->assertCount( 1, $this->requests_to( 'hooks.slack-gov.com' ) );
	}

	/**
	 * The channel, name and icon are sent only when they are set.
	 */
	public function test_the_identity_is_sent_only_when_set() {
		$this->slack_answers( 200 );

		$this->channel()->send( $this->notification(), $this->instance() );
		$plain = $this->sent_payload();
		foreach ( array( 'channel', 'username', 'icon_emoji', 'icon_url', 'icon' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $plain, $key );
		}

		$this->channel()->send(
			$this->notification(),
			$this->instance(
				array(
					'channel'  => ' #ops ',
					'username' => 'Updates',
					'icon'     => ':robot_face:',
				)
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$emoji = $this->sent_payload();
		$this->assertSame( '#ops', $emoji['channel'] );
		$this->assertSame( 'Updates', $emoji['username'] );
		$this->assertSame( ':robot_face:', $emoji['icon_emoji'] );
		$this->assertArrayNotHasKey( 'icon_url', $emoji );

		$this->channel()->send( $this->notification(), $this->instance( array( 'icon' => 'https://example.org/icon.png' ) ) );
		$url = $this->sent_payload();
		$this->assertSame( 'https://example.org/icon.png', $url['icon_url'] );
		$this->assertArrayNotHasKey( 'icon_emoji', $url );
		$this->assertArrayNotHasKey( 'channel', $url );
		$this->assertArrayNotHasKey( 'username', $url );

		// A stored icon that is not valid (it never went through the form) is left out rather than sent.
		$this->channel()->send( $this->notification(), $this->instance( array( 'icon' => 'robot_face' ) ) );
		$this->assertArrayNotHasKey( 'icon_emoji', $this->sent_payload() );
		$this->assertArrayNotHasKey( 'icon_url', $this->sent_payload() );
	}

	/**
	 * The filters of every channel apply: the general one and the one of the type.
	 */
	public function test_the_payload_filters_are_applied() {
		$this->slack_answers( 200 );
		add_filter(
			'sc_wpun_channel_payload_slack',
			static function ( $payload ) {
				$payload['username'] = 'Filtered';
				return $payload;
			}
		);
		add_filter(
			'sc_wpun_channel_payload',
			static function ( $payload ) {
				$payload['thread_ts'] = '123.456';
				return $payload;
			}
		);

		$this->channel()->send( $this->notification(), $this->instance( array( 'username' => 'Stored' ) ) );

		$this->assertSame( 'Filtered', $this->sent_payload()['username'] );
		$this->assertSame( '123.456', $this->sent_payload()['thread_ts'] );
	}

	/**
	 * A filter that returns something that is not a message makes a failure, not a fatal error, and nothing is sent.
	 */
	public function test_a_filter_that_breaks_the_payload_does_not_fatal() {
		foreach ( array( 'garbage', null, false, array(), array( 'blocks' => array() ), array( 'text' => '' ) ) as $garbage ) {
			$filter = static function () use ( $garbage ) {
				return $garbage;
			};
			add_filter( 'sc_wpun_channel_payload_slack', $filter );

			$result = $this->channel()->send( $this->notification(), $this->instance() );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'bad_payload', $result->get_code() );
			$this->assert_result_hides( $result );
			remove_filter( 'sc_wpun_channel_payload_slack', $filter );
		}
		$this->assertSame( array(), $this->http_requests );
	}

	/**
	 * A filter that keeps only `text` is still a message.
	 */
	public function test_a_payload_with_only_text_is_sent() {
		$this->slack_answers( 200 );
		add_filter(
			'sc_wpun_channel_payload_slack',
			static function () {
				return array( 'text' => 'Hello' );
			}
		);

		$this->assertTrue( $this->channel()->send( $this->notification(), $this->instance() )->is_success() );
		$this->assertSame( array( 'text' => 'Hello' ), $this->sent_payload() );
	}

	/**
	 * An address that never went through the form (from 1.x, a constant) is checked before the request: no request is made.
	 */
	public function test_an_address_that_is_not_allowed_is_not_requested() {
		$bad = array(
			'http://hooks.slack.com/services/T000/B000/XXXX',
			'https://example.org/services/T000/B000/XXXX',
			'https://hooks.slack.com.evil.example/services/T000/B000/XXXX',
			'ftp://hooks.slack.com/services/T000/B000/XXXX',
			'javascript:alert(1)//XXXX',
			'XXXX',
		);
		foreach ( $bad as $url ) {
			$result = $this->channel()->send( $this->notification(), $this->instance( array( 'webhook_url' => $url ) ) );

			$this->assertFalse( $result->is_success(), $url );
			$this->assertSame( 'invalid_url', $result->get_code(), $url );
			$this->assertStringNotContainsString( 'XXXX', $result->get_message() );
			$this->assertStringNotContainsString( 'evil', $result->get_message() );
		}
		$this->assertSame( array(), $this->http_requests );
	}

	/**
	 * Without an address there is nothing to post to.
	 */
	public function test_a_missing_address_is_a_failure() {
		$result = $this->channel()->send( $this->notification(), $this->instance( array( 'webhook_url' => '' ) ) );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'not_configured', $result->get_code() );
		$this->assertSame( array(), $this->http_requests );
	}

	// What Slack answers.

	/**
	 * Slack's error words become a code and a message to act on, with the HTTP status.
	 */
	public function test_the_error_words_of_slack() {
		$cases = array(
			array( 400, 'invalid_payload' ),
			array( 403, 'action_prohibited' ),
			array( 403, 'posting_to_general_channel_denied' ),
			array( 404, 'channel_not_found' ),
			array( 404, 'user_not_found' ),
			array( 410, 'channel_is_archived' ),
			array( 404, 'no_service' ),
			array( 400, 'no_service_id' ),
			array( 403, 'no_active_hooks' ),
			array( 404, 'no_team' ),
			array( 403, 'team_disabled' ),
			array( 403, 'invalid_token' ),
			array( 400, 'too_many_attachments' ),
			array( 400, 'no_text' ),
		);
		foreach ( $cases as list( $status, $word ) ) {
			$this->reset_http();
			$this->slack_answers( $status, $word . "\n" );

			$result = $this->channel()->send( $this->notification(), $this->instance() );

			$this->assertFalse( $result->is_success(), $word );
			$this->assertSame( $word, $result->get_code(), $word );
			$this->assertSame( $status, $result->get_status(), $word );
			$this->assertSame( $word, $result->get_context()['error_code'] ?? null, $word );
			$this->assertNull( $result->get_retry_after(), $word );
			$this->assertGreaterThan( 20, strlen( $result->get_message() ), $word );
			$this->assertStringContainsString( $word, $result->get_message(), $word );
			$this->assert_result_hides( $result );
		}
	}

	/**
	 * A revoked webhook says so.
	 */
	public function test_a_revoked_webhook_is_explained() {
		$this->slack_answers( 404, 'no_service' );

		$result = $this->channel()->send( $this->notification(), $this->instance() );

		$this->assertStringContainsString( 'revoked', $result->get_message() );
	}

	/**
	 * Rate limiting passes on the wait of the Retry-After header.
	 */
	public function test_rate_limited_with_retry_after() {
		$this->slack_answers( 429, 'rate_limited', array( 'retry-after' => '30' ) );

		$result = $this->channel()->send( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'rate_limited', $result->get_code() );
		$this->assertSame( 429, $result->get_status() );
		$this->assertSame( 30, $result->get_retry_after() );
		$this->assert_result_hides( $result );
	}

	/**
	 * Rate limiting without the header has no wait, and nothing is slept or retried.
	 */
	public function test_rate_limited_without_retry_after() {
		$this->slack_answers( 429, 'rate_limited' );

		$result = $this->channel()->send( $this->notification(), $this->instance() );

		$this->assertSame( 'rate_limited', $result->get_code() );
		$this->assertSame( 429, $result->get_status() );
		$this->assertNull( $result->get_retry_after() );
		$this->assertCount( 1, $this->http_requests, 'Not retried.' );
	}

	/**
	 * An answer that is not a known word, such as an HTML error page, gives the status and nothing of the body.
	 */
	public function test_other_answers_are_a_bad_status() {
		$page = '<html><body><h1>Oops</h1> hooks.slack.com/services/T000/B000/XXXX </body></html>';
		foreach ( array( array( 500, $page ), array( 502, '' ), array( 500, 'Some Words' ), array( 503, "line\none" ) ) as list( $status, $body ) ) {
			$this->reset_http();
			$this->slack_answers( $status, $body );

			$result = $this->channel()->send( $this->notification(), $this->instance() );

			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'bad_status', $result->get_code() );
			$this->assertSame( $status, $result->get_status() );
			$this->assertStringContainsString( 'HTTP ' . $status, $result->get_message() );
			$this->assertStringNotContainsString( 'Oops', $result->get_message() );
			$this->assert_result_hides( $result );
		}
	}

	/**
	 * A word Slack added since is not known, so it gets the generic message of any other status, and is not repeated.
	 */
	public function test_an_unknown_word_gets_the_generic_message() {
		$this->slack_answers( 400, 'invalid_blocks' );

		$result = $this->channel()->send( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'bad_status', $result->get_code() );
		$this->assertSame( 400, $result->get_status() );
		$this->assertStringContainsString( 'HTTP 400', $result->get_message() );
		$this->assertStringNotContainsString( 'invalid_blocks', $result->get_message() );
		$this->assertArrayNotHasKey( 'error_code', $result->get_context() );
	}

	/**
	 * A request that fails: the error of the HTTP API can hold the address, so none of it is used.
	 */
	public function test_a_wp_error_does_not_leak_the_address() {
		$this->slack_answers( new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out for ' . self::URL ) );

		$result = $this->channel()->send( $this->notification(), $this->instance() );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'http_error', $result->get_code() );
		$this->assertNull( $result->get_status() );
		$this->assertSame( array( 'error_code' => 'http_request_failed' ), $result->get_context() );
		$this->assertStringNotContainsString( 'cURL', $result->get_message() );
		$this->assert_result_hides( $result );
	}

	/**
	 * Whatever happens, no result has the address or its token.
	 */
	public function test_no_result_has_the_address() {
		$answers = array( 200, 400, 401, 403, 404, 410, 429, 500, 502 );
		foreach ( $answers as $status ) {
			$this->reset_http();
			// Slack would not echo the address, but a proxy in between might.
			$this->slack_answers( $status, self::URL );

			$this->assert_result_hides( $this->channel()->send( $this->notification(), $this->instance() ) );
		}

		$this->reset_http();
		$this->slack_answers( new WP_Error( self::URL, self::URL ) );
		$this->assert_result_hides( $this->channel()->send( $this->notification(), $this->instance() ) );
	}

	/**
	 * After failed deliveries the address is nowhere: not in the stored state, the results, the Channels tab, the Site Health
	 * tests or the site information, whether the answer repeated it or the HTTP API put it in its error.
	 */
	public function test_the_address_reaches_no_surface() {
		sc_wpun_channels()->save( $this->instance() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_updates( '99.0.0' );
		$seen = '';

		foreach ( array( 500, new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out for ' . self::URL ) ) as $answer ) {
			$this->reset_http();
			sc_wpun()->network_context()->delete_option( 'sc_wpun_state' );
			$this->slack_answers( $answer, self::URL );

			sc_wpun()->update_checker()->do_update_check();

			$results = array();
			foreach ( sc_wpun()->update_checker()->last_results() as $id => $result ) {
				$results[ $id ] = array( $result->get_message(), $result->get_code(), $result->get_context() );
			}
			$this->assertFalse( sc_wpun()->update_checker()->last_results()['slack']->is_success() );

			ob_start();
			sc_wpun()->channels_tab()->render();
			$tab = ob_get_clean();

			$health = array();
			$tests  = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
			foreach ( $tests['direct'] as $name => $test ) {
				$health[ $name ] = call_user_func( $test['test'] );
			}

			$seen .= wp_json_encode( $results )
				. wp_json_encode( sc_wpun()->network_context()->get_option( 'sc_wpun_state' ) )
				. wp_json_encode( $health )
				. wp_json_encode( apply_filters( 'debug_information', array() ) )
				. $tab;
		}

		$this->assertStringContainsString( 'Slack', $seen, 'The surfaces were collected.' );
		foreach ( array( self::URL, 'XXXX', '/services/', 'T000' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $seen, $needle );
		}
	}

	// The test message.

	/**
	 * A test message is a short message without items, and the channel sends it.
	 */
	public function test_a_test_message() {
		$this->slack_answers( 200 );

		$result = $this->channel()->send( $this->notification_of( array(), array( 'is_test' => true ) ), $this->instance() );

		$this->assertTrue( $result->is_success() );
		$payload = $this->sent_payload();
		$this->assertSame( 'Test message from WP Updates Notifier', $payload['blocks'][0]['text']['text'] );
		$this->assertStringContainsString( 'test message', $payload['blocks'][1]['text']['text'] );
	}
}
