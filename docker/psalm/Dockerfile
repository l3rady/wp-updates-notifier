# syntax=docker/dockerfile:1
#
# Psalm taint analysis: `make taint`. Psalm 6 needs PHP 8.1+, so it cannot live in the root composer.json (PHP 7.4).
# Psalm and the WordPress stubs come from composer.lock in this directory; the base images are pinned by digest.
# The repository is mounted at /app when it runs (see the psalm service in docker-compose.yml).

FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS deps
WORKDIR /opt/psalm
COPY composer.json composer.lock patch-wpdb-stub.php ./
RUN composer install --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader \
    && php patch-wpdb-stub.php vendor/php-stubs/wordpress-stubs/wordpress-stubs.php

FROM php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37
COPY --from=deps /opt/psalm /opt/psalm
# Taint analysis is memory hungry on big stub files.
RUN echo 'memory_limit=2G' > /usr/local/etc/php/conf.d/psalm.ini
WORKDIR /app
ENTRYPOINT ["/opt/psalm/vendor/bin/psalm"]
