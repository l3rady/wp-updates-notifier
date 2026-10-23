# 0003. Rename the main file without a migration stub

Status: Accepted. Date: 2026-10-17.

## Context

The main file was `class-sc-wp-updates-notifier.php`, a class-file name. The conventional main file for the slug is `wp-updates-notifier.php`, which it was up to 1.4.4.

WordPress stores active plugins by path. When a plugin's main file is renamed, WordPress deactivates it on every site that updates. That happened when 1.5 renamed it away from `wp-updates-notifier.php`. A major version does not stop the update: WordPress.org offers it like any other.

## Decision

Version 2.0.0 renames the main file to `wp-updates-notifier.php` and ships **no migration stub**. Sites that update from 1.5 or 1.6 find the plugin deactivated and must activate it again. Their settings are kept, and activation schedules the check again. Sites still on 1.4.x already have the new path stored and stay active.

The readme's 2.0.0 upgrade notice tells users to activate the plugin again. The commit is `refactor!` with a `BREAKING CHANGE:` footer, so the changelog lists it.

## Consequences

- Good: no stub, no `active_plugins` rewriting and no tests for them, carried until the next major version for a one-off step.
- Good: the main file has the name people expect.
- Bad: sites on 1.5 or 1.6 that update in the background lose their update notices until someone activates the plugin. This is the failure the plugin exists to prevent, and it was accepted for one release.

## Considered options

- **A header-less stub at the old path.** It would replace the old path with the new one in `active_plugins` (and `active_sitewide_plugins` on multisite) as soon as it loaded, then load the new main file. WordPress loads active files without a plugin header, and only later validates the list, so it would have kept every site active. It was the first plan. The maintainer decided against it because 2.0.0 is already a breaking release, and the stub, its migration and its tests would be carried until 3.0.0.
- **Keep the old file name.** This avoids the problem and leaves a non-standard main file.

## References

- [#90](https://github.com/l3rady/wp-updates-notifier/issues/90): the rename, with the decision recorded in a comment.
- [PR #158](https://github.com/l3rady/wp-updates-notifier/pull/158): the implementation.
