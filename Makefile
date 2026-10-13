# Everything runs in Docker. The only host requirement is docker + docker compose.
#
#   make smoke                       # sanity-check the environment
#   make smoke PHP_VERSION=7.4       # pick a PHP version (default 8.3)
#   make matrix                      # smoke test every supported PHP version
#   make up                          # local site on http://localhost:8080, mail on :8025

PHP_VERSION ?= 8.3
WP_VERSION  ?= 7.1.3
MATRIX      ?= 7.4 8.0 8.1 8.2 8.3 8.4 8.5

export PHP_VERSION
export WP_VERSION
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

COMPOSE := docker compose --progress quiet

.DEFAULT_GOAL := help
.PHONY: help build shell composer deps lint fix stan stan-baseline rector rector-fix smoke test test-wp test-wp-ms test-known-bugs coverage matrix up down clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "} {printf "  %-16s %s\n", $$1, $$2}'

build: ## Build the PHP image for PHP_VERSION
	$(COMPOSE) build php

shell: ## Open a shell in the PHP container
	$(COMPOSE) run --rm php bash

composer: ## Run composer, e.g. make composer ARGS="require --dev foo/bar"
	$(COMPOSE) run --rm composer $(ARGS)

vendor/autoload.php: composer.json composer.lock
	$(COMPOSE) run --rm composer install --no-interaction --no-progress
	@touch $@

deps: vendor/autoload.php ## Install Composer dependencies

test-wp: ## Run the WordPress test suite (single site)
	$(COMPOSE) run --build --rm wp-tests sh /opt/wp-updates-notifier/docker/wp-tests.sh $(ARGS)

test-wp-ms: ## Run the WordPress test suite as multisite
	$(COMPOSE) run --build --rm -e WP_MULTISITE=1 wp-tests sh /opt/wp-updates-notifier/docker/wp-tests.sh $(ARGS)

test-known-bugs: ## Run the tests that document open bugs (they are expected to fail)
	$(COMPOSE) run --build --rm wp-tests sh /opt/wp-updates-notifier/docker/wp-tests.sh --exclude-group none --group known-bug $(ARGS)

test: test-wp test-wp-ms ## Run every test layer

lint: deps ## Check WordPress Coding Standards (fails on errors and warnings)
	$(COMPOSE) run --build --rm php vendor/bin/phpcs

fix: deps ## Auto-fix coding standard violations with phpcbf
	$(COMPOSE) run --build --rm php sh -c 'vendor/bin/phpcbf; s=$$?; [ $$s -le 1 ]'

stan: deps ## Static analysis with PHPStan (WordPress stubs)
	$(COMPOSE) run --build --rm php vendor/bin/phpstan analyse --no-progress --memory-limit=1G $(ARGS)

stan-baseline: deps ## Regenerate phpstan-baseline.neon (it must shrink, never grow)
	$(COMPOSE) run --build --rm php vendor/bin/phpstan analyse --no-progress --memory-limit=1G --generate-baseline phpstan-baseline.neon $(ARGS)

rector: deps ## Show the PHP 7.4 syntax upgrades Rector would make (changes nothing)
	$(COMPOSE) run --build --rm php vendor/bin/rector process --dry-run --no-progress-bar $(ARGS)

rector-fix: deps ## Apply Rector's upgrades, then run make fix and make lint
	$(COMPOSE) run --build --rm php vendor/bin/rector process --no-progress-bar $(ARGS)

smoke: ## Verify PHP, mysqli and that every PHP file lints
	$(COMPOSE) run --build --rm php sh docker/smoke.sh

matrix: ## Smoke test on every PHP version in MATRIX
	@for v in $(MATRIX); do \
		echo "=== PHP $$v ==="; \
		PHP_VERSION=$$v $(COMPOSE) run --build --rm php sh docker/smoke.sh || exit 1; \
	done

up: ## Start the local WordPress site (http://localhost:8080) with Mailpit (:8025)
	$(COMPOSE) --profile site up -d --build wordpress wp-cli mailpit webhook

down: ## Stop the local site
	$(COMPOSE) --profile site down

clean: ## Remove containers, volumes and built images
	$(COMPOSE) --profile site down --volumes --remove-orphans
	-docker image ls --format '{{.Repository}}:{{.Tag}}' | grep '^wp-updates-notifier:' | xargs -r docker image rm
