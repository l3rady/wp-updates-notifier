# 0015. Conventional Commits and a linear history

Status: Accepted. Date: 2026-10-13.

## Context

Releases are automated, and the version number and changelog have to come from somewhere reliable. Pull requests also need to land as clean history that is easy to read and bisect.

## Decision

- Every commit is a **Conventional Commit**. `fix` is a patch, `feat` a minor, and `!` with a `BREAKING CHANGE:` footer a major. release-please builds the next version and `CHANGELOG.md` from them.
- `make commits` checks the commits on a branch, and CI runs it on every pull request.
- **History is linear.** Branches rebase onto `main`, and `main` is never merged into a branch.
- Pull requests land as their own commits, not squashed, so the commit messages that decide the version are the ones on `main`.
- Commits are signed. Since 2026-10-19 the maintainer merges a pull request by rebasing it onto `main` and fast-forwarding `main` locally, so the signed commits land unchanged. GitHub's rebase and squash buttons write new, unsigned commits, and branch protection requires signed commits.

See `CONTRIBUTING.md`.

## Consequences

- Good: versions and the changelog follow from the history, with no manual step.
- Good: a linear history is easy to follow.
- Bad: contributors have to write commit messages with care, and rebase instead of merging.

## References

- [PR #119](https://github.com/l3rady/wp-updates-notifier/pull/119): automate releases with release-please.
