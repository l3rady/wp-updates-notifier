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
			$who  = '' === $who ? ucfirst( $from ) : sprintf( /* translators: 1: Name of the user who muted. 2: "from" and where the mute was set from. */ __( '%1$s, %2$s', 'wp-updates-notifier' ), $who, $from );
		}
		if ( '' === $who || '' === $when ) {
			return '' === $who ? $when : $who;
		}

		// translators: 1: Name of the user who muted, and where from. 2: Date and time.
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
			<p>
				<label for="sc-wpun-mute-snooze-until"><?php esc_html_e( 'Snooze until', 'wp-updates-notifier' ); ?></label>
				<input type="datetime-local" id="sc-wpun-mute-snooze-until" name="snooze_until" aria-describedby="sc-wpun-mute-snooze-description" />
			</p>
			<p class="description" id="sc-wpun-mute-snooze-description"><?php esc_html_e( 'Optional, in the time zone of the site. Notifications start again at that time. With "Skip the waiting version" they start again at that time or when a newer version is offered, whichever is first.', 'wp-updates-notifier' ); ?></p>
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
