<?php
/**
 * The About tab of the settings screen.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Plugin;

/**
 * Links about the plugin and its author. Plain links to other sites: nothing is loaded from them and nothing is tracked.
 *
 * The tab is hidden with the code-only `show_about` setting (see Settings::show_about()).
 *
 * @internal
 */
class About_Tab {

	/**
	 * Every address the tab links to, by slug. The documentation home page lists the same ones, which a test compares.
	 *
	 * @return array<string, string>
	 */
	public static function links(): array {
		return array(
			'documentation'   => Plugin::SITE_URL,
			'github'          => 'https://github.com/l3rady/wp-updates-notifier',
			'wordpress_org'   => 'https://wordpress.org/plugins/wp-updates-notifier/',
			'review'          => 'https://wordpress.org/support/plugin/wp-updates-notifier/reviews/#new-post',
			'support'         => 'https://wordpress.org/support/plugin/wp-updates-notifier/',
			'issues'          => 'https://github.com/l3rady/wp-updates-notifier/issues',
			'security'        => 'https://github.com/l3rady/wp-updates-notifier/blob/main/SECURITY.md',
			'donate'          => Plugin::SITE_URL . '#support-the-project',
			'author_github'   => 'https://github.com/l3rady',
			'author_linkedin' => 'https://www.linkedin.com/in/scott-cariss',
		);
	}

	/**
	 * A link to one of the addresses, with a note for screen readers that it leaves the site.
	 *
	 * @param string $slug Key of links().
	 * @param string $text Link text, already translated and not yet escaped.
	 *
	 * @return string HTML.
	 */
	private function link( string $slug, string $text ): string {
		$links = self::links();

		return '<a href="' . esc_url( $links[ $slug ] ) . '">' . esc_html( $text ) . '<span class="screen-reader-text"> ' . /* translators: Hidden accessibility text. */ esc_html__( '(external link)', 'wp-updates-notifier' ) . '</span></a>';
	}

	/**
	 * Output the tab.
	 *
	 * @return void
	 */
	public function render(): void {
		$allowed = array(
			'a'    => array( 'href' => true ),
			'span' => array( 'class' => true ),
		);
		?>
		<div class="sc-wpun-about">
			<h2><?php esc_html_e( 'About the plugin', 'wp-updates-notifier' ); ?></h2>
			<p><?php esc_html_e( 'WP Updates Notifier checks your site for WordPress core, plugin and theme updates and tells you about each new version once. It is free and open source, with no tracking and no account to sign up for.', 'wp-updates-notifier' ); ?></p>
			<ul>
				<li><?php echo wp_kses( $this->link( 'documentation', __( 'Documentation', 'wp-updates-notifier' ) ), $allowed ); ?></li>
				<li><?php echo wp_kses( $this->link( 'github', __( 'GitHub', 'wp-updates-notifier' ) ), $allowed ); ?></li>
				<li><?php echo wp_kses( $this->link( 'wordpress_org', __( 'WordPress.org', 'wp-updates-notifier' ) ), $allowed ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'Help and bugs', 'wp-updates-notifier' ); ?></h2>
			<ul>
				<li>
					<?php
					// translators: %s: Link to the WordPress.org support forum.
					echo wp_kses( sprintf( esc_html__( 'Questions: ask in the %s.', 'wp-updates-notifier' ), $this->link( 'support', __( 'support forum', 'wp-updates-notifier' ) ) ), $allowed );
					?>
				</li>
				<li>
					<?php
					// translators: %s: Link to the GitHub issues.
					echo wp_kses( sprintf( esc_html__( 'Bugs and ideas: open an issue on %s.', 'wp-updates-notifier' ), $this->link( 'issues', __( 'GitHub', 'wp-updates-notifier' ) ) ), $allowed );
					?>
				</li>
				<li>
					<?php
					// translators: %s: Link to the security policy.
					echo wp_kses( sprintf( esc_html__( 'Security problems: please report them privately, as the %s explains.', 'wp-updates-notifier' ), $this->link( 'security', __( 'security policy', 'wp-updates-notifier' ) ) ), $allowed );
					?>
				</li>
			</ul>

			<h2><?php esc_html_e( 'Leave a review', 'wp-updates-notifier' ); ?></h2>
			<p>
				<?php
				// translators: %s: Link to write a review on WordPress.org.
				echo wp_kses( sprintf( esc_html__( 'Like it? %s. It helps other people find the plugin.', 'wp-updates-notifier' ), $this->link( 'review', __( 'Leave a review on WordPress.org', 'wp-updates-notifier' ) ) ), $allowed );
				?>
			</p>

			<h2><?php esc_html_e( 'Donate', 'wp-updates-notifier' ); ?></h2>
			<p><?php esc_html_e( 'WP Updates Notifier is free. If it saves you time and you would like to say thanks, a donation helps keep it maintained.', 'wp-updates-notifier' ); ?></p>
			<p><?php echo wp_kses( $this->link( 'donate', __( 'Ways to donate', 'wp-updates-notifier' ) ), $allowed ); ?></p>

			<h2><?php esc_html_e( 'About the developer', 'wp-updates-notifier' ); ?></h2>
			<p><strong><?php esc_html_e( 'Scott Cariss', 'wp-updates-notifier' ); ?></strong></p>
			<p><?php esc_html_e( 'I have been a software engineer for over 20 years and I am now a Lead Platform Engineer, building the platforms other engineers ship on. I first released WP Updates Notifier on WordPress.org in 2011, and it now runs on around 30,000 sites.', 'wp-updates-notifier' ); ?></p>
			<ul>
				<li><?php echo wp_kses( $this->link( 'author_github', __( 'Scott Cariss on GitHub', 'wp-updates-notifier' ) ), $allowed ); ?></li>
				<li><?php echo wp_kses( $this->link( 'author_linkedin', __( 'Scott Cariss on LinkedIn', 'wp-updates-notifier' ) ), $allowed ); ?></li>
			</ul>

			<h2><?php esc_html_e( 'For power users', 'wp-updates-notifier' ); ?></h2>
			<p><?php esc_html_e( 'You can hide this tab with a line in wp-config.php:', 'wp-updates-notifier' ); ?></p>
			<pre><code>define( 'SC_WPUN_SETTING_SHOW_ABOUT', false );</code></pre>
			<p><?php esc_html_e( 'The setting is never stored and has no field on the settings screen. An environment variable, a configuration source or the sc_wpun_config_value filter can set it too. If you hide the tab, please consider a donation or a review in return.', 'wp-updates-notifier' ); ?></p>
		</div>
		<?php
	}
}
