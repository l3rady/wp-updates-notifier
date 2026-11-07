---
name: refactor-audit
description: Audit the whole WP Updates Notifier codebase for duplication, dead code, needless complexity and code that rebuilds what WordPress already provides, then raise refactor issues an implementer can work from. Use when asked for a refactor audit, a code smell hunt, a DRY review of the codebase or "what should we clean up". Not for reviewing a single diff (use code-review or simplify for that).
---

# Refactor audit

Finds what is worth restructuring across the whole plugin, verifies each finding against the code and its
history, and turns the survivors into `refactor` issues. It never changes code. Fixing the issues is separate
work.

There are two layers. The tools in `make quality` measure: copy-paste, unused code, complexity and Rector's
suggestions. This skill judges: the same idea written twice in different shapes, hand-written code that
WordPress already provides, and abstractions that add nothing. The tool reports are leads, not findings.

## 1. Gather

1. Run `make quality` (not `make quality-check`: the reports are unbaselined on purpose, so they still show the
   findings the CI gate's baselines hide) and read the reports in `quality-reports/`. If a tool fails, say so and carry on
   without it. Don't fix the tooling as part of the audit. Know their blind spots:
   - `dead-code.txt` doesn't read `tests/`, so a method only tests call is listed. That is a finding only
     when the tests exercise a path production never takes. An accessor tests need is not.
   - `jscpd` ignores comments, but still matches property declarations and constructor checks of similar
     value objects. Read the code before calling a clone duplication.
   - `complexity.txt` lists every method over the bar. Long, linear, well-tested code is not a finding.
2. Read these before judging anything:
   - `docs/developers/stability.md`: what is public API and how it may change.
   - `docs/developers/architecture.md`: how the parts fit together.
   - The titles in `docs/decisions/`, and the full text of any decision record that touches a slice when
     you audit that slice. These records explain much of the code that looks odd.
3. List open and recently closed `refactor` issues so nothing is raised twice:
   `gh issue list --label refactor --state all --limit 100`.

## 2. Audit one slice at a time

The plugin is too big for one reader. Audit these slices, each with its own `general-purpose` agent and all
in parallel. Give each agent the slice's files, the relevant tool findings for those files, and the rules in
section 4. Tell it to read the code and report candidates, not to edit anything.

| Slice | Files |
|---|---|
| Configuration and settings | `includes/class-config*.php`, `includes/interface-config-source.php`, `includes/class-constant-source.php`, `includes/class-env-source.php`, `includes/class-settings.php`, `includes/class-state.php`, `includes/class-frequencies.php`, `includes/class-translations.php`, `includes/class-plugin.php`, `includes/class-autoloader.php`, `wp-updates-notifier.php`, `uninstall.php` |
| Channels and delivery | `includes/abstract-class-channel.php`, `includes/class-channel-*.php`, `includes/class-rejected-channels.php`, `includes/class-dispatcher.php`, `includes/class-delivery-result.php`, `includes/class-routing.php`, `includes/class-field-types.php`, `includes/class-boolean-field.php`, `includes/class-enum-field.php` |
| Update check and notifications | `includes/class-update-checker.php`, `includes/class-notification*.php`, `includes/class-notifier.php`, `includes/class-scheduler.php`, `includes/class-item-types.php`, `includes/class-version-constraint.php`, `includes/class-auto-update-policy.php`, `includes/class-status-report.php`, `includes/class-site-health.php`, `includes/class-slack-notice.php` |
| Mutes and multisite | `includes/class-mute.php`, `includes/class-mute-store.php`, `includes/class-network-*.php`, `includes/admin/class-mute-actions.php`, `includes/admin/class-muted-tab.php`, `includes/admin/class-plugins-screen-mutes.php` |
| Admin screens | the rest of `includes/admin/`: the settings page, tabs, channel form, field renderer, notices and check-now |
| CLI and built-in modules | `includes/cli/`, `modules/email/`, `modules/webhook/` |

If files were added or moved since this table was written, put each new file in the closest slice and update
the table in the same change.

Each agent reports candidates in this shape:

```text
kind: duplication | wordpress-has-it | dead-code | complexity | needless-abstraction | api-candidate
title: one line
locations: every call site as path:line
evidence: the lines that show it, briefly
proposal: what the code would become
risk: what could break, and which tests cover it today
```

Then run one cross-cutting pass yourself. Compare the slices' candidates and look for one concept handled in
two slices, for example the same option read and write pattern in the stores, the same request validation in
two admin handlers, or the same formatting in the CLI and in the notifications. Duplication across slices is
the kind the per-slice agents cannot see.

## 3. Verify every candidate yourself

A candidate becomes a finding only after you have:

1. **Opened every location** and confirmed the code is what the agent says. Drop anything you cannot confirm.
2. **Checked the history.** Run `git log -S '<distinctive line>' --oneline` and read the commit and the issue
   it links. Code that differs from an obvious pattern is often deliberate, with the reason in a commit, an
   issue or a decision record. If it was deliberate and the reason still holds, drop the finding.
3. **For "WordPress already has it"**, proved the replacement:
   - it exists in WordPress 6.7, the minimum version. Check the `@since` in
     `vendor/php-stubs/wordpress-stubs/wordpress-stubs.php` (`make deps` installs it);
   - it behaves the same for this code's inputs, including empty, invalid and multisite cases. Read its
     source in the stubs or in WordPress core, not from memory;
   - it runs on PHP 7.4.
4. **Counted the call sites.** Apply the rule of three in section 4.
5. **Checked the tests.** Note which tests cover the code. If none do, the issue must ask for
   characterisation tests before the refactor.

## 4. Rules

These come from the project's decisions. A finding that breaks one is dropped or reshaped.

- **Built-in modules use only the public API** (decision 0004, `make check-public-api`). Code in `modules/`
  may repeat code from `includes/` because it is not allowed to call internal code. Don't raise that as
  duplication. If a module and core both need the behaviour, raise an `api-candidate`: a new public function,
  method or hook. New API follows `docs/developers/stability.md`.
- **The public API is in two states.** API version 1.0.0 shipped with plugin 2.0.0, and anything in it can only
  change through deprecation (`_deprecated_function()`, `apply_filters_deprecated()`) and a major release.
  API added for 1.1.0 is unreleased until plugin 3.0.0 and can still change freely. Check `@since` to tell
  which is which.
- **Backwards compatibility.** Keep `sc_wpun_*` hook names and arguments, option names (`sc_wpun_settings`,
  `sc_wpun_settings_ver` and the others), the `sc_wpun_update_check` cron hook, the `SC_WPUN_*` configuration
  names and the deprecated `SC_WP_Updates_Notifier` facade methods exactly as they are.
- **Lean over clever.** The maintainer prefers less code and less plumbing.
  - Rule of three: two similar blocks are not enough for a new abstraction unless they are long and certain
    to change together.
  - Never propose a layer, interface, base class or registry just to remove duplication.
  - A good refactor deletes more lines than it adds.
- **Accessibility and no JavaScript.** Admin screens work without JavaScript and meet WCAG 2.2 AA (decisions
  0011 and 0026). A refactor of admin markup must keep the same markup semantics.
- **Security behaviour is fixed.** Never propose loosening sanitising, escaping, capability checks or security
  tokens, even to use a shorter core helper, unless the helper does exactly the same thing. List security
  behaviour that add-ons rely on (see `stability.md`) as a risk in the issue.
- **No rewrites.** Each finding is one refactor an implementer can finish in a pull request of reasonable size.
  Split anything bigger.

## 5. Report

Write the report to `quality-reports/refactor-audit.md`, which is not committed. Group it by theme, most
valuable first. Value means lines removed, bugs made impossible, and code that has to change together today.
For each theme give:

- the findings it covers, with every `path:line`;
- the proposal;
- the public API impact: none, new API, or a change to 1.1.0 API;
- the risk and the test coverage;
- a rough size: S, M or L.

List the dropped candidates at the end, each with a one-line reason, so the next audit doesn't raise them again.

Show the user the themes as a short table. Unless they already said to file issues, ask which themes to file.

## 6. Raise issues

Raise one issue per theme, not one per smell. Use:

- title: Conventional Commit style, for example `refactor(mutes): share the option read and write between the stores`;
- label: `refactor`;
- milestone: whichever the user named. Never add the issue to a project board unless the user names one;
- body: the template in [issue-template.md](issue-template.md).

Public issues mention nothing outside this repository. They describe the code, not this audit's process.

When the issues exist, add their numbers to the report and tell the user the order you suggest working them
in. Put the smallest independent ones first, and an issue that adds tests before any refactor that depends
on them.
