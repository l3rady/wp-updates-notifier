<?php
/**
 * Telling admins about modules the registry refused.
 *
 * @package wp-updates-notifier
 */

namespace WPUN;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Shows the modules refused for needing a newer extension API on the plugin's settings page and in Site Health.
 *
 * This is not part of Config_Report on purpose: that report is about configuration values (their key and
 * source), and a refused module is not a configuration value.
 *
 * @internal
 */
final class Rejected_Channels {

	/**
	 * Registered channels.
	 *
	 * @var Channel_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param Channel_Registry $registry Registered channels.
	 */
	public function __construct( Channel_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Output the notice inside the plugin's settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		$rejected = $this->registry->get_rejected();
		if ( array() === $rejected ) {
			return;
		}
		?>
		<div class="notice notice-warning sc-wpun-rejected-channels">
			<p><strong><?php esc_html_e( 'Some notification modules need a newer version of WP Updates Notifier and are switched off. Their settings have been kept.', 'wp-updates-notifier' ); ?></strong></p>
			<ul>
			<?php foreach ( $rejected as $item ) : ?>
				<li><?php echo esc_html( $item['label'] ); ?>: <?php echo esc_html( $item['reason'] ); ?></li>
			<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Add the Site Health test while a module is refused.
	 *
	 * @param mixed $tests Site Health tests.
	 *
	 * @return mixed
	 */
	public function add_site_health_test( $tests ) {
		if ( is_array( $tests ) && array() !== $this->registry->get_rejected() ) {
			$tests['direct']['sc_wpun_rejected_channels'] = array(
				'label' => __( 'Some WP Updates Notifier modules need a newer version', 'wp-updates-notifier' ),
				'test'  => array( $this, 'site_health_result' ),
			);
		}

		return $tests;
	}

	/**
	 * The Site Health test result: each refused module and why.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health_result(): array {
		$items = '';
		foreach ( $this->registry->get_rejected() as $item ) {
			$items .= '<li>' . esc_html( $item['label'] ) . ': ' . esc_html( $item['reason'] ) . '</li>';
		}

		return array(
			'label'       => __( 'Some WP Updates Notifier modules need a newer version', 'wp-updates-notifier' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Updates Notifier', 'wp-updates-notifier' ),
				'color' => 'orange',
			),
			'description' => '<p>' . esc_html__( 'Some notification modules need a newer version of WP Updates Notifier than this site has, so they are switched off. Their settings have been kept.', 'wp-updates-notifier' ) . '</p><ul>' . $items . '</ul>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'plugins.php' ) ),
				esc_html__( 'Update WP Updates Notifier', 'wp-updates-notifier' )
			),
			'test'        => 'sc_wpun_rejected_channels',
		);
	}
}
