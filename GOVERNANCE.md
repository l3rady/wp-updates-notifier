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
| Continuity contact | [@juweez](https://github.com/juweez) | Has no part in day-to-day work. Starts the [continuity](#continuity) process if the maintainer can't continue. |
| Backup maintainer | [@rieves](https://github.com/rieves) | Has no part in day-to-day work. Takes over the project, or finds it a new home, if the maintainer can't continue. |

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

The project has a single active maintainer, so its bus factor is 1. If the
maintainer dies, is incapacitated or stops maintaining the project, these
arrangements let someone else create and close issues, accept changes and
release new versions within a week:

1. **The continuity contact confirms it.** [@juweez](https://github.com/juweez)
   confirms that the maintainer can't continue, tells the backup maintainer,
   and opens an issue in this repository to say so.
2. **Access to the accounts and keys.** The continuity contact has emergency
   access to the maintainer's password manager. It holds the GitHub and
   WordPress.org accounts, the `cariss.dev` domain (which hosts the
   documentation site), the release signing key and the release GitHub App's
   key. Through it, the backup maintainer can be given what they need at once,
   without waiting for a provider's own process.
3. **GitHub.** [@rieves](https://github.com/rieves) is the successor named in
   the maintainer's GitHub account, so GitHub's own process can also hand the
   repository to them.
4. **WordPress.org.** The backup maintainer is a committer on the
   [WordPress.org plugin](https://wordpress.org/plugins/wp-updates-notifier/),
   so they can publish releases from their own account.
5. **The backup maintainer decides what happens next.** They either maintain
   the project, or find it a new home: transfer the repository to a trusted
   maintainer, and ask the WordPress.org plugins team to move the plugin's
   ownership, so that sites relying on it keep getting security fixes.

The maintainer's will covers the legal rights the project needs, such as the
domain and the copyright in the maintainer's contributions.

If nobody can continue, the code stays available under GPL-2.0-or-later, and
anyone may fork it.
