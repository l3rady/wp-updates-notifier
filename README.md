# WP Updates Notifier

[![ci](https://github.com/l3rady/wp-updates-notifier/actions/workflows/ci.yml/badge.svg)](https://github.com/l3rady/wp-updates-notifier/actions/workflows/ci.yml)
[![lint](https://github.com/l3rady/wp-updates-notifier/actions/workflows/lint.yml/badge.svg)](https://github.com/l3rady/wp-updates-notifier/actions/workflows/lint.yml)
[![WordPress.org version](https://img.shields.io/wordpress/plugin/v/wp-updates-notifier)](https://wordpress.org/plugins/wp-updates-notifier/)
[![Active installs](https://img.shields.io/wordpress/plugin/installs/wp-updates-notifier)](https://wordpress.org/plugins/wp-updates-notifier/)
[![Licence: GPL-2.0-or-later](https://img.shields.io/badge/licence-GPL--2.0--or--later-blue)](LICENSE)

Tells you by email or Slack when your WordPress site has core, plugin or theme updates waiting, so you know without logging in. Useful for sites you rarely visit and for sites you look after for clients.

- Checks on a schedule (hourly, twice daily, daily or weekly) and tells you about each new version once.
- Core, plugin and theme updates, optionally only for active plugins and themes.
- Email to one or more addresses, and Slack through an incoming webhook.
- Can send WordPress's own automatic update emails to the same addresses.
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

Contributions are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

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
make up                          # local site: http://localhost:8080, mail in Mailpit on :8025
make down
```

### Building the plugin zip

```sh
make dist                        # build/wp-updates-notifier-<version>.zip, runtime files only
make dist-check                  # check its contents (and that .gitattributes matches .distignore)
make test-dist                   # install it into a fresh WordPress, activate, deactivate, uninstall
```

`.distignore` lists what stays out of the zip; the WordPress.org deploy uses the same list.

### Tests

```sh
make test-wp                     # WordPress test suite, single site
make test-wp-ms                  # the same as multisite
make test                        # both
make test-known-bugs             # tests documenting open bugs: they are expected to FAIL
make test-wp WP_VERSION=6.7.9 PHP_VERSION=7.4   # pick versions (WP_VERSION is a wordpress-develop tag or trunk)
```

The suite runs on WordPress's own test library with a real database, no real network (every HTTP request must be faked or the test fails) and no real mail. Tests for a bug that is still open carry `@group known-bug`, assert the *correct* behaviour, and are excluded from the normal run; the fix removes the tag.

`make up` starts WordPress with the plugin mounted, [Mailpit](https://mailpit.axllent.org/) (catches all outgoing mail) and an echo server standing in for a webhook (`docker compose logs webhook`). Use WP-CLI with `docker compose run --rm wp-cli <command>`.
