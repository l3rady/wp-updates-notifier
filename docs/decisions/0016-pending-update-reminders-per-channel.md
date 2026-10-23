# 0016. Remind about pending updates, per channel

Status: Accepted. Date: 2026-10-23.

## Context

Version 1.4.4 emailed about every waiting update on every check. Version 1.6 reports each version once, which stopped the repeated emails but also removed the nudge: an update nobody installs is never mentioned again. Users asked for the old behaviour back, for example in the forum thread "How to get old behavior of daily e-mail notification" (2020), and an earlier pull request, #13 (add-resend-updates-setting), tried a global setting.

Since 2.0.0 there are several channel instances, each with its own routing. A reminder has to fit that: someone may want a weekly email to the site owner and nothing for a chat channel.

## Decision

- **A per-channel routing key.** `remind` is `never` (the default), `daily` or `weekly`. It lives in the instance's routing, so channels defined in code can set it too, and no settings version changes.
- **A clock in the run state.** `channels[id][last_reminder]` in `sc_wpun_state` is the time the interval started or the last reminder was sent. It starts when something first waits for the instance, so the first reminder comes one interval later, and it is cleared when nothing waits or the setting is `never`. A skip run starts it too, so skipped updates are reminded about later.
- **One message, every waiting item.** A reminder lists everything waiting for the instance and is marked with `Notification::is_reminder()`. The built-in renderers and the email subject say so.
- **Fold rule.** A new update found in the same check as a due reminder goes in the reminder. There is one message, not two. A new update between reminders is sent at once, as before, and does not touch the clock.
- **Tolerance.** A reminder is due when the time since the last one is at least the interval minus half the check frequency's interval, with that tolerance capped at half the reminder interval. WP-Cron runs late on quiet sites and the next run is scheduled from the planned time, so a strict comparison would make a daily reminder on a daily check skip a cycle.
- **A failed reminder** does not move the clock and is tried again at the next check.

## Consequences

- Good: the old behaviour is available again, without the noise for people who did not ask for it.
- Good: it needs no new cron event, hook or lock. It reuses the update check.
- Bad: reminders follow the check frequency, so they can not be more frequent than checks. Weekly checks mean a daily reminder goes on every check.
- Bad: modules see one more kind of message. A reminder lists items that were delivered before, so a module that de-duplicates by item must not drop them. The module guide says so.

## Considered options

- **A global setting**, as in #13. Rejected: routing is already per instance, and "weekly to email only" is a real need.
- **A separate cron event** for reminders. Rejected: it adds scheduling to maintain, and the check already runs.
- **Two messages in the same run**, a reminder and a message for new updates. Rejected: it is noise.
- **Postponing the reminder when something new arrives.** Rejected: on a busy site the reminder could be postponed for ever.
- **A strict interval.** Rejected: cron drift would make reminders skip a cycle.

## References

- [Issue #81](https://github.com/l3rady/wp-updates-notifier/issues/81): optional reminders for updates still pending.
- Old pull request #13 (add-resend-updates-setting), which proposed a global setting.
- [Issue #75](https://github.com/l3rady/wp-updates-notifier/issues/75) and [issue #95](https://github.com/l3rady/wp-updates-notifier/issues/95): the run state option and the per-channel routing and delivery state that reminders build on.
