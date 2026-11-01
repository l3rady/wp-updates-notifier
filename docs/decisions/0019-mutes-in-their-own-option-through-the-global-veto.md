# 0019. Store mutes in their own option and apply them through the global veto

Status: Accepted. Date: 2026-10-29.

## Context

Site owners want to stop notifications about one plugin or theme: plugins pinned to a version, plugins with paid updates, plugins managed elsewhere. Alley's fork added an on/off switch per plugin. Two choices needed a decision: where a mute is applied in the check, and where it is stored.

## Decision

- **Applied through the global veto.** `Update_Checker::should_notify()` passes `! $mutes->is_muted( $item )` as the default of `sc_wpun_should_notify_item`. The `sc_wpun_plugins_need_update` and `sc_wpun_themes_need_update` filters are left to third parties (a test asserts the plugin registers no callback on them). The veto handles every item type alike, including items added with `sc_wpun_update_items`, and vetoed items still count as waiting, so the notified history and reminders behave correctly without new state code.
- **Two kinds of mute.** "Always", and "skip this version", which stores the version waiting and covers it and anything older (`version_compare()`). A newer version ends it.
- **Its own option, `sc_wpun_muted`**, not autoloaded, keyed by item type and then item key. Not in `sc_wpun_settings`, which has no list field type. Not in `sc_wpun_state`: every check rewrites the state with no lock (ADR 0009), so a mute made during a run would be lost.
- **Only users and WP-CLI write the option.** The check never does. An ended "skip" mute is recognised when read, hidden from the lists, and removed the next time the option is written. The check records that it told about the end (`ended_mutes` in the run state) so `sc_wpun_mute_ended` fires once at most. It is not fired when a mute or unmute pruned the ended entry before the next check; we accept that rather than keep ended mutes in the option.
- **A public API** from the start: `sc_wpun_mutes()`, `WPUN\Mute`, `WPUN\Mute_Store` and the hooks `sc_wpun_mutes`, `sc_wpun_pre_mute`, `sc_wpun_pre_unmute`, `sc_wpun_item_muted`, `sc_wpun_item_unmuted` and `sc_wpun_mute_ended`. The option layout stays private (ADR 0005). The API version goes from 1.0.0 to 1.1.0.
- **Core cannot be muted**, and multisite is left to a later issue.
- **Deleting a plugin or theme** (`deleted_plugin`, `deleted_theme`) removes its mute.

## Consequences

- Good: no change to the stored state or the settings, and no migration.
- Good: a third-party filter can still override a mute either way.
- Bad: a veto filter that returns `true` whatever it is given now unmutes everything. This is documented on the hook.
- Bad: a fourth option to back up and remove on uninstall.
- Bad: a stale ended mute can stay in the database until the next write. It is never shown.
- Bad: more public surface held to the deprecation policy.

## References

- [Issue #82](https://github.com/l3rady/wp-updates-notifier/issues/82): mute notifications for a plugin or theme. Based on an idea from Alley's fork (#39, pull request #41).
- [ADR 0005](0005-settings-state-and-channels-in-separate-options.md) and [ADR 0009](0009-no-lock-against-overlapping-update-checks.md).
- [ADR 0023](0023-snooze-time-checked-on-read-pruned-on-next-write.md): snoozes, which end by time and follow the same rule.
