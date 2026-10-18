# Contributing

Thanks for helping. Everything runs in Docker, so the only things you need are
`docker` and `docker compose`.

- Set up, tests and coding standards: see **Development** in the
  [README](README.md). `make lint`, `make stan`, `make rector` and `make test`
  must pass; CI runs them on every pull request.
- Check changes by hand on the local site (`make up`, see **Local site** in the
  README): debugging is on there, with Query Monitor, so a change must not add
  PHP notices or `doing_it_wrong` messages. It also runs with a persistent
  object cache. For changes that save or read options, transients or channels,
  check them with the cache on and with `make site-setup OBJECT_CACHE=off`.
- Commits use [Conventional Commits](https://www.conventionalcommits.org/)
  (`fix(cron): …`, `feat!: …` for breaking changes). They decide the next
  version number, so write them with care. `make commits` checks the commits on
  your branch and CI runs it on every pull request: allowed types (`feat`, `fix`,
  `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`),
  a lower-case summary of 72 characters or fewer, and a `!` together with a
  `BREAKING CHANGE:` footer for breaking changes. It cannot check that the
  summary is in the imperative mood or that the body explains why; reviewers do.
- Branch from `main`, name the branch `<type>/<issue>-<short-name>` (for example
  `fix/42-null-check`), and reference the issue in the pull request with
  `Closes #<number>`.
- Keep history linear: rebase onto `main`, do not merge `main` into a branch.
- A bug fix needs a test that fails without the fix. Tests for a bug that is
  still open carry `@group known-bug` (see `tests/wp/test-known-bugs.php`); the
  fix removes the tag.

## Releases

Merging to `main` changes no version. [release-please](https://github.com/googleapis/release-please)
keeps one **Release PR** open that collects the next version and the
`CHANGELOG.md` entry from the commit messages: `fix` is a patch, `feat` a minor,
and `!` with a `BREAKING CHANGE:` footer a major (`docs`, `chore`, `build`, `ci`,
`test` and `style` do not release by themselves). Merging the Release PR creates
the `vX.Y.Z` tag and the GitHub release. To force a version, add a
`Release-As: 2.0.0` footer to a commit.

The workflow also sets the copyright year and the `readme.txt` stable tag on the
Release PR's branch.
