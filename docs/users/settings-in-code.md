# Settings in code

You can set any of the plugin's settings, and the options of any channel, from outside the database. This is useful when you look after several sites and want the same setup on all of them, or when you want to keep an address or a key out of the database.

A value set this way is read-only on the settings screen. The field is greyed out and says "Set by" and where the value comes from, for example "Set by constant SC_WPUN_SETTING_FREQUENCY". Saving the form never changes it.

## Where values can come from

For each value the plugin looks in this order and uses the first it finds:

1. **Constants** defined in `wp-config.php`. These are always read.
2. **Environment variables**, only if you switch them on (see below).
3. **The database**, which is what you save on the settings screen.

Constants must be defined in `wp-config.php` above the line that says "That's all, stop editing!".

## Settings

Names start with `SC_WPUN_SETTING_`:

| Constant | Values | Setting |
| --- | --- | --- |
| `SC_WPUN_SETTING_FREQUENCY` | `hourly`, `twicedaily`, `daily` or `weekly` | **Frequency to check** |
| `SC_WPUN_SETTING_NOTIFY_CORE` | `none`, `all` or `major` | **Notify about WordPress updates?** |
| `SC_WPUN_SETTING_NOTIFY_PLUGINS` | `none`, `all` or `active` | **Notify about plugin updates?** |
| `SC_WPUN_SETTING_NOTIFY_THEMES` | `none`, `all` or `active` | **Notify about theme updates?** |
| `SC_WPUN_SETTING_HIDE_UPDATES` | `true` or `false` | **Update notice** |
| `SC_WPUN_SETTING_DOWNLOAD_LINKS` | `true` or `false` | **Download links** |

```php
define( 'SC_WPUN_SETTING_FREQUENCY', 'daily' );
define( 'SC_WPUN_SETTING_NOTIFY_PLUGINS', 'active' );
```

A yes or no value accepts `1`, `true`, `yes` and `on`, or `0`, `false`, `no` and `off`.

### Hide the About tab

The **About** tab can be hidden, and only in code. Set `SC_WPUN_SETTING_SHOW_ABOUT` to `false`:

```php
define( 'SC_WPUN_SETTING_SHOW_ABOUT', false );
```

## Channel options

Names start with `SC_WPUN_CHANNEL_`, then the channel's id in capitals, two underscores, and the option:

`SC_WPUN_CHANNEL_<ID>__<OPTION>`

The id is shown on the channel's edit form, together with the start of its constant names. The channel that a new install creates has the id `email`:

| Constant | Option |
| --- | --- |
| `SC_WPUN_CHANNEL_EMAIL__ENABLED` | **Enabled** (yes or no) |
| `SC_WPUN_CHANNEL_EMAIL__TO` | **Send to** |
| `SC_WPUN_CHANNEL_EMAIL__FROM` | **From address** |
| `SC_WPUN_CHANNEL_EMAIL__REPLY_TO` | **Reply-To address** |
| `SC_WPUN_CHANNEL_EMAIL__FORMAT` | **Format**: `text` or `html` |
| `SC_WPUN_CHANNEL_EMAIL__SUBJECT_PREFIX` | **Subject prefix** |

```php
define( 'SC_WPUN_CHANNEL_EMAIL__TO', 'ops@example.org,me@example.org' );
define( 'SC_WPUN_CHANNEL_EMAIL__FORMAT', 'html' );
```

Add-ons that bring their own channels get constants named the same way. Their documentation says which options are worth setting.

## Environment variables

Environment variables use the same names as the constants. They are ignored unless the environment variable `SC_WPUN_LOAD_FROM_ENV` is set to `1`, `true`, `yes` or `on`. This stops variables that your host happens to set from changing the plugin.

`SC_WPUN_LOAD_FROM_ENV` has to be an environment variable. Defining it as a constant in `wp-config.php` does nothing. If a constant and an environment variable both supply a value, the constant wins.

## Whole channels in code

The `SC_WPUN_CHANNELS` constant defines channels as a JSON list. Each channel has an `id` and a `type`, and can have a `label`, `enabled` (on unless you say otherwise), `settings` and `routing`:

```php
define(
	'SC_WPUN_CHANNELS',
	'[{"id":"ops_email","type":"email","label":"Operations","settings":{"to":"ops@example.org"}}]'
);
```

`routing` holds the same choices as the channel form: `types`, `scope`, `core` and `remind`. For example, `"routing":{"remind":"weekly"}` sends a weekly reminder of updates still waiting. `remind` is `never` (the default), `daily`, `weekly` or `every_check`. See [Reminders](channels.md#reminders).

These channels show **Defined in code** on the **Channels** tab. You can send a test through them but not edit, duplicate, disable or delete them there. A channel in code replaces a saved one with the same id.

## If a value is not valid

A value the plugin can not use is not applied:

- An invalid setting falls back to the value you saved.
- A channel with an invalid value does not send until you fix it.

The plugin tells you in two places: an error notice at the top of **Settings → Updates Notifier**, and a **WP Updates Notifier cannot use some configuration values** item in **Tools → Site Health**. Both name the constant or variable and where it was read from, never its value.

## More detail

How the sources are asked, the field types, and how to write a source that reads from a secrets service are in [Configuration sources](../developers/configuration-sources.md) in the developer guide.
