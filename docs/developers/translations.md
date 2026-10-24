# Translations

The plugin ships its own translations, so a site gets them without waiting for a language pack from translate.wordpress.org. The files are in [`languages/`](https://github.com/l3rady/wp-updates-notifier/tree/main/languages/).

## Locales

de_DE, fr_FR, es_ES, it_IT, nl_NL, pt_BR, ja, ru_RU, pl_PL, zh_CN and he_IL (Hebrew, which also exercises right-to-left layouts).

The list, with the plural forms of each locale (the values translate.wordpress.org uses), is in one place: the `locales` variable at the top of [`bin/translations.sh`](https://github.com/l3rady/wp-updates-notifier/blob/main/bin/translations.sh). To add a locale, add a line there and run `make translations`.

## The files

| File | What it is |
| --- | --- |
| `wp-updates-notifier.pot` | The template, generated from the code by `make pot`. |
| `wp-updates-notifier-<locale>.po` | The translations of one locale. Edited by hand. |
| `wp-updates-notifier-<locale>.mo` | Built from the `.po`. Used when the `translation_file_format` filter asks for the MO format. |
| `wp-updates-notifier-<locale>.l10n.php` | Built from the `.po`. The file WordPress 6.5 and later loads by default. |

Never edit the `.mo` and `.l10n.php` files: `make translations` writes them, and `make check-translations` fails if they differ from what the `.po` builds. The build is deterministic: the header holds no date (WP-CLI would otherwise write the current time into both files), so running it twice changes nothing.

Every `.po` file carries the marker `X-Machine-Translation: yes` in its header, with a comment at the top of the file. The translations are made by an AI assistant and are not yet reviewed by a native speaker. `make translations` writes the marker back if it is removed, and `make check-translations` fails without it.

## Commands

All run in Docker. Add `LOCALE=de_DE` to work on one locale.

| Command | What it does |
| --- | --- |
| `make pot` | Regenerate the template from the code. |
| `make translations` | Create a missing `.po` from the template, merge every `.po` with the template, then build the `.mo` and `.l10n.php` files. New strings come in empty, strings that left the code are dropped, and a changed string is not guessed from a similar one. Locations (`#:`) are left out, so moving code changes no `.po` file. |
| `make translations-todo` | Print the untranslated and fuzzy entries of each locale in PO format, with the translators comments (`#.`) and contexts, and a count per locale. This is what a translating session reads. |
| `make check-translations` | Fail unless, for every locale, `msgfmt -c --check-format` passes, nothing is untranslated or fuzzy, the strings are exactly those of the template, the plural forms and the marker are right, and the built files are current. It also fails on any other file in `languages/`. CI runs it in the `phpcs` job of `lint.yml`. |

## Changing a string

Any change to a translatable string, or to its translators comment, needs a translation session before CI is green:

1. `make pot && make translations && make translations-todo`
2. Translate the entries it lists in the `.po` files (keep placeholders such as `%s` and `%1$s` exactly, and fill every plural form of a plural entry).
3. `make translations`, then `make check-translations`.
4. Commit the `.pot`, the `.po` files and the built files together.

## How the files are loaded

WordPress looks for a plugin's translations in `wp-content/languages/plugins` (the language packs it installs) and resolves one directory per text domain and locale, with the language packs first. Packs from translate.wordpress.org exist for some locales (German, Spanish, Japanese and Polish, among others) and were built from the 1.x strings. An installed pack would therefore hide the complete bundled file.

So [`WPUN\Translations`](https://github.com/l3rady/wp-updates-notifier/blob/main/includes/class-translations.php) does two things:

1. The `lang_dir_for_domain` filter (WordPress 6.6 and later) points WordPress at the bundled `languages/` directory for every locale the plugin ships.
2. On `load_textdomain`, just before WordPress loads the bundled file, it loads the language pack of the same locale first.

WordPress looks a string up in the files of a text domain in the order they were loaded and takes the first hit. A string in the language pack wins, and the bundled file fills every gap. A locale the plugin does not ship is left to WordPress. Loading stays just in time: nothing is loaded until the first string is translated, which is after `init`. The plugin does not call `load_plugin_textdomain()`, as WordPress loads the files itself.

The tests are in `tests/wp/test-translations.php`. They build a fake bundled file and a fake language pack for a locale that does not exist, in both file formats.
