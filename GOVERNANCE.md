# Governance

WP Updates Notifier is a single-maintainer project. This file says who decides
what, how decisions are made, and how the project carries on if the maintainer
can't.

## Roles

| Role | Who | Responsibilities |
| --- | --- | --- |
| Maintainer | Scott Cariss ([@l3rady](https://github.com/l3rady)) | Everything listed under [Maintainer tasks](#maintainer-tasks). Holds admin access to the GitHub repository, commit access to the WordPress.org plugin, and the `cariss.dev` domain the documentation site runs on. |
| Contributors | Anyone who opens an issue or pull request | Follow [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md). Contributions are accepted under the project's licence, GPL-2.0-or-later. |
| Security reporters | Anyone who finds a vulnerability | Report it privately, as [SECURITY.md](SECURITY.md) explains, and not in a public issue. |

### Maintainer tasks

- **Triage:** reply to new issues and support forum threads, label them, and
  place them in a milestone or close them with a reason.
- **Review:** review every pull request against
  [CONTRIBUTING.md](CONTRIBUTING.md). Only the maintainer merges, by
  fast-forwarding `main`, so every commit stays signed.
- **Releases:** merge the Release PR that release-please keeps open, approve the
  `release` environment, and check the release on GitHub and WordPress.org.
  See Releases in [CONTRIBUTING.md](CONTRIBUTING.md).
- **Security:** answer vulnerability reports within the times in
  [SECURITY.md](SECURITY.md), fix them in the latest release and publish an
  advisory.
- **Dependencies:** review Dependabot pull requests and fix known
  vulnerabilities in development dependencies within the times in
  [SECURITY.md](SECURITY.md).
- **Code of Conduct:** handle reports made under the
  [Code of Conduct](CODE_OF_CONDUCT.md).
- **Roadmap:** keep the [roadmap](https://github.com/l3rady/wp-updates-notifier/blob/main/docs/roadmap.md) and
  the milestones current.

## How decisions are made

- **Day-to-day changes** are proposed and discussed in GitHub issues and pull
  requests. The maintainer decides, and says why when declining a change.
- **Decisions that shape the plugin** (requirements, public API, storage,
  security, release process) are recorded as
  [decision records](docs/decisions/README.md) in the same pull request as the
  change. A record is never edited to reverse a decision; a new record
  supersedes it.
- **The public API** changes only as the
  [stability and deprecation policy](docs/developers/stability.md) allows:
  semantic versioning, and deprecation before removal.
- **The plan** for the coming year is the
  [roadmap](https://github.com/l3rady/wp-updates-notifier/blob/main/docs/roadmap.md). Milestones hold the work
  for each release.

There is no voting. If the project gains more maintainers, this file will say
how they decide together, and they will be added to the table above.

## Continuity

The project has a single maintainer, so its bus factor is 1. If the maintainer
dies, is incapacitated or stops maintaining the project, the following
arrangements let someone else create and close issues, accept changes and
release new versions within a week:

<!-- TODO(maintainer): describe arrangements that are actually in place, for example:
  - a successor named in GitHub (Settings > Account > Successor settings), who can then manage this repository;
  - a second committer on the WordPress.org plugin, or the plugins team's process for adopting a closed plugin;
  - who holds the cariss.dev domain and the release signing and GitHub App keys (or how they are recovered from Bitwarden);
  - where any legal rights (will, executor) are recorded.
-->

To be completed by the maintainer.

If nobody can continue, the code stays available under GPL-2.0-or-later, and
anyone may fork it.
