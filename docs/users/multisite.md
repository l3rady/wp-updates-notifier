# Multisite networks

On a multisite network, WP Updates Notifier works only when it is **network-activated**. The network has one set of settings and one check, and sends one notification for each update.

Only super admins can act on updates on a network, so the notifications and the settings belong to them.

## Set it up

1. Install the plugin in the **Network Admin**, under **Plugins → Add New Plugin**.
2. Click **Network Activate**. The plugin cannot be activated for one site.
3. Go to **Settings → Updates Notifier** in the Network Admin.

The screens are the same as on a single site: [Settings](settings.md), [Channels](channels.md) and [Muting a plugin or theme](muting.md). They hold the settings of the whole network.

## What is different

- **Who can change the settings.** Only super admins (the `manage_network_options` capability). Site administrators do not see the screen. Lowering the `sc_wpun_capability` filter to `manage_options` does not let them in, because the Network Admin needs `manage_network` first.
- **One check.** It runs on the main site and covers the whole network. A notification names the network and links to the network's home page and to **Updates** in the Network Admin.
- **The default recipient.** The default email channel goes to the network admin email address, under **Settings → Network Settings**.
- **Muting.** The mute links are on the network **Plugins** screen, not on a site's. A mute applies to the whole network.
- **Site Health.** The plugin's tests in **Tools → Site Health** show on the main site only, so other sites' administrators never see the network's channels.
- **Active only.** The [Settings](settings.md#notify-about-plugin-updates-and-notify-about-theme-updates) "Yes, but only active" choices count a plugin or theme as active when it is network-activated or active on any site of the network. A parent of an active theme counts too. On a network that WordPress counts as large (10,000 sites by default, changed with the `wp_is_large_network` filter), reading every site costs too much, so every installed plugin and theme counts as active.
- **WP-CLI.** Run the [commands](wp-cli.md) as usual. Without `--url` WP-CLI uses the main site, which is the right one.

## Run the check from a system cron

The check runs on the main site, from WP-Cron. WP-Cron only runs when someone visits that site. If the main site has no visitors, checks wait. There is no setting to run the check on another site, because WordPress runs its automatic updates only on the main site, and the check needs them to run first (see [Updates WordPress installs automatically](how-notifications-work.md#updates-wordpress-installs-automatically)).

Call the main site's `wp-cron.php` from the server's clock instead, for example every 15 minutes:

```
*/15 * * * * curl -s https://main-site.example/wp-cron.php > /dev/null
```

Or use WP-CLI, as in [How do I run the check from a system cron or WP-CLI?](troubleshooting.md#how-do-i-run-the-check-from-a-system-cron-or-wp-cli).

## More than one network

Settings belong to a network, so each network that activates the plugin has its own settings and its own check. WordPress runs its automatic updates only on the main network. On any other network the check reads the update data without running them first.

## Upgrading from 2.x

- **Network-activated already.** It keeps working. The first time 3.0 runs, it copies the settings, the run state, the channels and the mutes from the main site to the network. Nothing is sent again. The other sites' own settings are not used. They stay in the database, so going back to 2.x works, and uninstalling the plugin removes them.
- **Activated for single sites.** These copies stop sending until a super admin network-activates the plugin. In the admin of each of those sites, super admins see a notice, and **Tools → Site Health** shows a critical result, asking for it. There is nothing to see in the Network Admin, because a plugin that is active only on some sites does not load there.
- **Old schedules.** A check that 2.x scheduled on a site removes itself the next time it fires.
- **Commands.** WP-CLI commands are not registered for a copy that is not network-activated.

Go back to a single copy by network-activating the plugin and deactivating it on the sites.
