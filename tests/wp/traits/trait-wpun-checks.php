<?php
/**
 * Helpers that run the update check.
 *
 * @package wp-updates-notifier
 */

/**
 * Run the update check, with or without updates to find.
 */
trait WPUN_Checks {

	/**
	 * Set the updates the site has, then run the update check.
	 *
	 * @param string|null $core       Core version offered, or null for no core update (as `set_updates()`).
	 * @param array       $plugins    Plugin updates: file => [ 'new_version' => , 'slug' => , 'url' => ].
	 * @param bool        $reset_mail Whether to empty the mail the test captured so far. Pass false to keep the
	 *                                mail of earlier checks, for a test that counts mail across checks.
	 * @return array<string, \WPUN\Delivery_Result> The results by instance id, as `last_results()`.
	 */
	protected function run_check( ?string $core = '99.0.0', array $plugins = array(), bool $reset_mail = true ): array {
		$this->set_updates( $core, $plugins );

		return $this->run_update_check( $reset_mail );
	}

	/**
	 * Run the update check on the updates the test already set.
	 *
	 * @param bool $reset_mail Whether to empty the mail the test captured so far. Pass false to keep the mail
	 *                         of earlier checks.
	 * @return array<string, \WPUN\Delivery_Result> The results by instance id, as `last_results()`.
	 */
	protected function run_update_check( bool $reset_mail = true ): array {
		if ( $reset_mail ) {
			reset_phpmailer_instance();
		}
		sc_wpun()->update_checker()->do_update_check();

		return sc_wpun()->update_checker()->last_results();
	}
}
