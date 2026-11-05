# Documentation site

The documentation at <https://wpun.cariss.dev> is built from the Markdown files in `docs/` with [MkDocs Material](https://squidfunk.github.io/mkdocs-material/), in Docker like everything else. `mkdocs.yml` holds the navigation and the theme, and `docs/stylesheets/extra.css` the Solarized colour schemes. A new page needs an entry in the `nav` of `mkdocs.yml`: the strict build fails on a page that is left out.

## Building and checking

- `make docs` builds it into `build/docs` and is strict: a warning, a broken link or a broken anchor fails it.
- `make docs-serve` previews it on <http://localhost:8000> and rebuilds as you edit.
- `make docs-a11y` runs axe (WCAG 2.2 A and AA), a no-third-party-requests check and keyboard focus checks on every built page, in the dark and the light scheme, and that every Mermaid diagram parses (a syntax error fails it with Mermaid's message). It takes about 30 seconds, and the reports are in `tests/e2e/reports/docs/`. A colour change in `extra.css` must keep it passing. Do not disable a rule to make it pass.
- `make docs-links` checks the links to other sites in the built site, `README.md`, the generated `readme.txt` and `CONTRIBUTING.md` with [lychee](https://lychee.cli.rs/). It needs only Docker and builds the site first. CI runs it on pull requests that change the documentation or a linked file, on every push to `main`, and every Monday, as links break without a change here. Fix a broken link rather than excluding it. `docker/docs/lychee.toml` holds the retries, accepts a 429 (a rate limit) and lists the few exclusions, each with its reason. Set `GITHUB_TOKEN` in your environment to avoid GitHub's rate limit on the many github.com links; the token is passed to the container and never printed.

## Writing pages

- Link to other pages with relative `.md` paths. A link to code outside `docs/` must be an absolute GitHub URL (`https://github.com/l3rady/wp-updates-notifier/blob/main/<path>`, or `tree/main/<path>` for a directory), as those files are not part of the site.
- `docs/developers/hooks.md` is generated: change the hook's doc block, then run `make hook-docs` (`make check-hooks` fails if it is stale).
- Translations have their own page: a changed string needs a translation session before CI is green. See [Translations](translations.md).

## Versions and deployment

The site is versioned with [mike](https://github.com/jimporter/mike) and deployed by CI (`.github/workflows/docs.yml`): `main` on every push, and each release as its own version and as `latest`. Published versions are not rebuilt; to fix one, run the workflow by hand on its tag. `latest` stays on the newest release. `make docs-deploy` is what the workflow runs.

## Pinned packages

The Python packages (`docker/docs/requirements.txt`, with hashes) and Mermaid (`docker/docs/package-lock.json`, self-hosted so no page loads anything from a third party) are pinned. Regenerate `requirements.txt` with the command at the top of `docker/docs/requirements.in`.
