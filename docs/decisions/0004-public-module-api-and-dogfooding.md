# 0004. A public, versioned module API, used by the built-in modules

Status: Accepted. Date: 2026-10-12.

## Context

Version 1.x sent email and Slack from fixed code. Site owners and agencies wanted other channels, several channels at once, and different rules per channel. The plugin could not offer those without other plugins being able to add channels, and without a promise about what stays stable.

## Decision

Notification channels are **modules** that register through a public hook, `sc_wpun_register_channels`. A site can have several instances of a module, each with its own routing. The hooks, the module classes and the field schema are public API, with their own semantic version (`SC_WPUN_API_VERSION`).

The built-in modules **use only that API**. Each lives in `modules/<type>/`, registers through the public hook and touches no internal classes. `make check-public-api` fails CI if a module reaches inside. If a module needs something that is not public, the API grows.

Add-ons state the API versions they work with as a Composer-style constraint, such as `^1.0`. A header on the Plugins screen, a function and the channel class all take one, and the registry enforces the last. The [stability and deprecation policy](../developers/stability.md) says what is public and how it changes.

## Consequences

- Good: the built-in email module is a working example, and the API is tested by real use.
- Good: add-on authors have a written promise, and a way to check it.
- Bad: the plugin has to keep the public surface stable, deprecate before removing and bump the API version.
- Bad: internal convenience is sometimes given up, because a module cannot use an internal class.

## Considered options

- **Built-in modules that use internal classes, with the public API for add-ons only.** Simpler for the built-ins, but the API would go unused by the plugin itself and could drift from what the built-ins need.

## References

- [#91](https://github.com/l3rady/wp-updates-notifier/issues/91): the epic.
- [#92](https://github.com/l3rady/wp-updates-notifier/issues/92): the module API, registry and dispatcher.
- [#105](https://github.com/l3rady/wp-updates-notifier/issues/105): contract tests and the check that modules use only the public API.
- [#106](https://github.com/l3rady/wp-updates-notifier/issues/106): the developer guide.
- [#189](https://github.com/l3rady/wp-updates-notifier/issues/189), [#190](https://github.com/l3rady/wp-updates-notifier/issues/190) and [#191](https://github.com/l3rady/wp-updates-notifier/issues/191): API version constraints.
