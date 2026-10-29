# Muting a plugin or theme

Muting stops the notifications about one plugin or theme. Use it for a plugin you keep on an old version on purpose, a plugin whose updates are paid for and handled elsewhere, or one that something else looks after.

## The two kinds of mute

- **Always.** Nothing about that plugin or theme is sent, until you unmute it.
- **Skip this version.** Only for a plugin or theme that has an update waiting. The plugin remembers the version that is waiting now and says nothing about it. When a newer version is offered, the mute has ended and you are told about the newer version as usual.

A mute is global. A muted item goes to no channel. To keep an item from some channels only, use the channel's [routing](channels.md#choose-what-each-channel-receives).

WordPress core cannot be muted. Use **Notify about WordPress updates?** on the [General tab](settings.md#notify-about-wordpress-updates), or the channel's routing.

!!! note
    "Newer" is decided with PHP's `version_compare()`. It compares each part as a number, so `1.10` is newer than `1.9`. It also counts `2.0.0` as newer than `2.0`: if you skip version `2.0` and WordPress then offers `2.0.0`, the mute ends and you are told about `2.0.0`.

## How to mute

- **On the Plugins screen.** Each plugin has a **Mute update notifications** link under its name. When an update is waiting, the yellow update notice has **Don't notify me about version X**, which skips that version. A muted plugin says **Update notifications muted** and has an **Unmute update notifications** link instead. You can also tick plugins and use the bulk actions **Mute update notifications** and **Unmute update notifications**. The bulk actions mute for good.
- **On the Muted tab.** Open **Settings → Updates Notifier → Muted**. The table lists everything that is muted: the name, the kind of mute, who muted it and when, and whether an update is waiting. Use **Unmute** to turn notifications back on. Under the table, pick a plugin or theme, choose **Always** or **Skip the waiting version**, and click the button to mute it.
- **With WP-CLI.** See [`mute`, `unmute` and `muted`](wp-cli.md#mute).

**Themes** can only be muted on the Muted tab or with WP-CLI, because the Themes screen has no place for the link.

You need the same permission as for the settings screen, which is `manage_options` unless a developer changed it. People without it do not see the links. The links are protected by a security token, so a link someone sends you does nothing.

## What happens to a muted item

- **It still counts as waiting.** The plugin keeps its record of what each channel was told. Muting does not forget anything.
- **Unmuting an item you were already told about does not send it again.** If the update has not changed, the channel stays quiet.
- **Unmuting an item you were never told about sends it at the next check.** That is the scheduled check, **Check for updates and notify now**, or `wp updates-notifier check`.
- **Reminders leave it out.** A [reminder](how-notifications-work.md#reminders) lists only the updates that are not muted.
- **Messages do not mention it.** Muted items are listed on the Muted tab, in `wp updates-notifier status` and in the Site Health info (**Tools → Site Health → Info → WP Updates Notifier**).
- **A mute survives deactivating the plugin or theme.** Deleting it removes its mute.

## What muting does not change

Muting only affects this plugin's notifications. WordPress's own update notices in the dashboard, the update emails WordPress sends, and automatic updates are not touched. A muted plugin still shows its update in **Dashboard → Updates**, and is still updated if auto-updates are on for it.

## Mutes set in code

A developer can define mutes in code, for example in an mu-plugin. They work like the others and are listed on the Muted tab marked **Set in code**, but there is no **Unmute** for them. A developer can also refuse a mute, or refuse an unmute, and the message they gave is shown to you. See [Muting from code](../developers/muting-from-code.md).
