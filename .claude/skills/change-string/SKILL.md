---
name: change-string
description: Run the translation session WP Updates Notifier needs after any translatable string or translators comment is added, changed or removed, so every bundled locale stays complete and make check-translations passes. Use after editing a __(), _e(), esc_html__(), _n(), _x() or similar call, or a translators comment, in the plugin or its modules.
---

# Change a translatable string

The plugin bundles complete translations for every locale in `languages/`, as `.l10n.php` files only
(`docs/decisions/0017-bundle-l10n-php-translations-only.md`). CI fails when one locale misses a string, so any
change to a string, or to its translators comment, needs a translation session in the same pull request. The
full reference is `docs/contributing/translations.md`, section "Changing a string".

## Steps

1. Make the change in the code. Keep a translators comment (`/* translators: … */`) above any string with a
   placeholder.
2. `make translations-edit`. It writes `build/translations/<locale>.po` for every locale from the `.l10n.php`
   files and the code, overwriting any earlier PO file. New and changed strings come in empty; removed strings
   are dropped. It prints how many entries each locale still needs.
3. Translate every empty `msgstr` in `build/translations/*.po`, by hand, in each language:
   - keep placeholders such as `%s` and `%1$s` exactly;
   - fill every `msgstr[n]` of a plural entry (the plural forms per locale are in `bin/translations.sh`);
   - read the `#.` translators comment and the `msgctxt` before translating.

   `make translations-todo` lists what is still empty or fuzzy. To fill the same entry in eleven files, edit
   each `msgstr` under its exact `msgid`; don't touch the `msgid` lines.
4. `make translations`. It builds `languages/*.l10n.php` from the PO files. Never edit a `.l10n.php` file by
   hand: the check compares it byte for byte with a fresh build. Its `header field 'Last-Translator' missing`
   warnings are expected.
5. If a test asserts the old text, update the test too.
6. Commit the changed `languages/*.l10n.php` files with the code change. `build/` is not committed.

For a removed string, step 3 has nothing to translate, but steps 2 and 4 are still needed to drop it.

## Done when

```sh
make check-translations
```

It fails on an untranslated, fuzzy or obsolete entry, a wrong plural form, a placeholder mismatch, or a
`.l10n.php` file that differs from what `make translations` builds. `LOCALE=de_DE` limits any of these commands to
one locale while working.
