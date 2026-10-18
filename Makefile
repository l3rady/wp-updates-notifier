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

# The commits `make commits` checks: everything on this branch that is not on main.
COMMITS_FROM ?= origin/main
COMMITS_TO   ?= HEAD

# zizmor's output: plain, json, sarif, ... With ZIZMOR_OUT it is written to that file instead of the terminal
# (CI uploads the SARIF file to code scanning).
ZIZMOR_FORMAT ?= plain
ZIZMOR_OUT    ?=

# gitleaks: SECRETS_LOG_OPTS limits the history scan (a pull request: base..head); SECRETS_SARIF=1 also writes
# SARIF reports to security-reports/ for code scanning. Values are always redacted.
SECRETS_LOG_OPTS ?=
SECRETS_SARIF    ?=
GITLEAKS         := $(COMPOSE) --profile security run --rm -T
GITLEAKS_ARGS    := --no-banner --verbose --redact -c /app/.gitleaks.toml
secrets_report    = $(if $(SECRETS_SARIF),--report-format sarif --report-path /reports/$(1).sarif)

.DEFAULT_GOAL := help
.PHONY: help build shell composer deps dist dist-check test-dist plugin-check check-headers check-hooks hook-docs check-public-api lint commits fix stan stan-baseline rector rector-fix smoke test test-wp test-wp-ms test-release test-known-bugs coverage matrix up down clean site-setup site-log wp check-workflows zizmor secrets secrets-history secrets-tree secrets-zip security

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

test: test-wp test-wp-ms test-release ## Run every test layer

lint: deps ## Check WordPress Coding Standards (fails on errors and warnings), the plugin, the example add-on and the field showcase
	$(COMPOSE) run --build --rm php sh -c 'vendor/bin/phpcs && vendor/bin/phpcs --standard=examples/wpun-example-channel/phpcs.xml.dist && vendor/bin/phpcs --standard=docker/dev-plugins/wpun-field-showcase/phpcs.xml.dist'

commits: ## Check that the commits on this branch follow Conventional Commits
	$(COMPOSE) run --build --rm commitlint --from $(COMMITS_FROM) --to $(COMMITS_TO) --verbose

fix: deps ## Auto-fix coding standard violations with phpcbf
	$(COMPOSE) run --build --rm php sh -c 'vendor/bin/phpcbf; s=$$?; [ $$s -le 1 ] || exit $$s; vendor/bin/phpcbf --standard=examples/wpun-example-channel/phpcs.xml.dist; s=$$?; [ $$s -le 1 ] || exit $$s; vendor/bin/phpcbf --standard=docker/dev-plugins/wpun-field-showcase/phpcs.xml.dist; s=$$?; [ $$s -le 1 ]'

stan: deps ## Static analysis with PHPStan (WordPress stubs)
	$(COMPOSE) run --build --rm php vendor/bin/phpstan analyse --no-progress --memory-limit=1G $(ARGS)

stan-baseline: deps ## Regenerate phpstan-baseline.neon (it must shrink, never grow)
	$(COMPOSE) run --build --rm php vendor/bin/phpstan analyse --no-progress --memory-limit=1G --generate-baseline phpstan-baseline.neon $(ARGS)

rector: deps ## Show the PHP 7.4 syntax upgrades Rector would make (changes nothing)
	$(COMPOSE) run --build --rm php vendor/bin/rector process --dry-run --no-progress-bar $(ARGS)

rector-fix: deps ## Apply Rector's upgrades, then run make fix and make lint
	$(COMPOSE) run --build --rm php vendor/bin/rector process --no-progress-bar $(ARGS)

test-release: ## Rehearse the scripts the release workflow runs (copyright year, stable tag)
	$(COMPOSE) run --build --rm php sh tests/bin/test-release-scripts.sh

dist: ## Build build/wp-updates-notifier-<version>.zip (runtime files only)
	$(COMPOSE) run --build --rm php sh bin/build-dist.sh

dist-check: ## Check the zip: allowlisted contents, version, .gitattributes in step with .distignore
	$(COMPOSE) run --build --rm php sh bin/check-dist.sh

test-dist: ## Install the zip into a fresh WordPress, activate, deactivate and uninstall it
	$(COMPOSE) run --build --rm dist sh bin/test-dist.sh

plugin-check: ## Run WordPress.org's Plugin Check on the zip (fails on errors)
	$(COMPOSE) run --build --rm dist sh bin/plugin-check.sh

check-headers: ## Check the plugin header and readme.txt agree and follow WordPress.org's rules
	$(COMPOSE) run --build --rm php sh bin/check-headers.sh

check-hooks: ## Check every hook the plugin fires is documented with @since, and docs/developers/hooks.md is current
	$(COMPOSE) run --build --rm php sh bin/check-hooks.sh

hook-docs: ## Write docs/developers/hooks.md from the hook doc blocks
	$(COMPOSE) run --build --rm php sh bin/hook-docs.sh

check-public-api: ## Check modules and the test add-on use only the public API, and test the checker
	$(COMPOSE) run --build --rm php sh bin/check-public-api.sh

smoke: ## Verify PHP, mysqli and that every PHP file lints
	$(COMPOSE) run --build --rm php sh docker/smoke.sh

matrix: ## Smoke test on every PHP version in MATRIX
	@for v in $(MATRIX); do \
		echo "=== PHP $$v ==="; \
		PHP_VERSION=$$v $(COMPOSE) run --build --rm php sh docker/smoke.sh || exit 1; \
	done

check-workflows: ## Audit .github/workflows/ with actionlint (and shellcheck) and zizmor (fails on findings)
	$(COMPOSE) --profile security run --rm -T actionlint
	$(MAKE) --no-print-directory zizmor

zizmor: ## Audit .github/workflows/ with zizmor only (ZIZMOR_FORMAT=sarif ZIZMOR_OUT=file for code scanning)
	$(COMPOSE) --profile security run --rm -T zizmor --format $(ZIZMOR_FORMAT) .github/workflows/$(if $(ZIZMOR_OUT), > $(ZIZMOR_OUT))

secrets: secrets-history secrets-tree secrets-zip ## Scan the git history, the working tree and the built zip for secrets with gitleaks (make -k runs all three)

secrets-history: ## gitleaks over the git history (SECRETS_LOG_OPTS=base..head for just a pull request's commits)
	@mkdir -p security-reports
	$(GITLEAKS) gitleaks git $(GITLEAKS_ARGS) $(call secrets_report,history) $(if $(SECRETS_LOG_OPTS),--log-opts=$(SECRETS_LOG_OPTS)) /app

secrets-tree: ## gitleaks over the working tree (not vendor/, build/, node_modules/ or .git/)
	@mkdir -p security-reports
	$(GITLEAKS) gitleaks dir $(GITLEAKS_ARGS) $(call secrets_report,tree) /app

secrets-zip: dist ## gitleaks over the built zip, unpacked (dist builds from the tracked files)
	@mkdir -p security-reports
	$(COMPOSE) run --rm -T php sh -c 'rm -rf build/zip-scan && mkdir build/zip-scan && unzip -q build/wp-updates-notifier-*.zip -d build/zip-scan'
	$(GITLEAKS) -v "$(CURDIR)/build/zip-scan:/scan:ro" gitleaks dir $(GITLEAKS_ARGS) $(call secrets_report,zip) /scan

security: check-workflows secrets ## Run every security check

up: ## Start the local WordPress site (http://localhost:8080) with Mailpit (:8025), then set it up
	$(COMPOSE) --profile site up -d --build wordpress mailpit webhook
	$(MAKE) --no-print-directory site-setup

site-setup: ## Install and configure the local site: debug settings and plugins (safe to run again)
	$(COMPOSE) --profile site run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-site-setup

site-log: ## Follow the local site's debug log (wp-content/debug.log)
	$(COMPOSE) --profile site exec wordpress sh -c 'touch wp-content/debug.log && tail -n 50 -f wp-content/debug.log'

wp: ## Run WP-CLI on the local site, e.g. make wp ARGS="plugin list"
	$(COMPOSE) --profile site run --rm wp-cli $(ARGS)

down: ## Stop the local site
	$(COMPOSE) --profile site down

clean: ## Remove containers, volumes and built images
	$(COMPOSE) --profile site down --volumes --remove-orphans
	-docker image ls --format '{{.Repository}}:{{.Tag}}' | grep '^wp-updates-notifier:' | xargs -r docker image rm
