# 0025. A deferred delivery result, held in its own state

Status: Accepted. Date: 2026-11-01.

## Context

A channel or a `sc_wpun_pre_send` callback could only say "sent" or "failed". To hold a delivery until later (outside office hours, during a maintenance window, while a downstream system is paused) it had to return a failure with a `retry_after`. That showed up as an error in Site Health, on the Channels tab and in the CLI, fired `sc_wpun_notification_failed`, and replaced the instance's last delivery result with a failure (#349).

## Decision

- **A third kind of result.** `Delivery_Result::deferred( int $until, string $message = '' )` takes a Unix time, not seconds. `is_success()` is false and `is_deferred()` is true. Code that reads a result must check `is_deferred()` before treating a result as a failure.
- **Its own state, `held_until`.** A hold is a per-instance time in the run state, next to `retry_at`, not a marker on `last_result`. A hold therefore never replaces the last real delivery result, and Site Health and `last_failure` need no special case. `State::record_delivery()` listens to `sc_wpun_notification_deferred` and sets it, capped at 7 days like `retry_after`; a time in the past means no hold. Any later sent or failed result clears it, and a deferred result leaves `last_result` and `retry_at` as they were.
- **One waiting rule.** The update check skips an instance while the later of `retry_at` and `held_until` is in the future, in `State::waiting_until()`. The plan keeps `STATUS_WAITING` and reports that time in its `retry_at`, so the CLI keeps printing "waiting until" and nothing new is needed. `sc_wpun_instance_waiting_until()` reads the same method so the two cannot drift. Check now, reminders and `check --force` are covered because the skip comes before any send or reminder logic.
- **Nothing is marked notified.** A deferred result in a real run records no items, keeps a skip flag and does not restart the reminder interval. The first check after the hold delivers everything still pending.
- **The stale copy.** The check saves state from a copy read at its start, and re-copies `last_result` and `retry_at` from fresh state first. `held_until` goes through the same step, with a test.
- **Test sends ignore a `pre_send` deferral.** The channel is called and the result message says it was not held because it is a test. A deferred result from the channel itself is returned unchanged and is not stored, as for any test.
- **Resolutions still fire for a held instance** (ADR 0024), as for a disabled or rate-limited one. `Notification_Item::get_notified_at()` is the time of delivery, so a time to update measured from it starts at delivery. `sc_wpun_instance_waiting_until()` lets an add-on decide what to do.
- **API version.** `SC_WPUN_API_VERSION` stays 1.1.0. It is unreleased in this cycle (as in ADRs 0022 to 0024).

## Consequences

- Good: a site holds a channel with a few lines of code, with no error shown anywhere.
- Good: the existing waiting path, CLI output and Retry-After behaviour are reused.
- Bad: every reader of a result that tests `is_success()` must check `is_deferred()` first. The plugin's own readers do; add-ons that call `send()` themselves must too.
- Bad: the hold is stored per instance, so it survives a change of the code that decided it. Removing the callback does not lift an existing hold before its time.
- Bad: items held back are told late, so `get_notified_at()` and any time to update measure from delivery.

## Considered options

- **A built-in quiet hours setting.** Rejected: it adds settings UI for a niche need. The deferred result lets any add-on or snippet implement its own windows.
- **A `deferred` marker on `last_result`.** Rejected: a hold would overwrite the last real result, and every reader (Site Health, `last_failure`, the CLI) would need to know about it.
- **Holding back `sc_wpun_item_resolved` for held instances.** Rejected: it needs entries to be kept beyond today's pruning, and the waiting accessor covers the need.

## References

- [Issue #349](https://github.com/l3rady/wp-updates-notifier/issues/349): a deferred delivery result to hold notifications without an error.
- [ADR 0024](0024-resolved-items-detected-by-the-update-check.md): resolved items.
