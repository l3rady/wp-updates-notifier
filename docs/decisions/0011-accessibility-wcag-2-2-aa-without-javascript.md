# 0011. Settings screen to WCAG 2.2 AA, without JavaScript

Status: Accepted. Date: 2026-10-18.

## Context

A review of the settings screen, the field markup that modules get for free, the notices and the notifications found gaps against WCAG 2.2 level AA. Many were small, such as page titles, error messages and heading structure. Two needed a choice: whether an error summary should rely on JavaScript, and what markup the channels list should use.

## Decision

The settings screen and the notifications meet **WCAG 2.2 AA**. The admin UI needs **no JavaScript**: for example, a failed form gets an "Error:" page title and an error summary that is announced without script. The channels list follows core's `WP_List_Table` pattern.

CI checks this. `make a11y` runs axe (WCAG 2.2 A and AA) and keyboard tests against a throwaway site, as a required check. A person still checks new markup by keyboard and with a screen reader.

## Consequences

- Good: the screen works for keyboard and screen reader users, and with scripts blocked.
- Good: modules get accessible field markup without extra work.
- Bad: automated checks catch only part of WCAG, so manual checks stay necessary.
- Bad: some interactions are plainer than script would allow.

## References

- [#206](https://github.com/l3rady/wp-updates-notifier/issues/206): the accessibility review epic.
- [PR #264](https://github.com/l3rady/wp-updates-notifier/pull/264): the fixes and `make a11y`.
