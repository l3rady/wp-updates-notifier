<?php
/**
 * The plugin run state.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * What the plugin has already notified about and when it last checked.
 *
 * @internal
 * @phpstan-type Notified array{core: string, plugin: array<string, string>, theme: array<string, string>}
 * @phpstan-type StateData array{notified: Notified, last_check_time: int|false, slack_notice_sent: bool}
 */
class State {
	const OPT_FIELD = 'sc_wpun_state';

	/**
	 * What the plugin has already notified about and when it last checked.
	 *
	 * Kept apart from the settings, in an option that is not autoloaded, so a run
	 * never overwrites settings saved meanwhile and the settings form never sees it.
	 *
	 * @return StateData
	 */
	public function get(): array {
		return $this->normalise( apply_filters( 'sc_wpun_get_options_filter', get_option( self::OPT_FIELD ), self::OPT_FIELD ) );
	}

	/**
	 * Store the run state, creating the option without autoload if it is missing.
	 *
	 * @param array<string, mixed> $state Run state.
	 *
	 * @return void
	 */
	public function save( array $state ): void {
		$state = apply_filters( 'sc_wpun_put_options_filter', $state, self::OPT_FIELD );

		if ( false === get_option( self::OPT_FIELD ) ) {
			add_option( self::OPT_FIELD, $state, '', false );
		} else {
			update_option( self::OPT_FIELD, $state );
		}
	}

	/**
	 * Give the run state its expected shape and types.
	 *
	 * @param mixed $state Stored state.
	 *
	 * @return StateData
	 */
	public function normalise( $state ): array {
		$state    = is_array( $state ) ? $state : array();
		$notified = isset( $state['notified'] ) && is_array( $state['notified'] ) ? $state['notified'] : array();

		return array(
			'notified'          => array(
				'core'   => isset( $notified['core'] ) && is_scalar( $notified['core'] ) ? (string) $notified['core'] : '',
				'plugin' => $this->normalise_versions( $notified['plugin'] ?? array() ),
				'theme'  => $this->normalise_versions( $notified['theme'] ?? array() ),
			),
			'last_check_time'   => ! empty( $state['last_check_time'] ) && is_numeric( $state['last_check_time'] ) ? (int) $state['last_check_time'] : false,
			'slack_notice_sent' => ! empty( $state['slack_notice_sent'] ),
		);
	}


	/**
	 * Keep only scalar versions, as strings, keyed by plugin file or theme.
	 *
	 * @param mixed $versions Stored versions.
	 *
	 * @return array<string, string>
	 */
	public function normalise_versions( $versions ): array {
		if ( ! is_array( $versions ) ) {
			return array();
		}

		$clean = array();
		foreach ( $versions as $key => $version ) {
			if ( is_scalar( $version ) ) {
				$clean[ (string) $key ] = (string) $version;
			}
		}

		return $clean;
	}
}
