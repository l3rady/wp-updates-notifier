<?php
/**
 * Notices about what an admin action did, shown once on the next page load.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

/**
 * Keeps one notice per user until the settings page shows it.
 *
 * It is kept in user meta, so only the user who clicked sees it. The site is recorded because
 * user meta is shared by a network.
 *
 * @internal
 */
class Notices {
	const META_KEY = 'sc_wpun_notice';

	/**
	 * Remember a notice for the current user, replacing one that was not shown.
	 *
	 * @param bool   $success Whether it reports a success.
	 * @param string $message The text.
	 *
	 * @return void
	 */
	public function add( bool $success, string $message ): void {
		update_user_meta(
			get_current_user_id(),
			self::META_KEY,
			array(
				'success' => $success,
				'message' => $message,
				'site'    => get_current_blog_id(),
				'time'    => time(),
			)
		);
	}

	/**
	 * Read and delete the current user's notice.
	 *
	 * @return array{success: bool, message: string}|null Null if there is none, it belongs to another site or it is stale.
	 */
	public function take(): ?array {
		$user_id = get_current_user_id();
		$stored  = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $stored ) || ( $stored['site'] ?? 0 ) !== get_current_blog_id() ) {
			return null; // None, or it belongs to another site of the network and is shown there.
		}
		delete_user_meta( $user_id, self::META_KEY );
		if ( ! isset( $stored['time'] ) || ! is_int( $stored['time'] ) || $stored['time'] < time() - 5 * MINUTE_IN_SECONDS ) {
			return null; // Too old to still be about the last click.
		}

		return array(
			'success' => ! empty( $stored['success'] ),
			'message' => isset( $stored['message'] ) && is_string( $stored['message'] ) ? $stored['message'] : '',
		);
	}

	/**
	 * Output the current user's notice, if there is one.
	 *
	 * @return void
	 */
	public function render(): void {
		$notice = $this->take();
		if ( null === $notice ) {
			return;
		}
		?>
		<div class="notice <?php echo esc_attr( $notice['success'] ? 'notice-success' : 'notice-error' ); ?> sc-wpun-notice"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
		<?php
	}
}
