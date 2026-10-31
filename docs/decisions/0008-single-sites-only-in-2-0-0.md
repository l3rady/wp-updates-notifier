# 0008. Support single sites only in 2.0.0

Status: Superseded by [0021](0021-network-only-on-multisite.md). Date: 2026-10-17.

## Context

On multisite, a network-activated plugin runs once per site. Each site has its own settings page, cron event and notification, so one update produces one email per site. A proper fix needs a network admin screen, one cron event on the main site, network-level settings and channel instances, and changes to activation and uninstall. That is a feature of its own, and it touches the settings, channels and tests.

## Decision

2.0.0 supports **single sites**. Multisite network support moves to 3.0.0. The local development stack will run a single-site and a multisite install side by side to prepare for it.

## Consequences

- Good: 2.0.0 stays focused on reliability and the extension API.
- Good: network support gets designed once, on top of the module system.
- Bad: on multisite, the duplicate emails per site remain in 2.0.0.
- Bad: the multisite tests for the new features wait for 3.0.0.

## References

- [#85](https://github.com/l3rady/wp-updates-notifier/issues/85): multisite network admin support, now in the 3.0.0 milestone.
- [#176](https://github.com/l3rady/wp-updates-notifier/issues/176): run single-site and multisite local sites side by side.
