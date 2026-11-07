# 0030. Replace a channel type explicitly

Status: Accepted. Date: 2026-11-07.

## Context

An add-on sometimes wants to do better than a built-in channel, for example an email channel that sends through a transactional service. The registry allowed one channel per type. A second `register()` of `email` warned and was ignored, so the add-on could not take over, and it could not rely on running before or after the built-in module (#445).

Instances are stored by type. A replacement must serve the instances that already exist without changing them.

## Decision

- **`Channel_Registry::replace( Channel $channel ): bool`.** The replacement becomes the channel of the type. It works whether it runs before or after the original registers. If the original has not registered yet, the registry waits for it and keeps it as the original.
- **`Channel_Registry::get_replaced( string $type ): ?Channel`.** It returns the original, so a replacement can delegate to it and diagnostics can name it.
- **The first replacement wins.** A second `replace()` of a type warns and returns false.
- **A refused replacement keeps the original.** A replacement whose API constraint is not met is recorded as rejected, like any channel. It does not claim the type, so the original stays active and a later valid `replace()` can succeed and clears the rejection. The Channels tab lists an instance as unavailable only when no channel serves its type.
- **`unregister()` removes both** the replacement and the original.
- **Instances are untouched.** The settings an instance holds for keys the replacement does not declare are kept. Site Health shows which class replaces a type.
- **The contract is in the guide.** A replacement accepts every setting key of the original with the same meaning and supports at least its features.
- **API version.** `SC_WPUN_API_VERSION` stays 1.1.0. It is unreleased in this cycle (as in ADR 0025).

## Consequences

- Good: an add-on takes over a built-in type in one call, whatever the load order.
- Good: existing instances keep working, and deactivating the replacement hands them back to the built-in channel.
- Bad: two add-ons that both want a type compete, and the first wins. The site owner finds out in Site Health.
- Bad: a replacement that ignores the contract can break instances it did not create.

## Considered options

- **A `priority` argument on `register()`.** Rejected: it adds an ordering rule that every caller must understand, and a tie has no good answer.
- **A type alias, so the add-on registers its own type and instances are moved to it.** Rejected: instances would have to be rewritten, and the built-in type would stop working if the add-on were deactivated.

## References

- [Issue #445](https://github.com/l3rady/wp-updates-notifier/issues/445): replace a channel type.
- [ADR 0025](0025-deferred-delivery-result-held-until.md): the unreleased API version.
- [ADR 0029](0029-partial-delivery-recorded-from-the-result.md): the previous channel API addition.
