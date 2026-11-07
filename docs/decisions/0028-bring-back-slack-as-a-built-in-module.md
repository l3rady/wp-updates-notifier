# 0028. Bring back Slack as a built-in module

Status: Accepted. Date: 2026-11-07.

## Context

Version 2.0.0 stopped sending to Slack ([ADR 0006](0006-drop-slack-in-2-0-0-and-keep-its-settings.md)). It kept every 1.x Slack setup as a paused `slack` channel instance, and told the site owner so with a one-time email, a notice on the settings screen and a Site Health item.

About 30,000 sites run the plugin, and Slack is the most requested channel after email. The sites that relied on it have had no Slack alerts since 2.0.0, and new sites have to build their own channel. The plugin now has what a Slack module needs: a public channel API, a webhook module that is the model for a channel that talks to a web service, field groups, and `Delivery_Result::from_response()` for the HTTP answer.

## Decision

Slack is a built-in module again, in `modules/slack/`, registered like the email and webhook modules.

- **It posts to an incoming webhook.** No Slack app, token or OAuth is needed. The type is `slack` and the settings are `webhook_url`, `channel`, `username` and `icon`. The first two are the keys the 1.x migration already writes, so a stored 1.x instance resumes as it is.
- **The address is a secret and has an allowlist.** The webhook address carries the token, so it is never shown again once saved and never appears in a result, a notice or a log, not even in the error text of the HTTP API. Only https addresses on `hooks.slack.com` and `hooks.slack-gov.com` are accepted. The list is checked when the settings are saved and again before every request, because an address can arrive without the form: from the 1.x migration, a constant, an environment variable or `sc_wpun_channels`. The `sc_wpun_slack_allowed_hosts` filter adds a host, for a proxy or a Slack-compatible service.
- **The message is Block Kit.** `Slack_Message` turns a notification into a header, one or more sections per item type, an "...and N more" line when items were left out, and a context line, with a plain-text fallback. It stays inside Slack's limits (50 blocks, 3,000 characters in a section, 150 in a header) and escapes only `&`, `<` and `>`, as Slack asks, so a plugin named `<!channel>` pings nobody. The existing `sc_wpun_channel_payload` filters apply.
- **Slack's answer is turned into a message to act on.** `Delivery_Result::from_response()` handles a failed request, a success, 429 with `Retry-After` and other statuses. The module adds one thing: a refusal with a known error word in the body (`no_service`, `channel_not_found` and so on) becomes a message the site owner can act on, with the word as the code. An unknown word gets the generic message of any other status.
- **A switched-on 1.x Slack instance delivers again.** At the first check after the upgrade it receives everything that is still waiting. A switched-off one stays off. If the webhook was deleted in Slack in the meantime, the delivery fails with the `no_service` message, which the Channels tab and Site Health show like any failure.
- **The 2.0.0 workaround is removed.** `Slack_Notice`, its email, its settings screen notice, its dismiss action and its Site Health test are deleted, and so is the special case for `slack` in the Site Health module check. Settings version 14 removes the `slack_notice_sent` mark from the stored state and the user meta `sc_wpun_dismissed_slack_notice`. Uninstall keeps removing both for sites that never ran the upgrade.

## Consequences

- Good: the sites that relied on Slack get their alerts back with no reconfiguration, and new sites get Slack without an add-on.
- Good: the channel classes are `@internal`, like those of the other built-in modules, and use only the public API. The only new public surface is the `slack` type and one filter.
- Good: one fewer admin action and one fewer notice to maintain.
- Bad: a site that did nothing about the 2.0.0 notice starts posting to Slack at the first check after the update to 3.0.0, with all the waiting updates in one message. This is the behaviour the site chose in 1.x, and it is in the upgrade notice.
- Bad: the Block Kit layout and the escaping are ours to keep right as Slack changes.
- Bad: a legacy webhook can set the channel, name and icon, while the webhook of a Slack app ignores them. The form collapses these fields and says so.

## Considered options

- **Leave Slack to an add-on.** Rejected: the sites that relied on it are the plugin's own users, and an add-on would ask each of them to find and install something to get back what 1.x did.
- **Post the plain-text or Markdown rendering of the notification.** Rejected: Slack's mrkdwn is its own dialect, and Block Kit gives a header, links with names and limits we can respect.
- **A Slack app with OAuth and `chat.postMessage`.** Rejected: it needs a token store, scopes and a consent flow, for a plugin that sends a message a day.
- **No allowlist, only `https_only`.** Rejected: an address that did not come through the form, such as an environment variable, would then reach any host.

## References

- [Issue #442](https://github.com/l3rady/wp-updates-notifier/issues/442): the Slack module.
- [ADR 0006](0006-drop-slack-in-2-0-0-and-keep-its-settings.md): Slack dropped in 2.0.0, which this record supersedes.
- [ADR 0004](0004-public-module-api-and-dogfooding.md): the built-in modules use the public API.
- [Hook reference](../developers/hooks.md#sc_wpun_slack_allowed_hosts): `sc_wpun_slack_allowed_hosts`.
- [Channels, the Slack channel](../users/channels.md#the-slack-channel).
