# WP Updates Notifier Example Channel

A small, complete example of an add-on for WP Updates Notifier: a notification channel that posts the update list as JSON to a chat webhook. It uses only the public extension API, so it keeps working across releases of the plugin that share its API major version.

It is a teaching example, not a product. Read the code, copy it and change it.

## What is in it

- `wpun-example-channel.php`: the plugin file. The header (with `Requires Plugins: wp-updates-notifier` and `Requires WPUN API: ^1.0`, which WP Updates Notifier reads to show a notice on the Plugins screen when its API does not satisfy it), a check, with a version constraint (`REQUIRED_API`, `^1.0`) and `sc_wpun_api_satisfies()`, that the installed plugin offers the API version the add-on was written for (needed for code outside the channel and for the add-on's own admin notice, shown when it does not), and the registration of the channel on the `sc_wpun_register_channels` action.
- `class-example-channel.php`: the channel. Its API constraint (`get_api_constraint()`, which the plugin enforces when registering the channel), its settings as a field schema (a secret webhook address and an optional name), `send()`, and the rules a channel follows: a short timeout, `wp_safe_remote_post()`, a `Delivery_Result` for every outcome (including HTTP 429 with `Retry-After`, read with `Delivery_Result::retry_after_from_response()`), a length limit with an "...and N more" summary, and no secret in any message.
- Accessibility: the settings are schema fields with a visible label and a description, so the plugin gives them labels, error linking and the rest. The message comes from `Notification_Renderer`, whose text has no arrows and whose links name their item. See the Accessibility section of [Writing a notification module](../../docs/developers/writing-a-module.md#accessibility).
- `phpcs.xml.dist`: the WordPress Coding Standards ruleset for the add-on, with its own text domain and prefix. Copy it with the add-on and run `vendor/bin/phpcs` in its directory. In the plugin repository, `make lint` runs it.

The classes and functions a module may use are those not marked `@internal`; `bin/check-public-api.php` in the plugin repository checks that this directory uses nothing else. The hooks are listed in [the hook reference](../../docs/developers/hooks.md).

## Try it

1. Copy the `wpun-example-channel` directory to `wp-content/plugins/` of a site that has WP Updates Notifier 2.0 or later.
2. Activate "WP Updates Notifier Example Channel".
3. In Settings, WP Updates Notifier, Channels, add a channel of the type "Example chat (developer demo)" and enter the address of an incoming webhook. Use "Send test message" to check it.

## Make it your own

1. Rename the directory and the main file (`wpun-example-<your-name>/wpun-example-<your-name>.php`), and the plugin header: `Plugin Name`, `Description`, `Text Domain`.
2. Change the namespace `WPUN_Example_Channel` in both files to your own, and the text domain `wpun-example-channel` in the strings.
3. Change `Example_Channel::TYPE` (`example`) to a unique channel type: lower case letters, digits and underscores. Instances are stored under it, so do not change it once people use it.
4. Change the label, description and fields in `get_fields()`, and the request and the handling of the answer in `send()` and `result()` for your service.
5. Keep the checks in `boot()`: they stop the add-on from calling into a plugin that is missing or too old. Keep `get_api_constraint()` too: the plugin uses it to refuse the channel and tell the site owner.
