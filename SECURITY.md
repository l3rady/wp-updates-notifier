# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 2.x | Yes: fixes go into the latest 2.x release |
| 1.x and older | No: update to 2.x |

Security fixes are made to the latest release only. 2.0.0 needs WordPress 6.7
and PHP 7.4 or newer.

## Reporting a vulnerability

Please do not open a public issue for a security problem. Report it privately
on GitHub: open the repository's **Security** tab and choose **Report a
vulnerability**. If you cannot use that, email
[scott@cariss.dev](mailto:scott@cariss.dev). Either way, describe the problem
and, if you can, how to reproduce it. You will get a reply within 7 days, and
a fix or a plan for one within 30 days. A fix is released before the details
are made public. There is no bug bounty and no third-party disclosure
programme: report here directly.

WP Updates Notifier checks the site for core, plugin and theme updates and sends
what it finds through notification channels. Email is the one built-in channel,
and other plugins can add their own. Channel settings, including secrets such as
webhook URLs, are stored in the database or set in `wp-config.php` constants or
environment variables. The settings screen is for administrators (the
`manage_options` capability, unless a site changes it with the
`sc_wpun_capability` filter).

Problems we would like to hear about include:

- ways for users without that capability to change settings, manage channels,
  run the update check or send messages;
- unescaped output on the settings screen, in notices or in notifications;
- leaking secrets, such as webhook URLs or values from `wp-config.php` and the
  environment, into pages, logs or debug output;
- ways to make the plugin send requests or email to addresses an administrator
  did not configure;
- flaws in the public API that make add-on channels unsafe when used as
  documented.

The [threat model](docs/security/threat-model.md) describes what the plugin
trusts, each entry point, and the control and test that cover it. The
[2.0.0 security audit](docs/security/audit-2.0.0.md) checks the plugin against
OWASP ASVS 5.0 Level 1 and the WordPress security guidance.

## How this plugin is checked

Every pull request runs these scripted checks. A finding fails the build, and
the reports go to the repository's code scanning alerts.

- **Workflows:** actionlint and zizmor (`make check-workflows`).
- **Secrets:** gitleaks over the git history, the working tree and the release
  zip (`make secrets`).
- **Dependencies:** composer audit, OSV-Scanner and GitHub's dependency
  review. The plugin has no runtime dependencies. A known vulnerability in a
  development dependency, a GitHub Action or a tool image is fixed within 7
  days if it is High or Critical, and within 30 days otherwise. Other updates
  come through Dependabot after a 14-day cooldown.
- **Static analysis:** WordPress Coding Standards, including its escaping,
  security token and sanitising sniffs (`make lint`), PHPStan (`make stan`), Semgrep with
  the plugin's own rules (`make sast`), and Psalm's taint analysis (`make taint`).
- **Security tests:** a PHPUnit group (`tests/wp/security/`). It runs every
  admin-post handler against every role and every bad security token. It also checks
  that update data with markup is escaped in every output, that secrets never
  reach a page, notice or log, and that outbound requests refuse private
  addresses.
- **Dynamic scan:** OWASP ZAP against a throwaway site (`make dast`),
  authenticated and anonymous. It is run by hand before each release, as it is
  too slow for every pull request.
- **Repository:** OpenSSF Scorecard, see below.

These are scripted tools with pinned versions. An AI-assisted review is run by
hand before a release; it is advisory and never gates a build. The
[threat model](docs/security/threat-model.md) maps each entry point to the
check that covers it.

## Verifying a release

Each release zip comes with a `.sha256` file. Download both into the same
directory and run:

```sh
sha256sum -c wp-updates-notifier-<version>.zip.sha256
```

This only catches a corrupted download: anyone who can swap the zip can swap
the checksum too. To check that the zip was built by this repository's release
workflow, verify its build provenance with the
[GitHub CLI](https://cli.github.com/):

```sh
gh attestation verify wp-updates-notifier-<version>.zip \
  --repo l3rady/wp-updates-notifier \
  --signer-workflow l3rady/wp-updates-notifier/.github/workflows/release-please.yml \
  --source-ref refs/heads/main
```

The release also carries a CycloneDX SBOM (`.cdx.json`). It lists no
components, because the plugin has no runtime dependencies. The SBOM is attested
against the zip too: add `--predicate-type https://cyclonedx.org/bom` to the
command above to check that attestation.

The zip is reproducible: check out the release tag, run `make dist` and compare
the SHA-256 of `build/wp-updates-notifier-<version>.zip` with the one you
downloaded. The two are identical when built with the same image. The base
images are pinned by digest, but zip, rsync and git come from Debian's apt and
are not pinned, so a later rebuild of the image can differ. The release run's
log records the versions it used. CI checks that two builds in one image match
on every pull request with `make dist-repro`.

Release tags (`vX.Y.Z`) are annotated and signed with a dedicated SSH key. The
`v*` tag ruleset stops them from being moved or deleted. GitHub shows them as
Verified, and you can check one in a clone:

```sh
git -c gpg.ssh.allowedSignersFile=.github/release-signers tag -v v<version>
```

`.github/release-signers` comes from the same clone, so also compare its key
with the signing keys listed for the maintainer at
`https://api.github.com/users/l3rady/ssh_signing_keys`.

## OpenSSF Scorecard

[OpenSSF Scorecard](https://scorecard.dev/viewer/?uri=github.com/l3rady/wp-updates-notifier)
scores the repository's supply-chain setup every week and on every push to
`main` (`.github/workflows/scorecard.yml`). Its results are in the repository's
code scanning alerts. These checks score below 10 on purpose:

- **Code-Review** and part of **Branch-Protection**: one maintainer writes and
  merges every change, so `main` can't require an approving review, a code
  owner's review or approval of the last push. Pull requests, signed commits,
  linear history and the required CI checks still apply to every change, and
  `.github/CODEOWNERS` marks workflow and release-script changes. The rules
  don't bind administrators, so the maintainer can fast-forward a reviewed
  branch onto `main` and keep its signed commits unchanged.
- **Contributors**: a one-maintainer project.
- **Fuzzing**: no fuzzing tool fits a WordPress plugin. Untrusted input is
  covered by the Psalm taint analysis (`make taint`) and the PHPUnit tests.
- **CII-Best-Practices**: no OpenSSF Best Practices badge yet.
- **Packaging** and **Signed-Releases**: not scored until the release workflow
  publishes a signed release.
