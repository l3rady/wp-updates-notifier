# 0002. Require PHP 7.4 and WordPress 6.7

Status: Accepted. Date: 2026-10-12.

## Context

The plugin declared no PHP requirement. `readme.txt` still said `Requires at least: 3.1` and `Tested up to: 5.3`, so WordPress.org showed its warning about plugins not tested with the latest three major releases. WordPress itself requires PHP 7.4 or later.

## Decision

Version 2.0.0 requires **PHP 7.4** (tested through 8.5) and **WordPress 6.7**. The plugin header, `readme.txt` and `composer.json` state the minimums. WordPress refuses to activate or update the plugin on older environments, so there is no runtime check.

WordPress 6.7 gives `wp_get_wp_version()` in place of the `$wp_version` global, and the 6.7 rules for loading translations at the right time. Everything else the plugin needs is older.

The code uses typed properties and `??`, and avoids syntax newer than PHP 7.4 (`match`, union types, constructor promotion, the nullsafe operator, named arguments). This is a breaking change, so the commits use `refactor!` or `feat!` with a `BREAKING CHANGE:` footer, and the release is a major version.

## Consequences

- Good: modern syntax, and one supported range to test.
- Good: WordPress.org no longer warns that the plugin is untested.
- Bad: sites below the minimum stay on 1.6.0, because WordPress.org does not offer them the update.
- Bad: CI has to test PHP 7.4 to 8.5 against WordPress 6.7, the current release and trunk.

## References

- [#50](https://github.com/l3rady/wp-updates-notifier/issues/50): require PHP 7.4 and WordPress 6.7.
