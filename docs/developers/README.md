# Developer guide

WP Updates Notifier 2.0.0 and later can be extended by other plugins: new notification channels, new kinds of item to report, routing rules, and places to read settings and secrets from. Everything here uses the public API, which follows the [stability and deprecation policy](stability.md).

- [Architecture](architecture.md): how an update check flows from WP-Cron to each channel instance, and where your code plugs in.
- [Writing a notification module](writing-a-module.md): a channel type step by step: field schema, validation, secrets, `send()`, `Delivery_Result`, rate and length limits, and testing with mocked HTTP.
- [Item types and routing](item-types-and-routing.md): reporting something other than core, plugin and theme updates, and deciding which instances receive it.
- [Configuration sources](configuration-sources.md): settings and secrets from `wp-config.php` constants, environment variables or a secrets service.
- [Hook reference](hooks.md): every action and filter, generated from the code.
- [Stability and deprecation](stability.md): what is public, how it changes, and how an add-on checks the API version with a version constraint.
- [Decision records](../decisions/README.md): why the plugin is built the way it is, such as the licence, the minimum versions, the module system and what was dropped.
- [Threat model](../security/threat-model.md): what the plugin trusts, its entry points, and the control and test for each. The security rules for add-ons are in [Writing a module](writing-a-module.md#secrets) (secrets, HTTP, and running the plugin's checks on your code).

## Examples

- [`examples/wpun-example-channel/`](https://github.com/l3rady/wp-updates-notifier/tree/main/examples/wpun-example-channel/): a complete, minimal channel add-on to copy. CI runs its tests ([`tests/wp/test-example-channel.php`](https://github.com/l3rady/wp-updates-notifier/blob/main/tests/wp/test-example-channel.php)) and checks it uses only the public API.
- [`modules/email/`](https://github.com/l3rady/wp-updates-notifier/tree/main/modules/email/): the built-in email channel, written like any add-on.
- [`tests/fixtures/plugins/wpun-test-addon/`](https://github.com/l3rady/wp-updates-notifier/tree/main/tests/fixtures/plugins/wpun-test-addon/): the add-on the test suite uses to exercise every extension point.
