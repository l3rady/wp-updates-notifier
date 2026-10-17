<?php
/**
 * Telling admins about configuration values that could not be used.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Shows the problems the Config found on the plugin's settings page and in Site Health. Never the values.
 *
 * @internal
 */
final class Config_Report {

	/**
	 * Resolver.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Config   $config   Resolver.
	 * @param Settings $settings Settings.
	 */
	public function __construct( Config $config, Settings $settings ) {
		$this->config   = $config;
		$this->settings = $settings;
	}

	/**
	 * The problems of this request, after making sure the settings were resolved.
	 *
	 * @return array<int, array{key: string, source: string, message: string}>
	 */
	private function problems(): array {
		$this->settings->get(); // Resolving is lazy and problems are found while doing it.

		return $this->config->problems();
	}

	/**
	 * Output the notice inside the plugin's settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		$problems = $this->problems();
		if ( array() === $problems ) {
			return;
		}
		?>
		<div class="notice notice-error sc-wpun-config-problems">
			<p><strong><?php esc_html_e( 'Some configuration values supplied from outside the database cannot be used.', 'wp-updates-notifier' ); ?></strong></p>
			<ul>
			<?php foreach ( $problems as $problem ) : ?>
				<li><code><?php echo esc_html( $problem['key'] ); ?></code> (<?php echo esc_html( $problem['source'] ); ?>): <?php echo esc_html( $problem['message'] ); ?></li>
			<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Add the Site Health test while it has something to report.
	 *
	 * @param mixed $tests Site Health tests.
	 *
	 * @return mixed
	 */
	public function add_site_health_test( $tests ) {
		if ( is_array( $tests ) && array() !== $this->problems() ) {
			$tests['direct']['sc_wpun_config'] = array(
				'label' => __( 'WP Updates Notifier cannot use some configuration values', 'wp-updates-notifier' ),
				'test'  => array( $this, 'site_health_result' ),
			);
		}

		return $tests;
	}

	/**
	 * The Site Health test result: where each unusable value came from and why, never the value.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health_result(): array {
		$items = '';
		foreach ( $this->problems() as $problem ) {
			$items .= '<li><code>' . esc_html( $problem['key'] ) . '</code> (' . esc_html( $problem['source'] ) . '): ' . esc_html( $problem['message'] ) . '</li>';
		}

		return array(
			'label'       => __( 'WP Updates Notifier cannot use some configuration values', 'wp-updates-notifier' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Updates Notifier', 'wp-updates-notifier' ),
				'color' => 'orange',
			),
			'description' => '<p>' . esc_html__( 'Some values supplied by constants, environment variables or other sources are not valid and were ignored.', 'wp-updates-notifier' ) . '</p><ul>' . $items . '</ul>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'options-general.php?page=' . Slack_Notice::SETTINGS_SLUG ) ),
				esc_html__( 'Review the notification settings', 'wp-updates-notifier' )
			),
			'test'        => 'sc_wpun_config',
		);
	}
}
