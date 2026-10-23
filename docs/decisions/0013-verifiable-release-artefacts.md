# 0013. Make releases verifiable

Status: Accepted. Date: 2026-10-19.

## Context

A checksum published next to the zip proves only that the download was not corrupted. It does not show that the zip came from this repository's CI at a given commit. If a release job or a maintainer's machine were compromised, users and WordPress.org could not tell.

release-please creates tags through the API, and those tags are unsigned.

## Decision

A release produces a zip that can be checked:

- **Reproducible zip.** `make dist-repro` builds it twice and fails if the SHA-256 values differ. CI runs it on every pull request.
- **SBOM.** A CycloneDX software bill of materials, attached to the release.
- **Build provenance.** The zip and the SBOM are attested, and `gh attestation verify` checks them.
- **Signed annotated tags.** The release job creates the `vX.Y.Z` tag itself, signed with a dedicated key in the `release` environment.
- release-please prepares a Release PR and a **draft** release. The release job waits for the maintainer's approval, then builds, attests, tags and publishes. See `CONTRIBUTING.md`.

## Consequences

- Good: anyone can check that a zip came from this repository's CI at a given commit.
- Good: nothing is published without the maintainer's approval.
- Bad: more release machinery, and a signing key to protect.
- Bad: the last acceptance check can only be done on the first real release.

## Considered options

- **Rely on the attestation and leave tags unsigned.** The attestation already ties the zip to the commit, but a signed tag shows who released it.

## References

- [#226](https://github.com/l3rady/wp-updates-notifier/issues/226): reproducible zip, provenance and SBOM.
- [PR #258](https://github.com/l3rady/wp-updates-notifier/pull/258): the release job and signed tags.
- [#57](https://github.com/l3rady/wp-updates-notifier/issues/57): deploy to WordPress.org on release.
