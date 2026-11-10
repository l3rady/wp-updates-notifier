# Settings groups

An add-on can have settings of its own that the plugin stores, draws, checks, saves and resolves the way it does its own. You describe the fields; you write no form, no capability check, no security token check and no storage code. Values can also be set from outside the database, so a site owner can set them in `wp-config.php`, from an environment variable or from a configuration source, with the "Set by" note on the screen and the reports in Site Health.

Settings groups are in API 1.1.0. Check `sc_wpun_api_supports( '1.1.0' )`, or declare `^1.1` in the `Requires WPUN API` header, before using them.

## Registering a group

Register in the `sc_wpun_register_settings` action, with `sc_wpun_register_settings_group( string $group, array $args, array $fields ): bool`:

```php
add_action(
	'sc_wpun_register_settings',
	function () {
		sc_wpun_register_settings_group(
			'acme',
			array(
				'label'       => 'Acme',
				'description' => 'Settings of the Acme add-on.',
			),
			array(
				// A switch only code can set: a constant in wp-config.php.
				'enabled' => array(
					'type'           => 'boolean',
					'label'          => 'Enabled',
					'default'        => true,
					'storage'        => false,
					'on_invalid'     => false,
					'constant_first' => true,
				),
				// A field on the settings screen.
				'prefix'  => array(
					'type'  => 'text',
					'label' => 'Message prefix',
				),
			)
		);
	}
);
```

The function returns false, with a `_doing_it_wrong()` notice, when the id, an argument or a schema is not valid, the id is taken, or you call it outside the action. A bad registration is reported once and registers nothing.

### The action

`sc_wpun_register_settings` has no arguments. It fires once per request, the first time something needs a group (the settings screen, a read, a report), and after `sc_wpun_register_channels`. It does not fire while the plugin loads, so hook it from `plugins_loaded` or earlier, never inside another hook that has already run. A read inside the action sees the groups registered so far.

### The id

The id matches `/^[a-z][a-z0-9_]{1,30}$/` and has no `__`. Use your own prefix, such as the name of your add-on. These are reserved:

- the names of the plugin's settings: `frequency`, `notify_core`, `notify_plugins`, `notify_themes`, `hide_updates`, `download_links` and `show_about`;
- the tabs of the screen: `general`, `channels`, `muted` and `about`;
- the endings of the plugin's other options and hooks: `ver`, `main`, `tab`, `tabs`, `validate` and `group`.

### The arguments

| Key | Meaning |
| --- | --- |
| `label` | Required. A non-empty string: the title of the panel. |
| `description` | Optional text under the title. |
| `tab` | The tab to show the group on. Default `general`, where its panels go below the plugin's settings, before the save button. Any other slug (lower case letters, digits, `_` and `-`, not `channels`, `muted` or `about`) makes the plugin add that tab and draw every group registered for it. |
| `tab_label` | The label of that tab. The first group on a tab that gives one names it; without one, the label of the first group on the tab is used. |
| `capability` | The capability needed to save the group. Default: `sc_wpun_capability()`. It can only be stricter: a user needs the screen's capability and this one. |
| `validate_callback` | `callable( array $clean, array $stored ): array\|WP_Error`, run after the checks of the fields, for checks across fields. Return the values to store, or a `WP_Error`. The error data `field` names the field to put the message on, as for `Channel::validate_settings()`; an error without one is shown in the summary only. |
| `field_groups` | Panels of their own for some of the fields, drawn after the group's panel, never inside it: an array by id of arrays with a `label`, and optionally a `description` and `collapsed`, as for [channel fields](writing-a-module.md). A field joins one with its `group` key. |
| `autoload` | Whether the option is loaded on every request. Default `false`. Network options have no autoload. |

### The fields

`$fields` is an array of field schemas by name. A name has lower case letters, digits and single underscores and starts with a letter. The schema is the one channels use (see [Writing a module](writing-a-module.md)): every type, `default`, `label`, `description`, `group`, `applies_when`, `required`, `secret`, `validate_callback`, `sanitize_callback` and the rest. Three keys are specific to groups:

- `storage`: `false` makes a field code-only. It is never stored, never has a control on the screen and a posted value is ignored. The screen lists it as a read-only line only while something supplies it, so the administrator can see why the add-on behaves as it does.
- `on_invalid`: the value to use when a configuration source supplies a value that cannot be used for the field. Without it, the value is ignored and the stored value or the default is used. A kill switch sets its "off" value, so a mistyped constant fails closed. It must be a valid value for the field.
- `constant_first`: read the constant `SC_WPUN_SETTING_<GROUP>__<FIELD>` before anything else. When it is defined it is final: a source added with `sc_wpun_config_sources`, one that reorders or drops the constant source, and the `sc_wpun_config_value` filter cannot change or remove it. For switches a site owner sets in `wp-config.php`.

## Where values come from

The constant or environment variable of a field is `SC_WPUN_SETTING_<GROUP>__<FIELD>`, in capitals: `SC_WPUN_SETTING_ACME__ENABLED`. The two underscores cannot collide with a setting of the plugin, which has no group. Resolution, casting, the `SC_WPUN_LOAD_FROM_ENV` opt-in for environment variables and custom sources work as for the plugin's own settings (see [Configuration sources](configuration-sources.md)). A source sees the group in `Config_Context::get_group()`.

The effective value is, in order:

1. a value a source supplies, cast to the type of the field;
2. if that value cannot be used: `on_invalid` if the schema has it;
3. the stored value;
4. the `default`; without one, `false` for a boolean, `0` for a number, an empty list for a multiple `enum`, and an empty string for the rest.

An unusable value is reported in the configuration test of Site Health and on the plugin's settings page, with the key and where it came from, never the value, and it says when `on_invalid` was used. When `on_invalid` was used the field counts as supplied: the screen locks it.

## Reading values

- `sc_wpun_setting( string $group, string $name )`: the effective value, typed. Use `''` as the group for the plugin's own settings. For an unknown group or field it returns `null` with a `_doing_it_wrong()` notice.
- `sc_wpun_setting_source( string $group, string $name ): ?string`: where the value comes from, for example `constant SC_WPUN_SETTING_ACME__ENABLED`, or `null` when the stored value or the default is used. It never holds the value.
- `sc_wpun_channel_field_source( string $instance_id, string $field ): ?string`: the same for a field of a channel instance, so code can trust a value set in code and tell it from one saved in the form.
- `sc_wpun_settings_groups(): array`: the registered groups by id. Each is its arguments with their defaults (an empty `capability` means `sc_wpun_capability()`, an empty `tab_label` means the tab is named after the group) and its `fields`.

Values are cached for the request. A save drops the cache, and so does a new resolution of the configuration, for example after a `switch_blog`.

```php
if ( true !== sc_wpun_setting( 'acme', 'enabled' ) ) {
	return;
}
```

## The settings screen

- A group is one panel, titled with its `label`, holding its description and the fields that have no field group. Each field group is a panel of its own after it. A group whose fields are all in field groups and that has no description draws only those panels.
- A tab has one form and one **Save settings** button. On the General tab a group's panels go after the plugin's settings and before the button, and the button saves both. A group on another tab is drawn on that tab with every other group for it, and they share the button. The add-on hooks nothing.
- A panel has the same markup and accessibility as the rest of the screen: a fieldset with a legend, field groups (collapsed ones in a `details` element), `applies_when` notes, and an error summary with `role="alert"` whose links go to the fields. It needs no JavaScript.
- A field supplied from configuration is shown disabled with "Set by constant ...". Its value is not printed, except for a number, boolean or choice, or a schema with `show_external`.
- A user who has the screen's capability but not the group's sees the controls disabled and a short note. What they post for the group is ignored, and the rest of the form still saves.
- Saving checks the screen's capability and a security token made for the tab, then each group's capability. Each field of every group is checked and cleaned, then `validate_callback`. If anything on the form fails, nothing on it is stored, the plugin's settings included: the errors appear on the fields and the submitted values stay in the form, except secrets. A secret is kept when left empty and emptied with its "clear" box.
- A field that is supplied from configuration or code-only is never stored: it keeps the value stored before.

## After a save

`sc_wpun_settings_group_saved( string $group, array $new, array $old )` fires after the values passed the checks and were stored, also when nothing changed. `$new` and `$old` are the stored values, not the effective ones, and contain secrets: do not log them.

```php
add_action(
	'sc_wpun_settings_group_saved',
	function ( $group, $new, $old ) {
		if ( 'acme' === $group && $new !== $old ) {
			delete_transient( 'acme_cache' );
		}
	},
	10,
	3
);
```

## Storage and uninstall

The stored values of a group are in the option `sc_wpun_settings_<group>`: only stored fields, never code-only ones and never a value supplied from configuration. It is a network option when the plugin runs for a network, it is not loaded on every request unless `autoload` is true, and it goes through `sc_wpun_get_options_filter` and `sc_wpun_put_options_filter` like the plugin's other options.

The plugin cannot tell when an add-on is gone, so it never deletes these options. Remove yours in your `uninstall.php` with `sc_wpun_delete_settings_group( 'acme' )`. It works whether or not the group is registered at that moment, and refuses an id that is not a valid group id.

## A complete example

A switch that only code can set, and a panel with one field:

```php
<?php
/**
 * Plugin Name:       Acme Updates
 * Requires Plugins:  wp-updates-notifier
 * Requires WPUN API: ^1.1
 */

add_action(
	'plugins_loaded',
	function () {
		if ( ! function_exists( 'sc_wpun_api_supports' ) || ! sc_wpun_api_supports( '1.1.0' ) ) {
			return;
		}

		add_action( 'sc_wpun_register_settings', 'acme_register_settings' );
		add_action( 'sc_wpun_settings_group_saved', 'acme_saved', 10, 2 );
	}
);

function acme_register_settings() {
	sc_wpun_register_settings_group(
		'acme',
		array(
			'label'      => 'Acme',
			'capability' => 'manage_acme',
		),
		array(
			'enabled' => array(
				'type'           => 'boolean',
				'label'          => 'Acme switched on',
				'default'        => true,
				'storage'        => false,
				'on_invalid'     => false,
				'constant_first' => true,
			),
			'api_key' => array(
				'type'     => 'secret',
				'label'    => 'API key',
				'required' => true,
			),
		)
	);
}

function acme_saved( $group, $new ) {
	if ( 'acme' === $group ) {
		delete_transient( 'acme_cache' );
	}
}

function acme_run() {
	if ( true !== sc_wpun_setting( 'acme', 'enabled' ) ) {
		return; // Switched off in wp-config.php, or by a value that is not valid.
	}

	$key = sc_wpun_setting( 'acme', 'api_key' );
	// ...
}
```

The site owner turns Acme off for good with `define( 'SC_WPUN_SETTING_ACME__ENABLED', false );`, and a mistyped value turns it off too. The decision is recorded in [ADR 0031](../decisions/0031-add-on-settings-share-the-configuration-path.md); a complete add-on is [`examples/wpun-example-channel/`](https://github.com/l3rady/wp-updates-notifier/tree/main/examples/wpun-example-channel/).
