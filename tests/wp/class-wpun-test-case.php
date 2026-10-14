<?php
/**
 * Base test case: no real network, no real mail, helpers for update data.
 *
 * @package wp-updates-notifier
 */

/**
 * Base class for the plugin's WordPress tests.
 *
 * Loaded by tests/bootstrap.php after WordPress's test library, so that
 * WP_UnitTestCase exists.
 */
abstract class WPUN_Test_Case extends WP_UnitTestCase {

	/**
	 * Every HTTP request attempted during the test: array of [ 'url' => string, 'args' => array ].
	 *
	 * @var array
	 */
	protected $http_requests = array();

	/**
	 * Fake responses: array of [ 'pattern' => string, 'response' => array|WP_Error ].
	 *
	 * @var array
	 */
	private $http_fakes = array();

	/**
	 * Settings version and key set of the current release, used as the base for fixtures.
	 */
	const SETTINGS_VERSION = '8.1';

	/**
	 * Set up: block the network and reset the mailer.
	 */
	public function set_up() {
		parent::set_up();

		$this->http_requests = array();
		$this->http_fakes    = array();
		add_filter( 'pre_http_request', array( $this, 'handle_http' ), 1, 3 );
		reset_phpmailer_instance();
	}

	/**
	 * Fake any HTTP request whose URL contains $pattern. The first matching fake wins.
	 *
	 * @param string         $pattern  Substring of the URL.
	 * @param array|WP_Error $response Response (see fake_response()) or error.
	 */
	protected function fake_http( $pattern, $response ) {
		$this->http_fakes[] = array(
			'pattern'  => $pattern,
			'response' => $response,
		);
	}

	/**
	 * Build an HTTP API response array.
	 *
	 * @param int    $code Status code.
	 * @param string $body Body.
	 * @return array
	 */
	protected function fake_response( $code = 200, $body = '' ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Network guard: fake what was asked for, fail the test on anything else.
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value.
	 * @param array                $args Request args.
	 * @param string               $url  URL.
	 * @return array|WP_Error
	 */
	public function handle_http( $pre, $args, $url ) {
		$this->http_requests[] = array(
			'url'  => $url,
			'args' => $args,
		);

		foreach ( $this->http_fakes as $fake ) {
			if ( false !== strpos( $url, $fake['pattern'] ) ) {
				return $fake['response'];
			}
		}

		$this->fail( 'Unexpected HTTP request to ' . $url );
	}

	/**
	 * HTTP requests made so far whose URL contains $pattern.
	 *
	 * @param string $pattern Substring of the URL.
	 * @return array
	 */
	protected function requests_to( $pattern ) {
		return array_values(
			array_filter(
				$this->http_requests,
				static function ( $request ) use ( $pattern ) {
					return false !== strpos( $request['url'], $pattern );
				}
			)
		);
	}

	/**
	 * Settings as the current release stores them, with overrides.
	 *
	 * @param array $overrides Values to override.
	 * @return array
	 */
	protected function settings( array $overrides = array() ) {
		$defaults = array(
			'frequency'              => 'hourly',
			'email_notifications'    => 1,
			'notify_to'              => 'ops@example.org',
			'notify_from'            => 'notifier@example.org',
			'slack_notifications'    => 0,
			'slack_webhook_url'      => '',
			'slack_channel_override' => '',
			'notify_plugins'         => 1,
			'notify_themes'          => 1,
			'notify_automatic'       => 1,
			'hide_updates'           => 1,
		);

		return array_merge( $defaults, $overrides );
	}

	/**
	 * Store settings as the current release would have.
	 *
	 * @param array $overrides Values to override.
	 */
	protected function save_settings( array $overrides = array() ) {
		update_option( 'sc_wpun_settings', $this->settings( $overrides ) );
		update_option( 'sc_wpun_settings_ver', self::SETTINGS_VERSION );
	}

	/**
	 * Store the run state (what was notified, when the last check ran).
	 *
	 * @param array     $notified        Notified versions: [ 'core' => , 'plugin' => [], 'theme' => [] ].
	 * @param int|false $last_check_time Time of the last check.
	 */
	protected function save_state( array $notified = array(), $last_check_time = false ) {
		update_option(
			'sc_wpun_state',
			array(
				'notified'        => array_merge(
					array(
						'core'   => '',
						'plugin' => array(),
						'theme'  => array(),
					),
					$notified
				),
				'last_check_time' => $last_check_time,
			),
			false
		);
	}

	/**
	 * Read the stored run state.
	 *
	 * @return array
	 */
	protected function stored_state() {
		return (array) get_option( 'sc_wpun_state' );
	}

	/**
	 * Read the stored settings.
	 *
	 * @return array
	 */
	protected function stored_settings() {
		return (array) get_option( 'sc_wpun_settings' );
	}

	/**
	 * Make every update check read fresh data from the transients instead of calling api.wordpress.org.
	 *
	 * Core skips its request when `last_checked` is recent and the installed set has not changed.
	 *
	 * @param string|null $core_version Version offered by core, or null for none.
	 * @param array       $plugins      Plugin updates: file => [ 'new_version' => , 'slug' => , 'url' => ].
	 * @param array       $themes       Theme updates: stylesheet => [ 'new_version' => , 'url' => ].
	 */
	protected function set_updates( $core_version = null, array $plugins = array(), array $themes = array() ) {
		$core                  = new stdClass();
		$core->last_checked    = time();
		$core->version_checked = wp_get_wp_version();
		$core->translations    = array();
		$core->updates         = array(
			(object) array(
				'response' => $core_version ? 'upgrade' : 'latest',
				'current'  => $core_version ? $core_version : wp_get_wp_version(),
				'version'  => $core_version ? $core_version : wp_get_wp_version(),
				'locale'   => 'en_US',
			),
		);
		set_site_transient( 'update_core', $core );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = array();
		foreach ( get_plugins() as $file => $data ) {
			$installed[ $file ] = $data['Version'];
		}

		$response = array();
		foreach ( $plugins as $file => $update ) {
			$response[ $file ] = (object) array_merge(
				array(
					'id'          => 'w.org/plugins/' . dirname( $file ),
					'plugin'      => $file,
					'slug'        => preg_replace( '/\.php$/', '', basename( $file ) ),
					'url'         => 'https://wordpress.org/plugins/' . preg_replace( '/\.php$/', '', basename( $file ) ) . '/',
					'package'     => '',
					'new_version' => '9.9.9',
				),
				$update
			);
		}

		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => time(),
				'checked'      => $installed,
				'response'     => $response,
				'translations' => array(),
				'no_update'    => array(),
			)
		);

		$checked_themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$checked_themes[ $stylesheet ] = $theme->get( 'Version' );
		}

		$theme_response = array();
		foreach ( $themes as $stylesheet => $update ) {
			$theme_response[ $stylesheet ] = array_merge(
				array(
					'theme'        => $stylesheet,
					'new_version'  => '9.9.9',
					'url'          => 'https://wordpress.org/themes/' . $stylesheet . '/',
					'package'      => '',
					'requires'     => '',
					'requires_php' => '',
				),
				$update
			);
		}

		set_site_transient(
			'update_themes',
			(object) array(
				'last_checked' => time(),
				'checked'      => $checked_themes,
				'response'     => $theme_response,
				'translations' => array(),
			)
		);
	}

	/**
	 * A plugin instance for calling its public methods. Its hooks are already registered by the bootstrap.
	 *
	 * @return SC_WP_Updates_Notifier
	 */
	protected function plugin() {
		return new SC_WP_Updates_Notifier();
	}

	/**
	 * Mail captured by the mock mailer.
	 *
	 * @return object|false The first sent message (->subject, ->body, ->header, ->to) or false.
	 */
	protected function sent_mail() {
		return tests_retrieve_phpmailer_instance()->get_sent();
	}

	/**
	 * Number of mails the mock mailer captured.
	 *
	 * @return int
	 */
	protected function sent_mail_count() {
		return count( tests_retrieve_phpmailer_instance()->mock_sent );
	}
}
