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

COMPOSE := docker compose

.DEFAULT_GOAL := help
.PHONY: help build shell composer deps smoke test test-wp test-wp-ms test-known-bugs coverage matrix up down clean

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "} {printf "  %-16s %s\n", $$1, $$2}'

build: ## Build the PHP image for PHP_VERSION
	$(COMPOSE) build php

shell: ## Open a shell in the PHP container
	$(COMPOSE) run --rm php bash

composer: ## Run composer, e.g. make composer ARGS="require --dev foo/bar"
	$(COMPOSE) run --rm composer $(ARGS)

vendor/autoload.php: composer.json
	$(COMPOSE) run --rm composer install --no-interaction --no-progress
	@touch $@

deps: vendor/autoload.php ## Install Composer dependencies

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
