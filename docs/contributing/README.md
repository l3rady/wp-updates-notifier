# Contributor guide

This section is for people changing the plugin. If you want to extend it from outside, with your own notification channel or item type, read the [developer guide](../developers/README.md) instead. The rules for a pull request (what must pass, the test policy, commits and branches) are in [CONTRIBUTING.md](https://github.com/l3rady/wp-updates-notifier/blob/main/CONTRIBUTING.md).

Docker and `docker compose` are the only requirements: no PHP, Composer or Node on the host.

## Quick start

```sh
make up      # local sites on :8080 and :8081
make test    # the WordPress suites and the release script tests
make help    # every target
```

`make up` starts a single site on http://localhost:8080 and a multisite on http://localhost:8081, with mail in Mailpit on :8025 and webhooks on :8026 (see [Local sites](local-sites.md)). Log in as admin / admin. `make test` runs `test-wp`, `test-wp-ms`, `test-release`, `test-readme`, `test-blueprint` and `test-wporg-assets`.

## The `make` targets you will use most

```sh
make help                        # list every target
make up                          # local sites, set up
make down                        # stop them
make shell                       # shell in the PHP container
make smoke PHP_VERSION=7.4       # check the environment (default PHP 8.3)
make matrix                      # smoke test PHP 7.4 to 8.5
make lint                        # WordPress Coding Standards (errors and warnings fail)
make fix                         # auto-fix what phpcbf can
make stan                        # PHPStan level 8 with the WordPress stubs
make rector                      # PHP 7.4 syntax upgrades still to do (none expected)
make commits                     # check the commit messages on this branch (Conventional Commits)
make test                        # the WordPress suites and the release script tests
make test-wp                     # WordPress test suite, single site
```

`make help` is generated from the `Makefile`, so it lists every target and is always current. This page does not, on purpose.

Pick the versions with `PHP_VERSION` and `WP_VERSION`. `WP_VERSION` is a wordpress-develop tag, or `trunk`:

```sh
make test-wp WP_VERSION=6.7.9 PHP_VERSION=7.4
```

## The pages

- [Local sites](local-sites.md): the single site and the multisite on your machine, with debugging, an object cache, fake updates, Mailpit and a webhook catcher.
- [Tests and checks](testing.md): every test layer, what it catches, and the `make` target and CI job for each, including coverage and mutation testing.
- [Security checks](security-checks.md): the scanners behind `make security`, and the dynamic scan.
- [Packaging](packaging.md): the plugin zip, the generated readme and Live Preview blueprint, the WordPress.org assets and the plugin artwork.
- [Translations](translations.md): the bundled locales, how the files are made and checked, and what to do when a string changes.
- [Documentation site](documentation-site.md): building, checking and deploying this site.
- [Releases](releases.md): how a release is made, and what to run before one.
