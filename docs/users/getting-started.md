# Getting started

## What you need

- WordPress 6.7 or newer.
- PHP 7.4 or newer.
- A single WordPress site. WP Updates Notifier 2.0.0 does not support multisite networks. If you use it on a network anyway, only super admins can change its settings.

## Install

Search for "WP Updates Notifier" under **Plugins → Add New** and click **Install Now**, then **Activate**. The [home page](../index.md#install) lists the other ways to install it.

## What you get straight away

On a new install the plugin sets up:

- A check that runs every hour (**Once Hourly**).
- One channel called **Email**. It is switched on and sends to the email address in **Settings → General** (the site's administration email address).
- Notifications about WordPress, plugin and theme updates, all of them.

Because the check is due as soon as you activate the plugin, the first one runs on one of the next page loads of your site. If updates are waiting, you get an email about them.

## Open the settings

Go to **Settings → Updates Notifier**. There are two tabs:

- **General**: the Status line, the **Check for updates and notify now** button, and the settings. See [Settings](settings.md).
- **Channels**: where notifications are sent. See [Channels](channels.md).

By default you need the `manage_options` capability to see them, which administrators have. On a multisite network you need `manage_network_options`, which only super admins have.

## Send a test message

Check that the email arrives before you rely on it.

1. Open the **Channels** tab.
2. Find **Email** in the table and click **Send test**.
3. A notice says **Test message sent through Email.** The subject of the email is "WP Updates Notifier: test message for" followed by your site name.

The notice means WordPress handed the message to your mail system. It does not prove the message was delivered. If it does not arrive, see [Why does the email not arrive?](troubleshooting.md#why-does-the-email-not-arrive).

The channel must be switched on to send a test.

## Change where emails go

Click **Edit** under **Email** and change **Send to**. Separate several addresses with commas. The other options are in [Channels](channels.md#the-email-options).

## Run a check now

On the **General** tab, click **Check for updates and notify now**. The plugin runs the same check as the schedule and tells you what happened:

- **Checked just now. Nothing new to send.**
- **Checked just now. Sent to 1 channel.**
- **Checked just now. Nothing was sent because no channel is enabled.**
- A message naming the channels that failed and why.

Only updates you were not told about yet are sent. The button does not change the schedule. See [How notifications work](how-notifications-work.md).

!!! note
    Plugin and theme data can be a few hours old when you click the button. See [Why is the update data hours old?](troubleshooting.md#why-is-the-update-data-hours-old) for why, and how to refresh it first.
