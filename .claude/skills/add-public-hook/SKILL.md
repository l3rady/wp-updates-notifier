---
name: add-public-hook
description: Add or change a public action or filter in WP Updates Notifier, with its doc block, its section in the hook reference, the generated docs/developers/hooks.md and a test. Use when adding a do_action or apply_filters call, renaming a hook, changing a hook's arguments or deprecating one.
---

# Add a public hook

A hook is public API from the release it ships in (`docs/developers/stability.md`). Released hooks change only
through deprecation: `apply_filters_deprecated()` or `do_action_deprecated()`, and removal in a major version.

## Steps

1. **Fire it** with the `sc_wpun_` prefix. Put a doc block within 3 lines above the call, in the shape of the
   ones in `includes/class-dispatcher.php`:

   ```php
   /**
    * Fires when … (one or two plain sentences: when it runs, what a listener may do).
    *
    * @since 3.0.0
    *
    * @param Notification $notification What was to be delivered.
    */
   do_action( 'sc_wpun_example', $notification );
   ```

   `@since` is the version of the next release, which is not the `Version:` in the plugin header. Copy it from
   the newest `@since` in `includes/` (`grep -rho "@since [0-9.]*" includes | sort -V | tail -1`). A hook the
   plugin only listens to and never fires gets `@hook-listened` as well as `@since`.
2. **Place it in the reference.** Add the hook name, or a prefix ending in `_`, to the right section of
   `wpun_hook_sections()` in `bin/hooks-scan.php`. A hook that matches no section lands silently in "Lifecycle
   and legacy", and no check fails.
3. **Generate the reference** with `make hook-docs`. It writes `docs/developers/hooks.md`; never edit that file
   by hand. Until then `make check-hooks` fails with "hooks.md is missing or out of date", whatever the section.
4. **Test what it does.** Add a test to `tests/wp/test-hooks.php` (class `Test_Hooks`) or to the test file of the
   feature: hook a callback, run the code, and assert the arguments and the effect of the return value. Reach
   the hook through the public method that runs it, not by reflection on a private one.
5. **Describe it** where a developer looks for it: the page in `docs/developers/` that covers the feature
   (`writing-a-module.md`, `item-types-and-routing.md`, `muting-from-code.md`, `configuration-sources.md`,
   `settings-groups.md` or `webhook-payload.md`). Before a release, new API also goes into the list for the
   unreleased API version in `docs/developers/stability.md`.

## Done when

```sh
make lint
make check-hooks
make test-wp ARGS="--filter Test_Hooks"
make test-wp
```

`make check-hooks` fails on a hook without `@since` or the prefix, and when `docs/developers/hooks.md` is not
what `make hook-docs` writes. Read the generated diff of `hooks.md` to see that the hook is in the right
section.
