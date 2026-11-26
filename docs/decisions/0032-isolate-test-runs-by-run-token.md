# 0032. Isolate test runs by run token

Status: Accepted. Date: 2026-11-26.

## Context

Several agents work in their own git worktrees at once and run the tests together. The PHPUnit suites share one MariaDB container and keep apart by table prefix, but some runs still clashed:

- Mutation runs in two checkouts used the same prefixes: Infection sets `TEST_TOKEN` to its worker number, so every checkout's worker 1 used `wptests_1_`, and the initial run used `wptests_`.
- Two runs of one suite in one checkout used one prefix and dropped each other's tables.
- `make test-dist` dropped and created a fixed database, `wordpress_dist`.
- Only `make test` started the database first, so two first runs at once raced on the container name.
- `make down` and `make clean` stopped the test database under every running agent, as it was in the project of the local site.

## Decision

- **A run token.** The Makefile passes a base, `WPUN_RUN_TOKEN=<checkout token><suite letter>` (`s` single site, `m` multisite, `c` coverage, `u` mutation, `d` test-dist, `p` plugin-check; the known-bug and slowest targets use `s`). `docker/wp-tests-entrypoint.sh` appends `_` and a random part of six hex digits and exports the result, so every process of the run sees one value: Infection's workers, WordPress's install script. With no base (a hand-run) the base is `x`. `TEST_TOKEN` stays Infection's worker number and the Makefile no longer sets it.
- **One rule for the prefix.** `docker/wp-tests-prefix.php` builds `wptests_<run>_<worker>_`, or `wptests_<run>_` without a worker. Each part keeps only `[0-9a-z_]`, so nothing in a token can reach a table name or a query. A worker of `0` is a worker: only an unset or empty `TEST_TOKEN` means none. The same file makes the `LIKE` pattern that selects a run's tables, with `_` escaped, as it is a wildcard.
- **Drop on exit.** The entrypoint runs the command as a child, keeps its exit code, and drops every table of the run (`docker/wp-tests-drop.php`) when it ends. A child started with `&` ignores SIGINT, so on INT or TERM the entrypoint sends SIGTERM to every other process of the container and exits with the signal's code: Ctrl-C still stops a run. A failed drop is reported and never changes the exit code. This covers every PHPUnit target the same way, mutation workers included. A killed run leaves its tables until the database container restarts, as its data is in memory.
- **Its own database for test-dist and plugin-check.** `bin/fresh-wordpress.sh` creates `wordpress_dist_<run token>` and drops it, and its temporary directory, when the script exits.
- **The test database in its own project.** `docker/compose.testdb.yml` is the project `wpun-testdb` with the `db` service and a network of the same name. The root `docker-compose.yml` joins that network as an external one, and `db` is still the host name. `make down` and `make clean` act on the root project only.
- **Started by every target that needs it.** `make testdb` starts it, and the PHPUnit, coverage, mutation, test-dist and plugin-check targets depend on it. It tries a second time when the first fails: two first runs at once race on the container and network name, and the second try finds them there.

The run token deviates from the proposal in issue #594, where the Makefile passed the random part. It is made in the entrypoint, where the tables are dropped, so the prefix and the pattern for dropping always agree.

## Consequences

- Good: any two runs, in any checkouts, of any suites, use different tables and databases.
- Good: no test tables pile up, and no agent has to start the database first.
- Good: `make down` and `make clean` no longer stop another agent's tests.
- Bad: the database container of an older checkout (`<project>-db-1`) stays until someone removes it. `make clean` removes it as an orphan.
- Bad: the entrypoint is now a parent process of the run, so a run killed with SIGKILL leaves its tables.
- Bad: a second compose file and an external network to know about.

## Considered options

- **One database per run.** Rejected: WordPress's test bootstrap and the shared container make a table prefix the simpler isolation, and creating databases would need a privilege step in every run.
- **A random part from the Makefile.** Rejected: the Makefile and the entrypoint would each need to know it, and the tables dropped could differ from those made.
- **A container per run.** Rejected: slow to start and heavy with many agents.

## References

- [Issue #594](https://github.com/l3rady/wp-updates-notifier/issues/594): isolate PHPUnit runs across worktrees.
