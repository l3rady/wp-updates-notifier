# 0001. Licence the plugin GPL-2.0-or-later

Status: Accepted. Date: 2026-10-13.

## Context

The plugin stated its licence three ways. The plugin header said `GPL3+`, `readme.txt` said `GPLv3 or later` and `composer.json` said `GPL-2.0-or-later`. There was no `LICENSE` file. The licence notice in the code, which is the grant contributors' code was submitted under, says "either version 2 of the License, or (at your option) any later version".

## Decision

The plugin is licensed **GPL-2.0-or-later**. The repository has a `LICENSE` file with the full GPL version 2 text, and the plugin header, `readme.txt` and `composer.json` all say the same. A contract test checks that they agree.

## Consequences

- Good: the licence matches the grant in the code and the WordPress.org norm.
- Good: users can still choose GPLv3, because of "or later".
- Bad: the plugin header and `readme.txt` change from GPL3+ wording, which is a visible change in 2.0.0.

## Considered options

- **GPL-3.0-or-later.** This was what the header and `readme.txt` said. It does not match the notice that contributors' code was submitted under, so it was rejected.

## References

- [#124](https://github.com/l3rady/wp-updates-notifier/issues/124): the licence file and consistent licence statements.
