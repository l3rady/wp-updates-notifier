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

`make up` starts WordPress with the plugin mounted, [Mailpit](https://mailpit.axllent.org/) (catches all outgoing mail) and an echo server standing in for a webhook (`docker compose logs webhook`). Use WP-CLI with `docker compose run --rm wp-cli <command>`.
