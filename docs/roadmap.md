---
title: Roadmap
description: What WP Updates Notifier plans to do over the next year, and what it will not do.
---

# Roadmap

This roadmap covers November 2026 to November 2027. Plans can change: the
[milestones](https://github.com/l3rady/wp-updates-notifier/milestones) and
[issues](https://github.com/l3rady/wp-updates-notifier/issues) show the current
state of the work. The maintainer reviews this page at each major release and at
least once a year (see [Governance](https://github.com/l3rady/wp-updates-notifier/blob/main/GOVERNANCE.md)).

## Next release: 3.0.0

3.0.0 builds on the extension API from 2.0.0. Most of it is already on `main`:

- a generic webhook channel that sends JSON signed in the Standard Webhooks
  format;
- network admin support on multisite;
- muting a plugin or theme, and snoozing it until a date or for a while;
- reminders about pending updates on every check;
- WP-CLI commands and Site Health tests;
- labels for updates WordPress is set to install automatically, and optional
  download links;
- field groups and conditional fields for channel forms, a deferred delivery
  result and an action when a reported update is no longer pending, for
  developers of add-ons.

Still to do before the release: rounding off multisite support
([#360](https://github.com/l3rady/wp-updates-notifier/issues/360)) and bundled
translations for 19 more locales
([#299](https://github.com/l3rady/wp-updates-notifier/issues/299)).

## Over the next year

- **WordPress and PHP:** test every WordPress release, and trunk, in CI, and
  support new PHP versions when they come out. Minimum versions only go up in a
  major release, with a decision record.
- **A stable API for add-ons:** keep to the
  [stability and deprecation policy](developers/stability.md), so add-ons
  written for 2.0.0 keep working.
- **Translations:** through [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/wp-updates-notifier/),
  with complete locales bundled in the plugin.
- **Security:** a security review before each major release, fixes in the
  latest release within the times in the
  [security policy](https://github.com/l3rady/wp-updates-notifier/blob/main/SECURITY.md),
  the OpenSSF Scorecard checks kept passing, and the
  [OpenSSF Best Practices](https://www.bestpractices.dev/projects/16308) silver
  badge kept up to date.
- **Accessibility:** WCAG 2.2 AA for every screen, checked in CI.
- **Bugs and requests:** reply to issues and support threads, and fix bugs with
  a regression test.

## What the project will not do

- **Install updates itself.** WordPress already does that; the plugin tells you
  what is waiting
  ([decision 0020](decisions/0020-scheduled-checks-run-wordpress-automatic-updates-first.md)).
- **Add a JavaScript build to the settings screen.** It stays plain PHP and HTML
  ([decision 0011](decisions/0011-accessibility-wcag-2-2-aa-without-javascript.md)).
- **Phone home.** No telemetry, tracking or remote calls other than the
  notifications you configure.
- **Bundle runtime dependencies.** The plugin uses WordPress and PHP only.
- **Build in a channel for every service.** The built-in channels are email and
  the generic webhook; add-ons can add more through the
  [module API](developers/writing-a-module.md).
- **Patch old releases.** Fixes go into the latest release only.
