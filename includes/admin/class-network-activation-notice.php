<?php
/**
 * Telling the admins of a site that the plugin must be network-activated.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * On a multisite the plugin only runs when it is network-activated. A copy activated on single sites does
 * nothing but this: it asks a network admin to network-activate it, in the admin and in Site Health.
 *
 * @internal
 */
class Network_Activation_Notice {

	/**
	 * What to tell: the plugin has stopped for this site and what to do about it.
	 *
	 * @return string
	 */
	public function message(): string {
		return __( 'WP Updates Notifier now runs for the whole network, and only when it is network-activated. Until it is, it sends no notifications for this site or any other.', 'wp-updates-notifier' );
	}

	/**
	 * The address of the network's plugin list, or an empty string for a user who cannot use it.
	 *
	 * @return string Not escaped for HTML.
	 */
	private function plugins_url(): string {
		return current_user_can( 'manage_network_plugins' ) ? network_admin_url( 'plugins.php' ) : '';
	}

	/**
	 * Output the notice, for the users who can network-activate a plugin.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( '' === $this->plugins_url() ) {
			return;
		}
		?>
		<div class="notice notice-warning sc-wpun-network-notice">
			<p><strong><?php esc_html_e( 'WP Updates Notifier is not network-activated', 'wp-updates-notifier' ); ?></strong></p>
			<p><?php echo esc_html( $this->message() ); ?></p>
			<p><a href="<?php echo esc_url( $this->plugins_url() ); ?>"><?php esc_html_e( 'Go to the network plugins', 'wp-updates-notifier' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * Add the Site Health test.
	 *
	 * @param mixed $tests Site Health tests.
	 *
	 * @return mixed
	 */
	public function add_site_health_test( $tests ) {
		if ( is_array( $tests ) ) {
			$tests['direct']['sc_wpun_network'] = array(
				'label' => __( 'WP Updates Notifier is not network-activated', 'wp-updates-notifier' ),
				'test'  => array( $this, 'site_health_result' ),
			);
		}

		return $tests;
	}

	/**
	 * The Site Health test result: critical, as the plugin is silent until a network admin acts.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health_result(): array {
		$url     = $this->plugins_url();
		$actions = '' === $url ? '' : sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Go to the network plugins', 'wp-updates-notifier' )
		);

		return array(
			'label'       => __( 'WP Updates Notifier is not network-activated', 'wp-updates-notifier' ),
			'status'      => 'critical',
			'badge'       => array(
				'label' => __( 'Updates Notifier', 'wp-updates-notifier' ),
				'color' => 'red',
			),
			'description' => '<p>' . esc_html( $this->message() ) . '</p>',
			'actions'     => $actions,
			'test'        => 'sc_wpun_network',
		);
	}
}
