# 0031. Add-on settings share the configuration path

Status: Accepted. Date: 2026-11-09.

## Context

Add-ons could add a channel and a settings tab, but not settings. A tab added with `sc_wpun_settings_tabs` is blank: the add-on draws it, checks the capability and the security token, validates, saves and handles network mode itself. Its values got none of what the plugin's own have: constants and environment variables (ADR 0007), custom sources, the "Set by" lock, reports of invalid values in Site Health and the site info.

An add-on that wants a site-wide setting a site owner can set in code, such as an off switch, had to copy the order of the sources, the `SC_WPUN_LOAD_FROM_ENV` opt-in, the casting and the error handling, because `Config` is internal (#455). It also could not tell whether a channel field came from a constant, because `Channel_Store::external_source()` is internal.

## Decision

- **Settings groups.** An add-on registers a group of fields with `sc_wpun_register_settings_group()` in the `sc_wpun_register_settings` action. The plugin stores, draws, validates and saves it, and resolves its values through the same `Config` path as its own settings. The fields use the channel field schema, plus `on_invalid` and `constant_first`.
- **Key format.** `SC_WPUN_SETTING_<GROUP>__<FIELD>`, upper case. The double underscore is the one channel fields use (`SC_WPUN_CHANNEL_<ID>__<FIELD>`). It cannot collide with the plugin's own settings: their names have no group, and a group id or a field name cannot contain `__`, so the first `__` always separates the group from the field. `Config_Context::setting( $name, $group = '' )` builds the key and `get_group()` lets sources tell the two apart.
- **`constant_first` is final.** When set and the constant is defined, it is read with `defined()` and `constant()` before anything else, and neither the sources nor the `sc_wpun_config_value` filter can change or remove it. A switch that must hold, for example one a site owner sets in `wp-config.php`, cannot be undone by a source another plugin adds or reorders. The constant is still checked against the field type like any value.
- **`on_invalid`.** A supplied value that cannot be used is reported without the value, and the field's `on_invalid` is used instead if the schema has one. Otherwise the stored value or the default applies, as for the plugin's settings. A switch sets its "off" value, so a mistyped constant fails closed. The field counts as supplied, so the screen locks it.
- **Storage.** One option per group, `sc_wpun_settings_<group>`, with the stored fields only, through `Network_Context` and the options filters. A code-only field (`'storage' => false`) and a value supplied from configuration are never written.
- **Reserved ids.** The names of the plugin's settings, `show_about`, `general`, `channels`, `muted` and `about`, and the endings that would collide with the plugin's other options, tabs and hooks: `ver` (the option `sc_wpun_settings_ver`), `main`, `tab`, `tabs`, `validate` and `group`. The id matches `/^[a-z][a-z0-9_]{1,30}$/`.
- **No deletion by the plugin.** The plugin cannot tell when an add-on is gone, so it never deletes a group's option. `sc_wpun_delete_settings_group()` lets the add-on do it on uninstall. The plugin's own round-trip meta for the screen's errors is deleted on uninstall like the channel form's.
- **A read API.** `sc_wpun_setting()`, `sc_wpun_setting_source()`, `sc_wpun_settings_groups()` and `sc_wpun_channel_field_source()`, so an add-on does not use internal classes.
- **No API bump.** `SC_WPUN_API_VERSION` stays 1.1.0: that version is unreleased in this cycle (as in ADRs 0025 and 0030), so it absorbs the new API. Add-ons check `sc_wpun_api_supports( '1.1.0' )`.

## Consequences

- Good: an add-on gets a form, validation, network mode, reports and the "Set by" lock by describing its fields.
- Good: a site owner sets add-on settings the same way as the plugin's, and an off switch can be made unforgeable.
- Good: the key format is predictable and cannot clash.
- Bad: a group's fields share one set for the whole site or network. Settings per site in a network are out of scope.
- Bad: `constant_first` ignores sources on purpose, so a site that wants a vault to override the constant cannot, for those fields. The add-on chooses per field.
- Bad: group options stay after an add-on is removed until it, or the site owner, deletes them.

## Considered options

- **A public wrapper over `Config`.** Rejected: add-ons would still draw, store and validate their own forms, and the internal class would become API.
- **Putting add-on fields into the plugin's own option.** Rejected: ADR 0005 keeps settings, state and channels apart, and a missing add-on would leave its keys behind in the plugin's option.
- **A different key separator.** Rejected: `__` already means "owner, then field" for channels.

## References

- [Issue #455](https://github.com/l3rady/wp-updates-notifier/issues/455): add-on settings groups.
- [ADR 0007](0007-settings-and-secrets-from-outside-the-database.md): settings and secrets from outside the database.
- [ADR 0005](0005-settings-state-and-channels-in-separate-options.md): separate options.
- [ADR 0030](0030-replace-a-channel-type-explicitly.md): the unreleased API version.
