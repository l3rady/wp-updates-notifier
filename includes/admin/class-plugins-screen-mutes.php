<?php
/**
 * Muting from the Plugins screen: row actions, the update notice, bulk actions and their handlers.
 *
 * @package wp-updates-notifier
 */

namespace WPUN\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

use WPUN\Mute;
use WPUN\Mute_Store;
use WPUN\Network_Context;

/**
 * Adds the mute links to the Plugins screen, and handles them.
 *
 * The hooks are added while the Plugins screen loads (`load-plugins.php`), so no other request has them. The links
 * are GET links to `admin-post.php` with a security token for the action and the plugin, as core's Activate and
 * Deactivate links are: the rows are inside core's bulk form, and forms cannot be nested. Skipping a version has the
 * version in its token, so a link only skips the version it was made for. A snooze link has the length in seconds in
 * its token, and the handler accepts only lengths that `sc_wpun_snooze_durations` lists. Network-activated, only the network Plugins
 * screen has them, as the sites' own Plugins screens are not where the plugin is managed.
 *
 * @internal
 */
class Plugins_Screen_Mutes {
	const MUTE_ACTION   = 'sc_wpun_mute';
	const UNMUTE_ACTION = 'sc_wpun_unmute';
	const SKIP_ACTION   = 'sc_wpun_skip';
	const SNOOZE_ACTION = 'sc_wpun_snooze';
	const BULK_MUTE     = 'sc_wpun_bulk_mute';
	const BULK_UNMUTE   = 'sc_wpun_bulk_unmute';
	const SOURCE        = 'plugins_screen';

	/**
	 * Mute storage.
	 *
	 * @var Mute_Store
	 */
	private $store;

	/**
	 * What muting shares with the Muted tab.
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
	 * Where the plugin runs.
	 *
	 * @var Network_Context
	 */
	private $network_context;

	/**
	 * Constructor.
	 *
	 * @param Mute_Store           $store   Mute storage.
	 * @param Mute_Actions         $actions What muting shares with the Muted tab.
	 * @param Notices              $notices Notices shown after an action.
	 * @param Network_Context|null $network_context Where the plugin runs.
	 */
	public function __construct( Mute_Store $store, Mute_Actions $actions, Notices $notices, ?Network_Context $network_context = null ) {
		$this->store   = $store;
		$this->actions = $actions;
		$this->notices = $notices;

		$this->network_context = $network_context ?? new Network_Context();
	}

	/**
	 * Add the hooks of the Plugins screen. Hooked on `load-plugins.php`.
	 *
	 * @return void
	 */
	public function load(): void {
		$network = $this->network_context->is_network();
		if ( is_network_admin() !== $network ) {
			return; // Network-activated: the network admin's screen only. Else the site's.
		}

		$screen = $network ? 'plugins-network' : 'plugins';

		add_action( $network ? 'network_admin_notices' : 'admin_notices', array( $this->notices, 'render' ) );
		// Core runs the bulk action handler for any action it does not know; it checks the capability itself.
		add_filter( "handle_bulk_actions-{$screen}", array( $this, 'handle_bulk_actions' ), 10, 3 );

		if ( ! Mute_Actions::can_mute() ) {
			return;
		}

		// The network admin's plugin list runs its own filter, not plugin_action_links.
		add_filter( $network ? 'network_admin_plugin_action_links' : 'plugin_action_links', array( $this, 'action_links' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 4 );
		add_filter( "bulk_actions-{$screen}", array( $this, 'bulk_actions' ) );
		foreach ( array_keys( $this->waiting() ) as $file ) {
			add_action(
				"in_plugin_update_message-{$file}",
				function () use ( $file ): void {
					$this->update_message( $file );
				}
			);
		}
	}

	/**
	 * The plugins an update is waiting for, with the version.
	 *
	 * @return array<string, string>
	 */
	private function waiting(): array {
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return array();
		}

		$waiting = array();
		foreach ( array_keys( $transient->response ) as $file ) {
			$version = $this->store->offered_version( 'plugin', (string) $file );
			if ( null !== $version ) {
				$waiting[ (string) $file ] = $version;
			}
		}

		return $waiting;
	}

	/**
	 * The action of a security token, for the action and the plugin (and the version, when skipping it, or the seconds, when snoozing).
	 *
	 * @param string $action  The admin-post action.
	 * @param string $file    Plugin file.
	 * @param string $version The version the link skips, or the length of the snooze in seconds; empty for the other actions.
	 *
	 * @return string
	 */
	private static function token_action( string $action, string $file, string $version = '' ): string {
		return $action . '|plugin|' . $file . ( '' === $version ? '' : '|' . $version ); // A separator that cannot be in a file name or version keeps the parts apart.
	}

	/**
	 * The address of an action link.
	 *
	 * @param string $action  The admin-post action.
	 * @param string $file    Plugin file.
	 * @param string $version The version to skip, for the skip action.
	 * @param int    $seconds The length of the snooze, for the snooze action.
	 *
	 * @return string Not escaped for HTML.
	 */
	private function link_url( string $action, string $file, string $version = '', int $seconds = 0 ): string {
		$args = array(
			'action' => $action,
			'type'   => 'plugin',
			'key'    => rawurlencode( $file ), // add_query_arg() does not encode values; a + in a version would arrive as a space.
		);
		if ( '' !== $version ) {
			$args['version'] = rawurlencode( $version );
		}
		if ( $seconds > 0 ) {
			$args['seconds'] = (string) $seconds;
		}
		$args['_wpnonce'] = wp_create_nonce( self::token_action( $action, $file, $seconds > 0 ? (string) $seconds : $version ) );

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * A link to an action.
	 *
	 * @param string $action  The admin-post action.
	 * @param string $file    Plugin file.
	 * @param string $text    Link text.
	 * @param string $name    Accessible name, with the name of the plugin.
	 * @param string $class_name   CSS class.
	 * @param string $version The version to skip, for the skip action.
	 * @param int    $seconds The length of the snooze, for the snooze action.
	 *
	 * @return string The escaped markup.
	 */
	private function link( string $action, string $file, string $text, string $name, string $class_name, string $version = '', int $seconds = 0 ): string {
		return '<a class="' . esc_attr( $class_name ) . '" href="' . esc_url( $this->link_url( $action, $file, $version, $seconds ) ) . '" aria-label="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</a>';
	}

	/**
	 * Add the mute or unmute link to the action links of a plugin.
	 *
	 * @param mixed $actions The links.
	 * @param mixed $file    Plugin file.
	 * @param mixed $data    Plugin data.
	 * @param mixed $context The list the row is in.
	 *
	 * @return mixed
	 */
	public function action_links( $actions, $file = '', $data = array(), $context = '' ) {
		if ( ! is_array( $actions ) || ! is_string( $file ) || '' === $file || in_array( $context, array( 'mustuse', 'dropins' ), true ) ) {
			return $actions;
		}

		$plugin = is_array( $data ) && isset( $data['Name'] ) && is_string( $data['Name'] ) && '' !== $data['Name'] ? $data['Name'] : $file;
		$mute   = $this->store->get( 'plugin', $file );
		if ( null === $mute ) {
			// translators: %s: Name of the plugin.
			$name                    = sprintf( __( 'Mute update notifications for %s', 'wp-updates-notifier' ), $plugin );
			$actions['sc_wpun_mute'] = $this->link( self::MUTE_ACTION, $file, __( 'Mute update notifications', 'wp-updates-notifier' ), $name, 'sc-wpun-mute' );
		} elseif ( ! $mute->is_from_code() ) {
			// translators: %s: Name of the plugin.
			$name                      = sprintf( __( 'Unmute update notifications for %s', 'wp-updates-notifier' ), $plugin );
			$actions['sc_wpun_unmute'] = $this->link( self::UNMUTE_ACTION, $file, __( 'Unmute update notifications', 'wp-updates-notifier' ), $name, 'sc-wpun-unmute' );
		}

		return $actions;
	}

	/**
	 * Say in the row meta that a plugin is muted, and offer to skip or snooze the waiting version when core hides its notice.
	 *
	 * Core shows the update notice to users who can update plugins only, and takes that away with
	 * `DISALLOW_FILE_MODS`, so for the others the skip and snooze links and their undo are here.
	 *
	 * @param mixed $meta   The details.
	 * @param mixed $file   Plugin file.
	 * @param mixed $data   Plugin data.
	 * @param mixed $status The list the row is in.
	 *
	 * @return mixed
	 */
	public function row_meta( $meta, $file = '', $data = array(), $status = '' ) {
		if ( ! is_array( $meta ) || ! is_string( $file ) || '' === $file || in_array( $status, array( 'mustuse', 'dropins' ), true ) ) {
			return $meta;
		}

		$mute    = $this->store->get( 'plugin', $file );
		$offered = $this->store->offered_version( 'plugin', $file );
		$hidden  = null !== $offered && ! current_user_can( 'update_plugins' ); // Core shows no update notice.

		if ( null === $mute ) {
			if ( $hidden ) {
				$meta[] = $this->skip_link( $file, $offered );
				$meta[] = $this->snooze_links( $file );
			}

			return $meta;
		}

		$meta[] = '<span class="sc-wpun-muted-note">' . esc_html( $this->note( $mute ) ) . '</span>';
		if ( $hidden && $this->can_undo( $mute ) ) {
			$meta[] = $this->undo_link( $file, $mute );
		}

		return $meta;
	}

	/**
	 * What a mute says about itself.
	 *
	 * @param Mute $mute The mute.
	 *
	 * @return string
	 */
	private function note( Mute $mute ): string {
		$expires = $mute->get_expires();
		if ( null !== $expires && $mute->is_always() ) {
			// translators: %s: Date and time.
			$text = sprintf( __( 'Notifications snoozed until %s', 'wp-updates-notifier' ), Settings_Page::format_site_time( $expires ) );
		} elseif ( null !== $expires ) {
			// translators: 1: Version that is skipped. 2: Date and time.
			$text = sprintf( __( 'Notifications for version %1$s are skipped until %2$s', 'wp-updates-notifier' ), (string) $mute->get_until(), Settings_Page::format_site_time( $expires ) );
		} elseif ( $mute->is_always() ) {
			$text = __( 'Update notifications muted', 'wp-updates-notifier' );
		} else {
			// translators: %s: Version that is skipped.
			$text = sprintf( __( 'Notifications for version %s are skipped', 'wp-updates-notifier' ), (string) $mute->get_until() );
		}

		if ( $mute->is_from_code() ) {
			// translators: %s: What the mute says, for example "Update notifications muted".
			$text = sprintf( __( '%s (set in code)', 'wp-updates-notifier' ), $text );
		}

		return $text;
	}

	/**
	 * The link that skips a version.
	 *
	 * @param string $file    Plugin file.
	 * @param string $version The waiting version.
	 *
	 * @return string The escaped markup.
	 */
	private function skip_link( string $file, string $version ): string {
		// translators: %s: Version of the update that is waiting.
		$text = sprintf( __( 'Don\'t notify me about version %s', 'wp-updates-notifier' ), $version );

		return $this->link( self::SKIP_ACTION, $file, $text, $text, 'sc-wpun-skip', $version );
	}

	/**
	 * The links that snooze the notifications about a plugin, one for each length.
	 *
	 * @param string $file Plugin file.
	 *
	 * @return string The escaped markup; empty if no length is offered.
	 */
	private function snooze_links( string $file ): string {
		$links = array();
		foreach ( Mute_Store::snooze_durations() as $seconds => $label ) {
			// translators: %s: Length of the snooze, for example "1 week".
			$name    = sprintf( __( 'Snooze update notifications for %s', 'wp-updates-notifier' ), $label );
			$links[] = $this->link( self::SNOOZE_ACTION, $file, $label, $name, 'sc-wpun-snooze', '', $seconds );
		}
		if ( array() === $links ) {
			return '';
		}

		// translators: Label before the lengths of a snooze, for example "Snooze: 1 day · 1 week".
		return '<span class="sc-wpun-snooze-links">' . esc_html__( 'Snooze:', 'wp-updates-notifier' ) . ' ' . implode( ' &middot; ', $links ) . '</span>';
	}

	/**
	 * Whether a mute can be undone from the Plugins screen: a skipped version or a snooze that is not set in code.
	 *
	 * @param Mute $mute The mute.
	 *
	 * @return bool
	 */
	private function can_undo( Mute $mute ): bool {
		return ( ! $mute->is_always() || null !== $mute->get_expires() ) && ! $mute->is_from_code();
	}

	/**
	 * The link that undoes a skipped version or a snooze.
	 *
	 * @param string $file Plugin file.
	 * @param Mute   $mute The mute.
	 *
	 * @return string The escaped markup.
	 */
	private function undo_link( string $file, Mute $mute ): string {
		$name = null !== $mute->get_expires() ? __( 'Undo snoozing', 'wp-updates-notifier' ) : __( 'Undo skipping the version', 'wp-updates-notifier' );

		return $this->link( self::UNMUTE_ACTION, $file, __( 'Undo', 'wp-updates-notifier' ), $name, 'sc-wpun-unmute' );
	}

	/**
	 * Add to core's update notice of a plugin: the skip and snooze links, or what was skipped or snoozed and the undo. Inline: core prints it inside a paragraph.
	 *
	 * @param string $file Plugin file.
	 *
	 * @return void
	 */
	public function update_message( string $file ): void {
		$offered = $this->store->offered_version( 'plugin', $file );
		if ( null === $offered ) {
			return;
		}

		$mute = $this->store->get( 'plugin', $file );
		if ( null === $mute ) {
			echo ' ' . $this->skip_link( $file, $offered ) . ' ' . $this->snooze_links( $file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		} elseif ( $this->can_undo( $mute ) ) {
			if ( null !== $mute->get_expires() ) {
				echo ' ' . esc_html( $this->note( $mute ) . '.' ) . ' ';
			} else {
				// translators: %s: Version that is skipped.
				echo ' ' . esc_html( sprintf( __( 'Notifications for version %s are skipped.', 'wp-updates-notifier' ), (string) $mute->get_until() ) ) . ' ';
			}
			echo $this->undo_link( $file, $mute ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		}
	}

	/**
	 * Add the bulk actions.
	 *
	 * @param mixed $actions The bulk actions.
	 *
	 * @return mixed
	 */
	public function bulk_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		$actions[ self::BULK_MUTE ]   = __( 'Mute update notifications', 'wp-updates-notifier' );
		$actions[ self::BULK_UNMUTE ] = __( 'Unmute update notifications', 'wp-updates-notifier' );

		return $actions;
	}

	/**
	 * Mute or unmute the selected plugins (always) and tell the user how it went. Hooked on `handle_bulk_actions-plugins`, or `-plugins-network`.
	 *
	 * Core has checked its own security token for the bulk form; the capability is checked here.
	 *
	 * @param mixed $redirect_to Where to go afterwards.
	 * @param mixed $action      The bulk action.
	 * @param mixed $items       The selected plugin files.
	 *
	 * @return mixed
	 */
	public function handle_bulk_actions( $redirect_to, $action = '', $items = array() ) {
		if ( self::BULK_MUTE !== $action && self::BULK_UNMUTE !== $action ) {
			return $redirect_to;
		}
		if ( ! Mute_Actions::can_mute() ) {
			$this->actions->deny();
			return $redirect_to;
		}

		$mute     = self::BULK_MUTE === $action;
		$done     = 0;
		$problems = array();
		foreach ( is_array( $items ) ? $items : array() as $file ) {
			if ( ! is_string( $file ) ) {
				continue;
			}
			$current = $this->store->get( 'plugin', $file );
			if ( $mute ? ( null !== $current && $current->is_always() && ! $current->is_from_code() ) : null === $current ) {
				continue; // Nothing to change.
			}
			[$worked, $message] = $mute ? $this->actions->mute( 'plugin', $file, self::SOURCE ) : $this->actions->unmute( 'plugin', $file, self::SOURCE );
			if ( $worked ) {
				++$done;
			} else {
				$problems[ $message ] = $message;
			}
		}

		if ( $mute ) {
			// translators: %d: Number of plugins.
			$text = sprintf( _n( 'Update notifications muted for %d plugin.', 'Update notifications muted for %d plugins.', $done, 'wp-updates-notifier' ), $done );
		} else {
			// translators: %d: Number of plugins.
			$text = sprintf( _n( 'Update notifications are on again for %d plugin.', 'Update notifications are on again for %d plugins.', $done, 'wp-updates-notifier' ), $done );
		}
		if ( array() !== $problems ) {
			$text .= ' ' . implode( ' ', $problems );
		}
		$this->notices->add( array() === $problems, $text );

		return is_string( $redirect_to ) && '' !== $redirect_to ? $redirect_to : $this->network_context->admin_page_url( 'plugins.php' );
	}

	/**
	 * Check a link's request: the capability, the plugin type, and the security token for the action, plugin and extra value.
	 *
	 * @param string $action The admin-post action.
	 * @param string $extra  The query argument the link carries as well, `version` or `seconds`; empty for none.
	 *
	 * @return array{0: string, 1: string}|null The plugin file and the extra value (empty if none); null if the request is refused.
	 */
	private function authorise( string $action, string $extra = '' ): ?array {
		if ( ! Mute_Actions::can_mute() || ! isset( $_GET['type'], $_GET['key'], $_GET['_wpnonce'] ) || ! is_string( $_GET['type'] ) || ! is_string( $_GET['key'] ) || ! is_string( $_GET['_wpnonce'] ) ) {
			return null;
		}
		if ( 'plugin' !== sanitize_key( wp_unslash( $_GET['type'] ) ) ) {
			return null;
		}

		$file    = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		$version = '';
		if ( '' !== $extra ) {
			if ( ! isset( $_GET[ $extra ] ) || ! is_string( $_GET[ $extra ] ) ) {
				return null;
			}
			$version = sanitize_text_field( wp_unslash( $_GET[ $extra ] ) );
			if ( '' === $version ) {
				return null;
			}
		}

		if ( '' === $file || false === wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::token_action( $action, $file, $version ) ) ) {
			return null;
		}

		return array( $file, $version );
	}

	/**
	 * Check a request to mute a plugin for good and do it.
	 *
	 * @return bool False if the user may not do it or the security token is not valid.
	 */
	public function process_mute(): bool {
		$request = $this->authorise( self::MUTE_ACTION );
		if ( null === $request ) {
			return false;
		}

		$this->notices->add( ...$this->actions->mute( 'plugin', $request[0], self::SOURCE ) );

		return true;
	}

	/**
	 * Check a request to skip the waiting version of a plugin and do it.
	 *
	 * @return bool False if the user may not do it or the security token is not valid.
	 */
	public function process_skip(): bool {
		$request = $this->authorise( self::SKIP_ACTION, 'version' );
		if ( null === $request ) {
			return false;
		}

		$this->notices->add( ...$this->actions->mute( 'plugin', $request[0], self::SOURCE, true, $request[1] ) );

		return true;
	}

	/**
	 * Check a request to snooze a plugin and do it.
	 *
	 * The length must still be one that `sc_wpun_snooze_durations` lists; a link made before the list changed
	 * gets a notice instead.
	 *
	 * @return bool False if the user may not do it or the security token is not valid.
	 */
	public function process_snooze(): bool {
		$request = $this->authorise( self::SNOOZE_ACTION, 'seconds' );
		if ( null === $request ) {
			return false;
		}

		$seconds = 1 === preg_match( '/^[1-9][0-9]*$/D', $request[1] ) ? (int) $request[1] : 0;
		if ( ! isset( Mute_Store::snooze_durations()[ $seconds ] ) ) {
			$this->notices->add( false, __( 'That length of snooze is not offered. Reload the page and try again.', 'wp-updates-notifier' ) );
			return true;
		}

		$this->notices->add( ...$this->actions->mute( 'plugin', $request[0], self::SOURCE, false, null, time() + $seconds ) );

		return true;
	}

	/**
	 * Check a request to unmute a plugin and do it.
	 *
	 * @return bool False if the user may not do it or the security token is not valid.
	 */
	public function process_unmute(): bool {
		$request = $this->authorise( self::UNMUTE_ACTION );
		if ( null === $request ) {
			return false;
		}

		$this->notices->add( ...$this->actions->unmute( 'plugin', $request[0], self::SOURCE ) );

		return true;
	}

	/**
	 * Handle the mute link (admin-post.php), then go back.
	 *
	 * @return void
	 */
	public function handle_mute(): void {
		$this->finish( $this->process_mute() );
	}

	/**
	 * Handle the skip link (admin-post.php), then go back.
	 *
	 * @return void
	 */
	public function handle_skip(): void {
		$this->finish( $this->process_skip() );
	}

	/**
	 * Handle the snooze link (admin-post.php), then go back.
	 *
	 * @return void
	 */
	public function handle_snooze(): void {
		$this->finish( $this->process_snooze() );
	}

	/**
	 * Handle the unmute link (admin-post.php), then go back.
	 *
	 * @return void
	 */
	public function handle_unmute(): void {
		$this->finish( $this->process_unmute() );
	}

	/**
	 * Answer 403 if the request was refused, else go back to the screen it came from.
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

		$this->actions->go_back( $this->network_context->admin_page_url( 'plugins.php' ) );
	}
}
