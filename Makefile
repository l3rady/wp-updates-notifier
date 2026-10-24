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

# The pull request `make ci-changes` looks at: the changes on this branch since it left BASE, up to HEAD.
BASE ?= origin/main
HEAD ?= HEAD

# gitleaks: SECRETS_LOG_OPTS limits the history scan (a pull request: base..head); SECRETS_SARIF=1 also writes
# SARIF reports to security-reports/ for code scanning. Values are always redacted.
SECRETS_LOG_OPTS ?=
SECRETS_SARIF    ?=
GITLEAKS         := $(COMPOSE) --profile security run --rm -T
GITLEAKS_ARGS    := --no-banner --verbose --redact -c /app/.gitleaks.toml
secrets_report    = $(if $(SECRETS_SARIF),--report-format sarif --report-path /reports/$(1).sarif)

# OSV-Scanner: AUDIT_SARIF=1 also writes security-reports/osv.sarif for code scanning.
AUDIT_SARIF ?=

# Semgrep: SAST_SARIF=1 also writes security-reports/semgrep.sarif for code scanning. Only the rules in .semgrep/ run,
# offline, with no metrics.
SAST_SARIF ?=
SEMGREP    := $(COMPOSE) --profile security run --rm -T semgrep semgrep
SEMGREP_ARGS := --metrics=off --disable-version-check
SAST_PATHS := wp-updates-notifier.php uninstall.php includes modules examples

# Psalm taint analysis: TAINT_SARIF=1 also writes security-reports/psalm.sarif for code scanning. Taint findings only;
# PHPStan does the type checks. Runs from its own image (docker/psalm), as Psalm 6 needs PHP 8.1+.
TAINT_SARIF ?=
PSALM       := $(COMPOSE) --profile security run --build --rm -T psalm

.DEFAULT_GOAL := help
.PHONY: help build shell composer deps dist dist-check dist-repro sbom test-dist plugin-check check-headers check-hooks hook-docs translations-edit translations-todo translations check-translations check-public-api lint commits fix stan stan-baseline rector rector-fix smoke test test-wp test-wp-ms test-release test-known-bugs coverage matrix docs docs-serve docs-a11y docs-links docs-deploy assets up down clean site-setup site-log wp check-workflows ci-changes test-ci-changes zizmor secrets secrets-history secrets-tree secrets-zip audit audit-composer audit-osv audit-images sast sast-scan sast-test sast-ignores taint dast a11y security

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

test-release: ## Rehearse the scripts the release workflow runs (copyright year, stable tag, signed commit request)
	$(COMPOSE) run --build --rm php sh tests/bin/test-release-scripts.sh

dist: ## Build build/wp-updates-notifier-<version>.zip (runtime files only)
	$(COMPOSE) run --build --rm php sh bin/build-dist.sh

dist-check: ## Check the zip: allowlisted contents, required files, version
	$(COMPOSE) run --build --rm php sh bin/check-dist.sh

dist-repro: ## Build the zip twice from fresh clones of HEAD (different umask, file times, time zone) and fail if the SHA-256 differs
	$(COMPOSE) run --build --rm -e DIST_REPRO_SCRIPT php sh bin/check-dist-repro.sh

sbom: dist ## Write a CycloneDX SBOM of the built zip to build/wp-updates-notifier-<version>.cdx.json (Syft)
	@v=$$(sed -n 's/^ \* Version: //p' wp-updates-notifier.php | head -n 1); \
	$(COMPOSE) --profile security run --rm -T syft dir:/build/wp-updates-notifier --source-name wp-updates-notifier --source-version "$$v" -o cyclonedx-json=/build/wp-updates-notifier-$$v.cdx.json

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

translations-edit: ## Write build/translations/<locale>.po from the .l10n.php files and the code, to translate (or LOCALE=xx)
	$(COMPOSE) run --build --rm php sh bin/translations.sh edit $(LOCALE)

translations-todo: ## List the untranslated and fuzzy entries of every locale (or LOCALE=xx)
	$(COMPOSE) run --build --rm php sh bin/translations.sh todo $(LOCALE)

translations: ## Build languages/*.l10n.php from build/translations/*.po (if newer) or the current files (or LOCALE=xx)
	$(COMPOSE) run --build --rm php sh bin/translations.sh build $(LOCALE)

check-translations: ## Check every bundled translation is complete, valid and built (or LOCALE=xx), and test the exporter
	$(COMPOSE) run --build --rm php sh -c 'sh tests/bin/test-l10n-to-po.sh && sh bin/translations.sh check $(LOCALE)'

check-public-api: ## Check modules and the test add-on use only the public API, and test the checker
	$(COMPOSE) run --build --rm php sh bin/check-public-api.sh

smoke: ## Verify PHP, mysqli and that every PHP file lints
	$(COMPOSE) run --build --rm php sh docker/smoke.sh

matrix: ## Smoke test on every PHP version in MATRIX
	@for v in $(MATRIX); do \
		echo "=== PHP $$v ==="; \
		PHP_VERSION=$$v $(COMPOSE) run --build --rm php sh docker/smoke.sh || exit 1; \
	done

check-workflows: ## Audit .github/ with actionlint (and shellcheck) and zizmor (fails on findings)
	$(COMPOSE) --profile security run --rm -T actionlint
	$(MAKE) --no-print-directory zizmor

# Runs in the PHP image, which has git, sh and awk, so nothing is installed on the host.
ci-changes: ## Show which CI jobs a pull request would run: the changes since BASE (default origin/main) up to HEAD (default HEAD)
	$(COMPOSE) run --build --rm php sh -c 'sh bin/ci-changes.sh "$$(git merge-base $(BASE) $(HEAD))" $(HEAD)'

test-ci-changes: ## Test the map of changed files to CI jobs, and that it agrees with the workflows and their gate jobs
	$(COMPOSE) run --build --rm php sh tests/bin/test-ci-changes.sh

zizmor: ## Audit .github/ (workflows, actions, dependabot.yml) with zizmor only (GITHUB_PUBLIC_TOKEN enables online audits; ZIZMOR_FORMAT=sarif ZIZMOR_OUT=file)
	$(COMPOSE) --profile security run --rm -T $${GITHUB_PUBLIC_TOKEN:+-e GH_TOKEN=$$GITHUB_PUBLIC_TOKEN} zizmor --format $(ZIZMOR_FORMAT) .github/$(if $(ZIZMOR_OUT), > $(ZIZMOR_OUT))

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

audit: audit-composer audit-osv ## Check the locked dependencies for known vulnerabilities and abandoned packages (make -k runs both)

audit-composer: ## composer audit on composer.lock (fails on advisories and abandoned packages)
	$(COMPOSE) run --rm composer audit --locked

# The lockfiles are composer.lock, docker/psalm/composer.lock and docker/commitlint/package-lock.json.
audit-osv: ## OSV-Scanner over the repository's lockfiles
	@mkdir -p security-reports
	$(if $(AUDIT_SARIF),-$(COMPOSE) --profile security run --rm -T osv-scanner scan source -r --format sarif --output-file /reports/osv.sarif /app)
	$(COMPOSE) --profile security run --rm -T osv-scanner scan source -r /app

audit-images: build ## Trivy over docker/ and the built image (HIGH and CRITICAL, fixable only). Reports, never fails
	$(COMPOSE) --profile security run --rm -T trivy config --skip-version-check --severity HIGH,CRITICAL --exit-code 0 docker/
	$(COMPOSE) --profile security run --rm -T -v /var/run/docker.sock:/var/run/docker.sock:ro trivy image --skip-version-check --severity HIGH,CRITICAL --ignore-unfixed --exit-code 0 wp-updates-notifier:php$(PHP_VERSION)

sast: sast-scan sast-test sast-ignores ## Static analysis of the shipped code with the rules in .semgrep/, their tests, and the suppression checks (make -k runs all)

sast-scan: ## Semgrep over the plugin and examples/ (fails on findings; SAST_SARIF=1 also writes the SARIF report)
	@mkdir -p security-reports
	$(SEMGREP) scan --error $(SEMGREP_ARGS) --no-git-ignore --config .semgrep/ $(if $(SAST_SARIF),--sarif-output=/reports/semgrep.sarif) $(SAST_PATHS)

# The test file is named, as `semgrep --test .semgrep/` does not look inside a hidden directory.
sast-test: ## Test the Semgrep rules against .semgrep/wpun.php
	$(SEMGREP) --test $(SEMGREP_ARGS) --config .semgrep/wpun.yml .semgrep/wpun.php

sast-ignores: ## Suppressions need a reason, every PHP file has its guard, and test the checker
	$(COMPOSE) run --build --rm php sh -c 'sh tests/bin/test-check-security-ignores.sh && sh bin/check-security-ignores.sh'

taint: deps ## Psalm taint analysis from untrusted sources (plugin headers, options, transients) to unsafe sinks (fails on findings; TAINT_SARIF=1 also writes the SARIF report)
	@mkdir -p security-reports
	$(PSALM) --taint-analysis --monochrome --no-cache --no-progress --threads=1 $(if $(TAINT_SARIF),--report=/reports/psalm.sarif)

dast: ## OWASP ZAP against a throwaway copy of the site (own compose project wpun-dast, never touches make up; fails on Medium/High alerts; slow)
	@./tests/dast/run.sh

a11y: ## axe and keyboard checks of the settings screen on a throwaway site (own compose project wpun-e2e, never touches make up)
	@./tests/e2e/run.sh

assets: ## Render the plugin icon, banner and docs site brand files from the SVG sources in .wordpress-org/src (docker/assets)
	$(COMPOSE) --profile assets run --build --rm -T assets sh bin/build-assets.sh

# The documentation site (docker/docs, mkdocs.yml). docs-deploy is what the deploy workflow runs: it commits one version
# to the local gh-pages branch with mike and never pushes (the caller does, so no token enters the container).
# DOCS_VERSION is the folder (main, 2.0.0), DOCS_ALIAS an alias for it (latest) and DOCS_TITLE its label in the version
# switcher. DOCS_DEFAULT=1 also makes DOCS_ALIAS (or DOCS_VERSION) the page the site root redirects to. DOCS_BRANCH is
# the branch mike commits to (tests rehearse on a throwaway one).
DOCS_VERSION ?=
DOCS_ALIAS   ?=
DOCS_TITLE   ?=
DOCS_DEFAULT ?=
DOCS_BRANCH  ?= gh-pages
DOCS         := $(COMPOSE) --profile docs run --build --rm -T docs

docs: ## Build the documentation site into build/docs (strict: broken links and anchors fail)
	$(DOCS) mkdocs build

docs-serve: ## Preview the documentation site on http://localhost:8000 (rebuilds as you edit)
	$(COMPOSE) --profile docs up --build docs-serve

docs-a11y: docs ## axe, third-party request and focus checks of the built site, in the dark and light schemes (own compose project wpun-docs-a11y)
	@./tests/e2e/docs/run.sh

# lychee reads docker/docs/lychee.toml (exclusions, retries, accepted statuses). GITHUB_TOKEN is passed on only when set,
# and never printed: without it github.com answers 429 after a few dozen links.
docs-links: docs ## Check the external links of the built site, README.md, readme.txt and CONTRIBUTING.md with lychee (GITHUB_TOKEN avoids GitHub's rate limit)
	$(COMPOSE) --profile docs run --rm -T lychee --config docker/docs/lychee.toml --no-progress build/docs README.md readme.txt CONTRIBUTING.md

docs-deploy: ## Commit one version to the local gh-pages branch with mike, without pushing: make docs-deploy DOCS_VERSION=main [DOCS_ALIAS=latest] [DOCS_TITLE=...] [DOCS_DEFAULT=1]
	@test -n "$(DOCS_VERSION)" || { echo 'Set DOCS_VERSION, e.g. make docs-deploy DOCS_VERSION=main' >&2; exit 1; }
	$(DOCS) mike deploy --branch $(DOCS_BRANCH) $(if $(DOCS_TITLE),--title "$(DOCS_TITLE)") --update-aliases $(DOCS_VERSION) $(DOCS_ALIAS)
	$(if $(DOCS_DEFAULT),$(DOCS) mike set-default --branch $(DOCS_BRANCH) $(or $(DOCS_ALIAS),$(DOCS_VERSION)))

security: check-workflows secrets audit sast taint ## Run every security check

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
