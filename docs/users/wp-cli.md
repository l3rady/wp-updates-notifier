# WP-CLI

The plugin adds commands to [WP-CLI](https://wp-cli.org/) under `wp updates-notifier`. Use them to run the check from a system cron, to see what a check would do, to test your channels, to mute plugins and themes and to see the state of the plugin.

Run them from the site's directory, or add `--path=/path/to/site`.

## check

Runs the update check now, as the schedule does. Each channel instance gets the updates it has not been told about yet, and each line says what happened.

```
wp updates-notifier check [--dry-run] [--force]
```

| Option | What it does |
| --- | --- |
| `--dry-run` | Shows what would be sent, and prints the message for each channel. Nothing is sent or saved. |
| `--force` | Sends everything that is waiting again, even what was already sent, and to channels that were set to skip it. A channel that is switched off, or was told by a service to wait, still gets nothing. |

Sample output:

```
Office (office): sent 5 items
Client (client): nothing new
Success: Check finished. 1 channel instance was sent a message.
```

A dry run says what it would do:

```
Office (office): would send 5 items
...
Success: Dry run finished, nothing was sent or changed. 1 channel instance would be sent a message.
```

The line for each channel is one of these:

- `switched off`
- `waiting until <date and time>`: the service asked the plugin to wait.
- `recorded without sending`: the channel was set to skip what was waiting.
- `sent 3 items` or `would send 3 items`
- `sent a reminder with 3 items` or `would send a reminder with 3 items`
- `nothing new`
- `failed: <reason>`

If no channel is switched on and available, the command warns and does nothing.

**Exit codes.** The command exits with 0 when it worked, also when there was nothing to send. It exits with 1 when a channel failed, so a system cron can tell you.

!!! note
    The check, and so `check --dry-run`, asks WordPress to refresh its update data first. That is the same as every check does. WordPress skips the request if it asked recently.

## test

Sends a test message. It has no updates in it.

```
wp updates-notifier test [--channel=<id>]
```

With `--channel`, the command tests that one channel instance. Find the id with `wp updates-notifier channels list`. Without it, every channel that is switched on, available and able to send a test message is tested.

```
Success: Test message sent through Office.
```

**Exit codes.** 0 when every test message was sent. 1 when the id is unknown, the channel is switched off, its module is not available (the message says why), it can not send test messages, or the send failed. Without `--channel`, 1 also when no channel could be tested.

## status

Shows the settings, the schedule, the channels, the mutes and the updates that are waiting.

```
wp updates-notifier status [--format=<table|json>]
```

It shows:

- **Settings.** The value of each setting and where it comes from: `database`, or for example a constant.
- **Schedule.** The frequency, when the next check is due and when the last one ran, in the site's time zone.
- **Channels.** For each channel instance: the module, whether the module is available, whether it is switched on, whether it is defined in code, the last delivery result, the last failure and the time it must wait until. Then its settings, each with its source.
- **Muted.** The plugins and themes that are [muted](muting.md), as `muted` lists them.
- **Pending updates.** For each channel instance, what is waiting for it, and which of that is new to it and which it was told about already.

Sample output:

```
Settings
setting         value  source
frequency       daily  database
notify_core     all    database
...
Schedule
frequency  next_run                       last_check
daily      2026-01-02 08:00:00 UTC        2026-01-01 08:00:01 UTC
Channels
id      label   module  availability  enabled  code_defined  last_result
office  Office  email   available     yes      no            success, 2026-01-01 08:00:01 UTC:
```

**Secrets are never shown.** A secret setting, such as an API token or a webhook address, shows only `set` or `not set`, in the table and in the JSON. If the module of a channel instance is not available, its settings are not shown at all, because without the module the plugin can not tell which ones are secret.

**Exit code.** 0.

In the JSON the sections are `settings`, `schedule`, `channels`, `muted` and `pending`. `muted` is a list with one entry for each mute, with the keys `type`, `key`, `name` (null if the item is not installed), `until` (null for an always-on mute), `from_code`, `time`, `user` (a user id, 0 if none) and `offered` (the version waiting, or null).

!!! note
    To find what is waiting, `status` does a dry run of the check. It sends and saves nothing, but it asks WordPress to refresh its update data, as `check --dry-run` does.

## mute

Mutes plugins or themes, so the notifications leave them out. See [Muting a plugin or theme](muting.md) for what that does.

```
wp updates-notifier mute <plugin|theme> <name>... [--until-next-version]
```

A plugin is named as for `wp plugin`: its directory name, its file for a single-file plugin, or its full file path from the plugins directory. A theme is named by its stylesheet. Names must be installed.

| Option | What it does |
| --- | --- |
| `--until-next-version` | Skips only the version that is waiting now, so a newer version is notified again. It fails for an item with no update waiting. |

```
wp updates-notifier mute plugin akismet hello.php
wp updates-notifier mute theme twentytwentyfive --until-next-version
```

Muting an item that is already muted the same way is a warning, not an error. Every name is handled even if one fails. A mute that is set in code, or that a site's code refuses, is an error with the reason.

**Exit codes.** 0 when every name was handled. 1 when a name is not installed, there is no update to skip, or a mute was refused. The last line then says how many names failed.

## unmute

Turns notifications on again.

```
wp updates-notifier unmute <plugin|theme> <name>...
```

Unmuting an item that is not muted is a warning. A mute that is set in code, or that a site's code refuses to remove, is an error. Names and exit codes are as for `mute`.

## muted

Lists the muted plugins and themes, including the ones set in code.

```
wp updates-notifier muted [--format=<table|json|csv|yaml|count>]
```

The columns are `type`, `name`, `key`, `mute` (`always` or `version X`), `set_in_code`, `muted_by` (a user name, empty if not known), `muted_at` and `update` (the version waiting, if any). `--format=count` prints only the number.

```
type    name         key                           mute          set_in_code  muted_by  muted_at                 update
plugin  Akismet      akismet/akismet.php           always        no           admin     2026-01-01 08:00:01 UTC
plugin  Hello Dolly  hello.php                     version 1.7.3 no           admin     2026-01-01 08:05:12 UTC  1.7.3
```

**Exit code.** 0.

## channels list

Lists the channel instances.

```
wp updates-notifier channels list [--format=<table|json|csv|yaml>] [--fields=<fields>]
```

The fields are `id`, `label`, `module`, `availability`, `enabled`, `code_defined` (`yes` when the instance is defined in code, so it can not be changed on the settings screen) and `last_result`. `wp updates-notifier status` shows where each setting of an instance comes from.

```
id      label   module  availability  enabled  code_defined  last_result
office  Office  email   available     yes      no            success, 2026-01-01 08:00:01 UTC:
```

`availability` is `available`, `rejected: <reason>` when the module needs a newer version of the plugin, or `unknown module` when the module is not installed or not active.

## Run the check from a system cron

Stop WordPress starting WP-Cron on page loads, in `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

Then run the check from the server's crontab, for example every hour:

```
0 * * * * wp updates-notifier check --path=/var/www/example.com --quiet
```

Run `wp updates-notifier check --dry-run` and `wp updates-notifier test` once by hand first. See also [How do I run the check from a system cron or WP-CLI?](troubleshooting.md#how-do-i-run-the-check-from-a-system-cron-or-wp-cli).
