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

/**
 * Adds the mute links to the Plugins screen, and handles them.
 *
 * The hooks are added while the Plugins screen loads (`load-plugins.php`), so no other request has them. The links
 * are GET links to `admin-post.php` with a security token for the action and the plugin, as core's Activate and
 * Deactivate links are: the rows are inside core's bulk form, and forms cannot be nested. Skipping a version has the
 * version in its token, so a link only skips the version it was made for. The network Plugins screen is left out.
 *
 * @internal
 */
class Plugins_Screen_Mutes {
	const MUTE_ACTION   = 'sc_wpun_mute';
	const UNMUTE_ACTION = 'sc_wpun_unmute';
	const SKIP_ACTION   = 'sc_wpun_skip';
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
	 * Constructor.
	 *
	 * @param Mute_Store   $store   Mute storage.
	 * @param Mute_Actions $actions What muting shares with the Muted tab.
	 * @param Notices      $notices Notices shown after an action.
	 */
	public function __construct( Mute_Store $store, Mute_Actions $actions, Notices $notices ) {
		$this->store   = $store;
		$this->actions = $actions;
		$this->notices = $notices;
	}

	/**
	 * Add the hooks of the Plugins screen. Hooked on `load-plugins.php`.
	 *
	 * @return void
	 */
	public function load(): void {
		if ( is_network_admin() ) {
			return; // Multisite is a later step (#85).
		}

		add_action( 'admin_notices', array( $this->notices, 'render' ) );
		// Core runs the bulk action handler for any action it does not know; it checks the capability itself.
		add_filter( 'handle_bulk_actions-plugins', array( $this, 'handle_bulk_actions' ), 10, 3 );

		if ( ! Mute_Actions::can_mute() ) {
			return;
		}

		add_filter( 'plugin_action_links', array( $this, 'action_links' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 4 );
		add_filter( 'bulk_actions-plugins', array( $this, 'bulk_actions' ) );
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
	 * The action of a security token, for the action and the plugin (and the version, when skipping it).
	 *
	 * @param string $action  The admin-post action.
	 * @param string $file    Plugin file.
	 * @param string $version The version the link skips; empty for the other actions.
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
	 *
	 * @return string Not escaped for HTML.
	 */
	private function link_url( string $action, string $file, string $version = '' ): string {
		$args = array(
			'action' => $action,
			'type'   => 'plugin',
			'key'    => rawurlencode( $file ), // add_query_arg() does not encode values; a + in a version would arrive as a space.
		);
		if ( '' !== $version ) {
			$args['version'] = rawurlencode( $version );
		}
		$args['_wpnonce'] = wp_create_nonce( self::token_action( $action, $file, $version ) );

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
	 *
	 * @return string The escaped markup.
	 */
	private function link( string $action, string $file, string $text, string $name, string $class_name, string $version = '' ): string {
		return '<a class="' . esc_attr( $class_name ) . '" href="' . esc_url( $this->link_url( $action, $file, $version ) ) . '" aria-label="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</a>';
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
	 * Say in the row meta that a plugin is muted, and offer to skip the waiting version when core hides its notice.
	 *
	 * Core shows the update notice to users who can update plugins only, and takes that away with
	 * `DISALLOW_FILE_MODS`, so for the others the skip link and its undo are here.
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
			}

			return $meta;
		}

		$meta[] = '<span class="sc-wpun-muted-note">' . esc_html( $this->note( $mute ) ) . '</span>';
		if ( $hidden && ! $mute->is_always() && ! $mute->is_from_code() ) {
			$meta[] = $this->link( self::UNMUTE_ACTION, $file, __( 'Undo', 'wp-updates-notifier' ), __( 'Undo skipping the version', 'wp-updates-notifier' ), 'sc-wpun-unmute' );
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
		if ( $mute->is_always() ) {
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
	 * Add to core's update notice of a plugin: the skip link, or what was skipped and the undo. Inline: core prints it inside a paragraph.
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
			echo ' ' . $this->skip_link( $file, $offered ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		} elseif ( ! $mute->is_always() && ! $mute->is_from_code() ) {
			// translators: %s: Version that is skipped.
			echo ' ' . esc_html( sprintf( __( 'Notifications for version %s are skipped.', 'wp-updates-notifier' ), (string) $mute->get_until() ) ) . ' ';
			echo $this->link( self::UNMUTE_ACTION, $file, __( 'Undo', 'wp-updates-notifier' ), __( 'Undo skipping the version', 'wp-updates-notifier' ), 'sc-wpun-unmute' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
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
	 * Mute or unmute the selected plugins (always) and tell the user how it went. Hooked on `handle_bulk_actions-plugins`.
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

		return is_string( $redirect_to ) && '' !== $redirect_to ? $redirect_to : admin_url( 'plugins.php' );
	}

	/**
	 * Check a link's request: the capability, the plugin type, and the security token for the action, plugin and version.
	 *
	 * @param string $action       The admin-post action.
	 * @param bool   $with_version Whether the link carries the version it skips.
	 *
	 * @return array{0: string, 1: string}|null The plugin file and the version (empty if none); null if the request is refused.
	 */
	private function authorise( string $action, bool $with_version ): ?array {
		if ( ! Mute_Actions::can_mute() || ! isset( $_GET['type'], $_GET['key'], $_GET['_wpnonce'] ) || ! is_string( $_GET['type'] ) || ! is_string( $_GET['key'] ) || ! is_string( $_GET['_wpnonce'] ) ) {
			return null;
		}
		if ( 'plugin' !== sanitize_key( wp_unslash( $_GET['type'] ) ) ) {
			return null;
		}

		$file    = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		$version = '';
		if ( $with_version ) {
			if ( ! isset( $_GET['version'] ) || ! is_string( $_GET['version'] ) ) {
				return null;
			}
			$version = sanitize_text_field( wp_unslash( $_GET['version'] ) );
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
		$request = $this->authorise( self::MUTE_ACTION, false );
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
		$request = $this->authorise( self::SKIP_ACTION, true );
		if ( null === $request ) {
			return false;
		}

		$this->notices->add( ...$this->actions->mute( 'plugin', $request[0], self::SOURCE, true, $request[1] ) );

		return true;
	}

	/**
	 * Check a request to unmute a plugin and do it.
	 *
	 * @return bool False if the user may not do it or the security token is not valid.
	 */
	public function process_unmute(): bool {
		$request = $this->authorise( self::UNMUTE_ACTION, false );
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

		$this->actions->go_back( admin_url( 'plugins.php' ) );
	}
}
