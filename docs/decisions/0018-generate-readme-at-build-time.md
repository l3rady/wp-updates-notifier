# 0018. Generate readme.txt at build time and do not commit it

Status: Accepted. Date: 2026-10-25.

## Context

`readme.txt` repeated facts that live elsewhere: the plugin header (name, requirements, licence, version), `CHANGELOG.md`, the user guide on wpun.cariss.dev and the links on the About tab. Kept by hand it drifted. It still listed 1.x features, named a settings page that no longer exists, and its changelog stopped at 1.6. `bin/set-stable-tag.sh` patched one line of it on the Release PR, and the Release PR only worked because nobody else touched the file.

WordPress.org reads `readme.txt` or `readme.md`, and both must use the WordPress readme format, so `README.md` (the GitHub page) cannot serve. The file has to exist in the zip, the SVN deploy and the GitHub release asset, and all three must carry the same one.

## Decision

- **`readme.txt` is not committed.** It is in `.gitignore`. `bin/readme.php` generates it, `bin/build-dist.sh` writes it into `build/wp-updates-notifier/` after copying the tracked files, and so the zip, the SVN deploy and the release asset carry the same file. `make readme` writes a preview to `build/readme.txt`, never to the repository root.
- **The inputs are committed files.** `.wordpress-org/readme/template.txt` holds the hand-written text and the fields that cannot be derived (contributors, tags, tested up to). The plugin header gives the name, requirements, licence and the stable tag (the version). `About_Tab::links()` gives the donate link, so the readme, the About tab and the home page cannot disagree. `CHANGELOG.md` gives the latest 3 releases. The FAQ comes from the `##` headings of `docs/users/troubleshooting.md` that `.wordpress-org/readme/faq.txt` lists, followed by the questions only the readme has.
- **It is reproducible.** The generator reads nothing that varies (no date, locale, environment or network), so `make dist-repro` covers it, and the attestation and SBOM describe what ships.
- **It fails rather than guess.** A listed FAQ heading that is missing, or Markdown the generator cannot render, stops the build.
- **`make check-headers` checks the generated file** against WordPress.org's rules: the title, the required fields and their agreement with the plugin header, the stable tag, at most 5 tags, a short description of at most 150 characters, only known sections, Upgrade Notice entries of at most 300 characters, and no placeholder left.
- **The Release PR no longer sets a stable tag.** The release workflow only sets the copyright year.

## Consequences

- Good: the version, requirements, licence, changelog and FAQ have one source each, and the readme cannot go stale.
- Good: no generated file in the repository, so no staleness check and no merge conflicts in it.
- Good: the FAQ answers are the ones on the documentation site, so they are written once.
- Bad: a reader of the repository cannot open `readme.txt` or see its diff in a pull request. `make readme` shows it, and a change to a source shows in the diff of the source.
- Bad: anything that needs the readme must build it first. The SVN deploy for readme-only changes (#57), lychee (`make docs-links` does) and the WordPress tests (they call the generator) do.
- Bad: the FAQ and changelog Markdown support is the subset the user guide uses. A new construct needs the generator taught first.

## Considered options

- **Commit a generated `readme.txt` with a `make check-readme` staleness check.** This was the first plan. It keeps a file the zip is built from `git ls-files` with, allows a readme-only deploy straight from git, and shows the diff in review. Rejected because the maintainer wants no generated file in the repository, and the build directory carries the generated file to every channel anyway.
- **Keep `readme.txt` by hand.** Rejected: it drifted, which is the problem.
- **Ship `readme.md` instead.** Rejected: WordPress.org prefers `readme.txt` and both need the same format.

## References

- [Issue #282](https://github.com/l3rady/wp-updates-notifier/issues/282): generate readme.txt.
- [Issue #283](https://github.com/l3rady/wp-updates-notifier/issues/283): the donate link and the Author URI.
- [Issue #57](https://github.com/l3rady/wp-updates-notifier/issues/57): the WordPress.org deploy.
- [Issue #59](https://github.com/l3rady/wp-updates-notifier/issues/59): the readme text.
