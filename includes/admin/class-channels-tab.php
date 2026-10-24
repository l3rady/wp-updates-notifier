<?php
/**
 * The Channels tab of the settings screen.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Channel_Registry;
use WPUN\Channel_Store;
use WPUN\Dispatcher;
use WPUN\Notification;
use WPUN\Routing;
use WPUN\State;

/**
 * Lists the channel instances with their last delivery, and handles the row actions that need no form:
 * send a test message, switch on or off, duplicate and delete.
 *
 * An instance whose module is not registered is not listed and every action treats it as missing. If the module
 * was refused for needing a newer API, its instances are shown below the list, read-only, with the reason.
 *
 * @internal
 */
class Channels_Tab {
	const TEST_ACTION      = 'sc_wpun_send_test';
	const TOGGLE_ACTION    = 'sc_wpun_channel_toggle';
	const DUPLICATE_ACTION = 'sc_wpun_channel_duplicate';
	const DELETE_ACTION    = 'sc_wpun_channel_delete';

	/**
	 * Channel instance storage.
	 *
	 * @var Channel_Store
	 */
	private $store;

	/**
	 * Registered channels.
	 *
	 * @var Channel_Registry
	 */
	private $registry;

	/**
	 * Dispatcher, for the test message.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Run state, where the last delivery of each instance is kept.
	 *
	 * @var State
	 */
	private $state;

	/**
	 * Notices shown after an action.
	 *
	 * @var Notices
	 */
	private $notices;

	/**
	 * The add and edit forms.
	 *
	 * @var Channel_Form
	 */
	private $form;

	/**
	 * Constructor.
	 *
	 * @param Channel_Store    $store      Channel instance storage.
	 * @param Channel_Registry $registry   Registered channels.
	 * @param Dispatcher       $dispatcher Dispatcher.
	 * @param State            $state      Run state.
	 * @param Notices          $notices    Notices shown after an action.
	 * @param Channel_Form     $form       The add and edit forms.
	 */
	public function __construct( Channel_Store $store, Channel_Registry $registry, Dispatcher $dispatcher, State $state, Notices $notices, Channel_Form $form ) {
		$this->store      = $store;
		$this->registry   = $registry;
		$this->dispatcher = $dispatcher;
		$this->state      = $state;
		$this->notices    = $notices;
		$this->form       = $form;
	}

	/**
	 * The address of the tab.
	 *
	 * @param array<string, string> $args Extra query arguments.
	 *
	 * @return string
	 */
	private function tab_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'options-general.php?page=wp-updates-notifier&tab=channels' ) );
	}

	/**
	 * The instances to list and act on: every stored or code-defined one whose module is registered.
	 *
	 * @return array<string, Channel_Instance>
	 */
	private function listed(): array {
		return array_filter( $this->store->all(), array( $this->dispatcher, 'is_available' ) );
	}

	/**
	 * The stored or code-defined instances of modules the registry refused, with the reason.
	 *
	 * @return array<string, array{instance: Channel_Instance, label: string, reason: string}>
	 */
	private function unavailable(): array {
		$rejected = $this->registry->get_rejected();
		$found    = array();
		foreach ( $this->store->all() as $id => $instance ) {
			if ( isset( $rejected[ $instance->get_type() ] ) ) {
				$found[ $id ] = array(
					'instance' => $instance,
					'label'    => $rejected[ $instance->get_type() ]['label'],
					'reason'   => $rejected[ $instance->get_type() ]['reason'],
				);
			}
		}

		return $found;
	}

	/**
	 * Output the tab: the list, the form to add or edit a channel, or the confirmation of a delete.
	 *
	 * @return void
	 */
	public function render(): void {
		$request = $this->requested();
		$problem = null;

		if ( 'add' === $request['action'] ) {
			$this->form->render_picker();
			return;
		}

		if ( 'new' === $request['action'] ) {
			$problem = $this->form->render_new( $request['key'] );
			if ( null === $problem ) {
				return;
			}
		} elseif ( 'edit' === $request['action'] ) {
			$problem = $this->form->render_edit( $request['key'] );
			if ( null === $problem ) {
				return;
			}
		} elseif ( 'delete' === $request['action'] ) {
			$id       = $request['key'];
			$instance = $this->listed()[ $id ] ?? null;
			if ( null !== $instance && ! $this->store->is_code_defined( $id ) ) {
				$this->render_confirm_delete( $instance );
				return;
			}
			$problem = $this->no_such_channel();
		}

		if ( null !== $problem ) {
			echo '<div class="notice notice-error inline sc-wpun-inline-notice"><p>' . esc_html( $problem ) . '</p></div>';
		}

		$this->render_list();
		$this->render_unavailable();
	}

	/**
	 * What the view is called and whether it shows errors, for the page title.
	 *
	 * @return array{name: string, errors: bool}|null Null for the list, and when the route shows the list in its place.
	 */
	public function view(): ?array {
		$request = $this->requested();

		if ( 'add' === $request['action'] ) {
			return array(
				'name'   => Channel_Form::picker_heading(),
				'errors' => false,
			);
		}
		if ( 'new' === $request['action'] || 'edit' === $request['action'] ) {
			return $this->form->view( $request['action'], $request['key'] );
		}
		if ( 'delete' === $request['action'] ) {
			$instance = $this->listed()[ $request['key'] ] ?? null;
			if ( null !== $instance && ! $this->store->is_code_defined( $request['key'] ) ) {
				return array(
					'name'   => $this->delete_heading( $instance ),
					'errors' => false,
				);
			}
		}

		return null;
	}

	/**
	 * The title of the delete confirmation: also its h2.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return string
	 */
	private function delete_heading( Channel_Instance $instance ): string {
		// translators: %s: Label of the channel.
		return sprintf( __( 'Delete %s?', 'wp-updates-notifier' ), $instance->get_label() );
	}

	/**
	 * The route in the request.
	 *
	 * @return array{action: string, key: string} The action (`add`, `new`, `edit`, `delete`; empty for the list) and the type or id it is about.
	 */
	private function requested(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Choosing what to display, nothing is changed.
		$action = isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$name   = 'new' === $action ? 'type' : 'id';
		$key    = isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_key( wp_unslash( $_GET[ $name ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( in_array( $action, array( 'add', 'new', 'edit', 'delete' ), true ) ) {
			return array(
				'action' => $action,
				'key'    => $key,
			);
		}

		return array(
			'action' => '',
			'key'    => '',
		);
	}

	/**
	 * Output the link to the module picker, styled as a button.
	 *
	 * @return void
	 */
	private function render_add_link(): void {
		?>
		<p><a class="button sc-wpun-add-channel" href="<?php echo esc_url( $this->tab_url( array( 'action' => 'add' ) ) ); ?>"><?php esc_html_e( 'Add channel', 'wp-updates-notifier' ); ?></a></p>
		<?php
	}

	/**
	 * Output the list of instances.
	 *
	 * @return void
	 */
	private function render_list(): void {
		$instances = $this->listed();
		?>
		<h2><?php esc_html_e( 'Channels', 'wp-updates-notifier' ); ?></h2>
		<?php
		$this->render_add_link();
		if ( array() === $instances ) {
			echo '<p class="sc-wpun-no-channels">' . esc_html__( 'No channels are set up yet. A channel is where the update notifications are sent, for example an email address.', 'wp-updates-notifier' ) . '</p>';
			return;
		}

		$channels = $this->state->get()['channels'];
		?>
		<table class="wp-list-table widefat fixed striped table-view-list sc-wpun-channels">
			<caption class="screen-reader-text"><?php esc_html_e( 'Channels', 'wp-updates-notifier' ); ?></caption>
			<thead>
				<tr>
					<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Label', 'wp-updates-notifier' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Module', 'wp-updates-notifier' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Enabled', 'wp-updates-notifier' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Routing', 'wp-updates-notifier' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Last delivery', 'wp-updates-notifier' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $instances as $id => $instance ) : ?>
					<?php $this->render_row( $instance, $channels[ $id ]['last_result'] ?? null, $channels[ $id ]['retry_at'] ?? null ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Output the read-only table of instances whose module was refused, if there are any.
	 *
	 * @return void
	 */
	private function render_unavailable(): void {
		$unavailable = $this->unavailable();
		if ( array() === $unavailable ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Unavailable channels', 'wp-updates-notifier' ); ?></h3>
		<p><?php esc_html_e( 'These channels belong to modules that need a newer version of WP Updates Notifier. Nothing is sent through them and their settings are kept.', 'wp-updates-notifier' ); ?></p>
		<table class="widefat striped sc-wpun-unavailable-channels">
			<caption class="screen-reader-text"><?php esc_html_e( 'Unavailable channels', 'wp-updates-notifier' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Label', 'wp-updates-notifier' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Module', 'wp-updates-notifier' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'wp-updates-notifier' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $unavailable as $item ) : ?>
					<tr>
						<td><?php echo esc_html( $item['instance']->get_label() ); ?></td>
						<td><?php echo esc_html( $item['label'] ); ?></td>
						<td>
							<?php
							echo esc_html(
								sprintf(
									// translators: %s: Why the module is not available.
									__( 'Unavailable: %s', 'wp-updates-notifier' ),
									$item['reason']
								)
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Output a row of the list.
	 *
	 * @param Channel_Instance                                      $instance    The instance.
	 * @param array{success: bool, message: string, time: int}|null $last_result Its last delivery, if any.
	 * @param int|null                                              $retry_at    When it may be tried again, if it was asked to wait.
	 *
	 * @return void
	 */
	private function render_row( Channel_Instance $instance, ?array $last_result, ?int $retry_at = null ): void {
		$id           = $instance->get_id();
		$label        = $instance->get_label();
		$channel      = $this->registry->get( $instance->get_type() );
		$code_defined = $this->store->is_code_defined( $id );
		$external     = $this->store->external_source( $id, 'enabled' );
		?>
		<tr class="sc-wpun-channel" data-id="<?php echo esc_attr( $id ); ?>">
			<td class="column-primary has-row-actions sc-wpun-channel-label" data-colname="<?php esc_attr_e( 'Label', 'wp-updates-notifier' ); ?>">
				<strong><?php echo esc_html( $label ); ?></strong>
				<?php if ( $code_defined ) : ?>
					<span class="sc-wpun-code-defined description"><?php esc_html_e( 'Defined in code', 'wp-updates-notifier' ); ?></span>
				<?php endif; ?>
				<?php $this->render_row_actions( $instance, $channel, $code_defined, null !== $external ); ?>
				<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'wp-updates-notifier' ); ?></span></button>
			</td>
			<td class="sc-wpun-channel-module" data-colname="<?php esc_attr_e( 'Module', 'wp-updates-notifier' ); ?>"><?php echo esc_html( null === $channel ? $instance->get_type() : $channel->get_label() ); ?></td>
			<td class="sc-wpun-channel-enabled" data-colname="<?php esc_attr_e( 'Enabled', 'wp-updates-notifier' ); ?>">
				<span class="sc-wpun-state"><?php echo esc_html( $instance->is_enabled() ? __( 'On', 'wp-updates-notifier' ) : __( 'Off', 'wp-updates-notifier' ) ); ?></span>
				<?php
				if ( ! $code_defined && null !== $external ) {
					// translators: %s: Where the value comes from, for example "constant SC_WPUN_SETTING_FREQUENCY".
					echo ' <span class="description sc-wpun-external">' . esc_html( sprintf( __( 'Set by %s', 'wp-updates-notifier' ), $external ) ) . '</span>';
				}
				?>
			</td>
			<td class="sc-wpun-channel-routing" data-colname="<?php esc_attr_e( 'Routing', 'wp-updates-notifier' ); ?>"><?php echo esc_html( self::routing_summary( $instance->get_routing() ) ); ?></td>
			<td class="sc-wpun-channel-last" data-colname="<?php esc_attr_e( 'Last delivery', 'wp-updates-notifier' ); ?>"><?php echo esc_html( self::last_delivery_text( $last_result, $retry_at ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Output the actions under the label, in the style of the core list tables.
	 *
	 * Nothing is output when no action applies. A code-defined instance can only be tested, and the
	 * enable switch is left out when something outside the database sets it.
	 *
	 * @param Channel_Instance $instance      The instance.
	 * @param Channel|null     $channel       Its module, if it is registered.
	 * @param bool             $code_defined  Whether the instance is defined in code.
	 * @param bool             $external_flag Whether an external source sets `enabled`.
	 *
	 * @return void
	 */
	private function render_row_actions( Channel_Instance $instance, ?Channel $channel, bool $code_defined, bool $external_flag ): void {
		$id    = $instance->get_id();
		$label = $instance->get_label();
		$items = array();

		if ( ! $code_defined ) {
			// translators: %s: Label of the channel.
			$edit_name     = sprintf( __( 'Edit %s', 'wp-updates-notifier' ), $label );
			$items['edit'] = '<a class="sc-wpun-edit" href="' . esc_url( $this->tab_url( array( 'action' => 'edit', 'id' => $id ) ) ) . '" aria-label="' . esc_attr( $edit_name ) . '">' . esc_html__( 'Edit', 'wp-updates-notifier' ) . '</a>'; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		if ( null !== $channel && $channel->supports( 'test_message' ) ) {
			// translators: %s: Label of the channel.
			$action_name           = sprintf( __( 'Send test to %s', 'wp-updates-notifier' ), $label );
			$items['sc-wpun-test'] = $this->action_form( self::TEST_ACTION, $id, __( 'Send test', 'wp-updates-notifier' ), $action_name );
		}
		if ( ! $code_defined ) {
			// translators: %s: Label of the channel.
			$action_name                = sprintf( __( 'Duplicate %s', 'wp-updates-notifier' ), $label );
			$items['sc-wpun-duplicate'] = $this->action_form( self::DUPLICATE_ACTION, $id, __( 'Duplicate', 'wp-updates-notifier' ), $action_name );
			if ( ! $external_flag ) {
				if ( $instance->is_enabled() ) {
					// translators: %s: Label of the channel.
					$action_name             = sprintf( __( 'Disable %s', 'wp-updates-notifier' ), $label );
					$items['sc-wpun-toggle'] = $this->action_form( self::TOGGLE_ACTION, $id, __( 'Disable', 'wp-updates-notifier' ), $action_name );
				} else {
					// translators: %s: Label of the channel.
					$action_name             = sprintf( __( 'Enable %s', 'wp-updates-notifier' ), $label );
					$items['sc-wpun-toggle'] = $this->action_form( self::TOGGLE_ACTION, $id, __( 'Enable', 'wp-updates-notifier' ), $action_name );
				}
			}
			// translators: %s: Label of the channel.
			$delete_name    = sprintf( __( 'Delete %s', 'wp-updates-notifier' ), $label );
			$items['trash'] = '<a class="submitdelete sc-wpun-delete" href="' . esc_url( $this->tab_url( array( 'action' => 'delete', 'id' => $id ) ) ) . '" aria-label="' . esc_attr( $delete_name ) . '">' . esc_html__( 'Delete', 'wp-updates-notifier' ) . '</a>'; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		if ( array() === $items ) {
			return;
		}

		// As core does: the separator sits inside every item but the last.
		$last = array_key_last( $items );
		$html = '';
		foreach ( $items as $class => $item ) {
			$html .= '<span class="' . esc_attr( $class ) . '">' . $item . ( $class === $last ? '' : ' | ' ) . '</span>';
		}
		echo '<div class="row-actions">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each item is escaped where it is built.
	}

	/**
	 * A button in a small form posting to admin-post.php, styled as a link.
	 *
	 * The nonce is tied to the action and the instance, and has no id attribute, so rows do not repeat one.
	 *
	 * @param string $action The admin-post action.
	 * @param string $id     Instance id.
	 * @param string $text   Button text.
	 * @param string $name   Accessible name of the button, including the label of the instance.
	 *
	 * @return string The escaped markup.
	 */
	private function action_form( string $action, string $id, string $text, string $name ): string {
		ob_start();
		?>
		<form class="sc-wpun-row-action" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( $action . '_' . $id ) ); ?>" />
			<button type="submit" class="button-link" aria-label="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $text ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Output the question before a delete.
	 *
	 * @param Channel_Instance $instance The instance.
	 *
	 * @return void
	 */
	private function render_confirm_delete( Channel_Instance $instance ): void {
		$id = $instance->get_id();
		?>
		<div class="sc-wpun-confirm-delete">
			<h2><?php echo esc_html( $this->delete_heading( $instance ) ); ?></h2>
			<p>
				<?php
				// translators: %s: Label of the channel.
				echo esc_html( sprintf( __( 'Delete the channel %s? Its settings and delivery history are removed. This cannot be undone.', 'wp-updates-notifier' ), $instance->get_label() ) );
				?>
			</p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::DELETE_ACTION . '_' . $id ) ); ?>" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Delete', 'wp-updates-notifier' ); ?></button>
				<a class="button" href="<?php echo esc_url( $this->tab_url() ); ?>"><?php esc_html_e( 'Cancel', 'wp-updates-notifier' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * One line telling which updates an instance receives, from the labels of the routing fields.
	 *
	 * @internal
	 *
	 * @param array<mixed> $routing Routing rules of the instance.
	 *
	 * @return string
	 */
	public static function routing_summary( array $routing ): string {
		$fields  = Routing::fields();
		$routing = Routing::normalise( $routing );
		$types   = is_array( $routing['types'] ) ? $routing['types'] : array();
		$parts   = array();

		if ( array() !== $types ) {
			$labels = array();
			foreach ( $types as $type ) {
				if ( is_string( $type ) && isset( $fields['types']['options'][ $type ] ) && is_string( $fields['types']['options'][ $type ] ) ) {
					$labels[] = $fields['types']['options'][ $type ];
				}
			}
			$parts[] = array() === $labels ? __( 'No known item types', 'wp-updates-notifier' ) : implode( ', ', $labels );
		}

		$routed = static fn( string ...$wanted ): bool => array() === $types || array() !== array_intersect( $wanted, $types );

		if ( 'major' === $routing['core'] && $routed( 'core' ) && isset( $fields['core']['options']['major'] ) ) {
			$parts[] = $fields['core']['label'] . ': ' . $fields['core']['options']['major'];
		}
		if ( 'active' === $routing['scope'] && $routed( 'plugin', 'theme' ) && isset( $fields['scope']['options']['active'] ) ) {
			$parts[] = $fields['scope']['label'] . ': ' . $fields['scope']['options']['active'];
		}

		$summary = array() === $parts ? __( 'All updates', 'wp-updates-notifier' ) : implode( ' · ', $parts );

		if ( 'never' !== $routing['remind'] && isset( $fields['remind']['options'][ $routing['remind'] ] ) ) {
			$summary .= ' · ' . $fields['remind']['label'] . ': ' . $fields['remind']['options'][ $routing['remind'] ];
		}

		return $summary;
	}

	/**
	 * The text of the last delivery column.
	 *
	 * @internal
	 *
	 * @param array{success: bool, message: string, time: int}|null $last_result The stored result.
	 * @param int|null                                              $retry_at    When the instance may be tried again, if it was asked to wait.
	 *
	 * @return string
	 */
	public static function last_delivery_text( ?array $last_result, ?int $retry_at = null ): string {
		if ( null === $last_result ) {
			return __( 'Never', 'wp-updates-notifier' );
		}

		$time = Settings_Page::format_site_time( $last_result['time'] );
		if ( $last_result['success'] ) {
			// translators: %s: Date and time of the delivery.
			return sprintf( __( 'Sent %s', 'wp-updates-notifier' ), $time );
		}

		// translators: 1: Date and time of the delivery. 2: Why it failed.
		$text = sprintf( __( 'Failed %1$s: %2$s', 'wp-updates-notifier' ), $time, $last_result['message'] );
		if ( null !== $retry_at && $retry_at > time() ) {
			// translators: %s: Date and time the channel will be tried again.
			$text .= ' ' . sprintf( __( 'Rate limited, next try after %s', 'wp-updates-notifier' ), Settings_Page::format_site_time( $retry_at ) );
		}

		return $text;
	}

	/**
	 * The text for an id that is not listed.
	 *
	 * @return string
	 */
	private function no_such_channel(): string {
		return __( 'There is no such channel.', 'wp-updates-notifier' );
	}

	/**
	 * Check the capability and the nonce of a row action.
	 *
	 * @param string $action The admin-post action, which is also the first part of the nonce action.
	 *
	 * @return string|null The instance id the request is about; null if the user may not do this or the nonce is not valid.
	 */
	private function authorise( string $action ): ?string {
		if ( ! current_user_can( Settings_Page::capability() ) || ! isset( $_POST['id'], $_POST['_wpnonce'] ) || ! is_string( $_POST['id'] ) || ! is_string( $_POST['_wpnonce'] ) ) {
			return null;
		}

		$id = sanitize_key( wp_unslash( $_POST['id'] ) );
		if ( false === wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), $action . '_' . $id ) ) {
			return null;
		}

		return $id;
	}

	/**
	 * Check a test send request, send the test and remember the result for the current user.
	 *
	 * @return bool False if the user may not manage the plugin or the nonce is not valid.
	 */
	public function process_test_send(): bool {
		$id = $this->authorise( self::TEST_ACTION );
		if ( null === $id ) {
			return false;
		}

		$instance = $this->listed()[ $id ] ?? null;
		if ( null === $instance ) {
			$this->notices->add( false, $this->no_such_channel() );
			return true;
		}

		$channel = $this->registry->get( $instance->get_type() );
		if ( null === $channel || ! $channel->supports( 'test_message' ) ) {
			// translators: %s: Label of the channel.
			$this->notices->add( false, sprintf( __( '%s cannot send a test message.', 'wp-updates-notifier' ), $instance->get_label() ) );
			return true;
		}

		$notification = new Notification(
			get_bloginfo(),
			home_url(),
			admin_url( 'update-core.php' ),
			array(),
			array(
				'is_test' => true,
				'source'  => 'test',
			)
		);
		$result       = $this->dispatcher->send_one( $notification, $instance );

		if ( $result->is_success() ) {
			// translators: %s: Name of the channel.
			$this->notices->add( true, sprintf( __( 'Test message sent through %s.', 'wp-updates-notifier' ), $instance->get_label() ) );
		} else {
			// translators: 1: Name of the channel. 2: Why it failed.
			$this->notices->add( false, sprintf( __( 'The test message could not be sent through %1$s: %2$s', 'wp-updates-notifier' ), $instance->get_label(), $result->get_message() ) );
		}

		return true;
	}

	/**
	 * Check a request to switch an instance on or off and do it.
	 *
	 * @return bool False if the user may not manage the plugin or the nonce is not valid.
	 */
	public function process_toggle(): bool {
		$id = $this->authorise( self::TOGGLE_ACTION );
		if ( null === $id ) {
			return false;
		}

		$instance = $this->changeable( $id );
		if ( null === $instance ) {
			return true;
		}

		$source = $this->store->external_source( $id, 'enabled' );
		if ( null !== $source ) {
			// translators: 1: Label of the channel. 2: Where the value comes from, for example "constant SC_WPUN_CHANNEL_EMAIL__ENABLED".
			$this->notices->add( false, sprintf( __( '%1$s cannot be switched here: set by %2$s.', 'wp-updates-notifier' ), $instance->get_label(), $source ) );
			return true;
		}

		// From the stored instance: the effective one has outside values, secrets included, laid over it.
		$stored = $this->store->stored_all()[ $id ] ?? null;
		if ( null === $stored ) {
			$this->notices->add( false, $this->no_such_channel() );
			return true;
		}

		$enabled = ! $instance->is_enabled();
		$this->store->save( $stored->with_enabled( $enabled ) );
		$this->notices->add(
			true,
			$enabled
				// translators: %s: Label of the channel.
				? sprintf( __( '%s enabled.', 'wp-updates-notifier' ), $instance->get_label() )
				// translators: %s: Label of the channel.
				: sprintf( __( '%s disabled.', 'wp-updates-notifier' ), $instance->get_label() )
		);

		return true;
	}

	/**
	 * Check a request to duplicate an instance and do it. The copy starts switched off.
	 *
	 * @return bool False if the user may not manage the plugin or the nonce is not valid.
	 */
	public function process_duplicate(): bool {
		$id = $this->authorise( self::DUPLICATE_ACTION );
		if ( null === $id ) {
			return false;
		}

		$instance = $this->changeable( $id );
		if ( null === $instance ) {
			return true;
		}

		// From the stored instance: saving the effective one under a new id would write outside values, such as wp-config secrets, to the database.
		$stored = $this->store->stored_all()[ $id ] ?? null;
		if ( null === $stored ) {
			$this->notices->add( false, $this->no_such_channel() );
			return true;
		}

		$taken  = array_merge( array_keys( $this->store->stored_all() ), array_keys( $this->store->all() ) );
		$new_id = $id . '_copy';
		$n      = 2;
		while ( in_array( $new_id, $taken, true ) ) {
			$new_id = $id . '_copy_' . $n;
			++$n;
		}

		$copy = new Channel_Instance(
			$new_id,
			$stored->get_type(),
			/* translators: %s: Label of the channel that is copied. */
			sprintf( __( '%s (copy)', 'wp-updates-notifier' ), $stored->get_label() ),
			false,
			$stored->get_settings(),
			$stored->get_routing()
		);
		$this->store->save( $copy );
		// translators: %s: Label of the new channel.
		$this->notices->add( true, sprintf( __( 'Channel duplicated as %s.', 'wp-updates-notifier' ), $copy->get_label() ) );

		return true;
	}

	/**
	 * Check a request to delete an instance and do it.
	 *
	 * @return bool False if the user may not manage the plugin or the nonce is not valid.
	 */
	public function process_delete(): bool {
		$id = $this->authorise( self::DELETE_ACTION );
		if ( null === $id ) {
			return false;
		}

		$instance = $this->changeable( $id );
		if ( null === $instance ) {
			return true;
		}

		if ( $this->store->delete( $id ) ) { // Fires sc_wpun_channel_deleted, which makes State forget it.
			// translators: %s: Label of the channel.
			$this->notices->add( true, sprintf( __( '%s deleted.', 'wp-updates-notifier' ), $instance->get_label() ) );
		} else {
			$this->notices->add( false, $this->no_such_channel() );
		}

		return true;
	}

	/**
	 * The instance an action that changes it may work on; says why not, with a notice, if there is none.
	 *
	 * @param string $id Instance id.
	 *
	 * @return Channel_Instance|null Null if it is not listed, or defined in code.
	 */
	private function changeable( string $id ): ?Channel_Instance {
		$instance = $this->listed()[ $id ] ?? null;
		if ( null === $instance ) {
			$this->notices->add( false, $this->no_such_channel() );
			return null;
		}
		if ( $this->store->is_code_defined( $id ) ) {
			// translators: %s: Label of the channel.
			$this->notices->add( false, sprintf( __( '%s is defined in code and cannot be changed here.', 'wp-updates-notifier' ), $instance->get_label() ) );
			return null;
		}

		return $instance;
	}

	/**
	 * Handle the send test form (admin-post.php), then go back to the tab.
	 *
	 * @return void
	 */
	public function handle_test_send(): void {
		$this->finish( $this->process_test_send() );
	}

	/**
	 * Handle the enable/disable form (admin-post.php), then go back to the tab.
	 *
	 * @return void
	 */
	public function handle_toggle(): void {
		$this->finish( $this->process_toggle() );
	}

	/**
	 * Handle the duplicate form (admin-post.php), then go back to the tab.
	 *
	 * @return void
	 */
	public function handle_duplicate(): void {
		$this->finish( $this->process_duplicate() );
	}

	/**
	 * Handle the delete form (admin-post.php), then go back to the tab.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		$this->finish( $this->process_delete() );
	}

	/**
	 * Answer 403 if the request was refused, else go back to the tab.
	 *
	 * @param bool $allowed What the process method returned.
	 *
	 * @return void
	 */
	private function finish( bool $allowed ): void {
		if ( ! $allowed ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'wp-updates-notifier' ), '', array( 'response' => 403 ) );
		}

		wp_safe_redirect( $this->tab_url() );
		exit;
	}
}
