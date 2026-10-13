# syntax=docker/dockerfile:1
#
# Dev/test environment for WP Updates Notifier. Docker is the only host requirement.
#
#   docker build --target dev      --build-arg PHP_VERSION=7.4 -f docker/Dockerfile .
#   docker build --target wp-tests --build-arg WP_VERSION=7.1.3 ...   (WordPress test suite)
#   docker build --target coverage ...                                (adds pcov)

ARG PHP_VERSION=8.3

# ---------------------------------------------------------------------------
# base: PHP + mysqli (WordPress needs it) + Composer
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli AS base

# The PHP 7.4 and 8.0 images are built on Debian bullseye, which is end of life
# and has moved to archive.debian.org.
RUN . /etc/os-release \
    && if [ "${VERSION_CODENAME}" = "bullseye" ]; then \
        sed -i \
            -e 's|deb.debian.org/debian-security|archive.debian.org/debian-security|' \
            -e 's|deb.debian.org/debian|archive.debian.org/debian|' \
            /etc/apt/sources.list \
        && echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive; \
    fi

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip rsync libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mysqli zip

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-wp-updates-notifier.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# WP-CLI, to install the built plugin into a fresh WordPress (make test-dist).
COPY --from=wordpress:cli /usr/local/bin/wp /usr/local/bin/wp

# ---------------------------------------------------------------------------
# dev: non-root user matching the host so bind-mounted files stay owned by you
# ---------------------------------------------------------------------------
FROM base AS dev

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN groupadd --gid "${HOST_GID}" dev \
    && useradd --uid "${HOST_UID}" --gid "${HOST_GID}" --create-home --shell /bin/bash dev \
    && mkdir -p /app /home/dev/.composer/cache \
    && chown -R dev:dev /app /home/dev/.composer

ENV COMPOSER_HOME=/home/dev/.composer
USER dev
WORKDIR /app

# ---------------------------------------------------------------------------
# coverage: dev + pcov (kept separate so normal runs stay fast)
# ---------------------------------------------------------------------------
FROM base AS coverage-base
RUN pecl install pcov && docker-php-ext-enable pcov

FROM coverage-base AS coverage

ARG HOST_UID=1000
ARG HOST_GID=1000

RUN groupadd --gid "${HOST_GID}" dev \
    && useradd --uid "${HOST_UID}" --gid "${HOST_GID}" --create-home --shell /bin/bash dev \
    && mkdir -p /app /home/dev/.composer/cache \
    && chown -R dev:dev /app /home/dev/.composer

ENV COMPOSER_HOME=/home/dev/.composer
USER dev
WORKDIR /app

# ---------------------------------------------------------------------------
# wp-tests: pinned wordpress-develop checkout to run the plugin's PHPUnit suite
# with core's test bootstrap. WP_VERSION is a wordpress-develop tag or "trunk".
# ---------------------------------------------------------------------------
FROM base AS wp-tests

ARG WP_VERSION=7.1.3

# WordPress's test bootstrap refuses to run without gd.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd

RUN git clone --depth 1 --branch "${WP_VERSION}" \
        https://github.com/WordPress/wordpress-develop.git /opt/wordpress-develop \
    && cd /opt/wordpress-develop \
    && composer update --no-interaction --no-progress --prefer-dist

COPY docker/wp-tests-entrypoint.sh /usr/local/bin/wp-tests-entrypoint
RUN chmod +x /usr/local/bin/wp-tests-entrypoint

ENV WP_TESTS_DIR=/opt/wordpress-develop/tests/phpunit
WORKDIR /opt/wordpress-develop
ENTRYPOINT ["wp-tests-entrypoint"]
CMD ["php", "./vendor/bin/phpunit", "--version"]

# ---------------------------------------------------------------------------
# commitlint: checks that commit messages follow Conventional Commits.
# Separate from the PHP image because it is the only thing here that needs Node.
# ---------------------------------------------------------------------------
FROM node:26-alpine AS commitlint

ENV NODE_PATH=/usr/local/lib/node_modules

RUN apk add --no-cache git \
    && npm install --global --no-fund --no-audit @commitlint/cli@21.2.3 @commitlint/config-conventional@21.2.3 \
    && git config --system --add safe.directory /app

WORKDIR /app
ENTRYPOINT ["commitlint"]
