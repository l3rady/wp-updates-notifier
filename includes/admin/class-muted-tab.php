<?php
/**
 * The Muted tab of the settings screen.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Mute_Store;
use WPUN\Status_Report;

/**
 * Lists the muted plugins and themes, unmutes them, and mutes a plugin or theme from a form.
 *
 * Themes can be muted here and from WP-CLI only: the Themes screen has no place for a link. The forms POST to
 * `admin-post.php`, like those of the Channels tab. Seeing the tab needs the settings capability; the forms and their
 * handlers also need the mute capability (`sc_wpun_mute_capability`).
 *
 * @internal
 */
class Muted_Tab {
	const ADD_ACTION    = 'sc_wpun_muted_add';
	const REMOVE_ACTION = 'sc_wpun_muted_remove';
	const SOURCE        = 'settings';

	/**
	 * Mute storage.
	 *
	 * @var Mute_Store
	 */
	private $store;

	/**
	 * What muting shares with the Plugins screen.
	 *
	 * @var Mute_Actions
	 */
	private $actions;

	/**
	 * Notices shown after an action.
	 *
	 * @var Notices
	 */
	private $notices;

	/**
	 * The report that lists the mutes.
	 *
	 * @var Status_Report
	 */
	private $report;

	/**
	 * Constructor.
	 *
	 * @param Mute_Store    $store   Mute storage.
	 * @param Mute_Actions  $actions What muting shares with the Plugins screen.
	 * @param Notices       $notices Notices shown after an action.
	 * @param Status_Report $report  The report that lists the mutes.
	 */
	public function __construct( Mute_Store $store, Mute_Actions $actions, Notices $notices, Status_Report $report ) {
		$this->store   = $store;
		$this->actions = $actions;
		$this->notices = $notices;
		$this->report  = $report;
	}

	/**
	 * The address of the tab.
	 *
	 * @return string
	 */
	private function tab_url(): string {
		return admin_url( 'options-general.php?page=wp-updates-notifier&tab=muted' );
	}

	/**
	 * Output the tab: the list of mutes and the form to mute something.
	 *
	 * @return void
	 */
	public function render(): void {
		$rows = $this->report->muted();
		$can  = Mute_Actions::can_mute();
		?>
		<h2><?php esc_html_e( 'Muted', 'wp-updates-notifier' ); ?></h2>
		<p><?php esc_html_e( 'A muted plugin or theme is left out of the update notifications. WordPress\'s own update notices, emails and automatic updates are not affected.', 'wp-updates-notifier' ); ?></p>
		<?php
		if ( array() === $rows ) {
			$this->render_empty();
		} else {
			$this->render_list( $rows, $can );
		}
		if ( $can ) {
			$this->render_form();
		}
	}

	/**
	 * Output what to do when nothing is muted.
	 *
	 * @return void
	 */
	private function render_empty(): void {
		echo '<p class="sc-wpun-no-muted">' . wp_kses(
			sprintf(
				// translators: %s: Link to the Plugins screen, with the text "Plugins screen".
				__( 'Nothing is muted. Muting a plugin or theme stops the update notifications about it, always or for the version that is waiting. Mute a plugin from the %s, or a plugin or theme with the form below.', 'wp-updates-notifier' ),
				'<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Plugins screen', 'wp-updates-notifier' ) . '</a>'
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>';
	}

	/**
	 * Output the table of mutes.
	 *
	 * @param array<int, array{type: string, key: string, name: ?string, until: ?string, from_code: bool, time: int, user: int, offered: ?string}> $rows The mutes.
	 * @param bool                                                                                                                                 $can  Whether the user may unmute.
	 *
	 * @return void
	 */
	private function render_list( array $rows, bool $can ): void {
		?>
		<table class="wp-list-table widefat fixed striped table-view-list sc-wpun-muted">
			<caption class="screen-reader-text"><?php esc_html_e( 'Muted plugins and themes', 'wp-updates-notifier' ); ?></caption>
			<thead>
				<tr>
					<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Name', 'wp-updates-notifier' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Mute', 'wp-updates-notifier' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Muted by', 'wp-updates-notifier' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Status', 'wp-updates-notifier' ); ?></th>
					<th scope="col" class="manage-column"><?php esc_html_e( 'Unmute', 'wp-updates-notifier' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $this->render_row( $row, $can ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The kind of item, for people.
	 *
	 * @param string $type Item type.
	 *
	 * @return string
	 */
	private static function type_label( string $type ): string {
		if ( 'plugin' === $type ) {
			return __( 'Plugin', 'wp-updates-notifier' );
		}
		if ( 'theme' === $type ) {
			return __( 'Theme', 'wp-updates-notifier' );
		}

		return $type;
	}

	/**
	 * Who muted an item and when.
	 *
	 * @param array{from_code: bool, time: int, user: int} $row The mute.
	 *
	 * @return string
	 */
	private static function muted_by( array $row ): string {
		if ( $row['from_code'] ) {
			return __( 'Set in code', 'wp-updates-notifier' );
		}

		$when = $row['time'] > 0 ? Settings_Page::format_site_time( $row['time'] ) : '';
		if ( $row['user'] <= 0 ) {
			return $when;
		}

		$user = get_userdata( $row['user'] );
		$who  = false === $user ? __( 'Unknown user', 'wp-updates-notifier' ) : $user->display_name;
		if ( '' === $when ) {
			return $who;
		}

		// translators: 1: Name of the user who muted. 2: Date and time.
		return sprintf( __( '%1$s, %2$s', 'wp-updates-notifier' ), $who, $when );
	}

	/**
	 * Output a row of the list.
	 *
	 * @param array{type: string, key: string, name: ?string, until: ?string, from_code: bool, time: int, user: int, offered: ?string} $row The mute.
	 * @param bool                                                                                                                     $can Whether the user may unmute.
	 *
	 * @return void
	 */
	private function render_row( array $row, bool $can ): void {
		$title = $row['name'] ?? $row['key'];
		?>
		<tr class="sc-wpun-muted-item">
			<td class="column-primary sc-wpun-muted-name" data-colname="<?php esc_attr_e( 'Name', 'wp-updates-notifier' ); ?>">
				<strong><?php echo esc_html( $title ); ?></strong>
				<span class="description">
					<?php
					echo esc_html(
						null === $row['name']
							? sprintf( /* translators: %s: Kind of item, for example "Plugin". */ __( '%s, not installed', 'wp-updates-notifier' ), self::type_label( $row['type'] ) )
							: self::type_label( $row['type'] )
					);
					?>
				</span>
				<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'wp-updates-notifier' ); ?></span></button>
			</td>
			<td class="sc-wpun-muted-mute" data-colname="<?php esc_attr_e( 'Mute', 'wp-updates-notifier' ); ?>">
				<?php
				echo esc_html( null === $row['until'] ? __( 'Always', 'wp-updates-notifier' ) : sprintf( /* translators: %s: Version that is skipped. */ __( 'Version %s', 'wp-updates-notifier' ), $row['until'] ) );
				?>
			</td>
			<td class="sc-wpun-muted-by" data-colname="<?php esc_attr_e( 'Muted by', 'wp-updates-notifier' ); ?>"><?php echo esc_html( self::muted_by( $row ) ); ?></td>
			<td class="sc-wpun-muted-status" data-colname="<?php esc_attr_e( 'Status', 'wp-updates-notifier' ); ?>">
				<?php
				echo esc_html( null === $row['offered'] ? __( 'No update waiting', 'wp-updates-notifier' ) : sprintf( /* translators: %s: Version of the update that is waiting. */ __( 'Update to %s waiting', 'wp-updates-notifier' ), $row['offered'] ) );
				?>
			</td>
			<td class="sc-wpun-muted-unmute" data-colname="<?php esc_attr_e( 'Unmute', 'wp-updates-notifier' ); ?>">
				<?php if ( $row['from_code'] || ! $can ) : ?>
					<span aria-hidden="true">&mdash;</span>
					<span class="screen-reader-text"><?php echo esc_html( $row['from_code'] ? __( 'Set in code, it cannot be unmuted here', 'wp-updates-notifier' ) : __( 'You may not unmute', 'wp-updates-notifier' ) ); ?></span>
				<?php else : ?>
					<form class="sc-wpun-row-action" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::REMOVE_ACTION ); ?>" />
						<input type="hidden" name="type" value="<?php echo esc_attr( $row['type'] ); ?>" />
						<input type="hidden" name="key" value="<?php echo esc_attr( $row['key'] ); ?>" />
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::REMOVE_ACTION . '|' . $row['type'] . '|' . $row['key'] ) ); ?>" />
						<?php
						/* translators: %s: Name of the plugin or theme. */
						$unmute_label = sprintf( __( 'Unmute %s', 'wp-updates-notifier' ), $title );
						?>
						<button type="submit" class="button-link" aria-label="<?php echo esc_attr( $unmute_label ); ?>"><?php esc_html_e( 'Unmute', 'wp-updates-notifier' ); ?></button>
					</form>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Output the form that mutes a plugin or theme.
	 *
	 * @return void
	 */
	private function render_form(): void {
		$groups = array();
		foreach ( $this->actions->installed() as $type => $items ) {
			foreach ( $items as $key => $name ) {
				if ( null === $this->store->get( $type, $key ) ) {
					$groups[ $type ][ $key ] = $name;
				}
			}
		}
		?>
		<h3><?php esc_html_e( 'Mute a plugin or theme', 'wp-updates-notifier' ); ?></h3>
		<?php
		if ( array() === $groups ) {
			echo '<p class="sc-wpun-all-muted">' . esc_html__( 'Every installed plugin and theme is muted.', 'wp-updates-notifier' ) . '</p>';
			return;
		}
		?>
		<form class="sc-wpun-mute-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ADD_ACTION ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::ADD_ACTION ) ); ?>" />
			<p>
				<label for="sc-wpun-mute-item"><?php esc_html_e( 'Plugin or theme', 'wp-updates-notifier' ); ?></label>
				<select id="sc-wpun-mute-item" name="item">
					<?php foreach ( array( 'plugin' => __( 'Plugins', 'wp-updates-notifier' ), 'theme' => __( 'Themes', 'wp-updates-notifier' ) ) as $type => $label ) : // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound ?>
						<?php if ( isset( $groups[ $type ] ) ) : ?>
							<optgroup label="<?php echo esc_attr( $label ); ?>">
								<?php foreach ( $groups[ $type ] as $key => $name ) : ?>
									<option value="<?php echo esc_attr( $type . ':' . $key ); ?>"><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</p>
			<fieldset>
				<legend><?php esc_html_e( 'What to mute', 'wp-updates-notifier' ); ?></legend>
				<p>
					<label><input type="radio" name="mode" value="always" checked="checked" /> <?php esc_html_e( 'Always', 'wp-updates-notifier' ); ?></label><br />
					<label><input type="radio" name="mode" value="skip" aria-describedby="sc-wpun-mute-skip-description" /> <?php esc_html_e( 'Skip the waiting version', 'wp-updates-notifier' ); ?></label>
				</p>
				<p class="description" id="sc-wpun-mute-skip-description"><?php esc_html_e( 'Notifications start again when a newer version than the waiting one is offered. It needs an update to be waiting.', 'wp-updates-notifier' ); ?></p>
			</fieldset>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Mute', 'wp-updates-notifier' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Whether the current request may change mutes from this tab: the user holds both capabilities.
	 *
	 * @return bool
	 */
	private function may(): bool {
		return current_user_can( Settings_Page::capability() ) && Mute_Actions::can_mute();
	}

	/**
	 * Whether the security token of the request is valid for the action.
	 *
	 * @param string $action The token action.
	 *
	 * @return bool
	 */
	private function token_valid( string $action ): bool {
		if ( ! isset( $_POST['_wpnonce'] ) || ! is_string( $_POST['_wpnonce'] ) ) {
			return false;
		}

		return false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), $action );
	}

	/**
	 * A field of the form as text.
	 *
	 * @param string $name Field name.
	 *
	 * @return string Empty if it is missing.
	 */
	private function field( string $name ): string {
		return isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read after the token check, or to build the token's action.
	}

	/**
	 * Check a request to mute a plugin or theme and do it.
	 *
	 * @return bool False if the user may not do it or the security token is not valid.
	 */
	public function process_add(): bool {
		if ( ! $this->may() || ! $this->token_valid( self::ADD_ACTION ) ) {
			return false;
		}

		$item = explode( ':', $this->field( 'item' ), 2 );
		if ( 2 !== count( $item ) ) {
			$this->notices->add( false, __( 'Choose a plugin or theme to mute.', 'wp-updates-notifier' ) );
			return true;
		}

		$this->notices->add( ...$this->actions->mute( $item[0], $item[1], self::SOURCE, 'skip' === $this->field( 'mode' ) ) );

		return true;
	}

	/**
	 * Check a request to unmute a plugin or theme and do it.
	 *
	 * @return bool False if the user may not do it or the security token is not valid.
	 */
	public function process_remove(): bool {
		$type = $this->field( 'type' );
		$key  = $this->field( 'key' );
		if ( ! $this->may() || '' === $type || '' === $key || ! $this->token_valid( self::REMOVE_ACTION . '|' . $type . '|' . $key ) ) {
			return false;
		}

		$this->notices->add( ...$this->actions->unmute( $type, $key, self::SOURCE ) );

		return true;
	}

	/**
	 * Handle the form that mutes (admin-post.php), then go back to the tab.
	 *
	 * @return void
	 */
	public function handle_add(): void {
		$this->finish( $this->process_add() );
	}

	/**
	 * Handle the unmute form (admin-post.php), then go back to the tab.
	 *
	 * @return void
	 */
	public function handle_remove(): void {
		$this->finish( $this->process_remove() );
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
			$this->actions->deny();
			return;
		}

		wp_safe_redirect( $this->tab_url() );
		exit;
	}
}
