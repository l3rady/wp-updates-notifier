# 0014. Publish the documentation at wpun.cariss.dev with MkDocs Material and mike

Status: Accepted. Date: 2026-10-20.

## Context

The public API, the configuration sources and the settings need documentation that can be browsed per version, not one long readme. Released plugin versions link to the documentation, so the address has to outlive any change of hosting or repository name.

## Decision

The documentation is a static site built from the Markdown in `docs/`, at **https://wpun.cariss.dev**.

- **A custom subdomain** served by GitHub Pages, not `l3rady.github.io`. Links shipped in released plugins keep working if the hosting or the repository moves.
- **MkDocs Material, with `mike` for versions.** `main`, each release, and a `latest` alias that becomes the default once the first release is out. Docs stay as one set of Markdown files, with no copy per version.
- **GitHub Pages deploys from the `gh-pages` branch.** `mike` builds up every version there.
- Pull requests build the site in strict mode, so broken links and pages missing from the navigation fail.
- No tracking and no third-party requests, like the plugin itself.

## Consequences

- Good: stable links, documentation per release, and checks on every pull request.
- Bad: the domain and its DNS have to be kept.
- Bad: Python tooling, run in Docker, alongside the PHP tooling.

## Considered options

- **Docusaurus.** Versioning is built in, but it copies the whole docs folder for each version, and it needs React and Node.
- **VitePress or Starlight.** No built-in versioning.

## References

- [#271](https://github.com/l3rady/wp-updates-notifier/issues/271): the website epic.
- [#156](https://github.com/l3rady/wp-updates-notifier/issues/156): the versioned documentation site.
