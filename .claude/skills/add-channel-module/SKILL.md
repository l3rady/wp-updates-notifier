---
name: add-channel-module
description: Add a built-in channel module to WP Updates Notifier under modules/, written like an add-on with the public API only, with its field schema, tests and docs. Use when adding a new channel type to the plugin itself, or reworking one of modules/email, modules/webhook or modules/slack. For an add-on outside the plugin, follow docs/developers/writing-a-module.md instead.
---

# Add a channel module

The built-in modules dogfood the public module API (`docs/decisions/0004-public-module-api-and-dogfooding.md`):
a module in `modules/` is written exactly as an add-on would be. If it needs something that is not public, the
API grows, in the same pull request; the module never reaches inside. A new built-in channel is a product
decision, so it also gets a decision record in `docs/decisions/` (see 0028 for Slack).

## Steps

1. **Files.** Copy the shape of `modules/slack/`: `modules/<name>/<name>.php` (the entry file),
   `class-<name>-channel.php`, and helper classes beside it. The namespace is `WPUN\Modules\<Name>`. Every file
   starts with the `ABSPATH` guard. The entry file requires its classes and registers the channel on
   `sc_wpun_register_channels`. Load the entry file from `wp-updates-notifier.php`, next to the other modules.
2. **The channel class** extends `WPUN\Channel`: `get_type()` (unique, never changes), `get_label()`,
   `get_description()`, `get_fields()`, and `send()`, which always returns a `Delivery_Result` and never retries
   or sleeps. For an HTTP channel, `wp_safe_remote_post()` and `Delivery_Result::from_response()` turn the
   response into the result, `Retry-After` included. `docs/developers/writing-a-module.md` sections 2 to 4
   describe each, and its "Checklist" section is the list to meet.
3. **The field schema.** Use the schema field types rather than custom markup. Every credential is a field with
   `'secret' => true`; an address the plugin posts to uses `https_only`. Groups and conditional fields come from
   `get_field_groups()` and the schema, without JavaScript (`docs/decisions/0026-field-groups-and-conditional-fields-without-javascript.md`).
4. **Only public API.** Use the classes and functions listed in `docs/developers/stability.md`. Not `sc_wpun()`,
   not anything tagged `@internal`, and not the plugin's options directly: data per instance goes through
   `sc_wpun_instance_data()`.
5. **Hooks and strings.** A hook the module fires follows the `add-public-hook` skill. Its strings use the
   `wp-updates-notifier` text domain and need the `change-string` skill.
6. **Tests.** A `tests/wp/test-<name>-channel.php` class that extends `WPUN_Test_Case` (Slack's tests have their
   own base, `tests/wp/class-wpun-slack-test-case.php`). In `set_up()`, register the channel when the registry
   lacks it, as `tests/wp/test-webhook-channel.php` does: other tests replace the registry, so a test that passes
   alone fails in the full suite without it. Answer HTTP with `fake_http()`; no request leaves the machine.
   Cover success, an error status, a `WP_Error`, a 429 with `Retry-After`, a long message, and that no failure
   message or log contains a secret.
7. **Browser checks.** `make a11y` only draws the forms of the instances seeded in `tests/e2e/seed.sh` and
   listed in `tests/e2e/specs/helpers.js`. Add an instance of the new channel to both, or the a11y pass says
   nothing about its form.
8. **Docs.** The channel and its settings in `docs/users/channels.md`, including the list of kinds at the top;
   anything developers can hook into in `docs/developers/`; the decision record.

## Done when

```sh
make check-public-api
make lint stan rector
make check-hooks check-translations
make test-wp ARGS="--filter Test_<Name>_Channel"
make test-wp
make a11y
```

`make check-public-api` fails when a module uses an `@internal` class, function or method, `sc_wpun()` or
reflection. Run the targeted test before the full suite: the full suite is what catches a missing
registration in `set_up()`.
