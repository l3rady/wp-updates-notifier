# 0010. Leave WordPress's own auto-update emails alone

Status: Accepted. Date: 2026-10-19.

## Context

1.x had an "Automatic updates" setting that rewrote the email WordPress sends after a core auto-update, sending it to the plugin's notification address. It covered core only, because plugin and theme auto-update emails still went to the admin email. With channels, "the notification address" is a guess: there may be no email channel, several, or a switched-off one. It also changed WordPress's default behaviour.

An issue to route plugin and theme auto-update emails the same way was open.

## Decision

The plugin reports available updates through its channels and **does not touch WordPress's own emails**. The `notify_automatic` setting and the hook on `auto_core_update_email` are removed. WordPress sends these emails to the site admin email. An admin who wants them elsewhere changes that email, or uses the `auto_core_update_email` and `auto_plugin_theme_update_email` filters. The request to route plugin and theme emails was closed as not planned.

## Consequences

- Good: less code, and WordPress behaves as documented.
- Good: no rule is needed to pick an email channel.
- Bad: a breaking change for sites that used the setting. Their core auto-update emails now go to the admin email.

## References

- [#262](https://github.com/l3rady/wp-updates-notifier/issues/262): stop redirecting core auto-update emails.
- [#84](https://github.com/l3rady/wp-updates-notifier/issues/84): route plugin and theme auto-update emails, closed as not planned.
