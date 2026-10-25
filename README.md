# WP Updates Notifier

[![ci](https://github.com/l3rady/wp-updates-notifier/actions/workflows/ci.yml/badge.svg)](https://github.com/l3rady/wp-updates-notifier/actions/workflows/ci.yml)
[![lint](https://github.com/l3rady/wp-updates-notifier/actions/workflows/lint.yml/badge.svg)](https://github.com/l3rady/wp-updates-notifier/actions/workflows/lint.yml)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/l3rady/wp-updates-notifier/badge)](https://scorecard.dev/viewer/?uri=github.com/l3rady/wp-updates-notifier)
[![WordPress.org version](https://img.shields.io/wordpress/plugin/v/wp-updates-notifier)](https://wordpress.org/plugins/wp-updates-notifier/)
[![Active installs](https://img.shields.io/wordpress/plugin/installs/wp-updates-notifier)](https://wordpress.org/plugins/wp-updates-notifier/)
[![Licence: GPL-2.0-or-later](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue)](LICENSE)

Tells you by email when your WordPress site has core, plugin or theme updates waiting, so you know without logging in. Useful for sites you rarely visit and for sites you look after for clients.

The plugin's website, with the documentation, is at **<https://wpun.cariss.dev>**.

- Checks on a schedule (hourly, twice daily, daily or weekly) and tells you about each new version once.
- Core, plugin and theme updates, optionally only for active plugins and themes.
- Email to one or more addresses.
- Hooks for developers to change what is checked and what is sent.

Requires WordPress 6.7 or newer and PHP 7.4 or newer.

## Installing

- **WordPress.org (recommended):** search for "WP Updates Notifier" under Plugins → Add New, or download it from [wordpress.org/plugins/wp-updates-notifier](https://wordpress.org/plugins/wp-updates-notifier/).
- **Composer:** through [WordPress Packagist](https://wpackagist.org/): `composer require wpackagist-plugin/wp-updates-notifier`.
- **Zip:** each [GitHub release](https://github.com/l3rady/wp-updates-notifier/releases) has the plugin zip attached; upload it under Plugins → Add New → Upload Plugin.

Then open **Settings → Updates Notifier** to choose where notifications go.

Checks run on WP-Cron, which only runs when the site gets visits. On a quiet site, or with `DISABLE_WP_CRON`, set up a real cron job that calls `wp-cron.php`.

## Help, bugs and security

- Questions: the [support forum](https://wordpress.org/support/plugin/wp-updates-notifier/).
- Bugs and ideas: [GitHub issues](https://github.com/l3rady/wp-updates-notifier/issues).
- Security problems: please report them privately, see [SECURITY.md](SECURITY.md).
- Verifying a download: each release zip has build provenance you can check with
  `gh attestation verify wp-updates-notifier-<version>.zip --repo l3rady/wp-updates-notifier`. See
  [Verifying a release](SECURITY.md#verifying-a-release) for the full command, the checksum and the signed tag.

Contributions are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## Extending the plugin

Add-ons can add notification channels, item types, routing rules and configuration sources through a public, versioned API. Start with the [developer guide](https://wpun.cariss.dev/main/developers/) and the [example add-on](examples/wpun-example-channel/).

## Development

Docker is the only requirement: no PHP, Composer or Node on the host.

```sh
make help                        # list every target
make smoke PHP_VERSION=7.4       # check the environment (default PHP 8.3)
make matrix                      # smoke test PHP 7.4 to 8.5
make lint                        # WordPress Coding Standards (errors and warnings fail)
make fix                         # auto-fix what phpcbf can
make stan                        # PHPStan level 8 with the WordPress stubs
make rector                      # PHP 7.4 syntax upgrades still to do (none expected)
make commits                     # check the commit messages on this branch (Conventional Commits)
make shell                       # shell in the PHP container
make up                          # local site: http://localhost:8080, mail in Mailpit on :8025 (see Local site)
make down
```

### Building the plugin zip

```sh
make dist                        # build/wp-updates-notifier-<version>.zip, runtime files only
make dist-check                  # check its contents
make test-dist                   # install it into a fresh WordPress, activate, deactivate, uninstall
make plugin-check                # WordPress.org's Plugin Check on the zip (fails on errors)
make check-headers               # plugin header and the generated readme.txt agree and follow WordPress.org's rules
make readme                      # preview the generated readme.txt in build/readme.txt (it is not committed)
make test-readme                 # test the readme generator
```

`.distignore` lists what stays out of the zip; the WordPress.org deploy uses the same list.

### Security checks

```sh
make security                    # every check below: run it before a pull request
make check-workflows             # actionlint (with shellcheck) and zizmor over .github/workflows/
make secrets                     # gitleaks over the git history, the working tree and the built zip
make audit                       # composer audit and OSV-Scanner over every lockfile
make sast                        # Semgrep with the rules in .semgrep/, and the suppression checks
make taint                       # Psalm taint analysis
make audit-images                # Trivy over docker/ and the built image (reports only, not in make security)
make test-wp ARGS="--group security"   # just the security tests
```

They need only Docker. Reports go to `security-reports/` (gitignored). zizmor runs its online audits only when `GITHUB_PUBLIC_TOKEN` holds a token that can read public repositories; without one it skips them. What each check covers, and the rules for suppressions, baselines and new tools, are in [CONTRIBUTING.md](CONTRIBUTING.md#security-checks).

### Dynamic security scan

```sh
make dast                        # OWASP ZAP against a throwaway copy of the site (about 11 minutes)
```

`make dast` builds an isolated site as its own compose project (`wpun-dast`, from `tests/dast/compose.yml`) with the plugin active, an email channel, the field showcase and outdated plugins, scans it with OWASP ZAP and removes it again, volumes included, even when the scan fails. It publishes no ports and shares no container, volume or network with `make up`, so it never touches your local site. The scan is defined by `tests/dast/zap-plan.yaml`: it logs in as an administrator, spiders and actively scans only the plugin's settings screens and `admin-post.php` handlers, and then checks that a visitor who is not logged in is turned away from the same URLs. Any Medium or High alert fails the run; the HTML and SARIF reports are written to `security-reports/`. It is not run in CI, because the active scan alone takes about 10 minutes. Run it before each release (see [Releases](CONTRIBUTING.md#releases)).

Limits: security tokens turn away most of the active scan's requests to the POST handlers. That is the plugin working, and why the capability and security token matrix tests remain the real enforcement; the scan looks at the plugin from the outside (reflected input, leaking error pages, headers, redirects), it does not replace them.

### Tests

```sh
make test-wp                     # WordPress test suite, single site
make test-wp-ms                  # the same as multisite
make test                        # both
make test-known-bugs             # tests documenting open bugs: they are expected to FAIL
make a11y                        # axe and keyboard checks of the settings screen in a browser (about 40 seconds)
make screenshots                 # regenerate .wordpress-org/screenshot-1.png to 4 from a throwaway site (about 30 seconds)
make test-wp WP_VERSION=6.7.9 PHP_VERSION=7.4   # pick versions (WP_VERSION is a wordpress-develop tag or trunk)
```

The suite runs on WordPress's own test library with a real database, no real network (every HTTP request must be faked or the test fails) and no real mail. Tests for a bug that is still open carry `@group known-bug`, assert the *correct* behaviour, and are excluded from the normal run; the fix removes the tag.

`make a11y` builds a throwaway site as its own compose project (`wpun-e2e`, from `tests/e2e/compose.yml`; it never touches `make up`) and runs Playwright from the official image against the settings screen: axe scans of every view for WCAG 2.2 A and AA, and keyboard checks of the Channels list and a channel form. Any violation fails the run and is listed with its rule and elements; the HTML report is written to `tests/e2e/reports/`. CI runs it on every pull request.

`make screenshots` writes the WordPress.org screenshots (`.wordpress-org/screenshot-1.png` to `screenshot-4.png`). It builds its own throwaway site (compose project `wpun-shots`, from `tests/screenshots/compose.yml`; it touches neither `make up` nor `make a11y`) with made-up data: example.com addresses, fake plugins whose updates and the offline update data come from `tests/screenshots/mu-plugin.php`, and Mailpit to catch the update email. It runs the update check once from WP-CLI before the browser opens any page, then photographs the General tab, the Channels list, a channel's form and the email. The captions are in `.wordpress-org/readme/template.txt`, in the same order as `SHOTS` in `tests/screenshots/screenshots.spec.js`. Dates and times in the files are those of the run. CI does not run it.

### Local site

```sh
make up                          # start the site and set it up: http://localhost:8080 (admin / admin)
make site-setup                  # set it up again (make up runs it; safe to repeat)
make wp ARGS="plugin list"       # WP-CLI on the site
make site-log                    # follow wp-content/debug.log
make down                        # stop it; make clean also deletes its data
```

`make up` starts WordPress with the plugin mounted, [Mailpit](https://mailpit.axllent.org/) (catches all outgoing mail) and an echo server standing in for a webhook. Then `make site-setup` (`docker/site-setup.sh`) installs WordPress if the site is new, sets the constants below in `wp-config.php` and installs the debugging plugins. It is safe to run again, and it works on an existing site as well as a fresh one.

- **Debugging:** `WP_DEBUG`, `WP_DEBUG_LOG`, `WP_DEBUG_DISPLAY`, `SCRIPT_DEBUG`, `SAVEQUERIES` and `WP_DEVELOPMENT_MODE` (`plugin`) are on, and `WP_ENVIRONMENT_TYPE` is `local`, for the web server and WP-CLI alike. PHP notices show on the page and go to `wp-content/debug.log` (`make site-log`). [Query Monitor](https://wordpress.org/plugins/query-monitor/) (PHP errors, hooks, HTTP API calls, queries, `doing_it_wrong` notices), [Debug Bar](https://wordpress.org/plugins/debug-bar/) and [WP Crontrol](https://wordpress.org/plugins/wp-crontrol/) (view and run `sc_wpun_update_check`) are active. They cost a little: `SCRIPT_DEBUG` turns off script concatenation (an admin page loads about 120 separate scripts and styles), and `SAVEQUERIES` and Query Monitor add 10–25 ms to every page.
- **Persistent object cache:** the site runs [Redis Object Cache](https://wordpress.org/plugins/redis-cache/) against a [Valkey](https://valkey.io/) service, as many production sites do. Options and transients, including the `update_*` data the update check reads, then live in the cache, so check that the plugin behaves the same with it on and off. The web server and WP-CLI run the same image (`docker/Dockerfile`, target `site`) and share the cache. `make wp ARGS="redis status"` shows it, and `make wp ARGS="cache flush"` empties it. Query Monitor's Object Cache panel shows hits and misses. To test without it, run `make site-setup OBJECT_CACHE=off`, or put `OBJECT_CACHE=off` in a `.env` file at the repo root so it stays off. `make site-setup` turns it back on. Keys carry the prefix `wpun-single:`, so another local site can share the same Valkey.
- **Only reachable from this machine:** the site (8080) and Mailpit (8025) are published on `127.0.0.1`, not on every interface, because the site has a fixed admin login and debug output, and Mailpit shows every email it catches. They still open from a Windows browser under WSL2 mirrored networking, but not from other machines on the network.
- **Speed:** a warm page takes 20–70 ms. Three things can still make the site feel slow:
  - From a Windows browser on WSL2 (mirrored networking), `localhost` resolves to IPv6 `::1` first, and that never answers, so each new connection waits about 0.2–0.3 s before falling back to IPv4. Apache keeps connections open for 5 minutes, so you mostly pay this once. To avoid it altogether, put `SITE_HOST=127.0.0.1` in `.env`, run `make site-setup` (it moves the site to http://127.0.0.1:8080), and log in again. Delete the line and run it again to go back.
  - The first admin page after WordPress's update data expires (every 12 hours) waits about 2 s for wordpress.org. Valkey keeps its data on disk, so a restart doesn't expire it.
  - The first request after `make up` recreates the container compiles PHP again (OPcache starts empty).
- **Pending updates:** setup installs outdated plugins and themes from WordPress.org, so an update check finds real updates. It installs Akismet 5.6 (active), Classic Editor 1.6.7 (inactive), Hello Dolly 1.6 from WordPress.org (inactive, auto-updates on, so WordPress updates it by itself within 12 hours) and Twenty Twenty-Four 1.3 (the active theme) and Twenty Twenty-Three 1.5 (inactive). The bundled Hello Dolly stays current as the control. Run `make site-setup` again to put the old versions back after updating them. To run a check now, use the **Check now** button on the General tab, or `make wp ARGS="cron event run sc_wpun_update_check"`. The email arrives in Mailpit.
  - For a pending update with no network, run `make wp ARGS="config set WPUN_FAKE_UPDATE true --raw --type=constant"`. WordPress then reports version 9.9.9 for the dummy plugin `docker/dev-plugins/wpun-dummy`. `config delete WPUN_FAKE_UPDATE` switches it off.
  - For a core update, downgrade WordPress in place, for example `make wp ARGS="core update --version=7.0.7 --force"`. Update it again from Dashboard → Updates.
  - WordPress never offers updates for the plugins mounted from the repo, and hides their Delete link (`docker/mu-plugins/mounted-plugins.php`). Updating or deleting one would delete the working tree.
- **Field showcase:** the "Field showcase (dev only)" channel (`docker/dev-plugins/wpun-field-showcase/`, active on the local site only) has every settings field type and display, a custom field type, and callbacks that fail on demand (type `fail`). Use it to check the channel forms by hand: layout, errors, read-only values and the keyboard. It sends nothing; it writes what it would have sent to the debug log. A second module, "Field showcase: custom render", draws its own fields. To see fields set from outside, add constants for an instance (here with the id `showcase`), for example `make wp ARGS="config set SC_WPUN_CHANNEL_SHOWCASE__TEXT_REQUIRED 'Set from wp-config' --type=constant"`, or `SC_WPUN_CHANNEL_SHOWCASE__SECRET_REQUIRED` for a secret. The full list is in the plugin's main file.
- The example add-on (`examples/wpun-example-channel/`) is mounted too: activate "WP Updates Notifier Example Channel" on the Plugins screen.
- Channels can post to the echo server at `http://webhook:8080/<any path>`. Read what it received with `docker compose logs webhook`.
- WP-Cron runs by itself on page loads. WP-CLI calls don't spawn it; run due events with `make wp ARGS="cron event run --due-now"`.

All of this is set up by files in `docker/`, which never reach the plugin zip.
