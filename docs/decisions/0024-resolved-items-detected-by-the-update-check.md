# 0024. Detect resolved items in the update check

Status: Accepted. Date: 2026-11-01.

## Context

Add-ons that follow up on a notification (close a helpdesk ticket, log time-to-update, post "done") need to know when a reported update is no longer pending (#348). An update is installed in many ways: the Updates screen, automatic updates, WP-CLI, a host's managed updates, an uploaded zip or FTP. Core's `upgrader_process_complete` covers only some of them.

The plugin already knows the answer. Each channel instance's state records what it was told (`notified`), and every update check sees what is pending. The check already forgets what is no longer pending.

## Decision

- **The check is the single source of truth.** `sc_wpun_item_resolved( $item, $how, $instance )` fires from the update check, after the state is saved, once per item per instance when an item in the instance's `notified` state is no longer pending. The cost is latency of up to one check interval, which is documented.
- **Parallel `notified_meta`.** The item as it was reported (name, installed version, links, and the time) is kept in `channels[id][notified_meta]`, next to `notified`, which stays a map of scalar versions because the dedupe and `normalise_versions()` rely on that. The details are written when the notified version changes, so a reminder or forced send keeps the original time. Before saving, the check trims the details to what is still notified, so one pass covers pruning and `skip_pending()`.
- **A snoozed item keeps its entry, flagged.** ADR 0023 first forgot the notified version while an item was snoozed, so it was new when the snooze ended. That would hide an update installed during the snooze. The check now keeps the entry and sets `snoozed` in its details; `State::is_new()` treats a flagged entry as new (also in the CLI status report), and recording the item clears the flag and starts its time again.
- **No download link is stored.** The package address can hold a licence key or a private token (see `sc_wpun_item_download_url`). Other links are kept.
- **A version guard.** An item gone from the update data counts as resolved only if the plugin or theme is no longer installed (`removed`), or the installed version is the notified one or newer (`updated`, `core`). If the update data is unusable, for example the `update_plugins` transient is not an object, every notified plugin is gone from the data; an installed version that is older is dropped silently, as before.
- **Built-in types only.** Core, plugin and theme. We can not tell what "installed" means for an add-on type, so those entries are forgotten silently, as today.
- **For every instance whose entry is pruned,** also a switched-off one or one waiting out a Retry-After, because pruning runs for all instances. Integrations can check `$instance->is_enabled()`. Items recorded by "skip what's waiting" count as notified, so they fire too.
- **Never in a dry run or a test send.** The dry run returns before the state is saved, and test sends do not go through the check.
- **API version.** `SC_WPUN_API_VERSION` stays 1.1.0. It is unreleased in this cycle (as in ADRs 0022 and 0023).

## Consequences

- Good: every route of installing is covered, with no new cron event, lock or write path.
- Good: `Notification_Item::get_notified_at()` gives add-ons what they need for time-to-update.
- Bad: up to one check interval of latency.
- Bad: the time of an item resets when it is sent again after a snooze.
- Bad: if two checks overlap it can fire twice (ADR 0009).
- Bad: entries stored before this change have no details. They fire with a null `notified_at`, no installed version and no links.

## Considered options

- **A filter to veto resolution.** Rejected: nothing sensible can be done with a veto.
- **Firing from `upgrader_process_complete`.** Rejected: it would fire twice for the same item and miss the other routes.
- **Changing `notified` to hold arrays.** Rejected: the dedupe and normalisation rely on scalar versions, and old state would need a migration.
- **Add-on item types.** Rejected for now: no way to know if one is installed.

## References

- [Issue #348](https://github.com/l3rady/wp-updates-notifier/issues/348): fire an action when a reported update is no longer pending.
- [ADR 0009](0009-no-lock-against-overlapping-update-checks.md): no lock against overlapping checks.
- [ADR 0023](0023-snooze-time-checked-on-read-pruned-on-next-write.md): snooze flags the notified entry.
