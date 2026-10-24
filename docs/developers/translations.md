# Translations

The plugin ships its own translations, so a site gets them without waiting for a language pack from translate.wordpress.org. The files are in [`languages/`](https://github.com/l3rady/wp-updates-notifier/tree/main/languages/).

## Locales

de_DE, fr_FR, es_ES, it_IT, nl_NL, pt_BR, ja, ru_RU, pl_PL, zh_CN and he_IL (Hebrew, which also exercises right-to-left layouts).

The list, with the plural forms of each locale (the values translate.wordpress.org uses), is in one place: the `locales` variable at the top of [`bin/translations.sh`](https://github.com/l3rady/wp-updates-notifier/blob/main/bin/translations.sh). To add a locale, add a line there, run `make translations-edit`, translate the file, then run `make translations`.

## The files

The repository holds one file per locale and nothing else: `languages/wp-updates-notifier-<locale>.l10n.php`, the format WordPress 6.5 and later loads by default (the plugin needs 6.7). It is the source of truth. There is no committed template (`.pot`), `.po` or `.mo` file: translate.wordpress.org scans the code for strings itself, and the gettext files are made in the git-ignored `build/translations/` when a translating session needs them. [`bin/l10n-to-po.php`](https://github.com/l3rady/wp-updates-notifier/blob/main/bin/l10n-to-po.php) turns a `.l10n.php` file back into a PO file.

Never edit a `.l10n.php` file by hand: `make check-translations` fails unless it is byte for byte what the translations and the code build. The build is deterministic: the header holds no date (WP-CLI would otherwise write the current time into the file), so running it twice changes nothing.

**Status: machine-translated, not yet reviewed.** All the translations were made by an AI assistant in Claude Code sessions and have not been reviewed by a native speaker. The `.l10n.php` format cannot carry a marker for that, so this page and [ADR 0017](../decisions/0017-bundle-l10n-php-translations-only.md) record it. They stay so until the translations are imported to translate.wordpress.org and reviewed there, which needs a project translation editor ([#58](https://github.com/l3rady/wp-updates-notifier/issues/58)).

## Commands

All run in Docker. Add `LOCALE=de_DE` to work on one locale.

| Command | What it does |
| --- | --- |
| `make translations-edit` | Generate the template from the code, export each `.l10n.php` file to a PO file in `build/translations/<locale>.po` (overwriting it) and merge it with the template. New strings come in empty, strings that left the code are dropped, a changed string is not guessed from a similar one, and the translators comments (`#.`) and contexts are there to read. It prints the number of untranslated and fuzzy entries per locale. A translating session fills the empty `msgstr` entries. |
| `make translations-todo` | Print the untranslated and fuzzy entries of each locale in PO format, with a count per locale. |
| `make translations` | Build `languages/*.l10n.php` from the PO files, merged with the template again, with the fixed header and the plural forms from the table in `bin/translations.sh`. |
| `make check-translations` | Fail unless, for every locale, `msgfmt -c --check-format` passes, nothing is untranslated, fuzzy or obsolete (a translation of a string no longer in the code), the plural forms and language are right, and the file is byte for byte what a fresh build gives. It also fails on make-pot warnings and on any other file in `languages/`. CI runs it in the `phpcs` job of `lint.yml`. |

Which PO file `translations-todo` and `translations` read: `build/translations/<locale>.po` when it is newer than the locale's `.l10n.php` (a session is editing it), otherwise a fresh export of the `.l10n.php`. So a leftover edited file never overwrites newer committed translations. `translations-edit` always overwrites it, and `translations` leaves it in place, so a session can build again after a fix.

## Changing a string

Any change to a translatable string, or to its translators comment, needs a translation session before CI is green:

1. `make translations-edit`
2. Translate the empty entries in `build/translations/*.po` (keep placeholders such as `%s` and `%1$s` exactly, and fill every plural form of a plural entry).
3. `make translations`, then `make check-translations`.
4. Commit the `.l10n.php` files.

## How the files are loaded

WordPress looks for a plugin's translations in `wp-content/languages/plugins` (the language packs it installs) and resolves one directory per text domain and locale, with the language packs first. Packs from translate.wordpress.org exist for some locales (German, Spanish, Japanese and Polish, among others) and were built from the 1.x strings. An installed pack would therefore hide the complete bundled file.

So [`WPUN\Translations`](https://github.com/l3rady/wp-updates-notifier/blob/main/includes/class-translations.php) does two things:

1. The `lang_dir_for_domain` filter (WordPress 6.6 and later) points WordPress at the bundled `languages/` directory for every locale the plugin ships.
2. On `load_textdomain`, just before WordPress loads the bundled file, it loads the language pack of the same locale first.

WordPress looks a string up in the files of a text domain in the order they were loaded and takes the first hit. A string in the language pack wins, and the bundled file fills every gap. A locale the plugin does not ship is left to WordPress. The bundled directory holds only `.l10n.php` files, so a site that forces the MO format with the `translation_file_format` filter gets no bundled strings; a language pack counts in either format. Loading stays just in time: nothing is loaded until the first string is translated, which is after `init`. The plugin does not call `load_plugin_textdomain()`, as WordPress loads the files itself.

The tests are in `tests/wp/test-translations.php`. They build a fake bundled file and a fake language pack (as `.l10n.php` and as `.mo`) for a locale that does not exist.
