# 0021. On multisite, run only when network-activated

Status: Accepted. Date: 2026-10-31.

## Context

ADR 0008 kept 2.0.0 to single sites. On multisite a 2.x install runs once per site: each site has its own settings, cron event and notification, so one update sends one email per site (#27).

On a network, a site's own admin cannot act on an update notification:

- WordPress gives `update_core`, `update_plugins` and `update_themes` to super admins only.
- The update data (`update_core`, `update_plugins`, `update_themes`) is one set of site transients for the whole network.
- `WP_Automatic_Updater::run()` returns early unless it runs on the main site of the main network. Since ADR 0020 the check runs WordPress's automatic updates first, so a check anywhere else would skip them.
- A check on one site cannot see what is active on the other sites (#71).

The first proposal in #85 kept per-site activation as an option. It would still send duplicates, to people who cannot act on them.

## Decision

- **Network-only.** The plugin header gets `Network: true`. On multisite the plugin works only when network-activated. There is one set of settings, channels, mutes and run state for the network, one cron event on the main site, and one notification per check.
- **One code path, two contexts.** The mode depends on how the plugin is activated, not on which screen the request comes from. Network mode is multisite plus network activation. Single-site mode is everything else. An `@internal` context object answers every question that depends on the mode, and each screen, handler and store is registered once and asks it:

  | Question | Single site | Network |
  | --- | --- | --- |
  | Option storage | `get_option()` and the other option functions | `get_site_option()` and the other site option functions, under the same option names |
  | Settings screen | `options-general.php`, on `admin_menu` | `settings.php`, on `network_admin_menu` |
  | Screen URLs | `admin_url()` | `network_admin_url()` |
  | Settings form saves through | `options.php` | `network/edit.php`, through a `network_admin_edit_{action}` handler with the same security token. `update_site_option()` runs `sanitize_option()`, so the existing `register_setting()` validator still runs once |
  | Plugin row links and notices | `plugin_action_links_*`, `admin_notices` | `network_admin_plugin_action_links_*`, `network_admin_notices` |
  | Name and links in the notification | `get_bloginfo()`, `home_url()`, `admin_url( 'update-core.php' )` | network name, `network_home_url()`, `network_admin_url( 'update-core.php' )` |
  | Where the check runs | the site | the main site only |

  Hooks that only fire in the network admin (`network_admin_menu`, `network_admin_notices`, `network_admin_plugin_action_links_*`) are registered whenever the plugin is in network mode. `admin-post.php` handlers stay as they are. They run on the main site's admin and redirect back to the URLs the context object gives them.
- **Cron on the main site.** `maybe_schedule()`, `sc_wpun_enable_cron` and `sc_wpun_disable_cron` act only on the main site. On any other site, a leftover 2.x event clears itself the first time it fires and does nothing else. Making existing events clean themselves up avoids looping over every site at upgrade time.
- **"Active only" covers the network.** It counts network-activated plugins and every plugin or theme active on any site.
- **Mutes, the update nag and Site Health.** Mute links and bulk actions appear on the network Plugins screen (`plugins-network`) and not on site Plugins screens. The plugin's Site Health tests and debug information appear only on the main site, so other sites' admins never see the network's channels.
- **A copy activated per site stops.** `Network: true` blocks new per-site activations, but WordPress keeps the ones a 2.x network already has. On multisite, a copy that is active on a site but not network-activated does no checks and sends nothing. It shows super admins a notice in that site's admin and a Site Health result asking them to network-activate. It cannot show anything in the network admin, because a plugin activated only on other sites does not load there.
- **Migration when the plugin is first network-activated.** If no network settings exist yet, all four options (`sc_wpun_settings`, `sc_wpun_state`, `sc_wpun_channels`, `sc_wpun_muted`) are copied from the main site. They keep their channel instance ids, because `SC_WPUN_CHANNEL_<ID>__<FIELD>` uses those ids. Copying the run state as well stops the first 3.0 check from sending every update again. If the main site has no settings, the defaults are used. The per-site options stay where they are, so going back to 2.x still works. Uninstall removes them, as it already does (#78).
- **Multiple networks.** Site options belong to a network, so each network that activates the plugin gets its own settings and check. WordPress runs its automatic updates only on the main network, so on any other network the check reads update data without running them first.
- **Public API.** No hook, function or class changes its name or arguments:
  - `sc_wpun_get_options_filter` and `sc_wpun_put_options_filter` receive the same option names. In network mode they wrap site options.
  - `Config_Context::get_site_id()` returns the main site's id in network mode.
  - The site name and URLs in a `Notification` are the network's, as described above.
  - `sc_wpun_capability` keeps its default (`manage_network_options` on multisite). Lowering it to `manage_options` no longer lets site administrators in, because the network admin needs `manage_network` first. The documented example and the docblock change to say so.
  - New, as part of API 1.1.0, which is already the version for plugin 3.0.0 and has not been released: `sc_wpun_is_network()`, which says whether the plugin runs in network mode, and `sc_wpun_settings_url( $tab = '' )`, which gives the address of the settings screen or one of its tabs in either mode. Add-ons use them instead of hard-coding `options-general.php`.
- **No filter to pick the site that runs the check.** The case for one is a main site with no visitors, where WP-Cron never fires (#27). Running the check anywhere else would skip WordPress's automatic updates (ADR 0020). The documentation tells such networks to call the main site's `wp-cron.php` from a system cron.

## Consequences

- Good: one notification per update on a network, sent to the people who can act on it.
- Good: one implementation of each screen, handler and store. Every difference between the modes is in one place, which tests can cover in both modes.
- Good: existing hooks and extensions keep working.
- Bad: 2.x networks that activated the plugin per site stop getting notifications until a super admin network-activates it. The notice and the Site Health result say so, but only on the sites where it is still active.
- Bad: on a network whose main site gets no traffic, scheduled checks need a system cron.
- Bad: "active only" reads every site's active plugins and theme, which costs more on large networks.
- Bad: site administrators can no longer be given the settings screen with `sc_wpun_capability`.
- Bad: more public API, held to the deprecation policy.

## Considered options

- **Keep per-site activation as an option, documenting or warning about the duplicates.** Rejected: site admins cannot act on updates, and it keeps the duplicate emails.
- **Keep the 2.x per-site behaviour for copies activated per site until they are network-activated.** Rejected in favour of a clear break: network-only is the supported mode on multisite.
- **Decide the mode by `is_network_admin()`.** Rejected: `admin-post.php` handlers, cron, WP-CLI and Site Health run outside the network admin, so storage would split between site and network options from one request to the next.
- **A separate network settings screen class.** Rejected: it means two copies of every screen to maintain.

## References

- [#85](https://github.com/l3rady/wp-updates-notifier/issues/85): multisite network admin support.
- [#27](https://github.com/l3rady/wp-updates-notifier/issues/27): one email per site on a network install.
- [#71](https://github.com/l3rady/wp-updates-notifier/issues/71), [#78](https://github.com/l3rady/wp-updates-notifier/issues/78), [#176](https://github.com/l3rady/wp-updates-notifier/issues/176), [#328](https://github.com/l3rady/wp-updates-notifier/issues/328).
- [ADR 0008](0008-single-sites-only-in-2-0-0.md), [ADR 0020](0020-scheduled-checks-run-wordpress-automatic-updates-first.md).
