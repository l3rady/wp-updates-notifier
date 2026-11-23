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
	 * The only tags the list of problems has. Each part of it is escaped when built.
	 */
	const LIST_TAGS = array(
		'li'   => array(),
		'code' => array(),
	);

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
	 * Channel instances.
	 *
	 * @var Channel_Store
	 */
	private $store;

	/**
	 * Where the plugin runs.
	 *
	 * @var Network_Context
	 */
	private $network_context;

	/**
	 * Settings groups of add-ons.
	 *
	 * @var Settings_Groups
	 */
	private $groups;

	/**
	 * Constructor.
	 *
	 * @param Config               $config   Resolver.
	 * @param Settings             $settings Settings.
	 * @param Channel_Store        $store    Channel instances.
	 * @param Settings_Groups      $groups   Settings groups of add-ons.
	 * @param Network_Context|null $network_context Where the plugin runs.
	 */
	public function __construct( Config $config, Settings $settings, Channel_Store $store, Settings_Groups $groups, ?Network_Context $network_context = null ) {
		$this->config   = $config;
		$this->settings = $settings;
		$this->store    = $store;
		$this->groups   = $groups;

		$this->network_context = $network_context ?? new Network_Context();
	}

	/**
	 * The problems of this request, after making sure the settings were resolved.
	 *
	 * @return array<int, array{key: string, source: string, message: string}>
	 */
	public function problems(): array {
		// Resolving is lazy and problems are found while doing it.
		$this->settings->get();
		$this->settings->show_about();
		$this->store->all();
		$this->groups->resolve_all();

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
			<p><strong><?php echo esc_html( $this->summary() ); ?></strong></p>
			<ul>
			<?php echo wp_kses( $this->items_html(), self::LIST_TAGS ); ?>
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
				'label' => $this->label(),
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
		return Site_Health::result(
			'sc_wpun_config',
			'recommended',
			$this->label(),
			'<p>' . esc_html( $this->summary() ) . '</p><ul>' . $this->items_html() . '</ul>',
			Site_Health::settings_action( $this->network_context->settings_url() )
		);
	}

	/**
	 * The title of the notice and of the Site Health test.
	 *
	 * @return string
	 */
	private function label(): string {
		return __( 'WP Updates Notifier cannot use some configuration values', 'wp-updates-notifier' );
	}

	/**
	 * The sentence above the list of problems. Plain text: the caller escapes it.
	 *
	 * @return string
	 */
	private function summary(): string {
		return __( 'Some values supplied by constants, environment variables or other sources are not valid. An invalid setting falls back to its saved value, its default, or the value its add-on chose; a channel with an invalid value does not send until it is fixed.', 'wp-updates-notifier' );
	}

	/**
	 * One list item for each problem: where the value came from and why, never the value. Every part is escaped.
	 *
	 * @return string
	 */
	private function items_html(): string {
		$items = '';
		foreach ( $this->problems() as $problem ) {
			$items .= '<li><code>' . esc_html( $problem['key'] ) . '</code> (' . esc_html( $problem['source'] ) . '): ' . esc_html( $problem['message'] ) . '</li>';
		}

		return $items;
	}
}
