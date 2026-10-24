# Channels

A channel is a place where notifications are sent. WP Updates Notifier comes with one kind, **Email**. You can have as many channels as you like, for example one that sends everything to you and another that sends only WordPress updates to a client.

Open them on the **Channels** tab of **Settings → Updates Notifier**. The table shows, for each channel:

- **Label**: the name you gave it, with the actions underneath. A channel set up in code also says **Defined in code**.
- **Module**: the kind of channel, such as Email.
- **Enabled**: **On** or **Off**.
- **Routing**: what the channel receives. **All updates** means no limit.
- **Last delivery**: **Never**, **Sent** with the date and time, or **Failed** with the date and time and the reason.

A test message does not count as a delivery, so it never changes **Last delivery**.

## Add a channel

1. Click **Add channel**.
2. Choose a module from the list. On a standard install that is **Email**.
3. Give it a **Label**. The channel's id is made from the label when you save it and cannot be changed afterwards.
4. Fill in the settings (see [the email options](#the-email-options)) and choose its [routing](#choose-what-each-channel-receives).
5. Click **Add channel**.

On the form, **Enabled** is **Send notifications through this channel**. Untick it to save the channel without using it yet.

### Updates that are already waiting

A new channel has been told about nothing, so its first check sends it everything that is waiting for an update, even if other channels were told about those updates long ago.

If you only want updates that appear from now on, tick **Don't send the updates that are already waiting**. This box appears when you add a channel or edit one that is switched off, and it applies when you save the channel switched on.

## Edit a channel

Click **Edit** under the channel's label, change what you need and click **Save channel**.

## Send a test message

Click **Send test** under a channel. A notice at the top says whether it was sent, and if it failed, why. The channel must be switched on.

A test goes only to that channel and is not cut down by its routing.

## Switch a channel off or on

Click **Disable** under the channel. Nothing is sent through a switched-off channel, and nothing is recorded as sent, so when you click **Enable** again it receives everything that is waiting. To avoid that, edit the channel while it is off and tick **Don't send the updates that are already waiting** before you switch it on.

If a constant or environment variable sets whether the channel is enabled, the channel shows "Set by" and the source, and there is no **Disable** or **Enable** action. See [Settings in code](settings-in-code.md).

## Duplicate a channel

Click **Duplicate** to copy a channel's settings and routing, for example to send the same options to another address. The copy is called "label (copy)" and is switched off. Edit it, change what you need and tick **Enabled** when it is ready.

## Delete a channel

Click **Delete** and confirm. The channel's settings and delivery history are removed, and this cannot be undone. If you add a channel again later it starts with no history.

## Channels defined in code

A channel defined in code is read-only. It shows **Defined in code** and only offers **Send test**. See [Settings in code](settings-in-code.md).

## Choose what each channel receives

Each channel has a **Routing** section. Use it to send some kinds of update to some channels only.

- **Item types**: tick **WordPress**, **Plugins** or **Themes** to receive only those. Tick nothing to receive every type, including any that an add-on adds later.
- **Plugins and themes**: **All**, or **Only active ones**.
- **WordPress updates**: **All**, or **Only major releases**.
- **Reminders**: **Never** (the default), **Daily** or **Weekly**. See [Reminders](#reminders).

Routing works on top of the [General settings](settings.md). Those decide what the plugin looks at, and routing then narrows what each channel gets. If **Notify about plugin updates?** is **No**, no channel can receive plugin updates. If it is **Yes, but only active plugins**, choosing **All** here still gives you only the active ones. The form adds a note under an option when a General setting limits it.

### Reminders

By default a channel is told about each update once. Set **Reminders** to **Daily** or **Weekly** to be reminded about updates you have not installed yet.

- A reminder is one message that lists every update still waiting for that channel. It is marked as a reminder: the email subject is "WP Updates Notifier: reminder of updates waiting for" followed by your site name, and the first line says "Reminder: these updates are still waiting for" followed by your site name and its address.
- A new update is still sent at once, as usual. If a new update turns up in the same check as a reminder that is due, you get one message, the reminder, with the new update in it.
- The first reminder comes one interval after an update starts waiting for the channel. Reminders stop when nothing is waiting any more.
- Each channel has its own setting, so you can, for example, remind only one address each week.
- The **Routing** column shows it, for example "Reminders: Weekly".

Reminders are worked out at each check, so they can not come more often than your [frequency to check](settings.md#frequency-to-check). See [How notifications work](how-notifications-work.md#reminders).

## The email options

| Option | What it does |
| --- | --- |
| **Send to** | Required. One or more email addresses, separated by commas. |
| **From address** | The address the email comes from. Leave it empty to use the WordPress default. Only set an address at a domain this site is allowed to send mail for. If you set it, the sender name is "WP Updates Notifier". |
| **Reply-To address** | Where replies go. Optional. |
| **Format** | **Plain text** (the default) or **HTML**. |
| **Subject prefix** | Text put before the subject, followed by a space. Optional. |

The subject is "WP Updates Notifier: updates available for" followed by your site name. With the prefix `[Client]` it becomes "[Client] WP Updates Notifier: updates available for My Site".

Why the From address matters is covered under [Why does the email not arrive?](troubleshooting.md#why-does-the-email-not-arrive).
