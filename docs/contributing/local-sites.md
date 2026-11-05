# Local sites

`make up` starts two WordPress sites with the plugin mounted (a single site and a multisite, described below), [Mailpit](https://mailpit.axllent.org/) (it catches all outgoing mail) and an echo server standing in for a webhook. Then `make site-setup` (`docker/site-setup.sh`) installs WordPress if a site is new, sets the constants below in `wp-config.php` and installs the debugging plugins. It is safe to run again, and it works on an existing site as well as a fresh one.

```sh
make up                          # start both sites and set them up (admin / admin on both)
                                 #   single site: http://localhost:8080
                                 #   multisite:   http://localhost:8081 (network admin: /wp-admin/network/)
make site-setup                  # set them up again (make up runs it; safe to repeat)
make wp ARGS="plugin list"       # WP-CLI on the single site
make wp-ms ARGS="site list"      # WP-CLI on the multisite
make site-log                    # follow the single site's wp-content/debug.log
make site-log-ms                 # follow the multisite's
make down                        # stop them; make clean also deletes their data
```

Mail goes to Mailpit on http://localhost:8025 and webhooks to the catcher on http://localhost:8026. All of this is set up by files in `docker/`, which never reach the plugin zip.

## Checking a change by hand

Check changes on both sites: log in as admin / admin. Debugging is on, with Query Monitor, so a change must not add PHP notices or `doing_it_wrong` messages. The sites also run with a persistent object cache. For changes that save or read options, transients or channels, check them with the cache on and with `make site-setup OBJECT_CACHE=off` (see [Persistent object cache](#persistent-object-cache)). The plugin starts network-activated on the multisite; see [Network or per-site copy](#network-or-per-site-copy) to test the dormant mode. The rules for pull requests are in [CONTRIBUTING.md](https://github.com/l3rady/wp-updates-notifier/blob/main/CONTRIBUTING.md).

## Multisite

The second site is a subdirectory network on http://localhost:8081, with its own volume (`site-wp-ms`) and its own database (`wordpress_ms`) on the same MariaDB server as the single site. Run WP-CLI on it with `make wp-ms`.

The sub-sites are:

- http://localhost:8081/, the main site, with Twenty Twenty-Four and Akismet;
- http://localhost:8081/alpha/, with Twenty Twenty-Three and Classic Editor;
- http://localhost:8081/beta/, with Twenty Twenty-Five and Hello Dolly.

So "active only" differs from site to site.

Everything else on this page applies to the multisite too, with these differences:

- The debugging plugins, Redis Object Cache, the field showcase and WP Updates Notifier are network-activated.
- The outdated plugins and themes are installed once for the whole network.
- The cache prefix is `wpun-network:`.
- Mail comes from `wordpress@network.example.test` (the single site: `wordpress@single.example.test`), so Mailpit shows which site sent it.
- The two sites keep separate logins, as their cookie names differ (they derive from the site URL, port included).

The multisite is for the local setup only: `SITE_MODE` (dast, e2e) does not apply to it.

### Network or per-site copy

The plugin starts network-activated, which is how it works on a network. `Network: true` makes WP-CLI network-activate it even without `--network`, so `plugin activate` cannot make a per-site copy.

To test the dormant mode (a 2.x copy that is active on one site only), deactivate it on the network, then add it to a site's list, keeping what is there:

```sh
make wp-ms ARGS="plugin deactivate wp-updates-notifier --network"
make wp-ms ARGS="option update active_plugins '[\"classic-editor/classic-editor.php\",\"wp-updates-notifier/wp-updates-notifier.php\"]' --format=json --url=localhost:8081/alpha/"
```

To go back, set `active_plugins` of that site to its other plugins again and run `make wp-ms ARGS="plugin activate wp-updates-notifier --network"`. `make site-setup` network-activates it again.

## Debugging

`WP_DEBUG`, `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY`, `SCRIPT_DEBUG`, `SAVEQUERIES` and `WP_DEVELOPMENT_MODE` (`plugin`) are on, and `WP_ENVIRONMENT_TYPE` is `local`, for the web server and WP-CLI alike. PHP notices show on the page and go to `wp-content/debug.log` (`make site-log`).

These plugins are active:

- [Query Monitor](https://wordpress.org/plugins/query-monitor/): PHP errors, hooks, HTTP API calls, queries and `doing_it_wrong` notices.
- [Debug Bar](https://wordpress.org/plugins/debug-bar/).
- [WP Crontrol](https://wordpress.org/plugins/wp-crontrol/): view and run `sc_wpun_update_check`.

They cost a little: `SCRIPT_DEBUG` turns off script concatenation (an admin page loads about 120 separate scripts and styles), and `SAVEQUERIES` and Query Monitor add 10 to 25 ms to every page.

## Persistent object cache

The site runs [Redis Object Cache](https://wordpress.org/plugins/redis-cache/) against a [Valkey](https://valkey.io/) service, as many production sites do. Options and transients, including the `update_*` data the update check reads, then live in the cache, so check that the plugin behaves the same with it on and off. The web server and WP-CLI run the same image (`docker/Dockerfile`, target `site`) and share the cache.

- `make wp ARGS="redis status"` shows it, and `make wp ARGS="cache flush"` empties it.
- Query Monitor's Object Cache panel shows hits and misses.
- To test without it, run `make site-setup OBJECT_CACHE=off`, or put `OBJECT_CACHE=off` in a `.env` file at the repo root so it stays off. `make site-setup` turns it back on.
- Keys carry the prefix `wpun-single:` (the multisite uses `wpun-network:`), so the sites share the same Valkey.

## Only reachable from this machine

The sites (8080 and 8081), Mailpit (8025) and the webhook catcher (8026) are published on `127.0.0.1`, not on every interface, because the site has a fixed admin login and debug output, and Mailpit and the webhook catcher show every email and request they catch. They still open from a Windows browser under WSL2 mirrored networking, but not from other machines on the network.

## Speed

A warm page takes 20 to 70 ms. Three things can still make the site feel slow:

- **IPv6 on WSL2.** From a Windows browser on WSL2 (mirrored networking), `localhost` resolves to IPv6 `::1` first, and that never answers, so each new connection waits about 0.2 to 0.3 s before falling back to IPv4. Apache keeps connections open for 5 minutes, so you mostly pay this once. To avoid it altogether, put `SITE_HOST=127.0.0.1` in `.env`, run `make site-setup` (it moves the site to http://127.0.0.1:8080, the multisite to http://127.0.0.1:8081), and log in again. Delete the line and run it again to go back.
- **WordPress's update data.** The first admin page after the data expires (every 12 hours) waits about 2 s for wordpress.org. Valkey keeps its data on disk, so a restart doesn't expire it.
- **OPcache.** The first request after `make up` recreates the container compiles PHP again (OPcache starts empty).

## Pending updates

Setup installs outdated plugins and themes from WordPress.org, so an update check finds real updates:

- Akismet 5.6 (active);
- Classic Editor 1.6.7 (inactive);
- Hello Dolly 1.6 from WordPress.org (inactive, auto-updates on, so WordPress updates it by itself within 12 hours);
- Twenty Twenty-Four 1.3 (the active theme) and Twenty Twenty-Three 1.5 (inactive).

The bundled Hello Dolly stays current as the control. Run `make site-setup` again to put the old versions back after updating them.

To run a check now, use the **Check now** button on the General tab, or `make wp ARGS="cron event run sc_wpun_update_check"`. The email arrives in Mailpit.

### A pending update with no network

Run `make wp ARGS="config set WPUN_FAKE_UPDATE true --raw --type=constant"`. WordPress then reports version 9.9.9 for the dummy plugin `docker/dev-plugins/wpun-dummy`. `config delete WPUN_FAKE_UPDATE` switches it off.

### A core update

Downgrade WordPress in place, for example `make wp ARGS="core update --version=7.0.7 --force"`. Update it again from Dashboard → Updates.

### Plugins mounted from the repo

WordPress never offers updates for the plugins mounted from the repo, and hides their Delete link (`docker/mu-plugins/mounted-plugins.php`). Updating or deleting one would delete the working tree.

## Field showcase

The "Field showcase (dev only)" channel (`docker/dev-plugins/wpun-field-showcase/`, active on the local site only) has every settings field type and display, a custom field type, and callbacks that fail on demand (type `fail`). Use it to check the channel forms by hand: layout, errors, read-only values and the keyboard. It sends nothing; it writes what it would have sent to the debug log. A second module, "Field showcase: custom render", draws its own fields.

To see fields set from outside, add constants for an instance (here with the id `showcase`), for example `make wp ARGS="config set SC_WPUN_CHANNEL_SHOWCASE__TEXT_REQUIRED 'Set from wp-config' --type=constant"`, or `SC_WPUN_CHANNEL_SHOWCASE__SECRET_REQUIRED` for a secret. The full list is in the plugin's main file.

## Example add-on

The example add-on (`examples/wpun-example-channel/`) is mounted too: activate "WP Updates Notifier Example Channel" on the Plugins screen. See the [developer guide](../developers/README.md).

## Webhooks

Each local site has a "Local webhook" channel that posts to the webhook catcher ([Webhook Tester](https://github.com/tarampampam/webhook-tester)), as email goes to Mailpit. See what it sent, with every header and the body:

- single site: http://localhost:8026/s/00000000-0000-4000-8000-000000000001
- multisite: http://localhost:8026/s/00000000-0000-4000-8000-000000000002

The signing secret is the Standard Webhooks test vector's, `whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw`, so you can check a signature by hand.

`docker/mu-plugins/webhook.php` lets the sites reach the `webhook` host, which is private, and lets the address use http. Other channels, such as the example add-on, can post to `http://webhook:8080/<any UUID>`: a new UUID opens a new session. Captures are kept in memory until the container restarts.

## WP-Cron

WP-Cron runs by itself on page loads. WP-CLI calls don't spawn it; run due events with `make wp ARGS="cron event run --due-now"`.
