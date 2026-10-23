# 0006. Drop Slack in 2.0.0 and keep its settings

Status: Accepted. Date: 2026-10-14.

## Context

About 30,000 sites run the plugin, and some rely on Slack for update alerts. Version 2.0.0 is a modernisation release focused on reliability and the new extension API, and it ships email as its only channel. Removing Slack quietly would stop those alerts with no warning, which is exactly what the plugin exists to prevent.

## Decision

2.0.0 sends no Slack notifications, but it does not delete anything:

- The 1.x Slack settings are migrated into a `slack` channel instance, like any other, with its enabled flag.
- 2.0.0 registers no `slack` module, so the dispatcher treats the instance as **unavailable**. It is skipped, reported and never deleted. If a module that provides the `slack` type is installed later, it resumes the instance with no reconfiguration.
- Admins are told, once, in three places: a one-time email on the first run after the upgrade, a dismissible notice on the plugin's own settings screen only, and a Site Health item while a stored Slack instance exists. The email goes to the site's admin email if email notifications are off.
- The wording is factual: what changed, what was kept, and that more notification channels are expected later.

The notice is `includes/class-slack-notice.php`.

## Consequences

- Good: nobody loses their Slack settings, and nobody is left unaware that alerts stopped.
- Good: the notice appears only on the plugin's screen, as WordPress.org guideline 11 asks.
- Bad: sites that relied on Slack get no Slack alerts from 2.0.0 unless a module that provides the `slack` type is installed.
- Bad: a stored but unusable instance is code and data to carry.

## Considered options

- **Remove Slack and its settings.** Simplest, but silent for the sites that depend on it.

## References

- [#143](https://github.com/l3rady/wp-updates-notifier/issues/143): drop Slack, keep its settings.
- [#93](https://github.com/l3rady/wp-updates-notifier/issues/93): the migration to instances.
- [#92](https://github.com/l3rady/wp-updates-notifier/issues/92): unavailable instances in the dispatcher.
