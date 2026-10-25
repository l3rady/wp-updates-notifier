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
- Changes to the settings screen must keep `make a11y` passing (about 40
  seconds, Docker only; CI runs it on every pull request): axe scans of every
  settings view for WCAG 2.2 A and AA, and keyboard checks of the Channels list
  and a channel form, on a throwaway site that never touches `make up`. It lists
  each violation with its rule, elements and help text; the report is in
  `tests/e2e/reports/html/index.html` (the `a11y-report` artifact when CI fails).
  Also check new markup by keyboard and with a screen reader. Playwright is
  pinned by image digest and npm lockfile in `tests/e2e/`: keep the image tag
  and `@playwright/test` at the same version.
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

## Documentation site

The documentation at <https://wpun.cariss.dev> is built from the Markdown files in
`docs/` with [MkDocs Material](https://squidfunk.github.io/mkdocs-material/), in
Docker like everything else. `mkdocs.yml` holds the navigation and the theme, and
`docs/stylesheets/extra.css` the Solarized colour schemes.

- `make docs` builds it into `build/docs` and is strict: a warning, a broken link
  or a broken anchor fails it.
- `make docs-serve` previews it on <http://localhost:8000> and rebuilds as you edit.
- `make docs-a11y` runs axe (WCAG 2.2 A and AA), a no-third-party-requests check and
  keyboard focus checks on every built page, in the dark and the light scheme, and
  that every Mermaid diagram parses (a syntax error fails it with Mermaid's message)
  (about 30 seconds; the reports are in `tests/e2e/reports/docs/`). A colour change
  in `extra.css` must keep it passing. Do not disable a rule to make it pass.
- `make docs-links` checks the links to other sites in the built site, `README.md`,
  the generated `readme.txt` and `CONTRIBUTING.md` with [lychee](https://lychee.cli.rs/) (needs only
  Docker; it builds the site first). CI runs it on every pull request and push, and
  every Monday, as links break without a change here. Fix a broken link rather than
  excluding it. `docker/docs/lychee.toml` holds the retries, accepts a 429 (a rate limit)
  and lists the few exclusions, each with its reason. Set `GITHUB_TOKEN` in your
  environment to avoid GitHub's rate limit on the many github.com links; the token is
  passed to the container and never printed.
- Link to other pages with relative `.md` paths. A link to code outside `docs/` must be
  an absolute GitHub URL (`https://github.com/l3rady/wp-updates-notifier/blob/main/<path>`,
  or `tree/main/<path>` for a directory), as those files are not part of the site.
- `docs/developers/hooks.md` is generated: change the hook's doc block, then run
  `make hook-docs` (`make check-hooks` fails if it is stale).
- The plugin bundles translations for 11 locales, as `languages/*.l10n.php` files (the only translation files in the
  repository). A changed string or translators comment needs a translation session before CI is green:
  `make translations-edit`, fill in the empty entries in `build/translations/*.po`, then `make translations &&
  make check-translations`. See [Translations](docs/developers/translations.md).
- The site is versioned with [mike](https://github.com/jimporter/mike) and deployed by
  CI (`.github/workflows/docs.yml`): `main` on every push, and each release as its own
  version and as `latest`. Published versions are not rebuilt; to fix one, run the
  workflow by hand on its tag. `latest` stays on the newest release.
  `make docs-deploy` is what the workflow runs.
- The Python packages (`docker/docs/requirements.txt`, with hashes) and Mermaid
  (`docker/docs/package-lock.json`, self-hosted so no page loads anything from a third
  party) are pinned. Regenerate `requirements.txt` with the command at the top of
  `docker/docs/requirements.in`.

## Plugin artwork

The icon and the banner are SVG files in `.wordpress-org/src/`: `icon` and `banner` are the
light versions for WordPress.org, the `-dark` ones are for the dark scheme of the
documentation site, and `icon-small` is the simplified icon for 32px and below (the
documentation site's logo and tab icon). Edit the SVG, then run `make assets`: it renders
every PNG and copy in `.wordpress-org/` and `docs/assets/brand/` in Docker and optimises
them losslessly, with the same bytes every run. Never edit an export by hand. Commit the
sources and the exports together.

The banner's text is set in Liberation Sans (metric-compatible with Arial), which the
assets image installs, so it renders the same on every machine. Deploying the artwork to
WordPress.org is a separate step.

## Releases

Merging to `main` changes no version. [release-please](https://github.com/googleapis/release-please)
keeps one **Release PR** open that collects the next version and the
`CHANGELOG.md` entry from the commit messages: `fix` is a patch, `feat` a minor,
and `!` with a `BREAKING CHANGE:` footer a major (`docs`, `chore`, `build`, `ci`,
`test` and `style` do not release by themselves). Merging the Release PR creates
a draft GitHub release. The `release` job then waits for the maintainer's
approval in the `release` environment. It builds the zip, its `.sha256` and an
SBOM, attests the zip, creates the signed `vX.Y.Z` tag, uploads the assets and
publishes the release. Publishing starts the `wordpress-org` workflow, which
waits for approval in the `wordpress-org` environment (it holds `SVN_USERNAME`
and `SVN_PASSWORD` and accepts only `v*` tags). It downloads the published zip,
checks its `.sha256` and attestation, and deploys exactly those files to SVN
`trunk` and `tags/X.Y.Z`, with `build/wporg-assets/` (below) as `assets/`. Only
a release reaches WordPress.org: nothing merged to `main` is deployed, so a
`Tested up to` bump or a new screenshot ships with the next release (a patch
release, if it can't wait). Don't merge anything to `main` between merging the
Release PR and the release being published: until then there is no tag, so
release-please can't see the release and would open a Release PR that repeats
it. If that happens, run the workflow by hand (Actions, release-please, Run
workflow) after publishing to rebuild the Release PR. To force a version, add a
`Release-As: 2.0.0` footer to a commit.

If the release job fails, use **Re-run failed jobs**, not "Re-run all jobs":
re-running release-please finds nothing new to release, and the release job is
skipped. Every step after the tag push is safe to repeat, and a tag that is
already pushed is checked, not created again. If the `wordpress-org` workflow
fails, re-run it: it deploys the same attested zip, and stops without changes
when SVN already has `tags/X.Y.Z`.

Run the release-please workflow by hand with **rehearse** ticked to try the
release without releasing. The release job also waits for approval in the
`release` environment. It builds and signs a throwaway tag on the runner, then
stops before anything is attested, pushed or published. The
`wordpress-org-rehearsal` job then deploys the zip it built as a dry run, with
no credentials (the SVN checkout is anonymous): the log ends with the
`svn status` of trunk, the new tag and `assets/`, and nothing is committed.
Rehearse before each release and read that list. Before 2.0.0, the first automated deploy, it removes the files 1.x
shipped by hand (`trunk/js/`, `trunk/tests/`, `trunk/bin/`, `.travis.yml` and
the phpcs and phpunit configs). That is intended.

The `release` environment is limited to `main`, needs the maintainer's approval
and holds:

- the secret `RELEASE_SIGNING_KEY`: a dedicated ed25519 SSH key with no
  passphrase (and LF line endings);
- the variables `RELEASE_TAGGER_NAME` and `RELEASE_TAGGER_EMAIL`: the email must
  be a verified email on the GitHub account where the public key is registered
  as a **signing** key, or GitHub won't show the tag as Verified.

The public key goes in `.github/release-signers` as
`<RELEASE_TAGGER_EMAIL> namespaces="git" ssh-ed25519 AAAA…`.

The workflow also sets the copyright year on the Release PR's branch.

`readme.txt` is not in the repository. `bin/build-dist.sh` generates it into the
zip (`make readme` writes a preview to `build/readme.txt`) from the plugin header
(name, requirements, licence, and the version as the stable tag), `CHANGELOG.md`
(the latest 3 releases), the questions of `docs/users/troubleshooting.md` listed in
`.wordpress-org/readme/faq.txt`, and `.wordpress-org/readme/template.txt` (the rest).
Edit those, never a `readme.txt`. `make check-headers` checks the result against
WordPress.org's rules, and `make test-readme` tests the generator.

The Live Preview blueprint is generated the same way: `bin/blueprint.php` builds
it from `.wordpress-org/blueprint/` (`make blueprint` writes a preview to
`build/blueprint.json`), `make check-blueprint` validates it against the
Playground schema and `make test-blueprint` tests the generator. The release
deploy ships it to SVN `assets/blueprints/`.

`make wporg-assets` assembles what goes to SVN `assets/` in `build/wporg-assets/`: the
top-level files of `.wordpress-org/` (icons, banners, screenshots; never `src/`,
`readme/` or `blueprint/`) and `blueprints/blueprint.json`. `make wporg-assets-check`
validates it (names, formats, exact sizes, screenshots against the readme captions, the
SVG icon, the blueprint schema) and runs in the `plugin-check` CI job and before the
deploy. The deploy (`10up/action-wordpress-plugin-deploy`) uses `build/wporg-assets/` as
its `ASSETS_DIR`.
It syncs it with `rsync --delete` and sets `svn:mime-type` for the png, jpg, gif and svg
files itself, so no extra step is needed. `make dist` wipes `build/`, so run
`make wporg-assets` after it. `make dist-check` fails if the zip holds any of these files.

The zip is reproducible. `make dist-repro` builds it twice from fresh clones of
`HEAD` (so only committed changes count), with a different umask, file times and
time zone, and fails if the SHA-256 differs. `make dist` also writes the
`.sha256` file. `make sbom` writes a CycloneDX SBOM of the zip contents to
`build/wp-updates-notifier-<version>.cdx.json`.

Before merging the Release PR, run the OWASP ZAP scan with `make dast` (about
11 minutes, Docker only). It is too slow for CI. It must finish with no Medium
or High alerts. The report is in `security-reports/zap-report.html`.

Also regenerate the WordPress.org screenshots with `make screenshots` (about 30
seconds, Docker only), look at the four files in `.wordpress-org/` and commit
them if the settings screens or the email changed. Check that the captions in
`.wordpress-org/readme/template.txt` still match. They are uploaded with the
other assets, not with the zip.

Run `make blueprint-smoke` as well (about 2 minutes, Docker and the network). It
runs the generated Live Preview blueprint in WordPress Playground with the zip
of `make dist` and checks the demo in a browser. It fails if Playground or the
plugin changed in a way that breaks the demo.
