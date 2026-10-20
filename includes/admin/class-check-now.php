<?php
/**
 * The button on the General tab that runs the update check on demand.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Channel_Store;
use WPUN\Delivery_Result;
use WPUN\Dispatcher;
use WPUN\Notification;
use WPUN\Update_Checker;

/**
 * Runs the check as the `sc_wpun_run_check` action does and tells the user what it did.
 *
 * Only updates that were not notified yet are sent. The schedule is not touched. Two runs at the same time
 * are not guarded against, so an update may be delivered twice.
 *
 * @internal
 */
class Check_Now {
	const ACTION = 'sc_wpun_check_now';

	/**
	 * Channel instance storage.
	 *
	 * @var Channel_Store
	 */
	private $store;

	/**
	 * Dispatcher, to tell whether a channel is available.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Notices shown after an action.
	 *
	 * @var Notices
	 */
	private $notices;

	/**
	 * The update check, run the way the `sc_wpun_run_check` action runs it.
	 *
	 * @var Update_Checker
	 */
	private $checker;

	/**
	 * Constructor.
	 *
	 * @param Channel_Store  $store      Channel instance storage.
	 * @param Dispatcher     $dispatcher Dispatcher.
	 * @param Notices        $notices    Notices shown after an action.
	 * @param Update_Checker $checker   The update check.
	 */
	public function __construct( Channel_Store $store, Dispatcher $dispatcher, Notices $notices, Update_Checker $checker ) {
		$this->store      = $store;
		$this->dispatcher = $dispatcher;
		$this->notices    = $notices;
		$this->checker    = $checker;
	}

	/**
	 * The address of the General tab.
	 *
	 * @return string
	 */
	private function general_url(): string {
		return admin_url( 'options-general.php?page=wp-updates-notifier&tab=general' );
	}

	/**
	 * Output the form with the button, and what it does.
	 *
	 * @return void
	 */
	public function render(): void {
		$channels_url = admin_url( 'options-general.php?page=wp-updates-notifier&tab=channels' );
		?>
		<form class="sc-wpun-check-now" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>" />
			<button type="submit" class="button" aria-describedby="sc-wpun-check-now-description"><?php esc_html_e( 'Check for updates and notify now', 'wp-updates-notifier' ); ?></button>
			<p class="description" id="sc-wpun-check-now-description">
				<?php
				echo wp_kses(
					sprintf(
						// translators: %s: Link to the Channels tab, with the text "Channels tab".
						__( 'Only updates that were not notified yet are sent. To try a channel, send a test message from the %s.', 'wp-updates-notifier' ),
						'<a href="' . esc_url( $channels_url ) . '">' . esc_html__( 'Channels tab', 'wp-updates-notifier' ) . '</a>'
					),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
			</p>
		</form>
		<?php
	}

	/**
	 * Whether the current request may run the check: the user manages the plugin and the nonce is valid.
	 *
	 * @return bool
	 */
	private function authorised(): bool {
		if ( ! current_user_can( Settings_Page::capability() ) || ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ) {
			return false;
		}

		return false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), self::ACTION );
	}

	/**
	 * Check the request, run the check and remember what it did for the current user.
	 *
	 * @return bool False if the user may not manage the plugin or the nonce is not valid: nothing ran.
	 */
	public function process(): bool {
		if ( ! $this->authorised() ) {
			return false;
		}

		$has_channel = false;
		foreach ( $this->store->all() as $instance ) {
			if ( $instance->is_enabled() && $this->dispatcher->is_available( $instance ) ) {
				$has_channel = true;
				break;
			}
		}

		$results  = array();
		$listener = static function ( Notification $notification, array $sent ) use ( &$results ): void {
			$results = $sent;
		};
		add_action( 'sc_wpun_after_update_check', $listener, 10, 2 );
		$this->checker->run_manual_check(); // What the `sc_wpun_run_check` action runs.
		remove_action( 'sc_wpun_after_update_check', $listener, 10 );

		if ( ! $has_channel ) {
			$this->notices->add( true, __( 'Checked just now. Nothing was sent because no channel is enabled.', 'wp-updates-notifier' ) );
		} elseif ( array() === $results ) {
			$this->notices->add( true, __( 'Checked just now. Nothing new to send.', 'wp-updates-notifier' ) );
		} else {
			$this->notices->add( ...$this->summary( $results ) );
		}

		return true;
	}

	/**
	 * Describe what was sent.
	 *
	 * @param array<string, Delivery_Result> $results Result by channel instance id.
	 *
	 * @return array{0: bool, 1: string} Whether every delivery worked, and the text.
	 */
	private function summary( array $results ): array {
		$failed = array();
		foreach ( $results as $id => $result ) {
			if ( $result instanceof Delivery_Result && $result->is_success() ) {
				continue;
			}
			$instance = $this->store->get( (string) $id );
			$label    = null === $instance ? (string) $id : $instance->get_label();
			$message  = $result instanceof Delivery_Result ? $result->get_message() : '';
			$failed[] = '' === $message ? $label : $label . ' (' . $message . ')';
		}

		$sent = count( $results ) - count( $failed );
		if ( array() === $failed ) {
			// translators: %d: Number of channels the update was sent to.
			return array( true, sprintf( _n( 'Checked just now. Sent to %d channel.', 'Checked just now. Sent to %d channels.', $sent, 'wp-updates-notifier' ), $sent ) );
		}

		$count = count( $failed );
		$list  = implode( ', ', $failed );
		if ( 0 === $sent ) {
			// translators: 1: Number of channels that failed. 2: The channels and why each failed.
			return array( false, sprintf( _n( 'Checked just now. %1$d channel failed: %2$s', 'Checked just now. %1$d channels failed: %2$s', $count, 'wp-updates-notifier' ), $count, $list ) );
		}

		return array(
			false,
			sprintf(
				// translators: 1: Number of channels the update was sent to. 2: Number of channels that failed. 3: The channels that failed and why.
				_n( 'Checked just now. Sent to %1$d channel; %2$d failed: %3$s', 'Checked just now. Sent to %1$d channels; %2$d failed: %3$s', $sent, 'wp-updates-notifier' ),
				$sent,
				$count,
				$list
			),
		);
	}

	/**
	 * Handle the form (admin-post.php), then go back to the General tab.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! $this->process() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-updates-notifier' ), '', array( 'response' => 403 ) );
		}

		wp_safe_redirect( $this->general_url() );
		exit;
	}
}
