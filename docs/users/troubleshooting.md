# Troubleshooting

## Why did I not get an email about an update?

Check these in turn:

1. **What does Site Health say?** Open **Tools → Site Health**. Under **Status**, the plugin reports whether the update check is scheduled and has run lately, whether WP-Cron is switched off, whether a channel is on and able to send, whether the module of each channel is available, and whether the last delivery of each channel failed. It makes no request to WordPress.org to do this. **Info → WP Updates Notifier** lists your settings (and where each comes from), the schedule and the channels, ready to paste into a support request. Secrets only show as set or not set, and email addresses are replaced.
2. **Was it already reported?** Each channel is told about each version once. See [How notifications work](how-notifications-work.md).
3. **Is the channel on?** On the **Channels** tab, **Enabled** must say **On**.
4. **Does a setting leave it out?** On the **General** tab, the setting for that kind of update must not be **No**, and if it is limited to active plugins or themes, the plugin or theme must be active. Check the channel's **Routing** column too.
5. **Did the send fail?** **Last delivery** shows **Failed** and the reason.
6. **Has the check run?** **Last checked** on the **General** tab says when. If it is old, see the next entry.
7. **Is the data old?** See [Why is the update data hours old?](#why-is-the-update-data-hours-old).

## Why did no reminder arrive?

Reminders are optional and per channel. Check these in turn:

1. **Is it on?** Edit the channel. **Reminders** must be **Daily** or **Weekly**, not **Never**.
2. **Is anything waiting?** A channel is reminded only about updates it receives that are still waiting. Check the channel's **Routing** column, and the **General** tab settings. If routing leaves nothing for the channel, there is nothing to remind it about.
3. **Has the first interval passed?** The first reminder comes one interval after an update starts waiting for the channel, not at once. A new update is sent at once as a normal message.
4. **How often do you check?** Reminders are decided at each check, so a weekly check can not remind you daily. See [Reminders](how-notifications-work.md#reminders).
5. **Is the channel switched on and free to send?** A channel that is switched off, or was told by a service to wait (**Rate limited, next try after** under **Last delivery**), gets no reminder until it is on and the wait is over.
6. **Did the last send fail?** **Last delivery** shows the reason. A failed reminder is tried again at the next check.

## Does it work on multisite?

Yes, when the plugin is network-activated. The network has one set of settings in the Network Admin, under **Settings → Updates Notifier**, one check on the main site and one notification for each update. A copy activated for a single site does nothing until a super admin network-activates it. The Multisite networks page of the documentation has the details.

## Emails only arrive after someone visits the site

The schedule is run by WP-Cron. WP-Cron is not a real clock. WordPress only looks for due tasks when a page is requested, so on a site that few people visit, a check due at 03:00 can wait until someone arrives at 09:00. A forum user put it as "I only receive emails after someone has logged in".

On a multisite network, it is the visitors of the main site that matter, because the check runs there.

To fix it, run WP-Cron from the server's own clock. See [How do I run the check from a system cron or WP-CLI?](#how-do-i-run-the-check-from-a-system-cron-or-wp-cli).

## How do I run the check from a system cron or WP-CLI?

Use the server's own clock instead of WP-Cron. There are two ways.

### Run the WP-CLI command

This is the way we suggest. Stop WordPress starting WP-Cron on page loads by adding this to `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

Then have the server run the check, for example every hour:

```
0 * * * * wp updates-notifier check --path=/var/www/example.com --quiet
```

The command runs the same check as the schedule, and anything already reported is not sent again. It exits with an error if a channel failed, so your cron can tell you. It does not use WP-Cron, so **Next check** on the **General** tab shows nothing. The time of your cron entry is the schedule.

Other commands help you set it up:

- `wp updates-notifier check --dry-run` shows what would be sent and changes nothing.
- `wp updates-notifier test` sends a test message through each channel.
- `wp updates-notifier status` shows the settings, the schedule, the channels and what is waiting.

Run `wp help updates-notifier` for all of them.

Things to know:

- The plugin does not lock checks against each other. If this overlaps another run, the same update can be sent twice. This is a known trade-off, and rare. See [How notifications work](how-notifications-work.md#overlapping-checks).
- A check started this way is not a WP-Cron run, so WordPress lets plugin and theme data get up to 12 hours old before it refreshes it. A check started by `wp-cron.php` allows 2 hours. See [Why is the update data hours old?](#why-is-the-update-data-hours-old).

### Request wp-cron.php

If you can not use WP-CLI, keep `DISABLE_WP_CRON` and have the server request `wp-cron.php` on a regular schedule, for example every 15 minutes:

```
*/15 * * * * curl -fsS https://example.com/wp-cron.php > /dev/null
```

WP-Cron then runs the plugin's check at the **Frequency to check** you chose, and WordPress's own update tasks too.

## I was told about an update that has auto-updates on

Scheduled checks run WordPress's automatic updates first, so an update that is still waiting and has auto-updates on is one WordPress failed to install or could not install. The message carries the note "Automatic updates are on, but WordPress has not installed this update yet." Check these:

- The email WordPress sends to the site admin email (**Settings → General**) after an automatic update. It says what failed.
- **Dashboard → Updates**, where you can install the update yourself.
- **Tools → Site Health → Info → WP Updates Notifier → WordPress automatic updates**. It says whether automatic updates can run on this site, or why not: `DISALLOW_FILE_MODS`, `AUTOMATIC_UPDATER_DISABLED` or the `automatic_updater_disabled` filter, or a version control checkout.

A check started with **Check for updates and notify now** or `wp updates-notifier check` does not run WordPress's updater first, so it can report an update that WordPress installs later. See [Updates WordPress installs automatically](how-notifications-work.md#updates-wordpress-installs-automatically).

## A scheduled check takes a long time

Before it reads the updates, a scheduled check lets WordPress run its automatic updates. That can include downloading and installing plugins, themes and WordPress itself, so the check can take a while. This is WordPress's own work, and it also runs in WordPress's own twice-daily event. If it times out on your host, switch off automatic updates for the items that are slow, or run the update yourself from **Dashboard → Updates**.

## Why is the update data hours old?

On every check the plugin asks WordPress to refresh what it knows about updates. WordPress asks api.wordpress.org, but it does not do so every time. It skips the request when it has asked recently:

| How the check started | Core updates | Plugins and themes |
| --- | --- | --- |
| The schedule, or `wp-cron.php` | 1 minute | 2 hours |
| **Check for updates and notify now**, or `wp updates-notifier check` | 1 minute | 12 hours |

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
