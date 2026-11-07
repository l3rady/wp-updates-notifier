# Releases

Merging to `main` changes no version. [release-please](https://github.com/googleapis/release-please) keeps one **Release PR** open that collects the next version and the `CHANGELOG.md` entry from the commit messages: `fix` is a patch, `feat` a minor, and `!` with a `BREAKING CHANGE:` footer a major (`docs`, `chore`, `build`, `ci`, `test` and `style` do not release by themselves). To force a version, add a `Release-As: X.Y.Z` footer to a commit. The workflow also sets the copyright year on the Release PR's branch.

The commit rules are in [CONTRIBUTING.md](https://github.com/l3rady/wp-updates-notifier/blob/main/CONTRIBUTING.md).

## Before merging the Release PR

Rehearse the release, then run the rest on your machine. CI runs none of those `make` targets, for the reason given.

1. **Rehearse the release** (below). It builds and signs a throwaway tag and dry-runs the WordPress.org deploy, so problems show before they cost a release. Read the `svn status` list at the end of the log.
2. **`make dast`** (about 11 minutes, Docker only). OWASP ZAP over a throwaway copy of the site. CI does not run it because the active scan alone takes about 10 minutes. It must finish with no Medium or High alerts. The report is in `security-reports/zap-report.html`. See [Security checks](security-checks.md#dynamic-security-scan).
3. **`make mutation`** (about 35 minutes, Docker only). It shows whether the tests would notice a wrong change, and CI does not run it because it takes over 45 minutes on a GitHub runner. It must pass the floors in `infection.json5`. Read the surviving mutants in `build/infection/infection.log` and add tests for the ones that matter. See [Mutation testing](testing.md#mutation-testing).
4. **`make e2e-upgrade`** (about 1 minute, Docker and the network). It installs the real 1.6.0 from WordPress.org, so CI does not run it. It updates 1.6.0 to the zip of `make dist` (a copy with the Version header set to 2.0.0; the repository's header is not touched) with WP-CLI, from the Plugins screen and as a background auto-update. Each time the plugin must be left deactivated at 2.0.0 with no PHP fatal error, and activating it again must keep the settings (the email channel, the Slack channel delivering, the check scheduled, mail sent to the migrated address). Run it when `main` changes the plugin's main file, the migration or the activation. See [Upgrade from 1.6.0](testing.md#upgrade-from-160).
5. **`make screenshots`** (about 30 seconds, Docker only). Regenerate the WordPress.org screenshots, look at the six files `.wordpress-org/screenshot-1.png` to `screenshot-6.png`, and commit them if the settings screens or the email changed. Check that the captions in `.wordpress-org/readme/template.txt` still match. They are uploaded with the other assets, not with the zip, and CI does not run it. See [Screenshots](testing.md#screenshots).
6. **`make blueprint-smoke`** (about 2 minutes, Docker and the network). It runs the generated Live Preview blueprint in WordPress Playground with the zip of `make dist` and checks the demo in a browser. It fails if Playground or the plugin changed in a way that breaks the demo. CI does not run it because it needs the network. See [Packaging](packaging.md#the-live-preview-blueprint).

## What the release does

Merging the Release PR creates a draft GitHub release. The `release` job then waits for the maintainer's approval in the `release` environment. It builds the zip, its `.sha256` and an SBOM, attests the zip, creates the signed `vX.Y.Z` tag, uploads the assets and publishes the release. See [Packaging](packaging.md) for how the zip, the readme, the blueprint and the WordPress.org assets are made and checked.

Publishing starts the `wordpress-org` workflow, which waits for approval in the `wordpress-org` environment (it holds `SVN_USERNAME` and `SVN_PASSWORD` and accepts only `v*` tags). It downloads the published zip, checks its `.sha256` and attestation, and deploys exactly those files to SVN `trunk` and `tags/X.Y.Z`, with `build/wporg-assets/` as `assets/`.

Only a release reaches WordPress.org: nothing merged to `main` is deployed, so a `Tested up to` bump or a new screenshot ships with the next release (a patch release, if it can't wait).

Don't merge anything to `main` between merging the Release PR and the release being published: until then there is no tag, so release-please can't see the release and would open a Release PR that repeats it. If that happens, run the workflow by hand (Actions, release-please, Run workflow) after publishing to rebuild the Release PR.

## Rehearsing a release

Run the release-please workflow by hand with **rehearse** ticked to try the release without releasing. The release job also waits for approval in the `release` environment. It builds and signs a throwaway tag on the runner, then stops before anything is attested, pushed or published.

The `wordpress-org-rehearsal` job then deploys the zip it built as a dry run, with no credentials (the SVN checkout is anonymous): the log ends with the `svn status` of trunk, the new tag and `assets/`, and nothing is committed. Rehearse before each release and read that list.

## When a release fails

If the release job fails, use **Re-run failed jobs**, not "Re-run all jobs": re-running release-please finds nothing new to release, and the release job is skipped. Every step after the tag push is safe to repeat, and a tag that is already pushed is checked, not created again.

If the `wordpress-org` workflow fails, re-run it: it deploys the same attested zip, and stops without changes when SVN already has `tags/X.Y.Z`.

## Environments and secrets

The `release` environment is limited to `main`, needs the maintainer's approval and holds:

- the secret `RELEASE_SIGNING_KEY`: a dedicated ed25519 SSH key with no passphrase (and LF line endings);
- the variables `RELEASE_TAGGER_NAME` and `RELEASE_TAGGER_EMAIL`: the email must be a verified email on the GitHub account where the public key is registered as a **signing** key, or GitHub won't show the tag as Verified.

The public key goes in `.github/release-signers` as `<RELEASE_TAGGER_EMAIL> namespaces="git" ssh-ed25519 AAAA…`.

The release app's private key, the secret `RELEASE_APP_PRIVATE_KEY`, is stored in two environments, because two jobs use it. The `release` environment above holds it for the `release` job. The `release-please` environment holds it for the `release-please` job: it is limited to `main` and has no required reviewers, so that job runs unattended on every push to `main`. The `release-please` job can't use `release`, which waits for approval. The variable `RELEASE_APP_CLIENT_ID` is a repository variable.
