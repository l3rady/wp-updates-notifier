# Troubleshooting

## Why did I not get an email about an update?

Check these in turn:

1. **Was it already reported?** Each channel is told about each version once. See [How notifications work](how-notifications-work.md).
2. **Is the channel on?** On the **Channels** tab, **Enabled** must say **On**.
3. **Does a setting leave it out?** On the **General** tab, the setting for that kind of update must not be **No**, and if it is limited to active plugins or themes, the plugin or theme must be active. Check the channel's **Routing** column too.
4. **Did the send fail?** **Last delivery** shows **Failed** and the reason.
5. **Has the check run?** **Last checked** on the **General** tab says when. If it is old, see the next entry.
6. **Is the data old?** See [Why is the update data hours old?](#why-is-the-update-data-hours-old).

## Why did no reminder arrive?

Reminders are optional and per channel. Check these in turn:

1. **Is it on?** Edit the channel. **Reminders** must be **Daily** or **Weekly**, not **Never**.
2. **Is anything waiting?** A channel is reminded only about updates it receives that are still waiting. Check the channel's **Routing** column, and the **General** tab settings. If routing leaves nothing for the channel, there is nothing to remind it about.
3. **Has the first interval passed?** The first reminder comes one interval after an update starts waiting for the channel, not at once. A new update is sent at once as a normal message.
4. **How often do you check?** Reminders are decided at each check, so a weekly check can not remind you daily. See [Reminders](how-notifications-work.md#reminders).
5. **Is the channel switched on and free to send?** A channel that is switched off, or was told by a service to wait (**Rate limited, next try after** under **Last delivery**), gets no reminder until it is on and the wait is over.
6. **Did the last send fail?** **Last delivery** shows the reason. A failed reminder is tried again at the next check.

## Emails only arrive after someone visits the site

The schedule is run by WP-Cron. WP-Cron is not a real clock. WordPress only looks for due tasks when a page is requested, so on a site that few people visit, a check due at 03:00 can wait until someone arrives at 09:00. A forum user put it as "I only receive emails after someone has logged in".

To fix it, run WP-Cron from the server's own clock. See [How do I run the check from a system cron?](#how-do-i-run-the-check-from-a-system-cron).

## How do I run the check from a system cron?

There are two ways. Use the first unless you have a reason not to.

### Request wp-cron.php

Stop WordPress starting WP-Cron on page loads by adding this to `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

Then have the server request `wp-cron.php` on a regular schedule, for example every 15 minutes:

```
*/15 * * * * curl -fsS https://example.com/wp-cron.php > /dev/null
```

WP-Cron then runs the plugin's check at the **Frequency to check** you chose, and WordPress's own update tasks too.

### Fire the check yourself

The plugin's check can also be started from code with the `sc_wpun_run_check` action. For example, with WP-CLI:

```
*/30 * * * * cd /path/to/site && wp eval "do_action( 'sc_wpun_run_check' );"
```

This runs the same check as the schedule, and anything already reported is not sent again. It does not move the WP-Cron schedule, so **Next check** on the **General** tab does not change. If you rely on this alone, the time of your cron entry is the schedule.

Things to know:

- The plugin does not lock checks against each other. If this overlaps another run, the same update can be sent twice. This is a known trade-off, and rare. See [How notifications work](how-notifications-work.md#overlapping-checks).
- A check started this way is not a WP-Cron run, so WordPress lets plugin and theme data get up to 12 hours old before it refreshes it. A check started by `wp-cron.php` allows 2 hours. See [Why is the update data hours old?](#why-is-the-update-data-hours-old).

## Why is the update data hours old?

On every check the plugin asks WordPress to refresh what it knows about updates. WordPress asks api.wordpress.org, but it does not do so every time. It skips the request when it has asked recently:

| How the check started | Core updates | Plugins and themes |
| --- | --- | --- |
| The schedule, or `wp-cron.php` | 1 minute | 2 hours |
| **Check for updates and notify now**, or `wp eval` | 1 minute | 12 hours |

So a scheduled check can see plugin and theme data up to two hours old, and a manual check up to twelve. WordPress also asks again early if the plugins or themes you have installed, or their versions, have changed.

To get current data before a manual check, open **Dashboard → Updates** in WordPress first. That page refreshes the data if it is more than a minute old.

!!! note
    These limits belong to WordPress, not to this plugin. They keep your site from calling api.wordpress.org too often.

## Why does the email not arrive?

**Send test** on the **Channels** tab says "Test message sent" when WordPress has handed the email to your mail system. That does not mean the message was delivered. Check the spam folder first, then look at how your site sends mail.

- **The From address.** With **From address** empty, the email uses the WordPress default, which is `wordpress@` followed by your site's domain. If you set one, it must be at a domain your server is allowed to send mail for. Mail that claims to come from a domain the server has no permission for is often rejected or sent to spam.
- **SPF, DKIM and DMARC.** These DNS records tell mail providers which servers may send for your domain. If your domain publishes a strict DMARC policy and the From address does not line up with the server that sends, providers can reject the message. Ask your host or mail provider for the right records.
- **The server's mail.** Many hosts block or throttle the basic mail function WordPress uses. Install an SMTP plugin, or a plugin for a mail service, so WordPress sends through an authenticated mail server. Then send a test again.
- **Upgraded from 1.x?** Versions before 2.0.0 sent from the administration email address unless you set another. If your From address was that same address, the upgrade cleared it, so mail goes from the WordPress default. Set **From address** if your mail setup needs one.

If WordPress itself fails to send, the reason appears in the notice after the test and under **Last delivery**.

## Why does a message show the wrong address?

The address in a message is your site's home address, the **Site Address (URL)** in **Settings → General**. The link to the **Dashboard → Updates** screen is built from the **WordPress Address (URL)**.

Both can be wrong if `wp-config.php` sets `WP_HOME` or `WP_SITEURL` from the request, for example from `$_SERVER['HTTP_HOST']`. The check then runs in a request from a system cron or a visitor, and the address follows whichever host that request used. Set `WP_HOME` and `WP_SITEURL` to fixed addresses, or remove them and use the settings in **Settings → General**.

## I see a "configuration" notice or a Site Health item

An error notice at the top of **Settings → Updates Notifier** says "Some configuration values supplied from outside the database are not valid." The same problem appears in **Tools → Site Health** as "WP Updates Notifier cannot use some configuration values".

A constant, environment variable or other source gave the plugin a value it can not use. The notice names the constant or variable and where it was read from, never the value. An invalid setting falls back to the value you saved, and a channel with an invalid value does not send until you fix it. Correct the value, or remove it. See [Settings in code](settings-in-code.md).

## Why did Slack notifications stop?

WP Updates Notifier 2.0.0 does not send Slack notifications, and your Slack settings are kept. If a Slack channel was switched on, the plugin tells you once by email, shows a notice on its settings page that you can dismiss, and adds an item to **Tools → Site Health**. The notice and the item stay while a Slack channel is switched on.

## A channel shows as unavailable: "needs WP Updates Notifier API …"

The add-on that provides that channel needs a newer version of WP Updates Notifier than this site has, so the plugin refused to load it. Nothing is sent through the channel, but its settings are kept.

Update WP Updates Notifier (or install an older version of the add-on) and the channel works again with no reconfiguration. These places show which module is affected and the version it needs:

- the **Unavailable channels** list on the **Channels** tab;
- a notice at the top of **Settings → Updates Notifier**;
- **Tools → Site Health**, as "Some WP Updates Notifier modules need a newer version";
- under the add-on on the **Plugins** screen.

## Where are the hooks?

If you write code, every action and filter is in the [hook reference](../developers/hooks.md).
