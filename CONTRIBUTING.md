# Contributing

Thanks for helping. Everything runs in Docker, so the only things you need are
`docker` and `docker compose`.

- Set up, tests and coding standards: see **Development** in the
  [README](README.md). `make lint`, `make stan`, `make rector` and `make test`
  must pass; CI runs them on every pull request.
- Check changes by hand on the local site (`make up`, see **Local site** in the
  README): debugging is on there, with Query Monitor, so a change must not add
  PHP notices or `doing_it_wrong` messages. It also runs with a persistent
  object cache. For changes that save or read options, transients or channels,
  check them with the cache on and with `make site-setup OBJECT_CACHE=off`.
- Commits use [Conventional Commits](https://www.conventionalcommits.org/)
  (`fix(cron): …`, `feat!: …` for breaking changes). They decide the next
  version number, so write them with care. `make commits` checks the commits on
  your branch and CI runs it on every pull request: allowed types (`feat`, `fix`,
  `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`),
  a lower-case summary of 72 characters or fewer, and a `!` together with a
  `BREAKING CHANGE:` footer for breaking changes. It cannot check that the
  summary is in the imperative mood or that the body explains why; reviewers do.
- Branch from `main`, name the branch `<type>/<issue>-<short-name>` (for example
  `fix/42-null-check`), and reference the issue in the pull request with
  `Closes #<number>`.
- Keep history linear: rebase onto `main`, do not merge `main` into a branch.
- A bug fix needs a test that fails without the fix. Tests for a bug that is
  still open carry `@group known-bug` (see `tests/wp/test-known-bugs.php`); the
  fix removes the tag.

## Security checks

Run `make security` before opening a pull request. It needs only Docker, and
writes its reports to `security-reports/` (gitignored). It runs these checks,
each of which is also a job in `.github/workflows/security.yml`:

| Target | Tool | What it checks |
| --- | --- | --- |
| `make check-workflows` | actionlint, zizmor | The workflows in `.github/workflows/` |
| `make secrets` | gitleaks | The git history, the working tree and the built zip |
| `make audit` | composer audit, OSV-Scanner | Known vulnerabilities in every lockfile |
| `make sast` | Semgrep, with the rules in `.semgrep/` | The plugin code, plus a check that every suppression gives a reason |
| `make taint` | Psalm | Untrusted data reaching output, mail, URLs or SQL without escaping |

CI also runs these, outside `make security`:

- **Dependency review** on pull requests: new dependencies must have no known
  vulnerabilities and a licence on the allowlist.
- **Trivy** over the images (`make audit-images`): on `main` and weekly, report only.
- **OpenSSF Scorecard** (`scorecard.yml`) on `main`.

The security tests (`tests/wp/security/`, `@group security`) run with the rest
of the suite. To run only them, use `make test-wp ARGS="--group security"`.

The `make zizmor` target can also run zizmor's online audits. For that, set
`GITHUB_PUBLIC_TOKEN` to a GitHub token that can only read public
repositories. Without one, those audits are skipped.

### Suppressions and baselines

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

### Tools and dependencies

- Every tool image and base image is pinned by tag and digest, and every
  action by its full commit SHA. Use a release that is at least two weeks old.
  Dependabot proposes the updates after a 14-day cooldown.
- A new tool goes in `docker-compose.yml` under the `security` profile, with a
  `make` target and a job in `security.yml`. If it needs its own Composer or
  npm project, like Psalm in `docker/psalm` and commitlint in
  `docker/commitlint`, commit the lockfile and add the directory to
  `.github/dependabot.yml`.
- The plugin has no runtime dependencies, and `make dist-check` fails if one
  appears in `composer.json`'s `require` or the zip. Development dependencies
  must pass the dependency review's licence allowlist. An exception goes by
  package name, with the reason in a comment.

## Releases

Merging to `main` changes no version. [release-please](https://github.com/googleapis/release-please)
keeps one **Release PR** open that collects the next version and the
`CHANGELOG.md` entry from the commit messages: `fix` is a patch, `feat` a minor,
and `!` with a `BREAKING CHANGE:` footer a major (`docs`, `chore`, `build`, `ci`,
`test` and `style` do not release by themselves). Merging the Release PR creates
the `vX.Y.Z` tag and the GitHub release. To force a version, add a
`Release-As: 2.0.0` footer to a commit.

The workflow also sets the copyright year and the `readme.txt` stable tag on the
Release PR's branch.

Before merging the Release PR, run the OWASP ZAP scan with `make dast` (about
11 minutes, Docker only). It is too slow for CI. It must finish with no Medium
or High alerts. The report is in `security-reports/zap-report.html`.
