# 0020. Scheduled checks run WordPress's automatic updates first

Status: Accepted. Date: 2026-10-30.

## Context

A site owner asked not to be told about updates WordPress installs by itself (#83). Only `wp_version_check()` triggers WordPress's automatic updater. Under WP-Cron it fires `wp_maybe_auto_update` at its end, and the updater then handles plugins, themes and core in the same request. `wp_update_plugins()` and `wp_update_themes()` do not trigger it.

Before this decision the check ran `do_action( 'wp_version_check' )` only when "Notify about WordPress updates" was not "No". With core notifications off, plugins and themes with auto-updates on were reported before WordPress installed them.

## Decision

- **`Update_Checker::check()` runs `do_action( 'wp_version_check' )` once, at the start, whatever `notify_core` says.** It runs before the core, plugin and theme steps, so under WP-Cron WordPress's automatic updates run before the plugin and theme update data is read. An update WordPress installs by itself is not reported. What is still waiting is one WordPress failed to install or could not install. Outside WP-Cron (Check now, `wp updates-notifier check`) the call only refreshes the data. Removing the call, moving it after the steps, or making it conditional changes what users hear about, so a contract test pins it.
- **No setting to skip updates WordPress installs.** The original proposal skipped plugins and themes with auto-updates on. Applied to scheduled checks it would hide the updates WordPress failed to install, and the plugin does not report auto-update failures itself (ADR 0010). Core's "major releases only" and muting (ADR 0019) already cover the rest.
- **The plugin never calls `WP_Automatic_Updater::should_update( 'core', ... )` or `find_core_auto_update()`.** Both can send core's "update available" email.

## Consequences

- Good: scheduled checks no longer report updates WordPress installs a moment later, whatever the core setting.
- Good: nothing is hidden, so a failed automatic update is still reported.
- Bad: with core notifications off, WordPress's updater, which can include a core update, now runs inside the plugin's cron event. WordPress already runs it twice a day in its own event, and with core notifications on it already ran in the plugin's event.
- Bad: the updater does not run on every scheduled check. WordPress skips it when its last version check was under a minute ago, when api.wordpress.org does not answer, when another run holds the lock, or when something unhooked core's callback.

## References

- [Issue #83](https://github.com/l3rady/wp-updates-notifier/issues/83): label updates WordPress is set to install automatically.
- [#84](https://github.com/l3rady/wp-updates-notifier/issues/84): route plugin and theme auto-update emails, closed as not planned.
- [ADR 0010](0010-leave-wordpress-auto-update-emails-alone.md) and [ADR 0019](0019-mutes-in-their-own-option-through-the-global-veto.md).
