---
name: bump-settings-version
description: Change the shape of the settings WP Updates Notifier stores, with the settings version bump, the upgrade step, a fixture of the old shape and the tests, so sites that update keep their data. Use when adding, renaming, removing or retyping a key in the sc_wpun_settings option, moving data between options, or clearing a leftover option on upgrade.
---

# Bump the settings version

Sites update from any earlier release, and `Settings::maybe_upgrade()` turns what they stored into the current
shape. It runs when the stored `sc_wpun_settings_ver` differs from `Settings::OPT_VERSION`. Every step in it
must be safe to run on data of any older version, and on data that is already current.

## Steps

1. **Bump the version.** `OPT_VERSION` in `includes/class-settings.php` goes up by one whole number (for
   example `'14.0'` to `'15.0'`). Each change of shape gets its own version, even within one release, so its
   upgrade step can name it.
2. **Add the upgrade step** in `maybe_upgrade()` in the same file, before the defaults are merged. Start it with
   a comment that names the version and the change, like the others:
   `// Settings version 15 stores … as … instead of ….` Make it idempotent: check the old shape before
   converting (`array_key_exists()`, a type check, or `false === $this->context->get_option( … )`).
   - The merge with `default_settings()` adds a new key and the intersect drops a removed one, so the upgrade
     step itself needs nothing for those. A global setting is still declared in several places, all in the same
     change (the comment above `GLOBAL_SETTINGS` says so): `Settings::GLOBAL_SETTINGS`, `default_settings()`,
     `schemas()`, the `SettingsData` type at the top of the class, the `@type` list in the
     `sc_wpun_default_settings` doc block, `global_settings()` in `tests/wp/class-wpun-test-case.php` and
     `test_the_default_settings` in `tests/wp/test-settings-upgrade.php`. Its label and help text need the
     `change-string` skill.
   - Read and write options through the `Network_Context` in `$this->context`, never `get_option()` directly.
   - A value that may be `false` is stored wrapped: WordPress stores `false` as `''`, and
     `update_option( $name, false )` creates nothing (see `includes/class-instance-data.php`).
3. **Test the step** in `tests/wp/test-settings-upgrade.php`: store the previous version and the old shape
   inline, as the tests for versions 10 and 12 there do, run the upgrade, assert the new value, and assert that
   a second upgrade changes nothing. Don't add a fixture to `tests/fixtures/settings.php`: it holds the shapes
   of the 1.x releases, and every test that reads it expects 1.x keys.
4. **Check the old releases still upgrade**: the fixture tests run every 1.x shape through your new step too.
5. **Move the version the tests pin**: `SETTINGS_VERSION` in `tests/wp/class-wpun-test-case.php`, and the
   literal version that `tests/wp/test-slack-upgrade.php` asserts. Search for the others with the old version,
   `grep -rn "'14.0'" tests/wp`, and change only the ones that mean "the current version": a test that stores an
   old version on purpose keeps it. Leave `tests/fixtures/` alone.
6. If the change is visible to users or add-ons, say so in `docs/users/` or `docs/developers/`. The option
   layout itself is not public API.

## Done when

```sh
make lint stan
make test-wp ARGS="--filter Test_Settings_Upgrade"
make test-wp
make test-wp-ms
```

Run the two suites one after the other, never at the same time. On a network the options are site options, so
the multisite run is the one that catches a step that skipped `Network_Context`.
