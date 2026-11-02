# 0027. Webhook signing follows Standard Webhooks

Status: Accepted. Date: 2026-11-02.

## Context

The built-in webhook channel (#99) sends signed JSON to Zapier, Make, n8n, Home Assistant or any HTTP endpoint. The first design was our own: an `X-WPUN-Timestamp` header and an `X-WPUN-Signature: sha256=<hex>` header, in the style of GitHub. Every receiver would then have had to write its own verification from our documentation, and each of them is one more place to get a constant-time compare or a replay check wrong.

[Standard Webhooks](https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md) is an open specification for exactly this, with official verification libraries in many languages. Svix and others use the same scheme.

## Decision

- **Follow the symmetric scheme of the specification exactly.** The headers are `webhook-id`, `webhook-timestamp` and `webhook-signature`. The signed content is `{id}.{timestamp}.{body}`, where the body is the exact bytes sent. The signature is `v1,` followed by the base64 of the HMAC-SHA256. The header is a space-separated list, so a second signature can be added later without a format change. The specification's own test vector is in the unit tests.
- **The secret is `whsec_<base64>` only.** It must decode to 24 to 64 bytes, and the HMAC key is the decoded bytes. A plain string is refused instead of being used as a raw key, because the same secret pasted into a Standard Webhooks library would then give a different signature. The error message tells the site owner how to make a secret. Making the secret outside the plugin and pasting it into both places fits the secret field, which never shows a saved value.
- **The signature is optional.** Without a secret the module sends `webhook-id` and `webhook-timestamp` but no `webhook-signature`. The user guide says an unsigned endpoint must be treated as public.
- **`webhook-id` is random for every message.** It is `msg_` and a random identifier. A content-derived id was considered and rejected: a retry at the next check is a new message that may hold more items, and a reminder has the same content as the first send, so they would collide with it. The id says "this is one delivery". Deduplication of the same update is for the receiver, on `type` + `key` + `new_version`, which the documentation spells out. Overlapping checks can occasionally send the same update twice (ADR 0009), and no id scheme would remove that.
- **Renaming headers only renames them.** A site can choose custom names for the three headers, for a receiver that insists on others. The values and the signed content do not change, so this does not make the module compatible with another provider's signature format, such as GitHub's `X-Hub-Signature-256: sha256=<hex>`. Full control of the final headers is for site code, through the `sc_wpun_webhook_headers` filter. Header names are not part of the signed content, so renaming or adding a header there is safe.
- **No extra headers.** `Webhook-Event`, `Webhook-Version`, `Webhook-Attempt`, `Webhook-Retry-Count`, `Webhook-Delivery-ID`, `Webhook-Key-ID`, `Webhook-Source`, `Webhook-Environment` and `Webhook-Signature-Algorithm` were not added. The event type and the schema version are in the body, which is signed. The plugin never retries within a run, so there is no attempt or retry count. There is one key and no rotation. The scheme is fixed and named by the `v1` prefix.
- **Out of scope.** Key rotation (a second secret) and the asymmetric `v1a` signatures. The list format leaves room for rotation.

## Consequences

- Good: a receiver verifies with an official library in its own language, or with about fifteen lines of its own code. The user guide has examples for PHP and Node.
- Good: replay protection is part of the scheme. Receivers reject a timestamp more than 5 minutes from their own clock.
- Good: the module is a reference for third parties: it shows an HTTP channel with a secret, field groups, conditional fields, `Retry-After` and signing.
- Bad: a site owner must make the secret with `openssl` or similar. The plugin does not generate one, because it could not show it again.
- Bad: a receiver that can only check another provider's format cannot use the module without a proxy or code on the receiving side.
- Bad: no rotation yet. Changing the secret means changing it in both places at the same moment.

## Considered options

- **Our own `X-WPUN-*` headers with a hex HMAC.** Rejected: nothing to reuse on the receiving side, and nothing in it that Standard Webhooks does not do better.
- **Accept a plain string as the secret.** Rejected: it breaks the official libraries, which base64-decode the part after `whsec_`.
- **A content-derived `webhook-id`.** Rejected, see above.
- **The extra metadata headers.** Rejected, see above.

## References

- [Issue #99](https://github.com/l3rady/wp-updates-notifier/issues/99): the webhook channel.
- [Standard Webhooks specification](https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md).
- [ADR 0009](0009-no-lock-against-overlapping-update-checks.md): overlapping checks can send an update twice.
- [Webhook payload and signing](../developers/webhook-payload.md).
