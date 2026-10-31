# Settings

The **General** tab of **Settings → Updates Notifier** holds the settings that apply to the whole site, or to the whole network on multisite. Where each update goes is set per channel, on the [Channels](channels.md) tab.

Click **Save settings** after you change anything.

To stop the notifications about one plugin or theme, use the **Muted** tab. See [Muting a plugin or theme](muting.md).

## Status

- **Last checked**: when the plugin last ran a check, in your site's time zone, or "never".
- **Next check**: when the next scheduled check is due. If it says "not scheduled", save the settings to schedule it.

Running **Check for updates and notify now** updates **Last checked** but does not move **Next check**.

## Frequency to check

How often the plugin looks for updates:

- **Once Hourly** (the default)
- **Twice Daily**
- **Once Daily**
- **Once Weekly**

Shorter intervals are not offered, because each check also asks WordPress to refresh its update data from api.wordpress.org.

The schedule is run by WP-Cron, so a check only starts when someone visits the site, unless you run a system cron. See [Troubleshooting](troubleshooting.md#emails-only-arrive-after-someone-visits-the-site).

## Notify about WordPress updates?

- **No**: WordPress updates are not reported.
- **Yes** (the default): every WordPress update is reported.
- **Yes, but only major releases**: only updates that change the first two numbers of the version are reported. 6.7.1 to 6.8 counts, and 6.7 to 6.7.1 does not.

Choose **Yes, but only major releases** if you do not want to hear about minor releases that WordPress installs by itself.

**No** does not stop WordPress's automatic updates from running before each scheduled check. They run whatever you choose here, so plugin and theme updates that WordPress installs by itself are not reported. See [Updates WordPress installs automatically](how-notifications-work.md#updates-wordpress-installs-automatically).

## Notify about plugin updates? and Notify about theme updates?

Each has the same three choices:

- **No**: not reported.
- **Yes** (the default): every update is reported, whether or not the plugin or theme is active.
- **Yes, but only active plugins** or **Yes, but only active themes**: only the ones in use.

On a single site, "active" means:

- **Plugins**: plugins that are activated on the **Plugins** screen.
- **Themes**: the theme you have activated and, if it is a child theme, its parent theme.

On a multisite network, a plugin or theme is active when any site uses it, and network-activated plugins count too. See [Multisite networks](multisite.md).

These settings are the first filter. A channel can narrow them further with its own routing, but it can not report what is switched off here. See [Choose what each channel receives](channels.md#choose-what-each-channel-receives).

## Update notice

**Hide the WordPress update notice from users who cannot update WordPress.** This is on by default.

It removes WordPress's "a new version is available" banner from the admin screens of users who lack the `update_core` capability, such as editors and authors. They can not act on it. Users who can update WordPress still see WordPress's own notice.

This setting does not affect your notifications.

## Settings you set in code

A setting supplied by `wp-config.php` or an environment variable is read-only here and says "Set by" and where the value comes from. See [Settings in code](settings-in-code.md).
