Copy this into the issue body and fill in every section. Write plain, short sentences.

```markdown
<What is repeated, unused or rebuilt, and why it matters, in two or three sentences.>

## Where
- `path/to/file.php:123` <what this one does>
- `path/to/other.php:45` <what this one does>

## Proposal
<What the code becomes. Name the new or reused function, method or WordPress API. If it uses a WordPress
function, give the version it was added in and say why its behaviour matches.>

## Public API
<None / New API: what, with `@since` for the next API version / Changes 1.1.0 API (unreleased): what.>

## Acceptance criteria
- [ ] <Behaviour that must stay the same, stated so a test can check it.>
- [ ] <The duplication or dead code is gone: what no longer exists.>
- [ ] Tests cover <the behaviour>. <If they don't yet: characterisation tests come first, in their own commit.>
- [ ] No hook names, option names or `sc_wpun_*` function signatures change.

## Risk
<What could break, which tests cover it today, and anything security or accessibility relevant.>

## Checks
`make test stan lint check-public-api`<, plus `make a11y` for admin markup, `make e2e` for flows, or
`make check-hooks` when hooks move.>
```
