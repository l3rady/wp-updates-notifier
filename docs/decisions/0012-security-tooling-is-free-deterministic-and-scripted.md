# 0012. Security tooling is free, deterministic and scripted

Status: Accepted. Date: 2026-10-18.

## Context

Every build, from a pull request to the zip on WordPress.org, should be checked for security problems. Checks that give different answers locally and in CI, or on different days, cannot be trusted as a gate.

## Decision

Security checks are **free, deterministic tools run from scripts**:

- Everything runs through `make` in Docker, with a target and a CI job for each tool.
- Tool versions are pinned (image digest or action SHA) and Dependabot bumps them. Rules live in the repo and are never fetched at run time.
- Findings fail the build. Each suppression carries a one-line reason. A baseline may only shrink.
- A real secret found anywhere is rotated, never baselined.
- **No AI in the CI loop.** One AI-assisted review is run by hand and is advisory, not a gate. Each finding it confirms becomes a deterministic test or rule.

## Consequences

- Good: the same result locally and in CI, and no cost per run.
- Good: a fixed issue stays fixed without anyone repeating the review.
- Bad: the pinned tools need regular bumps.
- Bad: the tools find known patterns only. The hand-run review and the threat model cover what rules miss.

## References

- [#229](https://github.com/l3rady/wp-updates-notifier/issues/229): the security epic.
- [#230](https://github.com/l3rady/wp-updates-notifier/issues/230): the advisory AI-assisted review.
