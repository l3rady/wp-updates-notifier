# 0029. Partial delivery, recorded from the result

Status: Accepted. Date: 2026-11-07.

## Context

A channel can send one notification in several requests, for example one message per batch of items. It can stop halfway: a 429, a timeout, or a deferral. A failed or deferred result recorded nothing as notified, so the next attempt sent everything again, including the items that had already gone out (#444). For a reminder it was the same: the whole reminder was sent again.

## Decision

- **The result says what went out.** `Delivery_Result::with_delivered( array $items )` returns a copy that names the items sent before the stop, and `get_delivered()` reads them. There is no new kind of result. It has a meaning only on a failure or a deferred result. On a success everything went, and the list is ignored.
- **Matching.** The update check matches the delivered items against the items of its own plan by type, key and new version. Items that are not in the plan are ignored. What is recorded comes from the plan's items, never from the module's objects, so the stored name, version and links are the plugin's own.
- **A normal message.** The delivered items are recorded as notified and the rest stay pending, so the next attempt sends only the rest. A skip flag and the reminder clock stay as they are, and the statuses are still waiting or failed.
- **A reminder.** The delivered items are recorded as notified too, because a due reminder can hold new items that were never notified. They are also kept as `channels[id][reminder_sent]`, with the same shape as `notified`. The next reminder leaves them out, in both the notification and the plan. `reminder_sent` is pruned when an item is resolved or changes version, cleared by a completed reminder, cleared when the reminder clock stops or starts, and cleared by `State::skip_pending()`. A resumed reminder with nothing left counts as complete: the clock restarts and nothing is sent. The clock does not restart on a partial result.
- **Snooze flags.** Recording clears the snooze flag of the delivered items exactly as a success would. Items that were not delivered keep theirs.
- **Test sends** ignore the delivered items, as they ignore other state.
- **`sc_wpun_pre_send`** may return a result with delivered items and it is treated the same. A callback that replaces the delivery normally delivers nothing.
- **API version.** `SC_WPUN_API_VERSION` stays 1.1.0. It is unreleased in this cycle (as in ADR 0025).

## Consequences

- Good: a retry does not send items twice, and a channel opts in with one call.
- Good: no reader of a result (Site Health, the Channels tab, the CLI, `last_failure`) needs to change.
- Bad: a module must be honest about what it sent. Items it names are never sent again, even if the service did not keep them.
- Bad: a reminder keeps a little more state while it is stopped halfway.

## Considered options

- **A separate `partial` result kind.** Rejected: every reader of a result would need to handle a fourth kind.
- **Modules keeping their own record of what they sent.** Rejected: it duplicates the per-instance `notified` state, and the two could disagree.

## References

- [Issue #444](https://github.com/l3rady/wp-updates-notifier/issues/444): which items went out in a partly delivered notification.
- [ADR 0016](0016-pending-update-reminders-per-channel.md): reminders.
- [ADR 0025](0025-deferred-delivery-result-held-until.md): the deferred result.
