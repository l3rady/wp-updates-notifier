# 0022. Remind on every check

Status: Accepted. Date: 2026-11-01.

## Context

ADR 0016 added reminders per channel, with the values `never`, `daily` and `weekly`. Version 1.4.4 repeated every waiting update at every check, and some users asked for that back.

Daily reminders do not give it. The tolerance is at most half the check interval, so with hourly or twice-daily checks a channel is still reminded only about once a day.

Snooze (#346) is coming. It lets an admin silence a single item, as muting already does for a plugin or theme. With that, "keep telling me until it is installed, and let me silence what I decided to leave" becomes a reasonable default for some admins.

## Decision

- **A fourth value.** `remind` gains `every_check`, labelled "Every check". The options are Never (still the default), Daily, Weekly and Every check.
- **Same clock, no interval.** The first check that finds an update waiting sends it as a normal message and starts the clock `channels[id][last_reminder]`, as for daily and weekly. Every later check is due: one reminder with everything still waiting, new items folded in. There is no interval and no tolerance.
- **Same rules as before.** `--force` never makes a reminder. A skip run sends no reminder but starts the clock. A failed reminder does not move the clock. When nothing is waiting, the clock is cleared. Muted items are never in a reminder.
- **Every kind of check counts.** The scheduled check, **Check for updates and notify now**, `wp updates-notifier check` and `sc_wpun_run_check` all go through the same check.
- **No schema change.** No setting or state key is added. Unknown values still normalise to `never`. The value set of a public routing key grows, which is part of API 1.1.0 (unreleased), so `SC_WPUN_API_VERSION` stays as it is.

## Consequences

- Good: admins who want to be nagged until an update is installed can get it, at any check frequency.
- Good: a small change. It reuses the clock, the message and the rules of ADR 0016.
- Bad: a channel with frequent checks can be noisy. A "Check now" right after a check sends another reminder. The admin chooses this per channel, and mute (and later snooze) quiet single items.

## Considered options

- **Make the daily tolerance-free.** Rejected: it would change what existing daily channels do, and it still would not repeat at every check.
- **A numeric interval.** Rejected: more settings UI for little gain over the four choices.

## References

- [Issue #362](https://github.com/l3rady/wp-updates-notifier/issues/362): remind about pending updates on every check.
- [Issue #81](https://github.com/l3rady/wp-updates-notifier/issues/81): optional reminders for updates still pending.
- [Issue #346](https://github.com/l3rady/wp-updates-notifier/issues/346): snooze.
- [ADR 0016](0016-pending-update-reminders-per-channel.md): the reminders this extends.
