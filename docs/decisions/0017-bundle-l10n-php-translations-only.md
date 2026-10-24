# 0017. Bundle translations as `.l10n.php` files only

Status: Accepted. Date: 2026-10-24.

## Context

WordPress 6.5 added performant translations: a `.l10n.php` file is loaded in preference to the `.mo` file next to it. The plugin needs WordPress 6.7, so every supported site prefers `.l10n.php`.

translate.wordpress.org builds a language pack for a locale once about 90% of the release's strings are translated, and WordPress installs packs per locale into `wp-content/languages/plugins`. Core resolves one languages directory per text domain and locale, with the packs first. The old 1.x packs (de_DE, de_DE_formal, es_ES, ja, pl_PL, da_DK and en_GB) would therefore hide a complete bundled file for those locales.

The first version of the bundled translations committed a `.pot`, a `.po`, a `.mo` and a `.l10n.php` file for each of 11 locales: about 1.1 MB in the zip, and four files to keep in step.

## Decision

- **Bundle 11 locales**, made in Claude Code sessions: de_DE, fr_FR, es_ES, it_IT, nl_NL, pt_BR, ja, ru_RU, pl_PL, zh_CN and he_IL. The other 19 locales are tracked in #299.
- **Commit and ship only `languages/wp-updates-notifier-<locale>.l10n.php`.** There is no committed `.pot`, `.po` or `.mo`. The `.l10n.php` file is the source of truth, and the gettext files are generated into the git-ignored `build/translations/` when a translating session needs them (`make translations-edit`).
- **The loader** points WordPress at the bundled directory for the locales the plugin ships. It loads an installed language pack first, so the pack's strings win, and the bundled file fills the gaps. A pack counts in either format, as packs are external.
- **The translations are machine-made and unreviewed** until they are imported to translate.wordpress.org, which needs a project translation editor (#58). The `.l10n.php` format cannot carry a marker, so the documentation records this.

## Consequences

- Good: the zip carries about 320 KB of translations instead of about 1.1 MB, and there is one file per locale to keep in step.
- Good: translators lose nothing, as translate.wordpress.org scans the code for strings itself. This supersedes the committed `.pot` of #61.
- Bad: a site that forces the MO format with the `translation_file_format` filter gets no bundled strings.
- Bad: any change to a string or translators comment fails CI until a translation session runs `make translations-edit`, fills the empty entries, and runs `make translations`.

## Considered options

- **Keep the `.po` and `.mo` files too.** Rejected: they triple the size and add files that can drift from the `.l10n.php`, for sites that force a format WordPress no longer prefers.
- **Ship only the `.po` and let sites build.** Rejected: WordPress cannot read a `.po` file.
- **Rely on language packs alone.** Rejected: packs exist for a few locales, are partial, and wait for the translations to be imported.

## References

- [Issue #147](https://github.com/l3rady/wp-updates-notifier/issues/147): bundled translations.
- [Issue #61](https://github.com/l3rady/wp-updates-notifier/issues/61): the translation template.
- [Issue #58](https://github.com/l3rady/wp-updates-notifier/issues/58): a project translation editor on translate.wordpress.org.
- [Issue #299](https://github.com/l3rady/wp-updates-notifier/issues/299): the remaining locales.
