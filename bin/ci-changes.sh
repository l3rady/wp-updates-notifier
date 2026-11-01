#!/bin/sh
# Which CI jobs can a pull request's changes affect? The map from changed paths to jobs, used by the `changes` job of
# ci.yml, lint.yml, docs.yml and security.yml to skip the rest on pull requests. Run it locally with `make ci-changes`.
#
#   sh bin/ci-changes.sh BASE HEAD    the paths changed between the two commits (git diff --name-only)
#   sh bin/ci-changes.sh -            the paths on stdin, one per line (the tests use this)
#
# Environment: CI_EVENT is the event name (github.event_name; empty is a local run and counts as a pull request) and
# CI_HEAD_REF the pull request's branch (github.head_ref).
#
# Output, in $GITHUB_OUTPUT format, on stdout and appended to $GITHUB_OUTPUT when that is set:
#   jobs=["phpstan","docs",...]   the jobs to run
#   full=true|false               true for the full test matrices (not a pull request, or the Release PR)
# A summary of each changed path and the jobs it triggers goes to stderr.
#
# Everything runs when the event is not a pull request, on the Release PR (release-please--*), when a changed path is
# shared plumbing (ALL in the table below), and when a changed path matches no rule: a new file must never skip tests.
# A path can match several rules and all of them apply. The table below is the single source of truth;
# tests/bin/test-ci-changes.sh checks it against the workflows. Only the jobs listed in ALL_JOBS are in the map. The
# jobs that always run (workflows, secrets, commits) and the ones that never run on pull requests (images,
# wordpress-trunk, deploy) are not in it.
set -eu

# Every job the map controls: the job ids of the workflows, which are unique across them.
ALL_JOBS='phpstan rector release-scripts dist dist-repro plugin-check smoke wordpress coverage a11y e2e phpcs docs links sast taint dependencies dependency-review'

# One rule per line: a shell case glob (a * also matches /), then the job ids it triggers. ALL runs every job and -
# runs none (the path is known and affects no pull request job). Blank lines and lines starting with # are ignored.
rules() {
	cat <<'RULES'
# Shared plumbing: every job builds on it, so a change runs everything.
.github/workflows/*       ALL
.github/actions/*         ALL
Makefile                  ALL
docker/Dockerfile         ALL
docker/php.ini            ALL
docker/*.sh               ALL
.dockerignore             ALL
composer.json             ALL
composer.lock             ALL
docker-compose.yml        ALL
docker/compose.ci-cache.yml ALL
bin/ci-changes.sh         ALL
tests/bin/test-ci-changes.sh ALL
tests/bin/ci-changes/*    ALL

# The plugin's PHP: analysed, linted, tested, zipped and run in the browser. The hooks and translations checks (in
# the phpcs job) read it too. wp-updates-notifier.php and uninstall.php are also what the release scripts rehearse on.
wp-updates-notifier.php   phpstan rector smoke wordpress coverage dist dist-repro plugin-check a11y e2e phpcs sast taint release-scripts
uninstall.php             phpstan rector smoke wordpress coverage dist dist-repro plugin-check a11y e2e phpcs sast taint release-scripts
includes/*                phpstan rector smoke wordpress coverage dist dist-repro plugin-check a11y e2e phpcs sast taint
modules/*                 phpstan rector smoke wordpress coverage dist dist-repro plugin-check a11y e2e phpcs sast taint
# Translations: tested (test-translations), zipped, linted as PHP and checked complete by check-translations.
languages/*               smoke wordpress coverage dist dist-repro plugin-check a11y e2e phpcs
# The settings screen's stylesheet: in the zip and in the browser checks.
assets/*                  wordpress coverage dist dist-repro plugin-check a11y e2e
# The example add-on: PHPStan, phpcs, Semgrep, Psalm, public API check and the WordPress tests read it. Not in the zip.
examples/*                phpstan smoke wordpress coverage phpcs sast taint
# The dev plugins are mounted into the browser checks; PHPStan, phpcs, the public API check and a test read them.
docker/dev-plugins/*      phpstan smoke wordpress coverage a11y e2e phpcs
# Only the smoke test lints these.
docker/mu-plugins/*       smoke e2e
# The browser checks mount these into their throwaway site.
docker/apache/*           a11y e2e
docker/site/*             a11y e2e

# Tool configuration.
phpcs.xml.dist            phpcs
phpstan.neon.dist         phpstan
phpstan-baseline.neon     phpstan
rector.php                rector phpcs smoke
psalm.xml                 taint
psalm-baseline.xml        taint
docker/psalm/*            taint smoke
.semgrep/*                sast smoke
# The Semgrep suppression check and its test.
bin/check-security-ignores.sh        sast
tests/bin/test-check-security-ignores.sh sast
tests/bin/check-security-ignores/*   sast

# The lint job also runs check-hooks, check-translations and check-public-api, with their tests. The release script
# test copies the hook checker, so it rehearses it too.
bin/check-hooks.php       phpcs smoke release-scripts
bin/hooks-scan.php        phpcs smoke release-scripts
bin/hook-docs.php         phpcs smoke
bin/check-hooks.sh        phpcs
bin/hook-docs.sh          phpcs
tests/bin/test-check-hooks.sh phpcs
tests/bin/check-hooks/*   phpcs
bin/check-public-api.php  phpcs smoke
bin/check-public-api.sh   phpcs
tests/bin/test-check-public-api.sh phpcs
tests/bin/check-public-api/* phpcs
bin/translations.sh       phpcs
bin/l10n-to-po.php        phpcs smoke
tests/bin/test-l10n-to-po.sh phpcs
# check-hooks compares it with the hooks in the code, and the docs site includes it.
docs/developers/hooks.md  phpcs docs links

# readme.txt is not committed: bin/readme.php generates it from these (and from the plugin header and the About tab
# class, covered above). The header check, the zip, Plugin Check, the contract and About tab tests and lychee read it.
.wordpress-org/readme/*   plugin-check dist dist-repro wordpress coverage links
docs/users/troubleshooting.md plugin-check dist dist-repro wordpress coverage
bin/readme.php            plugin-check dist dist-repro wordpress coverage links
tests/bin/test-readme.sh  plugin-check
tests/bin/readme/*        plugin-check
LICENSE                   plugin-check dist dist-repro
# The Playground blueprint is not committed either: bin/blueprint.php generates it from .wordpress-org/blueprint (the
# template and the demo PHP; smoke lints the PHP and phpcs checks it). make check-blueprint and make test-blueprint
# run in the plugin-check job. The vendored schema, Ajv and their tests are tests/bin/blueprint; make blueprint-smoke
# (tests/blueprint-smoke) is local only.
.wordpress-org/blueprint/* phpcs smoke plugin-check e2e sast
bin/blueprint.php         smoke plugin-check
tests/bin/test-blueprint.sh plugin-check
tests/bin/blueprint/*     plugin-check
tests/bin/blueprint/package.json dependencies dependency-review
tests/bin/blueprint/package-lock.json dependencies dependency-review
tests/blueprint-smoke/package.json dependencies dependency-review
tests/blueprint-smoke/package-lock.json dependencies dependency-review
# check-headers.sh reads the plugin header and the generated readme.txt.
bin/check-headers.sh      plugin-check
# The zip, and what is built, checked and installed from it. build-dist.sh follows .distignore and .gitattributes.
.distignore               dist dist-repro plugin-check
.gitattributes            dist dist-repro plugin-check
bin/build-dist.sh         dist dist-repro plugin-check
bin/check-dist.sh         dist dist-repro plugin-check
bin/check-dist-repro.sh   dist dist-repro plugin-check
bin/test-dist.sh          dist dist-repro plugin-check
bin/plugin-check.sh       dist dist-repro plugin-check
bin/fresh-wordpress.sh    dist dist-repro plugin-check
# The WordPress.org assets are excluded from the zip (.distignore). make wporg-assets-check assembles and checks them
# in the plugin-check job; make assets (rendering the icons and banners) and make screenshots are not run by CI.
.wordpress-org/*          plugin-check
bin/build-wporg-assets.sh plugin-check
bin/check-wporg-assets.sh plugin-check
bin/check-wporg-assets.php plugin-check smoke
tests/bin/test-wporg-assets.sh plugin-check
docker/assets/*           -
bin/build-assets.sh       -

# make coverage: the WordPress suite with pcov (the coverage job). Its Clover check and the test of that check.
bin/coverage-check.php    coverage phpcs smoke
bin/coverage-merge.php    coverage phpcs smoke
tests/bin/test-coverage-check.sh coverage

# make mutation (Infection) runs by hand before a release, never in CI, so its settings affect no job.
infection.json5           -
docker/infection-bootstrap.php -

# What the release workflow runs on the Release PR, rehearsed by make test-release.
bin/sign-release-tag.sh   release-scripts
bin/commit-payload.php    release-scripts smoke
bin/update-copyright-year.sh release-scripts
tests/bin/test-release-scripts.sh release-scripts
release-please-config.json release-scripts
.release-please-manifest.json release-scripts
.github/release-signers   release-scripts

# The WordPress tests, their fixtures and the PHP files in them (linted by phpcs, parsed by the smoke test). The
# fixtures plugins are also analysed by PHPStan and checked by the public API check.
tests/wp/*                wordpress coverage phpcs smoke
tests/bootstrap.php       wordpress coverage phpcs smoke
tests/fixtures/*          wordpress coverage phpcs smoke phpstan e2e
phpunit.xml.dist          wordpress coverage
# The test about tab reads the docs home page.
docs/index.md             wordpress coverage docs links

# The browser checks of the settings screen (a11y) and of the docs site (docs). Both use tests/e2e/compose.yml. The
# flow tests (e2e, tests/flows) borrow its npm packages and its login.
tests/e2e/*               a11y docs e2e
# The flow tests: a real browser, WP-CLI cron run and Mailpit, on a throwaway site (make e2e). phpcs lints the PHP
# files. They are listed one by one because a * also matches /, and the rules of a path all apply: tests/flows/upgrade
# must not trigger the e2e job.
tests/flows/specs/*       e2e
tests/flows/compose.yml   e2e
tests/flows/compose.multisite.yml e2e
tests/flows/apache-ms.conf e2e
tests/flows/run.sh        e2e
tests/flows/seed.sh       e2e
tests/flows/playwright.config.js e2e
tests/flows/opcache.ini   e2e
tests/flows/control.php   e2e phpcs
tests/flows/reset.php     e2e phpcs
# make e2e-upgrade (tests/flows/upgrade) installs 1.6.0 from WordPress.org: no pull request job runs it, so run it
# before a release. phpcs lints its PHP files.
tests/flows/upgrade/*     -
tests/flows/upgrade/*.php phpcs
# Local only (make dast, make screenshots, make blueprint-smoke, make e2e-upgrade): no pull request job runs them.
tests/dast/*              -
tests/screenshots/*       -
tests/blueprint-smoke/*   -

# The documentation site: the strict build and its checks, and lychee over the built site.
docs/*                    docs links
mkdocs.yml                docs links
docker/docs/*             docs links
# Lychee also reads these files, and follows links to the main branch against the checkout. A linked file that is
# renamed or deleted is therefore only caught on main.
README.md                 links
CONTRIBUTING.md           links
SECURITY.md               links
CHANGELOG.md              plugin-check dist dist-repro wordpress coverage links

# Dependency manifests and lockfiles: osv-scanner (dependencies) and the dependency graph review, as well as the
# area they belong to above. composer.json and composer.lock are plumbing.
docker/*/package.json     dependencies dependency-review
docker/*/package-lock.json dependencies dependency-review
docker/docs/requirements.in  dependencies dependency-review
docker/docs/requirements.txt dependencies dependency-review
docker/docs/osv-scanner.toml dependencies
docker/psalm/composer.json   dependencies dependency-review
docker/psalm/composer.lock   dependencies dependency-review
tests/e2e/package.json    e2e dependencies dependency-review
tests/e2e/package-lock.json e2e dependencies dependency-review

# Only the always-on jobs (commits, secrets, workflows) look at these.
docker/commitlint/*       -
commitlint.config.js      -
.gitleaks.toml            -
.github/dependabot.yml    -
.github/zizmor.yml        -
.github/CODEOWNERS        -
.github/ISSUE_TEMPLATE/*  -
.github/pull_request_template.md -
.gitignore                -
RULES
}

fail() { echo "ci-changes: $*" >&2; exit 2; }

event="${CI_EVENT:-}"
head_ref="${CI_HEAD_REF:-}"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

full=false
case "$event" in
	''|pull_request) ;;
	*) full=true ;;
esac
case "$head_ref" in
	release-please--*) full=true ;;
esac

if [ "$full" = true ]; then
	# Not a pull request, or the Release PR: everything runs, and there is nothing to diff (a push or a schedule run
	# may be a shallow checkout without HEAD^1).
	selected="$ALL_JOBS"
	echo "everything runs: event '${event:-local}', branch '${head_ref:-none}'" >&2
else
	if [ "$#" -eq 1 ] && [ "$1" = "-" ]; then
		cat > "$work/files"
	elif [ "$#" -eq 2 ]; then
		git -c core.quotePath=false diff --name-only --no-renames "$1" "$2" > "$work/files"
	else
		fail "usage: ci-changes.sh BASE HEAD | ci-changes.sh -"
	fi
	rules | grep -Ev '^[[:space:]]*(#|$)' > "$work/rules"

	# The jobs one path triggers: the job ids of every matching rule, or nothing when no rule matches.
	match_path() {
		while read -r glob jobs; do
			# shellcheck disable=SC2254 # The glob is a pattern on purpose.
			case "$1" in $glob) printf '%s ' "$jobs" ;; esac
		done < "$work/rules"
	}

	run_all=false
	collected=" "
	while IFS= read -r path; do
		[ -n "$path" ] || continue
		matched="$(match_path "$path")"
		case " $matched" in
			" ") echo "$path -> unmatched, so everything" >&2; run_all=true ;;
			*" ALL "*) echo "$path -> everything (shared plumbing)" >&2; run_all=true ;;
			*) echo "$path -> $matched" >&2; collected="$collected$matched " ;;
		esac
	done < "$work/files"

	if [ "$run_all" = true ]; then
		selected="$ALL_JOBS"
	else
		selected=""
		for job in $ALL_JOBS; do
			case "$collected" in *" $job "*) selected="$selected $job" ;; esac
		done
	fi
fi

json="["
sep=""
for job in $selected; do
	json="$json$sep\"$job\""
	sep=","
done
json="$json]"

output="jobs=$json
full=$full"
printf '%s\n' "$output"
[ -z "${GITHUB_OUTPUT:-}" ] || printf '%s\n' "$output" >> "$GITHUB_OUTPUT"
