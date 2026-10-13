# WP Updates Notifier

Sends email or Slack messages to notify you if there are any updates for your WordPress site. Can notify about core, plugin and theme updates.

Available on [WordPress.org](https://wordpress.org/plugins/wp-updates-notifier/).

## Development

Docker is the only requirement: no PHP, Composer or Node on the host.

```sh
make help                        # list every target
make smoke PHP_VERSION=7.4       # check the environment (default PHP 8.3)
make matrix                      # smoke test PHP 7.4 to 8.5
make shell                       # shell in the PHP container
make up                          # local site: http://localhost:8080, mail in Mailpit on :8025
make down
```

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
