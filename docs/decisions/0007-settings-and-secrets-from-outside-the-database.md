# 0007. Allow settings and secrets from outside the database

Status: Accepted. Date: 2026-10-12.

## Context

Site builders and agencies want configuration in code or in the environment, not in the database. This matters most for secrets such as webhook URLs, bot tokens and API keys, which should not sit in `wp_options`. It applies to any setting.

## Decision

For every global setting and every field of a channel instance, the plugin looks for a value in this order:

1. **`wp-config.php` constants.** Always on.
2. **Environment variables.** Opt-in, by setting `SC_WPUN_LOAD_FROM_ENV`, so variables a host happens to set never change behaviour by surprise.
3. **Sources added by add-ons**, through the `sc_wpun_config_sources` filter. This is how a secrets service such as Vault can plug in.

The first source with a value wins. The `sc_wpun_config_value` filter then sees the result and has the last word. If nothing supplies a value, the one saved in the database is used.

Values from outside go through the same sanitisers and validators as saved ones. The settings screen shows them as read-only and says where they came from. The names are `SC_WPUN_SETTING_<SETTING>` and `SC_WPUN_CHANNEL_<ID>__<FIELD>`, and are public API. See [Configuration sources](../developers/configuration-sources.md).

## Consequences

- Good: secrets can stay out of the database and out of backups of it.
- Good: a deployment can pin settings, and add-ons can bring their own sources.
- Bad: the screen and the stored value can disagree, so the screen has to say where a value came from.
- Bad: instance IDs have to be constant-safe slugs that cannot change, because they form part of the names.

## Considered options

- **Environment variables always on.** Rejected, because stray host variables could change behaviour.

## References

- [#107](https://github.com/l3rady/wp-updates-notifier/issues/107): load settings and secrets from constants, environment variables or custom sources.
