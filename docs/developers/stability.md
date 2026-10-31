# Stability and deprecation

From 2.0.0, the hooks and the module API are public API. This page says what that covers, how it changes, and how an add-on checks what it can use.

## What is public

- Every hook in the [hook reference](hooks.md): its name, its arguments and what its return value does.
- The functions `sc_wpun_register_channel()`, `sc_wpun_channels()`, `sc_wpun_mutes()`, `sc_wpun_is_network()`, `sc_wpun_settings_url()`, `sc_wpun_api_version()`, `sc_wpun_api_satisfies()` and `sc_wpun_api_supports()`, and the `SC_WPUN_API_VERSION` constant.
- The classes and methods in the `WPUN` namespace that are documented with `@since` and not tagged `@internal`. These include `Channel`, `Channel_Instance`, `Channel_Registry`, `Channel_Store`, `Delivery_Result`, `Mute`, `Mute_Store`, `Notification`, `Notification_Item`, `Notification_Renderer`, `Routing`, `Item_Types`, `Version_Constraint`, `Config_Source`, `Config_Context` and `Config_Value`.
- The `auto_update` flag that the check sets on core, plugin and theme items, and the `sc_wpun_auto_update_enabled` filter that decides it.
- The names that configuration can be supplied under: `SC_WPUN_SETTING_*`, `SC_WPUN_CHANNEL_<ID>__<FIELD>`, `SC_WPUN_CHANNELS` and `SC_WPUN_LOAD_FROM_ENV`.
- The field schema keys and field types described on `WPUN\Channel`, including `'secret' => true` on a field of any type and `show_external`.
- The security behaviour that add-ons rely on:
  - a secret is never rendered, and a field's renderer receives only `'1'` or `''` for it;
  - `var_dump()` and `print_r()` of a `Channel_Instance` show `[redacted]` for every setting;
  - `Notification_Item` values are single lines, with control characters replaced by spaces;
  - `Notification_Renderer::markdown()` links only explicit `http` and `https` URLs.
  
  Loosening any of these is a breaking change.

Anything tagged `@internal` can change or disappear in any release, even if it is declared `public` in PHP. That includes `sc_wpun()`, the classes that run the plugin (such as `Plugin`, `Settings`, `State`, `Dispatcher`, `Update_Checker` and `Config`), `Field_Types` (use the `sc_wpun_field_types` filter instead), and everything in `WPUN\Admin`. The layout of the options in the database is not public either: read and write instances through `sc_wpun_channels()`.

The plugin's own modules in `modules/` use only the public API, and CI enforces it with `make check-public-api`. If a module needs something that is not public, the API grows; the module does not reach inside.

## How it changes

- **Breaking or removing** a public hook, function, class or method needs a major version of the plugin.
- **Deprecating** comes first. A deprecated hook goes through `apply_filters_deprecated()` or `do_action_deprecated()`, and a deprecated function or method calls `_deprecated_function()`, so `WP_DEBUG` shows a notice naming the replacement. It keeps working for at least one minor release before it can be removed in a major one.
- **Adding** a hook, a method or an optional argument can happen in any minor release.

## The API version

The public API has its own semantic version, separate from the plugin's: `SC_WPUN_API_VERSION`, also returned by `sc_wpun_api_version()`. It started at `1.0.0` with plugin version 2.0.0.

- A **minor** bump (1.0.0 to 1.1.0) adds API.
- A **major** bump (1.x to 2.0.0) removes or changes some, following the deprecation rules above.

State the API versions your add-on works with as a version constraint, in any of three ways:

- The `Requires WPUN API` plugin header, for example `Requires WPUN API: ^1.0`. WP Updates Notifier shows a notice below the add-on on the **Plugins** screen, before and after activation, when the site's API does not satisfy it or the value cannot be read. It does not block activation.
- `sc_wpun_api_satisfies()` in your add-on's `boot()`, before using the API:

```php
add_action(
	'plugins_loaded',
	function () {
		if ( ! function_exists( 'sc_wpun_api_satisfies' ) || ! sc_wpun_api_satisfies( '^1.0' ) ) {
			add_action( 'admin_notices', 'my_addon_needs_newer_updates_notifier' );
			return;
		}
		add_action( 'sc_wpun_register_channels', 'my_addon_register_channel' );
	}
);
```

- `Channel::get_api_constraint()`, which the registry enforces (see below).

`sc_wpun_api_satisfies()` takes Composer's constraint syntax and nothing else. `^1.0` is true for any 1.x from 1.0.0 on, and false for 2.0.0, since a new major version may have removed what you use.

| Constraint | Meaning |
| --- | --- |
| `1.2.0`, `=1.2.0` | exactly |
| `>=1.0`, `>1.0`, `<2.0`, `<=1.4`, `!=1.3.0` | comparison |
| `^1.2` | `>=1.2.0 <2.0.0` (for `^0.x`, Composer's rules) |
| `~1.2` | `>=1.2.0 <2.0.0` |
| `~1.2.3` | `>=1.2.3 <1.3.0` |
| `1.2.*` | `>=1.2.0 <1.3.0` |
| `>=1.0 <1.4`, `>=1.0, <1.4` | both (AND) |
| `^1.0 \|\| ^2.0` | either (OR) |

Missing minor and patch parts count as 0. Pre-release suffixes are not supported, because the API version never has one. Other syntaxes, such as Ruby's `~>` or a hyphen range, are invalid. An invalid constraint returns false, so a typo fails closed, and triggers `_doing_it_wrong()` once per constraint per request, so the developer sees why.

`sc_wpun_api_supports( '1.2.0' )` still works. It is the `^` shorthand, the same as `sc_wpun_api_satisfies( '^1.2.0' )`, and takes a full `X.Y.Z` version only.

To check some other version against a constraint, call `WPUN\Version_Constraint::satisfies( $version, $constraint )`. `Version_Constraint::is_valid( $constraint )` tells whether a constraint can be read, without a notice.

### Enforcement by the registry

A channel can declare the API versions it needs in `Channel::get_api_constraint()`, and the registry enforces it. When the site's API version does not satisfy the constraint, or the constraint cannot be read (which also triggers `_doing_it_wrong()`), `Channel_Registry::register()` returns false and fires `sc_wpun_channel_rejected`. `Channel_Registry::get_rejected()` lists the refused channels with the reason.

A site owner sees it in three places:

- On the **Channels** tab, instances of the refused module are listed below the channels in an "Unavailable channels" table with "Unavailable: ..." and the reason. They cannot be edited, tested, switched, duplicated or deleted, and their settings are kept.
- A notice on the plugin's own settings page (never site-wide) lists each refused module once.
- Site Health shows a recommendation while a module is refused.

Updating WP Updates Notifier, or the add-on to a version that registers successfully, clears all of it. The check in your `plugins_loaded` callback is still needed for code that runs outside the channel and for your own notice. See [Writing a module](writing-a-module.md).

Also declare the dependency in your plugin header, so WordPress (6.5 and later) refuses to activate your add-on without WP Updates Notifier:

```php
 * Requires Plugins: wp-updates-notifier
```
