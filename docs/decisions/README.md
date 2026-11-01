# Decision records

These are short records of decisions that shape WP Updates Notifier: what was decided, why, and what it costs. They exist so the reasons outlive the issue threads they came from.

A record is not a plan or a specification. It describes a decision that has been made. If a decision changes, the old record stays, its status says which record replaced it, and a new record explains the change.

## Format

Each record is a file named `NNNN-short-title.md`, numbered in the order the records were written. It has:

- a title, `# NNNN. Title`;
- a line with the **status** (Accepted, or Superseded by a later record) and the **date** of the decision;
- **Context**: the problem and what forced a choice;
- **Decision**: what was chosen;
- **Consequences**: the good and the bad;
- **Considered options**, where there were real alternatives;
- **References**: the issues and pull requests where the decision was made.

The date is when the decision was made, not when the record was written.

## Index

| Number | Title | Status | Date |
| --- | --- | --- | --- |
| [0001](0001-licence-gpl-2-or-later.md) | Licence the plugin GPL-2.0-or-later | Accepted | 2026-10-13 |
| [0002](0002-php-7-4-and-wordpress-6-7-minimums.md) | Require PHP 7.4 and WordPress 6.7 | Accepted | 2026-10-12 |
| [0003](0003-rename-main-file-without-a-migration-stub.md) | Rename the main file without a migration stub | Accepted | 2026-10-17 |
| [0004](0004-public-module-api-and-dogfooding.md) | A public, versioned module API, used by the built-in modules | Accepted | 2026-10-12 |
| [0005](0005-settings-state-and-channels-in-separate-options.md) | Keep settings, run state and channels in separate options | Accepted | 2026-10-12 |
| [0006](0006-drop-slack-in-2-0-0-and-keep-its-settings.md) | Drop Slack in 2.0.0 and keep its settings | Accepted | 2026-10-14 |
| [0007](0007-settings-and-secrets-from-outside-the-database.md) | Allow settings and secrets from outside the database | Accepted | 2026-10-12 |
| [0008](0008-single-sites-only-in-2-0-0.md) | Support single sites only in 2.0.0 | Superseded by 0021 | 2026-10-17 |
| [0009](0009-no-lock-against-overlapping-update-checks.md) | No lock against overlapping update checks | Accepted | 2026-10-18 |
| [0010](0010-leave-wordpress-auto-update-emails-alone.md) | Leave WordPress's own auto-update emails alone | Accepted | 2026-10-19 |
| [0011](0011-accessibility-wcag-2-2-aa-without-javascript.md) | Settings screen to WCAG 2.2 AA, without JavaScript | Accepted | 2026-10-18 |
| [0012](0012-security-tooling-is-free-deterministic-and-scripted.md) | Security tooling is free, deterministic and scripted | Accepted | 2026-10-18 |
| [0013](0013-verifiable-release-artefacts.md) | Make releases verifiable | Accepted | 2026-10-19 |
| [0014](0014-documentation-site-on-a-custom-subdomain.md) | Publish the documentation at wpun.cariss.dev with MkDocs Material and mike | Accepted | 2026-10-20 |
| [0015](0015-conventional-commits-and-linear-history.md) | Conventional Commits and a linear history | Accepted | 2026-10-13 |
| [0016](0016-pending-update-reminders-per-channel.md) | Remind about pending updates, per channel | Accepted | 2026-10-23 |
| [0017](0017-bundle-l10n-php-translations-only.md) | Bundle translations as `.l10n.php` files only | Accepted | 2026-10-24 |
| [0018](0018-generate-readme-at-build-time.md) | Generate readme.txt at build time and do not commit it | Accepted | 2026-10-25 |
| [0019](0019-mutes-in-their-own-option-through-the-global-veto.md) | Store mutes in their own option and apply them through the global veto | Accepted | 2026-10-29 |
| [0020](0020-scheduled-checks-run-wordpress-automatic-updates-first.md) | Scheduled checks run WordPress's automatic updates first | Accepted | 2026-10-30 |
| [0021](0021-network-only-on-multisite.md) | On multisite, run only when network-activated | Accepted | 2026-10-31 |
| [0022](0022-remind-on-every-check.md) | Remind on every check | Accepted | 2026-11-01 |
