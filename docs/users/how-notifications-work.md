# How notifications work

## Each update is reported once

On every check the plugin asks WordPress which updates are waiting. It then sends each enabled channel only what that channel has not been told about yet.

- The plugin remembers, for each channel, the version of each plugin, theme and WordPress it has reported. If the update is still waiting at the next check, nothing is sent.
- A newer version is a new update. If a plugin goes from 2.0 to 2.1 and then 2.2 is released before you update, you are told about 2.2 as well.
- Once an update is no longer waiting, for example because you installed it, the plugin forgets it.
- Each channel keeps its own record. Adding a channel does not cause the others to send again.

A check sends one message to each channel, listing everything that is new to that channel. It does not send one message per update. If nothing is new, nothing is sent.

Updates that a channel does not receive because of its [routing](channels.md#choose-what-each-channel-receives) are not reported to it, and they do not count as sent.

## Reminders

A channel can repeat the updates you have not installed yet. Set **Reminders** to **Daily** or **Weekly** in the channel's [routing](channels.md#reminders). The default is **Never**.

- The reminder is one message listing all updates still waiting for that channel, not only the ones that were reported before. It is marked as a reminder (see below).
- New updates are not held back. They are sent at once, as a normal message.
- If a new update is found in the same check as a reminder that is due, you get one message: the reminder, with the new update in it. You never get two.
- The first reminder comes one interval after something starts waiting for the channel. When nothing is waiting, the interval is cleared. The next update to wait starts it again.
- A failed reminder does not restart the interval. The channel tries again at the next check.
- A channel that is switched off, or is waiting because of `Retry-After`, gets no reminder. If you added or switched on a channel and chose not to send what was already waiting, that check sends no reminder, but the interval starts, so you are reminded about those updates later.

Reminders are decided at each check, so they can not come more often than the [frequency to check](settings.md#frequency-to-check). A reminder may go out up to half a check interval early, but never more than half its own interval early. This is because WP-Cron often runs a little late, and a strict rule would then skip a whole cycle. For example:

- A daily check and daily reminders: each daily check sends a reminder.
- Hourly checks and daily reminders: a reminder goes about a day after the last one, up to half an hour early.
- Weekly checks and daily reminders: a reminder goes on every check.

**Check for updates and notify now**, and `sc_wpun_run_check`, work out reminders too.

## What a message contains

An email says:

- A first line, "There are updates available for" followed by your site name and its address.
- A line for each update: **name from version X to Y**, for example "WooCommerce from version 9.3.1 to 9.4.0".
- Under a plugin: links to its page on WordPress.org and its changelog, when it comes from WordPress.org, or its own link if it has one. Under a theme: its link, if it has one.
- Under a plugin: whether its author has tested it with your version of WordPress. The message also warns you if the new version needs a newer PHP or WordPress than your site has.
- The address of your site's **Dashboard → Updates** screen, with a request to visit it to update. In HTML format this is a link, "Go to the Updates screen to update."

The subject is "WP Updates Notifier: updates available for" followed by your site name, with the channel's subject prefix first if it has one.

A reminder has the same contents, but its first line is "Reminder: these updates are still waiting for" followed by your site name and its address, and its subject is "WP Updates Notifier: reminder of updates waiting for" followed by your site name.

The site address in the message is your site's home address, the **Site Address (URL)** under **Settings → General**. If it is wrong, see [Why does a message show the wrong address?](troubleshooting.md#why-does-a-message-show-the-wrong-address).

## When a send fails

If a channel fails, for example because the mail server refused the message, the plugin does not record those updates as reported to that channel:

- The other channels are not affected. A failure in one never stops the rest.
- The channel tries the same updates again at the next check.
- The reason is shown under **Last delivery** on the **Channels** tab, and in the notice after **Check for updates and notify now**.

Some channels, usually ones from add-ons that talk to an online service, can be told by the service to wait before trying again. This is the HTTP `Retry-After` header. The plugin then leaves that channel alone until the time is up, at most seven days, and **Last delivery** says **Rate limited, next try after** and the time. Other channels carry on. When the wait is over, the channel is sent everything that is new to it at the next check. The built-in email channel does not do this.

A test message ignores the wait.

## Overlapping checks

The plugin does not lock checks against each other. If you click **Check for updates and notify now**, or something fires `sc_wpun_run_check` or runs `wp updates-notifier check`, while a scheduled check is running, both can send the same update before either records it. You then get the same notification twice. This is rare and harmless for update notices, so the plugin accepts it rather than adding a lock.

## Where the checks come from

- **The schedule.** WP-Cron runs the check at the frequency you chose. See [Settings](settings.md#frequency-to-check).
- **The button.** **Check for updates and notify now** runs the same check straight away and does not change the schedule.
- **Your own trigger.** A system cron can run `wp updates-notifier check`. See [How do I run the check from a system cron or WP-CLI?](troubleshooting.md#how-do-i-run-the-check-from-a-system-cron-or-wp-cli).

All three send only what is new.

For the list of hooks that let developers change this, see the [hook reference](../developers/hooks.md).
