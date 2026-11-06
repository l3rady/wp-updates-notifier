# Contributing

Thanks for helping. Everything runs in Docker, so the only things you need are
`docker` and `docker compose`.

Everyone taking part follows the [Code of Conduct](CODE_OF_CONDUCT.md).
[GOVERNANCE.md](GOVERNANCE.md) says who decides what, and the
[roadmap](https://github.com/l3rady/wp-updates-notifier/blob/main/docs/roadmap.md) says what is planned.

This file holds the rules for a pull request. The how-to is in the
[contributor guide](https://wpun.cariss.dev/main/contributing/): [local sites](https://wpun.cariss.dev/main/contributing/local-sites/),
[tests and checks](https://wpun.cariss.dev/main/contributing/testing/), [security checks](https://wpun.cariss.dev/main/contributing/security-checks/),
[packaging](https://wpun.cariss.dev/main/contributing/packaging/), [translations](https://wpun.cariss.dev/main/contributing/translations/),
the [documentation site](https://wpun.cariss.dev/main/contributing/documentation-site/) and [releases](https://wpun.cariss.dev/main/contributing/releases/).

## Proposing a change

- Branch from `main`, name the branch `<type>/<issue>-<short-name>` (for example
  `fix/42-null-check`), and reference the issue in the pull request with
  `Closes #<number>`.
- Keep history linear: rebase onto `main`, do not merge `main` into a branch.
- Commits use [Conventional Commits](https://www.conventionalcommits.org/)
  (`fix(cron): …`, `feat!: …` for breaking changes). They decide the next
  version number, so write them with care. `make commits` checks the commits on
  your branch and CI runs it on every pull request: allowed types (`feat`, `fix`,
  `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`),
  a lower-case summary of 72 characters or fewer, and a `!` together with a
  `BREAKING CHANGE:` footer for breaking changes. It cannot check that the
  summary is in the imperative mood or that the body explains why; reviewers do.

## What must pass

- `make lint`, `make stan`, `make rector` and `make test` must pass. Run
  `make security` before opening a pull request. CI runs them on pull requests
  (see [below](#which-checks-a-pull-request-runs)).
- Check changes by hand on the [local sites](https://wpun.cariss.dev/main/contributing/local-sites/) (`make up`).
  A change must not add PHP notices or `doing_it_wrong` messages. For changes
  that save or read options, transients or channels, check them with the
  persistent object cache on and with `make site-setup OBJECT_CACHE=off`.
- Changes to the settings screen must keep `make a11y` passing (WCAG 2.2 A and AA,
  and keyboard use). Also check new markup by keyboard and with a screen reader.
- Changes to what a user sees or receives (the settings screen, channels, the
  update check, the email) should keep `make e2e` passing, and a new flow gets a
  case in `tests/flows/specs/`. A change to the network behaviour should keep
  `make e2e-ms` passing.
- Changes to the documentation must keep `make docs`, `make docs-a11y` and
  `make docs-links` passing. Fix a broken link rather than excluding it, and do
  not disable an accessibility rule to make a check pass.
- A changed translatable string or translators comment needs a
  [translation session](https://wpun.cariss.dev/main/contributing/translations/#changing-a-string) before CI is green.

## Coding standards

- PHP follows the WordPress Coding Standards: `make lint` runs PHP_CodeSniffer, and
  warnings fail it too.
- `make stan` runs PHPStan at level 8 with the WordPress stubs, and `make rector`
  fails when the code is behind on PHP 7.4 syntax.
- `make fix` repairs what `phpcbf` can.
- `make quality` writes code-quality reports to `quality-reports/`: copy-paste
  blocks (`make quality-dupes`), unused code (`make quality-dead`), cognitive
  complexity (`make quality-complexity`) and Rector's clean-up suggestions
  (`make quality-rector`). They only report and never fail on findings. Read
  them as hints for a refactor, not as a to-do list.

## Test policy

- A bug fix needs a test that fails without the fix. Tests for a bug that is
  still open carry `@group known-bug` (see `tests/wp/test-known-bugs.php`); the
  fix removes the tag.
- **Coverage.** New code comes with tests. `COVERAGE_MIN` in the `Makefile` is a floor
  that only goes up; the aim is 90% or more, which is already met. When a change raises
  the coverage, raise `COVERAGE_MIN` to the new value rounded down to a whole number, in
  the same pull request. Do not lower it to get a change through; write the missing test.
- **Mutation testing.** The floors in `infection.json5` only go up: when a change raises
  the score, raise them in the same pull request to the new score rounded down, less one.
  Do not lower them to get a change through; write the missing test.
- **Survivors.** The code that decides whether a notification is sent (what was notified,
  the active-only filter, routing, reminders, validation of settings, the cron schedule
  and its repair) is tested to a higher standard than the floor: no survivor is left there
  unless it is ignored with a reason. To handle a survivor, read its diff in the log and
  either write the test that fails with the change, or, if the changed code does the same
  thing for everyone (a cast of an array key, a `trim()` before a function that trims, a
  check that cannot be reached), ignore it in `infection.json5`. Ignore it by the text of
  its line under the mutator that made it (`ignoreSourceCodeByRegex`), or by
  `Class::method` under `ignore`, put the reason in a comment above it, and keep the
  pattern as narrow as the line. Never change the plugin to make a mutant go away.

## Which checks a pull request runs

Every workflow still starts on every pull request, but a pull request only runs
the jobs its changes can affect. A `changes` job in `ci.yml`, `lint.yml`,
`docs.yml` and `security.yml` runs `bin/ci-changes.sh`, whose rule table maps
paths to jobs and is the single source of truth. A path can match several
rules, and all of them apply. The rest is skipped, and the WordPress tests run
4 legs instead of 18 (single site only).

Everything runs when a changed file is shared plumbing (the workflows, the
`Makefile`, the Docker setup, `composer.json`, `composer.lock`), when a changed
file matches no rule (so a new file never skips tests), on the Release PR, and
on every event that is not a pull request (pushes to `main`, the scheduled
runs, manual runs). `make ci-changes` shows what your branch would run (`BASE`
defaults to `origin/main`, `HEAD` to `HEAD`), and `make test-ci-changes` checks
the rules against the workflows.

Only the gate jobs (`ci-gate`, `lint-gate`, `docs-gate`, `security-gate`) and
`commits` are required checks. A gate passes when every job of its workflow
passed or was skipped, so a skipped job never blocks a merge. A new job must be
added to its workflow's gate `needs` and to the table in `bin/ci-changes.sh`
(or, if it always runs or never runs on pull requests, to the exceptions in
`tests/bin/test-ci-changes.sh`); that test fails until it is.

## Suppressions and baselines

- A `phpcs:ignore` or `phpcs:disable` for a `WordPress.Security.*` sniff, a
  `nosemgrep` and a `@psalm-suppress Tainted*` all need a reason on the same
  line, after ` -- `. For example:
  `// nosemgrep: wpun-no-unserialize -- Reads our own export.`
  `make sast` fails without one.
- A gitleaks allowlist entry in `.gitleaks.toml` names a path and the exact
  value, never a whole rule. A real secret that was committed is rotated,
  never allowlisted.
- `phpstan-baseline.neon` and `psalm-baseline.xml` only ever shrink. A new
  finding is fixed. If it is proven safe, it gets an annotation with a
  reason.

## Tools and dependencies

- Every tool image and base image is pinned by tag and digest, and every
  action by its full commit SHA. Use a release that is at least two weeks old.
  Dependabot proposes the updates after a 14-day cooldown, grouped into one
  pull request per directory each week (majors on their own, except actions).
- A new tool goes in `docker-compose.yml` under the `security` profile, with a
  `make` target and a job in `security.yml`. If it needs its own Composer or
  npm project, like Psalm in `docker/psalm` and commitlint in
  `docker/commitlint`, commit the lockfile and add the directory to
  `.github/dependabot.yml` with a group like the others.
- Every job sets `timeout-minutes`, and every workflow that runs on pull
  requests has a `concurrency` group, so a new push cancels the old run.
- The plugin has no runtime dependencies, and `make dist-check` fails if one
  appears in `composer.json`'s `require` or the zip. Development dependencies
  must pass the dependency review's licence allowlist. An exception goes by
  package name, with the reason in a comment.
- Playwright is pinned by image digest and npm lockfile in `tests/e2e/`: keep the
  image tag and `@playwright/test` at the same version.
