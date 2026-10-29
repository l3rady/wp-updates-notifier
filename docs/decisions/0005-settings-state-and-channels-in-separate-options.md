# 0005. Keep settings, run state and channels in separate options

Status: Accepted. Date: 2026-10-12.

## Context

In 1.x the settings and the run state (the versions already notified, and the time of the last check) shared one autoloaded option, `sc_wpun_settings`. Every cron run rewrote the whole array several times. A settings save that overlapped a cron run could be overwritten by the cron's stale copy. The run state also went through the settings sanitiser.

The module system added a third kind of data: channel instances, each with its own settings and routing.

## Decision

Each kind of data has its own option:

| Option | Holds | Notes |
| --- | --- | --- |
| `sc_wpun_settings` | The global settings | Versioned by `sc_wpun_settings_ver`, which drives upgrades. |
| `sc_wpun_state` | The run state | Not autoloaded, written once per run. |
| `sc_wpun_channels` | The channel instances | Not autoloaded. |
| `sc_wpun_muted` | The muted plugins and themes | Not autoloaded. Added in 3.0.0, see [0019](0019-mutes-in-their-own-option-through-the-global-veto.md). |

The 1.x email and Slack settings are migrated into channel instances once, and removed from `sc_wpun_settings`. The option names stay as they were wherever one existed, so older data and tools keep working.

The options are **not public API**. Add-ons read and write instances through `sc_wpun_channels()`, so the layout can change without breaking them (see [stability](../developers/stability.md)).

## Consequences

- Good: a settings save and a cron run no longer overwrite each other.
- Good: the autoloaded option is small, and channel secrets are not autoloaded on every request.
- Bad: three options to migrate, back up and clean up on uninstall.
- Bad: code that read the old email and Slack keys from `sc_wpun_settings` directly stops working. This was recorded as a breaking change for 2.0.0.

## References

- [#75](https://github.com/l3rady/wp-updates-notifier/issues/75): settings and run state shared one option.
- [#93](https://github.com/l3rady/wp-updates-notifier/issues/93): channels as instances, and the 1.x migration.
