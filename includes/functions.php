<?php
/**
 * The sc_wpun functions: the plugin accessor and the public helper functions.
 *
 * @package wp-updates-notifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Not loaded by WordPress.
}

if ( ! function_exists( 'sc_wpun' ) ) {
	/**
	 * The plugin.
	 *
	 * @internal
	 *
	 * @return \WPUN\Plugin
	 */
	function sc_wpun(): \WPUN\Plugin {
		static $plugin = null;

		$plugin ??= new \WPUN\Plugin();

		return $plugin;
	}
}

if ( ! function_exists( 'sc_wpun_register_channel' ) ) {
	/**
	 * Register a notification channel. Call it from the `sc_wpun_register_channels` action.
	 *
	 * @since 2.0.0
	 *
	 * @param \WPUN\Channel $channel The channel.
	 *
	 * @return bool False if its type is invalid or already registered.
	 */
	function sc_wpun_register_channel( \WPUN\Channel $channel ): bool {
		return sc_wpun()->channels()->register( $channel );
	}
}

if ( ! function_exists( 'sc_wpun_channel' ) ) {
	/**
	 * The registered channel of a type: the one that delivers its instances.
	 *
	 * For a replaced type it is the replacement, and a channel refused for its API constraint is never
	 * returned. The first call fires `sc_wpun_register_channels` if nothing has yet. Every caller gets the
	 * same object, so keep no state in it:
	 *
	 *     $channel = sc_wpun_channel( $instance->get_type() );
	 *     $label   = $channel ? $channel->get_label() : $instance->get_type();
	 *
	 * @since 3.0.0
	 *
	 * @param string $type Channel type.
	 *
	 * @return \WPUN\Channel|null Null if no channel of the type is registered.
	 */
	function sc_wpun_channel( string $type ): ?\WPUN\Channel {
		return sc_wpun()->channels()->get( $type );
	}
}

if ( ! function_exists( 'sc_wpun_channels' ) ) {
	/**
	 * The store of the configured channel instances.
	 *
	 * @since 2.0.0
	 *
	 * @return \WPUN\Channel_Store
	 */
	function sc_wpun_channels(): \WPUN\Channel_Store {
		return sc_wpun()->channel_store();
	}
}

if ( ! function_exists( 'sc_wpun_mutes' ) ) {
	/**
	 * The store of the mutes: plugins, themes and other items whose notifications are switched off.
	 *
	 * @since 3.0.0
	 *
	 * @return \WPUN\Mute_Store
	 */
	function sc_wpun_mutes(): \WPUN\Mute_Store {
		return sc_wpun()->mute_store();
	}
}

if ( ! function_exists( 'sc_wpun_instance_data' ) ) {
	/**
	 * The data a module keeps for one channel instance.
	 *
	 * Always returns an object. For an invalid id it refuses everything.
	 *
	 * @since 3.0.0
	 *
	 * @param string $instance_id Instance id.
	 *
	 * @return \WPUN\Instance_Data
	 */
	function sc_wpun_instance_data( string $instance_id ): \WPUN\Instance_Data {
		return new \WPUN\Instance_Data( $instance_id, sc_wpun()->network_context(), sc_wpun()->channel_store() );
	}
}

if ( ! function_exists( 'sc_wpun_register_settings_group' ) ) {
	/**
	 * Register a group of settings the plugin stores, draws and resolves like its own. Call it from the `sc_wpun_register_settings` action.
	 *
	 * Values come from a constant or environment variable named `SC_WPUN_SETTING_<GROUP>__<FIELD>` (upper case), or any other
	 * configuration source, before the stored value and the default. The values are stored in the option `sc_wpun_settings_<group>`.
	 *
	 * The id matches `/^[a-z][a-z0-9_]{1,30}$/`, has no `__`, and is not one of the plugin's setting names or `general`, `channels`,
	 * `muted` and `about`. Use your own prefix, for example `acme`. The `$args` are:
	 *
	 * - `label` (string, required) and `description` (string).
	 * - `tab` (string): the settings tab to show the group on. Default `general`, where it is a panel below the plugin's settings.
	 *   Any other slug other than `channels`, `muted` and `about` is a tab of its own, labelled `tab_label`.
	 * - `capability` (string): the capability needed to save the group. Default sc_wpun_capability(). It can only be stricter.
	 * - `validate_callback` (callable): `( array $clean, array $stored ): array|WP_Error`, a check across fields after the checks of the fields.
	 * - `field_groups` (array): sub-groups inside the panel, as `Channel::get_field_groups()` has them.
	 * - `autoload` (bool): whether the option is loaded on every request. Default false.
	 *
	 * Each of the `$fields` is a field schema as `Channel::get_fields()` has them, plus `on_invalid` (the value to use when a source
	 * supplies a value that cannot be used for the field, instead of the stored value) and `constant_first` (read the constant
	 * before the configuration sources, and let nothing override it). `'storage' => false` makes a field code-only.
	 *
	 *     add_action(
	 *         'sc_wpun_register_settings',
	 *         function () {
	 *             sc_wpun_register_settings_group(
	 *                 'acme',
	 *                 array( 'label' => 'Acme' ),
	 *                 array(
	 *                     'enabled' => array(
	 *                         'type'           => 'boolean',
	 *                         'default'        => true,
	 *                         'storage'        => false,
	 *                         'on_invalid'     => false,
	 *                         'constant_first' => true,
	 *                     ),
	 *                 )
	 *             );
	 *         }
	 *     );
	 *
	 * @since 3.0.0
	 *
	 * @param string                              $group  The group id.
	 * @param array<string, mixed>                $args   The group's arguments.
	 * @param array<string, array<string, mixed>> $fields The field schemas by name.
	 *
	 * @return bool False, with a notice to the developer, when the id, the arguments or a schema is not valid, the id is taken,
	 *              or the call is outside the action.
	 */
	function sc_wpun_register_settings_group( string $group, array $args, array $fields ): bool {
		return sc_wpun()->settings_groups()->register( $group, $args, $fields );
	}
}

if ( ! function_exists( 'sc_wpun_setting' ) ) {
	/**
	 * The effective value of a setting: the configuration first, then the stored value, then the default.
	 *
	 * The value is typed as the field is, and when a source supplied a value that cannot be used, the field's `on_invalid` applies.
	 * Use `''` as the group for the plugin's own settings (`frequency`, `notify_core`, `show_about` and so on). Cached for the request
	 * and dropped when the configuration is resolved again or the group is saved:
	 *
	 *     if ( true !== sc_wpun_setting( 'acme', 'enabled' ) ) {
	 *         return;
	 *     }
	 *
	 * @since 3.0.0
	 *
	 * @param string $group The group id, or `''` for the plugin's own settings.
	 * @param string $name  The setting or field name.
	 *
	 * @return mixed Null, with a notice to the developer, for a setting that does not exist.
	 */
	function sc_wpun_setting( string $group, string $name ) {
		$entry = sc_wpun()->settings_groups()->entry( $group, $name, __FUNCTION__ );

		return $entry['value'] ?? null;
	}
}

if ( ! function_exists( 'sc_wpun_setting_source' ) ) {
	/**
	 * Where the value of a setting comes from, when it is supplied from outside the database.
	 *
	 * For example "constant SC_WPUN_SETTING_ACME__ENABLED". It never contains the value. Use `''` as the group for the plugin's own
	 * settings. Code that must trust only a value set in code checks it:
	 *
	 *     $source = sc_wpun_setting_source( 'acme', 'enabled' );
	 *
	 * @since 3.0.0
	 *
	 * @param string $group The group id, or `''` for the plugin's own settings.
	 * @param string $name  The setting or field name.
	 *
	 * @return string|null Null when the stored value or the default is used, and for a setting that does not exist (with a notice
	 *                     to the developer).
	 */
	function sc_wpun_setting_source( string $group, string $name ): ?string {
		$entry = sc_wpun()->settings_groups()->entry( $group, $name, __FUNCTION__ );

		return $entry['source'] ?? null;
	}
}

if ( ! function_exists( 'sc_wpun_channel_field_source' ) ) {
	/**
	 * Where the value of a channel instance field comes from, when it is supplied from outside the database.
	 *
	 * For example "constant SC_WPUN_CHANNEL_EMAIL__TO". It never contains the value, and lets code treat a value set in code
	 * as trusted. The field `enabled` is the instance's on and off switch.
	 *
	 * @since 3.0.0
	 *
	 * @param string $instance_id The id of the channel instance.
	 * @param string $field       The field name, or `enabled`.
	 *
	 * @return string|null Null when the stored value is used.
	 */
	function sc_wpun_channel_field_source( string $instance_id, string $field ): ?string {
		return sc_wpun()->channel_store()->external_source( $instance_id, $field );
	}
}

if ( ! function_exists( 'sc_wpun_settings_groups' ) ) {
	/**
	 * The registered settings groups, for an add-on's own reports.
	 *
	 * Fires `sc_wpun_register_settings` if nothing has yet. Each group is an array of its arguments, with their defaults
	 * (`label`, `description`, `tab`, `tab_label`, `capability`, `validate_callback`, `field_groups`, `autoload`), and `fields`,
	 * the field schemas as registered. An empty `capability` means sc_wpun_capability(), and an empty `tab_label` means the
	 * tab is labelled by the group's label.
	 *
	 * @since 3.0.0
	 *
	 * @return array<string, array<string, mixed>> By group id.
	 */
	function sc_wpun_settings_groups(): array {
		return sc_wpun()->settings_groups()->all();
	}
}

if ( ! function_exists( 'sc_wpun_delete_settings_group' ) ) {
	/**
	 * Remove the stored values of a settings group, for an add-on's uninstall.
	 *
	 * The plugin cannot know when an add-on is gone, so it never deletes these options itself. Works for a group that is not
	 * registered, but not for an id that is not a valid group id.
	 *
	 * @since 3.0.0
	 *
	 * @param string $group The group id.
	 *
	 * @return void
	 */
	function sc_wpun_delete_settings_group( string $group ): void {
		sc_wpun()->settings_groups()->delete( $group );
	}
}

if ( ! function_exists( 'sc_wpun_capability' ) ) {
	/**
	 * The capability needed to use the plugin's settings screen.
	 *
	 * The one the plugin checks: `manage_options`, or `manage_network_options` on multisite, after the
	 * `sc_wpun_capability` filter. Code that offers the plugin's settings or actions somewhere else
	 * checks it to stay in step with the plugin:
	 *
	 *     if ( current_user_can( sc_wpun_capability() ) ) {
	 *         // May change the settings.
	 *     }
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	function sc_wpun_capability(): string {
		return \WPUN\Admin\Settings_Page::capability();
	}
}

if ( ! function_exists( 'sc_wpun_mute_capability' ) ) {
	/**
	 * The capability needed to mute and unmute plugins and themes.
	 *
	 * The one the plugin checks: sc_wpun_capability() after the `sc_wpun_mute_capability` filter. A
	 * dashboard widget or a REST route that mutes or unmutes checks it:
	 *
	 *     if ( current_user_can( sc_wpun_mute_capability() ) ) {
	 *         sc_wpun_mutes()->unmute( 'plugin', 'noisy/noisy.php' );
	 *     }
	 *
	 * @since 3.0.0
	 *
	 * @return string
	 */
	function sc_wpun_mute_capability(): string {
		return \WPUN\Admin\Mute_Actions::capability();
	}
}

if ( ! function_exists( 'sc_wpun_instance_waiting_until' ) ) {
	/**
	 * When a channel instance may next be delivered to.
	 *
	 * Update checks skip an instance that was told to wait (a failure with a Retry-After) or held by a
	 * deferred result (`Delivery_Result::deferred()`). This is the later of the two times, so a
	 * `sc_wpun_item_resolved` listener or a dashboard can tell that an instance is held:
	 *
	 *     $until = sc_wpun_instance_waiting_until( $instance->get_id() );
	 *     if ( null !== $until ) {
	 *         // Not told about new updates until $until, a Unix time.
	 *     }
	 *
	 * @since 3.0.0
	 *
	 * @param string $id Id of the channel instance.
	 *
	 * @return int|null A Unix time in the future, or null when the instance is not waiting.
	 */
	function sc_wpun_instance_waiting_until( string $id ): ?int {
		$state = sc_wpun()->state();
		$until = $state->waiting_until( $state->get()['channels'][ $id ] ?? array() );

		return $until > 0 ? $until : null;
	}
}

if ( ! function_exists( 'sc_wpun_instance_problems' ) ) {
	/**
	 * Why the plugin would refuse to deliver through a channel instance, apart from it being switched off or waiting.
	 *
	 * The codes are those of the failure the dispatcher records: `unavailable` when the channel type of the instance is not
	 * registered (its module is missing, refused or unregistered), and `misconfigured` when a value supplied from a constant,
	 * an environment variable or a source cannot be used. Code that sends through an instance outside the dispatcher, such
	 * as the webhook channel's `updates.resolved` message, checks it to honour the same rules:
	 *
	 *     if ( array() === sc_wpun_instance_problems( $instance->get_id() ) ) {
	 *         // Safe to deliver.
	 *     }
	 *
	 * @since 3.0.0
	 *
	 * @param string $id Id of the channel instance.
	 *
	 * @return string[] Empty when there is no problem, or no such instance.
	 */
	function sc_wpun_instance_problems( string $id ): array {
		$instance = sc_wpun()->channel_store()->get( $id );
		if ( null === $instance ) {
			return array();
		}

		$problems = array();
		if ( ! sc_wpun()->channels()->has( $instance->get_type() ) ) {
			$problems[] = 'unavailable';
		}
		try {
			if ( array() !== sc_wpun()->channel_store()->problems( $id ) ) {
				$problems[] = 'misconfigured';
			}
		} catch ( \Throwable $e ) {
			// The fields of the channel could not be read, so its values cannot be trusted.
			$problems[] = 'misconfigured';
		}

		return $problems;
	}
}

if ( ! function_exists( 'sc_wpun_is_network' ) ) {
	/**
	 * Whether the plugin runs for a whole multisite network.
	 *
	 * True on a multisite where the plugin is network-activated. The plugin then has one set of
	 * settings, channels and mutes for the network, its screen is in the network admin, and the
	 * check runs once, on the main site. False on a single site, and for a copy still activated
	 * per site on a network, which does nothing until it is network-activated.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	function sc_wpun_is_network(): bool {
		return sc_wpun()->network_context()->is_network();
	}
}

if ( ! function_exists( 'sc_wpun_settings_url' ) ) {
	/**
	 * The address of the plugin's settings screen, or of one of its tabs.
	 *
	 * Settings → Updates Notifier on a single site, Network Admin → Settings → Updates Notifier
	 * when the plugin runs for a network. Link to the screen with this rather than building the
	 * address, which differs between the two:
	 *
	 *     printf( '<a href="%s">Channels</a>', esc_url( sc_wpun_settings_url( 'channels' ) ) );
	 *
	 * @since 3.0.0
	 *
	 * @param string $tab Optional. A tab, for example `general`, `channels` or `muted`. Default the screen's first tab.
	 *
	 * @return string The address, not escaped.
	 */
	function sc_wpun_settings_url( string $tab = '' ): string {
		return sc_wpun()->network_context()->settings_url( $tab );
	}
}

if ( ! function_exists( 'sc_wpun_mute_url' ) ) {
	/**
	 * The address of the Muted tab with its form filled in for a plugin or theme.
	 *
	 * Link to it from another tool, for example a "mute this" link in a message. The link only fills in the form:
	 * nothing is muted until the user checks it and presses Mute. A plugin or theme that is not installed, or is
	 * muted already, is ignored (the tab says so for a muted one).
	 *
	 *     printf( '<a href="%s">Mute</a>', esc_url( sc_wpun_mute_url( 'plugin', 'akismet/akismet.php', 'snooze', WEEK_IN_SECONDS ) ) );
	 *
	 * @since 3.0.0
	 *
	 * @param string $type   `plugin` or `theme`.
	 * @param string $key    The plugin file, for example `akismet/akismet.php`, or the theme's stylesheet (its directory name).
	 * @param string $mode   Optional. `always`, `skip` (skip the waiting version) or `snooze`. Default `always`.
	 * @param int    $snooze Optional. With `snooze`, the length in seconds, one of those the `sc_wpun_snooze_durations` filter lists (by default a day, a week and 30 days). Fills in the end of the snooze. Default 0, none.
	 *
	 * @return string The address, not escaped.
	 */
	function sc_wpun_mute_url( string $type, string $key, string $mode = 'always', int $snooze = 0 ): string {
		$url  = sc_wpun_settings_url( 'muted' );
		$url .= '&' . \WPUN\Admin\Muted_Tab::ARG_TYPE . '=' . rawurlencode( $type );
		$url .= '&' . \WPUN\Admin\Muted_Tab::ARG_KEY . '=' . rawurlencode( $key );
		$url .= '&' . \WPUN\Admin\Muted_Tab::ARG_MODE . '=' . rawurlencode( $mode );
		if ( $snooze > 0 ) {
			$url .= '&' . \WPUN\Admin\Muted_Tab::ARG_SNOOZE . '=' . $snooze;
		}

		return $url;
	}
}

if ( ! function_exists( 'sc_wpun_version' ) ) {
	/**
	 * The version of the plugin, for a User-Agent or a diagnostics screen.
	 *
	 * An add-on checks what it can use with the API version (sc_wpun_api_version()), not with this one.
	 *
	 * @since 3.0.0
	 *
	 * @return string For example "3.0.0".
	 */
	function sc_wpun_version(): string {
		return SC_WPUN_VERSION;
	}
}

if ( ! function_exists( 'sc_wpun_api_version' ) ) {
	/**
	 * The version of the public API, for add-ons to check what they can use.
	 *
	 * Semver, separate from the plugin version: a minor version adds API, a major version
	 * removes or changes some. Most add-ons want sc_wpun_api_satisfies() instead.
	 *
	 * @since 2.0.0
	 *
	 * @return string For example "1.0.0".
	 */
	function sc_wpun_api_version(): string {
		return SC_WPUN_API_VERSION;
	}
}

if ( ! function_exists( 'sc_wpun_api_satisfies' ) ) {
	/**
	 * Whether the installed plugin's API version satisfies a version constraint.
	 *
	 * Constraints use Composer's syntax. Check one before using the API, and show an admin
	 * notice instead of failing when it is false. Declare `Requires Plugins: wp-updates-notifier`
	 * in the add-on's header too, so WordPress makes sure the plugin is active:
	 *
	 *     add_action( 'plugins_loaded', function () {
	 *         if ( ! function_exists( 'sc_wpun_api_satisfies' ) || ! sc_wpun_api_satisfies( '^1.0' ) ) {
	 *             add_action( 'admin_notices', 'my_addon_needs_newer_updates_notifier' );
	 *             return;
	 *         }
	 *         add_action( 'sc_wpun_register_channels', 'my_addon_register_channel' );
	 *     } );
	 *
	 * | Constraint                                  | Meaning                  |
	 * | ------------------------------------------- | ------------------------ |
	 * | `1.2.0`, `=1.2.0`                           | exactly                  |
	 * | `>=1.0`, `>1.0`, `<2.0`, `<=1.4`, `!=1.3.0` | comparison               |
	 * | `^1.2`                                      | `>=1.2.0 <2.0.0`         |
	 * | `~1.2`                                      | `>=1.2.0 <2.0.0`         |
	 * | `~1.2.3`                                    | `>=1.2.3 <1.3.0`         |
	 * | `1.2.*`                                     | `>=1.2.0 <1.3.0`         |
	 * | `>=1.0 <1.4`, `>=1.0, <1.4`                 | both (AND)               |
	 * | `^1.0 || ^2.0`                              | either (OR)              |
	 *
	 * Missing minor and patch parts count as 0, and pre-release suffixes are not supported.
	 * An invalid constraint returns false and triggers `_doing_it_wrong()`. See
	 * WPUN\Version_Constraint for the details.
	 *
	 * @since 2.0.0
	 *
	 * @param string $constraint The API versions the add-on works with, for example "^1.0".
	 *
	 * @return bool
	 */
	function sc_wpun_api_satisfies( string $constraint ): bool {
		return \WPUN\Version_Constraint::satisfies( sc_wpun_api_version(), $constraint );
	}
}

if ( ! function_exists( 'sc_wpun_api_supports' ) ) {
	/**
	 * Whether the installed plugin offers the API version an add-on was written for.
	 *
	 * The `^` shorthand of sc_wpun_api_satisfies(), which is the one to prefer: this is the same
	 * as `sc_wpun_api_satisfies( '^' . $required )`. True when the API has the same major
	 * version and is at least as new: an add-on written for 1.2.0 works with 1.2.0 and 1.5.0,
	 * but not with 1.1.0 or 2.0.0. Anything but a full "X.Y.Z" version returns false.
	 *
	 * @since 2.0.0
	 *
	 * @param string $required The API version the add-on needs, for example "1.0.0".
	 *
	 * @return bool
	 */
	function sc_wpun_api_supports( string $required ): bool {
		return 1 === preg_match( '/^\d+\.\d+\.\d+$/D', $required )
			&& \WPUN\Version_Constraint::satisfies( sc_wpun_api_version(), '^' . $required );
	}
}
