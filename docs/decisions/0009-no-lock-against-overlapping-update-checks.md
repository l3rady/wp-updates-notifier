# 0009. No lock against overlapping update checks

Status: Accepted. Date: 2026-10-18.

## Context

The action `sc_wpun_run_check` lets site code run the update check on demand. A manual run can overlap a scheduled one. Both can find the same new update and send it before either records it as notified, so a notice can arrive twice.

The first plan took a short lock around every check. Making it atomic is hard in WordPress: options need cache handling, and transients are not atomic.

## Decision

There is **no lock**. The worst outcome of an overlap is a duplicate update notice, and that is rare. The trade-off is documented in the [architecture](../developers/architecture.md) page and in [Writing a module](../developers/writing-a-module.md#duplicate-deliveries). A channel that must never deliver the same message twice keeps its own record in `send()`.

## Consequences

- Good: less code and fewer ways for a stuck lock to stop checks.
- Good: a manual run behaves exactly like a scheduled one.
- Bad: an occasional duplicate notice.
- Bad: channels with exactly-once needs carry the cost themselves.

## Considered options

- **A lock using a transient or an option with an expiry.** Rejected as a lot of plumbing for a rare, harmless outcome.

## References

- [#187](https://github.com/l3rady/wp-updates-notifier/issues/187): the public action to run the check, with the scope change in a comment.
- [PR #198](https://github.com/l3rady/wp-updates-notifier/pull/198): the implementation.
