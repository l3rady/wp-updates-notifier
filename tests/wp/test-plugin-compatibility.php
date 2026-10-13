<?php
/**
 * The compatibility lines of plugin updates (#70).
 *
 * @package wp-updates-notifier
 */

/**
 * Compatibility from the update data, without a request per plugin.
 */
class Test_Plugin_Compatibility extends WPUN_Test_Case {

	/**
	 * Settings that send email.
	 */
	public function set_up() {
		parent::set_up();
		$this->save_settings();
	}

	/**
	 * The site's WordPress version as major.minor, for example 7.1.
	 *
	 * @return string
	 */
	private function site_major_minor() {
		return implode( '.', array_slice( explode( '.', preg_replace( '/-.*$/', '', wp_get_wp_version() ) ), 0, 2 ) );
	}

	/**
	 * Run a check with one Hello Dolly update carrying the given fields, and return the email body.
	 *
	 * @param array $fields Extra fields of the update entry.
	 * @return string
	 */
	private function body_for_update( array $fields ) {
		$this->set_updates( null, array( 'hello.php' => array_merge( array( 'new_version' => '2.0.0' ), $fields ) ) );
		$this->plugin()->do_update_check();

		return $this->sent_mail()->body;
	}

	/**
	 * The data is in the update transient, so no request is made to WordPress.org per plugin.
	 */
	public function test_no_plugin_information_request_is_made() {
		$this->body_for_update( array( 'tested' => '99.0' ) );

		$this->assertCount( 0, $this->requests_to( 'plugins/info' ) );
	}

	/**
	 * "Tested up to" the site's major version counts, whatever the minor release.
	 */
	public function test_tested_up_to_the_current_major_version_counts() {
		$body = $this->body_for_update( array( 'tested' => $this->site_major_minor() ) );

		$this->assertStringContainsString( 'Compatibility with WordPress ' . $this->site_major_minor() . ': tested by its author', $body );
	}

	/**
	 * A higher tested version counts too.
	 */
	public function test_tested_with_a_newer_version_counts() {
		$this->assertStringContainsString( ': tested by its author', $this->body_for_update( array( 'tested' => '99.0' ) ) );
	}

	/**
	 * An older tested version says so.
	 */
	public function test_tested_with_an_older_version_says_so() {
		$body = $this->body_for_update( array( 'tested' => '5.0' ) );

		$this->assertStringContainsString( 'Compatibility with WordPress ' . $this->site_major_minor() . ': not tested yet (tested up to 5.0)', $body );
	}

	/**
	 * Without a tested version it is unknown.
	 */
	public function test_unknown_without_a_tested_version() {
		$this->assertStringContainsString( 'Compatibility with WordPress ' . $this->site_major_minor() . ': unknown', $this->body_for_update( array() ) );
	}

	/**
	 * An update that needs a newer PHP than the site has is flagged.
	 */
	public function test_a_php_requirement_above_the_site_is_flagged() {
		$body = $this->body_for_update( array( 'requires_php' => '99.0' ) );

		$this->assertStringContainsString( 'Warning: this version needs PHP 99.0; the site runs PHP ' . PHP_VERSION . '.', $body );
	}

	/**
	 * A PHP requirement the site meets is not mentioned.
	 */
	public function test_a_php_requirement_the_site_meets_is_not_mentioned() {
		$this->assertStringNotContainsString( 'needs PHP', $this->body_for_update( array( 'requires_php' => '5.6' ) ) );
	}

	/**
	 * An update that needs a newer WordPress than the site has is flagged.
	 */
	public function test_a_wordpress_requirement_above_the_site_is_flagged() {
		$body = $this->body_for_update( array( 'requires' => '99.0' ) );

		$this->assertStringContainsString( 'Warning: this version needs WordPress 99.0; the site runs WordPress ' . wp_get_wp_version() . '.', $body );
	}
}
