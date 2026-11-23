<?php
/**
 * Sending notifications.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Handles the deprecated email sending.
 *
 * @internal
 */
class Notifier {
	/**
	 * Channel instance storage, where the email settings are kept.
	 *
	 * @var Channel_Store
	 */
	private $channels;

	/**
	 * Run state, where what each instance was told is kept.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Where the plugin runs: one site or a whole network.
	 *
	 * @var Network_Context
	 */
	private $network_context;

	/**
	 * Constructor.
	 *
	 * @param Channel_Store        $channels        Channel instance storage.
	 * @param State                $state           Run state.
	 * @param Network_Context|null $network_context Where the plugin runs.
	 */
	public function __construct( Channel_Store $channels, State $state, ?Network_Context $network_context = null ) {
		$this->channels        = $channels;
		$this->state           = $state;
		$this->network_context = $network_context ?? new Network_Context();
	}

	/**
	 * Send a message the way versions before 2.0.0 did: plain text to every enabled email instance.
	 *
	 * Only for the deprecated SC_WP_Updates_Notifier::send_email_message(); updates go out
	 * through the email channel.
	 *
	 * @param string $message Body of the email.
	 *
	 * @return bool Whether an email was sent to any instance.
	 */
	public function send_legacy_message( $message ) {
		// translators: %s: Site name.
		$subject = sprintf( __( 'WP Updates Notifier: updates available for %s', 'wp-updates-notifier' ), wp_specialchars_decode( $this->network_context->site_name(), ENT_QUOTES ) );
		$sent    = false;

		foreach ( $this->channels->all() as $instance ) {
			$to = $instance->get_setting( 'to' );
			if ( 'email' !== $instance->get_type() || ! $instance->is_enabled() || ! is_string( $to ) || '' === trim( $to ) ) {
				continue;
			}
			/** This filter is documented in modules/email/class-email-channel.php */
			$mail_subject = apply_filters( 'sc_wpun_email_subject', $subject );
			/** This filter is documented in modules/email/class-email-channel.php */
			$mail_body = apply_filters( 'sc_wpun_email_content', $message );
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
			if ( wp_mail( $to, $mail_subject, $mail_body ) ) {
				$sent = true;
			}
		}

		return $sent;
	}

	/**
	 * Remove plugins from an update list if every channel instance has been told about that version.
	 *
	 * Kept for the deprecated SC_WP_Updates_Notifier method of the same name: the update check no
	 * longer uses it, because what was notified is tracked per instance.
	 *
	 * @param array<mixed> $plugins_need_update Array of plugins that need an update.
	 *
	 * @return array<mixed> $plugins_need_update
	 */
	public function check_plugins_against_notified( $plugins_need_update ) {
		return $this->without_notified( $plugins_need_update, 'plugin' );
	}

	/**
	 * Remove themes from an update list if every channel instance has been told about that version.
	 *
	 * Kept for the deprecated SC_WP_Updates_Notifier method of the same name.
	 *
	 * @param array<mixed> $themes_need_update Array of themes that need an update.
	 *
	 * @return array<mixed> $themes_need_update
	 */
	public function check_themes_against_notified( $themes_need_update ) {
		return $this->without_notified( $themes_need_update, 'theme' );
	}

	/**
	 * Remove the entries of an update list that every stored instance has been told about.
	 *
	 * @param mixed  $needing_update Update list, keyed by plugin file or stylesheet.
	 * @param string $type           Item type: plugin or theme.
	 *
	 * @return mixed The list, unchanged if it is not an array or no instance has state.
	 */
	private function without_notified( $needing_update, string $type ) {
		$instances = $this->channels->all();
		if ( ! is_array( $needing_update ) || array() === $instances ) {
			return $needing_update;
		}

		$channels = $this->state->get()['channels'];
		foreach ( $needing_update as $key => $data ) {
			$version = is_object( $data ) ? ( $data->new_version ?? null ) : ( is_array( $data ) ? ( $data['new_version'] ?? null ) : null );
			if ( ! is_scalar( $version ) ) {
				continue;
			}
			$told_all = true;
			foreach ( array_keys( $instances ) as $id ) {
				if ( ( $channels[ $id ]['notified'][ $type ][ (string) $key ] ?? null ) !== (string) $version ) {
					$told_all = false;
					break;
				}
			}
			if ( $told_all ) {
				unset( $needing_update[ $key ] );
			}
		}

		return $needing_update;
	}
}
