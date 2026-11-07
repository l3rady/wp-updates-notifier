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
use WPUN\Network_Context;
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

	// The arguments of a link that opens the tab with the form filled in. `sc_wpun_mute_url()` builds the link.
	const ARG_TYPE   = 'mute_type';
	const ARG_KEY    = 'mute_key';
	const ARG_MODE   = 'mute_mode';
	const ARG_SNOOZE = 'mute_snooze';

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
	 * Where the plugin runs.
	 *
	 * @var Network_Context
	 */
	private $network_context;

	/**
	 * Constructor.
	 *
	 * @param Mute_Store           $store   Mute storage.
	 * @param Mute_Actions         $actions What muting shares with the Plugins screen.
	 * @param Notices              $notices Notices shown after an action.
	 * @param Status_Report        $report  The report that lists the mutes.
	 * @param Network_Context|null $network_context Where the plugin runs.
	 */
	public function __construct( Mute_Store $store, Mute_Actions $actions, Notices $notices, Status_Report $report, ?Network_Context $network_context = null ) {
		$this->store           = $store;
		$this->actions         = $actions;
		$this->notices         = $notices;
		$this->report          = $report;
		$this->network_context = $network_context ?? new Network_Context();
	}

	/**
	 * The address of the tab.
	 *
	 * @return string
	 */
	private function tab_url(): string {
		return $this->network_context->settings_url( 'muted' );
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
				'<a href="' . esc_url( $this->network_context->admin_page_url( 'plugins.php' ) ) . '">' . esc_html__( 'Plugins screen', 'wp-updates-notifier' ) . '</a>'
			),
			array( 'a' => array( 'href' => array() ) )
		) . '</p>';
	}

	/**
	 * Output the table of mutes.
	 *
	 * @param array<int, array{type: string, key: string, name: ?string, until: ?string, from_code: bool, time: int, user: int, offered: ?string, expires: ?int, source: string}> $rows The mutes.
	 * @param bool                                                                                                                                                                $can  Whether the user may unmute.
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
	 * Who muted an item, where from and when.
	 *
	 * @param array{from_code: bool, time: int, user: int, source: string} $row The mute.
	 *
	 * @return string
	 */
	private static function muted_by( array $row ): string {
		if ( $row['from_code'] ) {
			return __( 'Set in code', 'wp-updates-notifier' );
		}

		$when = $row['time'] > 0 ? Settings_Page::format_site_time( $row['time'] ) : '';
		$who  = '';
		if ( $row['user'] > 0 ) {
			$user = get_userdata( $row['user'] );
			$who  = false === $user ? __( 'Unknown user', 'wp-updates-notifier' ) : $user->display_name;
		}

		$from = Mute_Store::source_label( $row['source'] );
		if ( '' !== $from ) {
			// translators: %s: Where the mute was set from, for example "WP-CLI".
			$from = sprintf( __( 'from %s', 'wp-updates-notifier' ), $from );
			$who  = '' === $who ? ucfirst( $from ) : sprintf( /* translators: 1: First part, such as the name of the user who muted. 2: Next part, such as where the mute was set from, or the date and time. */ __( '%1$s, %2$s', 'wp-updates-notifier' ), $who, $from );
		}
		if ( '' === $who || '' === $when ) {
			return '' === $who ? $when : $who;
		}

		// translators: 1: First part, such as the name of the user who muted. 2: Next part, such as where the mute was set from, or the date and time.
		return sprintf( __( '%1$s, %2$s', 'wp-updates-notifier' ), $who, $when );
	}

	/**
	 * What a mute covers: always, a version, a time, or a version for at most a time.
	 *
	 * @param array{until: ?string, expires: ?int} $row The mute.
	 *
	 * @return string
	 */
	private static function mute_text( array $row ): string {
		if ( null === $row['expires'] ) {
			return null === $row['until'] ? __( 'Always', 'wp-updates-notifier' ) : sprintf( /* translators: %s: Version that is skipped. */ __( 'Version %s', 'wp-updates-notifier' ), $row['until'] );
		}

		$when = Settings_Page::format_site_time( $row['expires'] );
		if ( null === $row['until'] ) {
			// translators: %s: Date and time the snooze ends.
			return sprintf( __( 'Snoozed until %s', 'wp-updates-notifier' ), $when );
		}

		// translators: 1: Version that is skipped. 2: Date and time the mute ends at the latest.
		return sprintf( __( 'Version %1$s, until %2$s', 'wp-updates-notifier' ), $row['until'], $when );
	}

	/**
	 * The id of a row of the list, which the note about a muted item links to. Never the raw key.
	 *
	 * @param string $type Item type.
	 * @param string $key  Item key.
	 *
	 * @return string
	 */
	private static function row_id( string $type, string $key ): string {
		return 'sc-wpun-muted-' . $type . '-' . substr( md5( $key ), 0, 12 );
	}

	/**
	 * Output a row of the list.
	 *
	 * @param array{type: string, key: string, name: ?string, until: ?string, from_code: bool, time: int, user: int, offered: ?string, expires: ?int, source: string} $row The mute.
	 * @param bool                                                                                                                                                    $can Whether the user may unmute.
	 *
	 * @return void
	 */
	private function render_row( array $row, bool $can ): void {
		$title = $row['name'] ?? $row['key'];
		?>
		<tr class="sc-wpun-muted-item" id="<?php echo esc_attr( self::row_id( $row['type'], $row['key'] ) ); ?>">
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
				echo esc_html( self::mute_text( $row ) );
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
		$installed = $this->actions->installed();
		$groups    = array();
		foreach ( $installed as $type => $items ) {
			foreach ( $items as $key => $name ) {
				if ( null === $this->store->get( $type, $key ) ) {
					$groups[ $type ][ $key ] = $name;
				}
			}
		}

		// A link can fill the form in. Only the type and key are looked up, in the installed list; the name shown is the list's.
		$linked  = $this->linked_item( $installed );
		$prefill = null !== $linked && isset( $groups[ $linked[0] ][ $linked[1] ] ) ? $this->prefill() : null;
		if ( null !== $linked && null === $prefill ) {
			$this->render_muted_note( $linked[0], $linked[1], $installed[ $linked[0] ][ $linked[1] ] );
		}

		if ( array() === $groups ) {
			?>
			<section class="sc-wpun-panel">
				<h3 class="sc-wpun-panel-title"><?php esc_html_e( 'Mute a plugin or theme', 'wp-updates-notifier' ); ?></h3>
				<p class="sc-wpun-all-muted"><?php esc_html_e( 'Every installed plugin and theme is muted.', 'wp-updates-notifier' ); ?></p>
			</section>
			<?php
			return;
		}
		?>
		<form class="sc-wpun-mute-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ADD_ACTION ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( self::ADD_ACTION ) ); ?>" />
			<fieldset class="sc-wpun-panel">
				<legend><h3><?php esc_html_e( 'Mute a plugin or theme', 'wp-updates-notifier' ); ?></h3></legend>
				<?php if ( null !== $prefill ) : ?>
					<div class="notice notice-info inline sc-wpun-prefill-note" id="sc-wpun-mute-prefilled">
						<p>
							<?php
							echo esc_html(
								sprintf(
									// translators: %s: Name of the plugin or theme.
									__( 'Pre-filled from a link: %s. Check and press Mute.', 'wp-updates-notifier' ),
									$installed[ $linked[0] ][ $linked[1] ]
								)
							);
							?>
						</p>
					</div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="sc-wpun-mute-item"><?php esc_html_e( 'Plugin or theme', 'wp-updates-notifier' ); ?></label></th>
							<td>
								<select id="sc-wpun-mute-item" name="item"<?php echo null === $prefill ? '' : ' autofocus="autofocus" aria-describedby="sc-wpun-mute-prefilled"'; ?>>
									<?php foreach ( array( 'plugin' => __( 'Plugins', 'wp-updates-notifier' ), 'theme' => __( 'Themes', 'wp-updates-notifier' ) ) as $type => $label ) : // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound ?>
										<?php if ( isset( $groups[ $type ] ) ) : ?>
											<optgroup label="<?php echo esc_attr( $label ); ?>">
												<?php foreach ( $groups[ $type ] as $key => $name ) : ?>
													<option value="<?php echo esc_attr( $type . ':' . $key ); ?>"<?php selected( null !== $linked && null !== $prefill && array( $type, (string) $key ) === $linked ); ?>><?php echo esc_html( $name ); ?></option>
												<?php endforeach; ?>
											</optgroup>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'What to mute', 'wp-updates-notifier' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'What to mute', 'wp-updates-notifier' ); ?></legend>
									<label><input type="radio" name="mode" value="always"<?php checked( null === $prefill || 'skip' !== $prefill['mode'] ); ?> /> <?php esc_html_e( 'Always', 'wp-updates-notifier' ); ?></label><br />
									<label><input type="radio" name="mode" value="skip"<?php checked( null !== $prefill && 'skip' === $prefill['mode'] ); ?> aria-describedby="sc-wpun-mute-skip-description" /> <?php esc_html_e( 'Skip the waiting version', 'wp-updates-notifier' ); ?></label>
									<p class="description" id="sc-wpun-mute-skip-description"><?php esc_html_e( 'Notifications start again when a newer version than the waiting one is offered. It needs an update to be waiting.', 'wp-updates-notifier' ); ?></p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sc-wpun-mute-snooze-until"><?php esc_html_e( 'Snooze until', 'wp-updates-notifier' ); ?></label></th>
							<td>
								<input type="datetime-local" id="sc-wpun-mute-snooze-until" name="snooze_until"<?php echo null === $prefill || '' === $prefill['snooze'] ? '' : ' value="' . esc_attr( $prefill['snooze'] ) . '"'; ?> aria-describedby="sc-wpun-mute-snooze-description" />
								<p class="description" id="sc-wpun-mute-snooze-description"><?php esc_html_e( 'Optional, in the time zone of the site. Notifications start again at that time. With "Skip the waiting version" they start again at that time or when a newer version is offered, whichever is first.', 'wp-updates-notifier' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</fieldset>
			<p class="submit"><button type="submit" class="button button-primary"><?php echo esc_html_x( 'Mute', 'button that mutes a plugin or theme', 'wp-updates-notifier' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * The plugin or theme a link points to, if it is installed.
	 *
	 * Reads the request to choose what to show, nothing is changed. Nothing read is output: the caller uses the
	 * installed list's own spelling and name.
	 *
	 * @param array{plugin: array<string, string>, theme: array<string, string>} $installed The installed items.
	 *
	 * @return array{0: string, 1: string}|null Type and key.
	 */
	private function linked_item( array $installed ): ?array {
		if ( ! isset( $_GET[ self::ARG_TYPE ], $_GET[ self::ARG_KEY ] ) || ! is_string( $_GET[ self::ARG_TYPE ] ) || ! is_string( $_GET[ self::ARG_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing what to display, nothing is changed.
			return null;
		}

		$type = sanitize_key( wp_unslash( $_GET[ self::ARG_TYPE ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing what to display, nothing is changed.
		$key  = sanitize_text_field( wp_unslash( $_GET[ self::ARG_KEY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Not sanitize_key(): it would strip the "/" and "." of a plugin file.
		if ( ! isset( $installed[ $type ][ $key ] ) ) {
			return null;
		}

		return array( $type, $key );
	}

	/**
	 * What a link asks the form to hold: the mode, and the end of the snooze.
	 *
	 * @return array{mode: string, snooze: string} The mode `always` or `skip`; the snooze as `Y-m-d\TH:i` in the site's time zone, or empty.
	 */
	private function prefill(): array {
		$mode = isset( $_GET[ self::ARG_MODE ] ) && is_string( $_GET[ self::ARG_MODE ] ) ? sanitize_key( wp_unslash( $_GET[ self::ARG_MODE ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing what to display, nothing is changed.
		$mode = in_array( $mode, array( 'always', 'skip', 'snooze' ), true ) ? $mode : 'always';

		$snooze = '';
		if ( 'snooze' === $mode && isset( $_GET[ self::ARG_SNOOZE ] ) && is_string( $_GET[ self::ARG_SNOOZE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing what to display, nothing is changed.
			$length = sanitize_text_field( wp_unslash( $_GET[ self::ARG_SNOOZE ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing what to display, nothing is changed.
			if ( ctype_digit( $length ) && (string) (int) $length === $length && isset( Mute_Store::snooze_durations()[ (int) $length ] ) ) {
				$snooze = (string) wp_date( 'Y-m-d\TH:i', time() + (int) $length, wp_timezone() );
			}
		}

		return array(
			'mode'   => 'skip' === $mode ? 'skip' : 'always',
			'snooze' => $snooze,
		);
	}

	/**
	 * Output the note that a linked item is muted already, with a link to its row.
	 *
	 * @param string $type Item type.
	 * @param string $key  Item key.
	 * @param string $name Name of the item, from the installed list.
	 *
	 * @return void
	 */
	private function render_muted_note( string $type, string $key, string $name ): void {
		?>
		<div class="notice notice-info inline sc-wpun-muted-note">
			<p>
				<?php
				echo esc_html(
					sprintf(
						// translators: %s: Name of the plugin or theme.
						__( '%s is already muted.', 'wp-updates-notifier' ),
						$name
					)
				);
				?>
				<a href="#<?php echo esc_attr( self::row_id( $type, $key ) ); ?>"><?php esc_html_e( 'See it in the list.', 'wp-updates-notifier' ); ?></a>
			</p>
		</div>
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
		if ( ! $this->may() || ! Settings_Page::posted_token_valid( self::ADD_ACTION ) ) {
			return false;
		}

		$item = explode( ':', $this->field( 'item' ), 2 );
		if ( 2 !== count( $item ) ) {
			$this->notices->add( false, __( 'Choose a plugin or theme to mute.', 'wp-updates-notifier' ) );
			return true;
		}

		$expires = null;
		$snooze  = $this->field( 'snooze_until' );
		if ( '' !== $snooze ) {
			$expires = self::parse_snooze( $snooze );
			if ( null === $expires ) {
				$this->notices->add( false, __( 'Enter the end of the snooze as a date and time.', 'wp-updates-notifier' ) );
				return true;
			}
			if ( $expires <= time() ) {
				$this->notices->add( false, __( 'The end of the snooze must be in the future.', 'wp-updates-notifier' ) );
				return true;
			}
		}

		$this->notices->add( ...$this->actions->mute( $item[0], $item[1], self::SOURCE, 'skip' === $this->field( 'mode' ), null, $expires ) );

		return true;
	}

	/**
	 * The time a date and time field holds, in the site's timezone.
	 *
	 * @param string $value Value of a `datetime-local` field, `YYYY-MM-DDTHH:MM` with optional seconds.
	 *
	 * @return int|null Unix timestamp; null if it is not a real date and time.
	 */
	private static function parse_snooze( string $value ): ?int {
		foreach ( array( '!Y-m-d\TH:i', '!Y-m-d\TH:i:s' ) as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( $format, $value, wp_timezone() );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( false !== $parsed && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $parsed->getTimestamp();
			}
		}

		return null;
	}

	/**
	 * Check a request to unmute a plugin or theme and do it.
	 *
	 * @return bool False if the user may not do it or the security token is not valid.
	 */
	public function process_remove(): bool {
		$type = $this->field( 'type' );
		$key  = $this->field( 'key' );
		if ( ! $this->may() || '' === $type || '' === $key || ! Settings_Page::posted_token_valid( self::REMOVE_ACTION . '|' . $type . '|' . $key ) ) {
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
		Settings_Page::finish( $allowed ? $this->tab_url() : null );
	}
}
