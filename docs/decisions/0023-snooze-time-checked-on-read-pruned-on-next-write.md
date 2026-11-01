# 0023. Check a snooze's time on read, prune on the next write

Status: Accepted. Date: 2026-11-01. Revised by #348 and [ADR 0024](0024-resolved-items-detected-by-the-update-check.md): re-notification by flagging instead of forgetting.

## Context

Snooze (#346) mutes an item until a time. When the time comes the item must be reported again, even if that version was reported before the snooze started.

ADR 0019 keeps the mutes in their own option and says the update check never writes it, because the check rewrites the run state with no lock (ADR 0009) and a mute made during a run would be lost. A "skip this version" mute is already recognised as ended when read and removed the next time the option is written. A snooze ends by time instead of by version, but the same rule fits.

## Decision

- **Time is checked on read.** A mute with an `expires` that has passed counts as ended. `is_muted()` is false, `get()` and `all()` leave it out, and `find()` still returns it for the check. This holds from the second the time passes, with no clean-up needed.
- **Pruned on the next write.** The check never writes `sc_wpun_muted`. The expired snooze is removed the next time a mute is saved or removed (ADR 0019).
- **Re-notification by flagging.** On every check, for every channel instance, the notified entry of an item whose snooze is in force is kept and flagged as snoozed (`snoozed` in its `notified_meta`). A flagged entry counts as new (`State::is_new()`), so when the snooze ends, the waiting version is sent as a normal message, or folded into a reminder that is due. Sending or recording the item clears the flag; a failed send keeps it. We do not act when the end is seen, because another mute change can prune the expired snooze first, and then the end is never seen and the item is never sent again. Keeping the entry also lets an update installed during the snooze be reported as resolved (ADR 0024).
- **`sc_wpun_mute_ended` gets a `$reason`.** It is `version` or `expired`. For a snooze it fires once, and the check records that in the run state under `ended_snoozes` (type, key, expiry time), kept only while the same snooze is still found. A mute that ended both ways is reported once, as `expired`. It never fires in a dry run.
- **The links sit in the update notice,** next to "Don't notify me about version X", and in the row meta when core hides the notice. The user chose this over row actions.
- **Only listed lengths are accepted.** The link carries the length in its security token, and the handler accepts only a length that the `sc_wpun_snooze_durations` filter lists.
- **The source is stored.** A mute keeps where it was set from (`plugins_screen`, `settings`, `cli`, `api` or an add-on's name), so the Muted tab can show it. `sc_wpun_mute_source_label` changes the name shown.
- **API version.** `SC_WPUN_API_VERSION` stays 1.1.0. It is unreleased in this cycle.

## Consequences

- Good: no cron event, no lock and no new write path. A snooze needs no state except `ended_snoozes`.
- Good: the resend does not depend on the order of events.
- Good: a policy can cap snoozes with `sc_wpun_pre_mute`.
- Bad: unmuting a snooze early sends the item again at the next check, because its notified entry is flagged. This is intended.
- Bad: `sc_wpun_mute_ended` can be missed if another mute change prunes the snooze before the next check. The resend is not missed. This is the same trade-off as ADR 0019.
- Bad: a version reported before the snooze is reported again after it, and its reported time starts again.

## Considered options

- **Forgetting the notified version while snoozed.** Replaced by flagging in ADR 0024: forgetting made an update installed during a snooze impossible to report as resolved.
- **A cron event per snooze.** Rejected: something to schedule, unschedule and repair. Checking the time on read is enough.
- **Pruning in the check.** Rejected: there is no lock (ADR 0009), so the check could overwrite a mute made at the same time.
- **Clearing the notified version at expiry.** Rejected: the clearing is lost when the expired snooze is pruned before the check sees it.
- **A separate `snooze()` method.** Rejected: it would repeat `mute()` with one more argument.
- **Row actions on the Plugins screen.** Rejected: the user chose links in the update notice, beside the skip link.

## References

- [Issue #346](https://github.com/l3rady/wp-updates-notifier/issues/346): snooze muted plugins and themes.
- [ADR 0009](0009-no-lock-against-overlapping-update-checks.md): no lock against overlapping checks.
- [ADR 0019](0019-mutes-in-their-own-option-through-the-global-veto.md): mutes in their own option.
- [ADR 0022](0022-remind-on-every-check.md): reminders, which a snoozed item is left out of.
