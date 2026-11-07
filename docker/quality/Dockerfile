# syntax=docker/dockerfile:1
#
# Code quality: `make quality` reports every finding and never fails on one; `make quality-check` is the gate CI runs,
# which fails on a finding that is not in the committed baselines in this directory.
#  - phpstan: dead code (shipmonk/dead-code-detector) and cognitive complexity (tomasvotruba/cognitive-complexity).
#    The dead code detector needs PHP 8.1+, so it cannot live in the root composer.json (PHP 7.4). The tools and
#    the WordPress stubs come from composer.lock in this directory; the code is still analysed as PHP 7.4.
#  - jscpd: copy-paste detection, installed with npm ci from package-lock.json in this directory.
# The base images are pinned by digest. The repository is mounted at /app when they run (see the quality and jscpd
# services in docker-compose.yml).

FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS deps
WORKDIR /opt/quality
COPY composer.json composer.lock ./
COPY src ./src
RUN composer install --no-interaction --no-progress --no-scripts --prefer-dist --optimize-autoloader

FROM php:8.5-cli@sha256:d5e3f03b94966fd65df52b9819c6dde463003b5a32d032698290a0b37b53bb37 AS phpstan
COPY --from=deps /opt/quality /opt/quality
WORKDIR /app
ENTRYPOINT ["/opt/quality/vendor/bin/phpstan"]

FROM node:26-alpine@sha256:6f08a523443ddedf52941874c203475b0ad503e859ad70cb26ab8be9a517dbd5 AS jscpd
ENV PATH=/opt/quality/node_modules/.bin:$PATH
WORKDIR /opt/quality
COPY package.json package-lock.json ./
RUN npm ci --no-fund --no-audit
WORKDIR /app
ENTRYPOINT ["jscpd"]
