# Notes for AI coding agents

WP Updates Notifier is a WordPress plugin for PHP 7.4+ and WordPress 6.7+. It checks for core, plugin and
theme updates and sends notifications through channels (email, webhook, Slack, and channels from add-ons).

Everything runs in Docker through `make`. The host needs Docker and git, nothing else. `make help` lists
every target. This file holds the commands and the rules that are easy to break. The detail is in the linked
docs; read them rather than guessing. When a command this file names changes, update the file in the same
pull request.

## Commands

| What | Command |
| --- | --- |
| One test class | `make test-wp FILE=tests/wp/test-routing.php` (about 4 s) |
| One test | `make test-wp FILTER=Test_Routing::test_name` |
| WordPress suite | `make test-wp` (single site), `make test-wp-ms` (multisite) |
| Before a pull request | `make check`, plus `make quality-check` and, when code was added, `make coverage` (or `make check-full` for admin screens, channels or the check itself). One line per step; a failure shows the log tail and the fix, the full output is in `build/check/<step>.log` |
| Coverage, when code was added | `make coverage` (fails below `COVERAGE_MIN` in the `Makefile`) |
| Coding standards fixer | `make fix` |
| PHP 7.4 syntax fixer | `make rector-fix` (it runs `make fix` and `make lint` after) |
| Hook reference | `make hook-docs` |
| Translations | `make translations-edit`, translate, `make translations` |
| Commit messages | `make commits` |
| Commit message hook | `make hooks` (once per checkout; `make commits` stays the full check) |

A Claude Code Stop hook (`bin/agent-stop-check.sh`) runs `lint`, `check-hooks`, `check-public-api` and
`check-translations` at the end of a turn that changed plugin code. A failure comes back as a message: fix it
before finishing. It skips steps that already passed on the same tree, and does nothing when Docker is not
running or `vendor/` is missing (run `make deps`).

`PHP_VERSION` and `WP_VERSION` pick the versions, for example `make test-wp PHP_VERSION=7.4`.

Never run `make up`, `make down`, `make clean` or `make site-setup` unless asked: they act on the one local
development site, whichever directory they are run from. The browser suites (`make a11y`, `make e2e`) use
their own throwaway sites and are safe.

## Worktrees and parallel work

- Run `make deps` first in a new worktree: `vendor/` is not shared.
- Each checkout and worktree has its own test table prefix, so `make test-wp` and `make test-wp-ms` may run at
  the same time in different checkouts. Never run the same suite twice at once in one checkout: both runs use the
  same tables and break each other. The same goes for two runs of one browser suite. All checkouts share one
  database container: if it is not running, start one run first, or `docker compose up -d --wait db`, as two runs
  that start it together clash.
- The git targets (`make commits`, `ci-changes`, `dist`, `dist-repro`, `secrets-history`) work in a worktree:
  the Makefile mounts the shared git directory into the container, read-only (`bin/git-mount.sh`). It needs git
  on the host, and a path without spaces. `make secrets-history` fails when gitleaks scans no commits.

## Commits, branches and pull requests

- Conventional Commits: `type(scope)!: summary`. Types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`,
  `test`, `build`, `ci`, `chore`, `revert`. The summary starts lower case and has no full stop.
- The whole first line, type and scope included, is at most 72 characters. Count it before committing.
- `!` needs a `BREAKING CHANGE:` footer, and the footer needs `!`. The body says why.
- Branch names: `<type>/<issue>-<short-name>`, for example `fix/42-null-check`.
- Keep history linear: rebase onto `origin/main`, never merge `main` into a branch.
- The pull request says `Closes #<number>`. Open it and stop: the maintainer merges.

## Rules and the checks that catch them

| Rule | Check | Fix |
| --- | --- | --- |
| A translatable string or translators comment was added, changed or removed | `make check-translations` | the "Changing a string" steps in `docs/contributing/translations.md`; never edit a `.l10n.php` file by hand |
| A hook was added or changed: it has a doc block with `@since` | `make check-hooks` | `make hook-docs`; a new hook also gets its section in `wpun_hook_sections()` in `bin/hooks-scan.php` |
| `modules/` and `examples/` use the public API only | `make check-public-api` | grow the public API; the module never reaches inside |
| A public class, method or function has `@since` (or is tagged `@internal`) | `make check-public-api` | add the tag with the plugin release it ships in |
| PHP 7.4 syntax, for example `[ $a, $b ] = …`, not `list()` | `make rector` | `make rector-fix` |
| WordPress Coding Standards, warnings included | `make lint` | `make fix` |
| PHPStan level 8; `phpstan-baseline.neon` only shrinks | `make stan` | fix the code |
| No new duplication, dead code, complex method or Rector suggestion | `make quality-check` | fix it; `make quality-baseline` only after a real fix shrank a baseline, never to hide a new finding |
| A `WordPress.Security.*` phpcs ignore, a `nosemgrep` or a `@psalm-suppress Tainted*` has ` -- reason` on the same line | `make sast` | add the reason |

The skills in `.claude/skills/` walk through the multi-step jobs: adding a public hook, changing a string,
changing what is stored, and adding a channel module.

## Public API

- Public means documented with `@since` and not tagged `@internal`. `docs/developers/stability.md` lists what
  is public and how it may change.
- Released public API changes only through deprecation (`apply_filters_deprecated()`,
  `_deprecated_function()`) and is removed only in a major version.
- New API before a release goes into the API version that release ships (`SC_WPUN_API_VERSION`); it is not
  bumped again for each change.
- Add the hooks and API a feature needs in the same pull request as the feature, with their docs in
  `docs/developers/`.

## Stored data

- The plugin's options are read and written through `Network_Context` only (`includes/class-network-context.php`),
  so they land in site options on a network.
- A change to the shape of stored settings bumps `Settings::OPT_VERSION` (`includes/class-settings.php`), adds
  an upgrade step in `maybe_upgrade()`, a fixture of the old shape in `tests/fixtures/settings.php` and a test.
- WordPress stores `false` as `''`, and `update_option( $name, false )` on a missing option creates nothing. A
  value that may be `false` is wrapped, as `Instance_Data` does (`includes/class-instance-data.php`).

## Multisite

The plugin is network-only on multisite (`docs/decisions/0021-network-only-on-multisite.md`). Run
`make test-wp-ms` for anything that touches options, activation, capabilities or admin addresses: a pull
request that changes few files runs only the single-site legs in CI, so the multisite suite will not catch it
there.

## Admin screens

No JavaScript on the settings screens (decisions 0011 and 0026 in `docs/decisions/`). They meet WCAG 2.2 AA,
and `make a11y` must pass after a change to them.

## Tests

- Test classes extend `WPUN_Test_Case` (`tests/wp/class-wpun-test-case.php`). No real HTTP: use `fake_http()`.
- A bug fix starts with a test that fails without the fix. A test for a bug that stays open carries
  `@group known-bug` (`tests/wp/test-known-bugs.php`).
- Security tests go in `tests/wp/security/`.
- The coverage floor (`COVERAGE_MIN` in the `Makefile`) and the mutation floors (`infection.json5`) only go up.
  Write the missing test; never lower a floor.

## Prose

- Plain, short sentences in code comments, docs and messages.
- Say "security token" in docs and messages; `nonce` stays in code.
- A decision that changes how the plugin works gets a record in `docs/decisions/`.

## Read more

- `CONTRIBUTING.md`: the rules for a pull request.
- `docs/developers/architecture.md`: how the parts fit together.
- `docs/developers/stability.md`: the public API.
- `docs/developers/writing-a-module.md`: the channel API.
- `docs/contributing/testing.md`: every test layer and check.
- `docs/decisions/README.md`: the decision records.
