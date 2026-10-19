# syntax=docker/dockerfile:1
#
# Dev/test environment for WP Updates Notifier. Docker is the only host requirement.
#
#   docker build --target dev      --build-arg PHP_VERSION=7.4 -f docker/Dockerfile .
#   docker build --target wp-tests --build-arg WP_VERSION=7.1.3 ...   (WordPress test suite)
#   docker build --target coverage ...                                (adds pcov)
#   docker build --target site ...                                    (the local site, `make up`)

ARG PHP_VERSION=8.3

# Every image is pinned by digest, and Dependabot bumps each line. The php and wordpress images come in one stage
# per PHP version in the test matrix; `FROM php-${PHP_VERSION}` picks one, and BuildKit pulls and builds only the
# stages the target needs. To support another PHP version, add both of its lines.
FROM php:7.4-cli@sha256:620a6b9f4d4feef2210026172570465e9d0c1de79766418d3affd09190a7fda5 AS php-7.4
FROM php:8.0-cli@sha256:0569e384b9064c04dec55dc6e41be41b494a878dfbb6577a7d76bd50cfd5bc00 AS php-8.0
FROM php:8.1-cli@sha256:76e563191d1ade120313a8736df24154d21da5155c0756f147c0b01bd19d9087 AS php-8.1
FROM php:8.2-cli@sha256:118ef4fb1db5e866fb604f2f260da81f819a0a1eac87a4083f9194f48e00beee AS php-8.2
FROM php:8.3-cli@sha256:aafe21201943a8a6e497ddfb471e2ce68ada76adede38c7d61fede8918b24319 AS php-8.3
FROM php:8.4-cli@sha256:1d2cf2b7d715b5b3aa959f0150ccbcc7b71e0181b637e443abf40a7fb56bf79a AS php-8.4
FROM php:8.5-cli@sha256:01a109229f4465bc9ef042d9198f09a4d9da7775a825dbd8572dcdbd8756c4d3 AS php-8.5
FROM wordpress:php7.4@sha256:7e46cf3373751b6d62b7a0fc3a7d6686f641a34a2a0eb18947da5375c55fd009 AS wordpress-php-7.4
FROM wordpress:php8.0@sha256:824689613b4e7b027d0d36f264a53a159d6c7adcf5250539e56efe2940651e19 AS wordpress-php-8.0
FROM wordpress:php8.1@sha256:f73396626d2f1b89d4c556917c15f55cfcc6b5f80595205516e490f0f04015e4 AS wordpress-php-8.1
FROM wordpress:php8.2@sha256:7cb0b86fdd78760c741db2fde3ed7a10084029164009e2c41b09de32f54607e8 AS wordpress-php-8.2
FROM wordpress:php8.3@sha256:81a34254acaa6ff3b9a67268c2745e9a041c4094cbe5ccd35b75ed3bbdc033b5 AS wordpress-php-8.3
FROM wordpress:php8.4@sha256:309307430abf80009378a63a79df73554a6449b700fac59a991b4959e6dda8ca AS wordpress-php-8.4
FROM wordpress:php8.5@sha256:c12bed9c5af9d76261221c2af3a6216a074dd80b4fc2459588b3cbf2dd8e1798 AS wordpress-php-8.5

# ---------------------------------------------------------------------------
# base: PHP + mysqli (WordPress needs it) + Composer
# ---------------------------------------------------------------------------
FROM php-${PHP_VERSION} AS base

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
    && apt-get install -y --no-install-recommends git openssh-client unzip zip rsync libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install mysqli zip

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-wp-updates-notifier.ini
COPY --from=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer
# WP-CLI, to install the built plugin into a fresh WordPress (make test-dist).
COPY --from=wordpress:cli@sha256:36243da432a0f0a3b56dc3240ecee9b159108d71305e7c064d987a20a465bfde /usr/local/bin/wp /usr/local/bin/wp

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
# site: the local site's WordPress (`make up`). The wordpress and wp-cli services
# both run this image, so the web server and WP-CLI share one PHP, with the
# phpredis extension for the persistent object cache (Valkey) and a MySQL client
# for `wp db`.
# ---------------------------------------------------------------------------
FROM wordpress-php-${PHP_VERSION} AS site

RUN apt-get update \
    && apt-get install -y --no-install-recommends mariadb-client ${PHPIZE_DEPS} \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove ${PHPIZE_DEPS} \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=wordpress:cli@sha256:36243da432a0f0a3b56dc3240ecee9b159108d71305e7c064d987a20a465bfde /usr/local/bin/wp /usr/local/bin/wp

# ---------------------------------------------------------------------------
# commitlint: checks that commit messages follow Conventional Commits.
# Separate from the PHP image because it is the only thing here that needs Node.
# ---------------------------------------------------------------------------
FROM node:26-alpine@sha256:6f08a523443ddedf52941874c203475b0ad503e859ad70cb26ab8be9a517dbd5 AS commitlint

# Installed with npm ci from the lockfile in docker/commitlint, so every package is pinned by its integrity hash.
ENV NODE_PATH=/opt/commitlint/node_modules
ENV PATH=/opt/commitlint/node_modules/.bin:$PATH

WORKDIR /opt/commitlint
COPY docker/commitlint/package.json docker/commitlint/package-lock.json ./
RUN apk add --no-cache git \
    && npm ci --no-fund --no-audit \
    && git config --system --add safe.directory /app

WORKDIR /app
ENTRYPOINT ["commitlint"]
