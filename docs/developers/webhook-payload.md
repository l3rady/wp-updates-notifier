# Webhook payload

The built-in `webhook` channel ([`modules/webhook/`](https://github.com/l3rady/wp-updates-notifier/tree/main/modules/webhook/)) sends a JSON document to the address a site owner chose. This page describes that document, how it is signed, and the hooks that change it. Site owners will find setup and the receiver recipes in the [user guide](../users/channels.md#the-webhook-channel). The reasons for the signing scheme are in [ADR 0027](../decisions/0027-webhook-signing-follows-standard-webhooks.md).

## The request

The channel makes a `POST` request with `Content-Type: application/json`. The body is encoded once, with `wp_json_encode()` and the flags `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Those exact bytes are signed and sent. The `User-Agent` is `WP-Updates-Notifier/<version>; <home URL>`. Any status in the 2xx range is a success. Redirects are not followed, so a 3xx is a failure.

## Signing headers

The channel follows the symmetric scheme of [Standard Webhooks](https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md), so receivers can use its libraries in any language.

| Header | Value |
| --- | --- |
| `webhook-id` | `msg_` and a random identifier. New for every message. |
| `webhook-timestamp` | The Unix time in seconds when the message was signed. |
| `webhook-signature` | `v1,` and the base64 of `HMAC-SHA256( key, "{id}.{timestamp}.{body}" )`. A space-separated list, so more than one signature is possible. |

- The key is the base64-decoded part of the signing secret after `whsec_`. The secret must decode to 24 to 64 bytes.
- Without a signing secret, the channel sends `webhook-id` and `webhook-timestamp` but no `webhook-signature`.
- A site owner can rename the three headers. Only the names change. See the user guide for the rules.
- Receivers should reject a timestamp more than 5 minutes from their clock. The user guide has verification examples in PHP and Node.js.

The spec's test vector, which the unit tests check: secret `whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw`, id `msg_p5jXN8AQM9LWM0D4loKWxJek`, timestamp `1614265330` and body `{"test": 2432232314}` give `v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=`.

## The document

```json
{
  "type": "updates.available",
  "timestamp": "2026-11-02T09:00:00+00:00",
  "schema_version": 1,
  "data": {
    "site": {
      "name": "Example Site",
      "url": "https://example.com",
      "updates_url": "https://example.com/wp-admin/update-core.php",
      "network": false
    },
    "notification": {
      "source": "cron",
      "is_test": false,
      "is_reminder": false
    },
    "items": [
      {
        "type": "plugin",
        "key": "akismet/akismet.php",
        "name": "Akismet Anti-spam",
        "current_version": "5.3",
        "new_version": "5.4",
        "links": {
          "details": "https://wordpress.org/plugins/akismet/",
          "changelog": "https://wordpress.org/plugins/akismet/#developers"
        },
        "notes": ["Compatible with WordPress 6.9."],
        "flags": {
          "active": true,
          "auto_update": false,
          "tested": "6.9",
          "requires_php": "7.4",
          "requires_wp": "6.2"
        }
      }
    ]
  }
}
```

### Envelope

| Field | Meaning |
| --- | --- |
| `type` | What the message is, see [Types](#types). |
| `timestamp` | When the message was made, in ISO 8601 and UTC. It is the same moment as the `webhook-timestamp` header, which gives it in Unix seconds. |
| `schema_version` | The version of this document. Currently `1`. |
| `data` | The content. |

### Types

| Type | When |
| --- | --- |
| `updates.available` | A normal message. |
| `updates.reminder` | The notification is a reminder. It lists everything still waiting for the channel, not only what is new, so a receiver must not treat it as new. |
| `updates.test` | A test send. |
| `updates.resolved` | Reported updates have been installed or removed. See [Resolved messages](#resolved-messages). |

### `data.site`

| Field | Meaning |
| --- | --- |
| `name` | The site name. HTML entities are decoded, because this is JSON and not HTML. |
| `url` | The site address. |
| `updates_url` | The address of the Updates screen. |
| `network` | `true` in network mode on a multisite network, where the site fields are the network's (see [Architecture](architecture.md)). |

### `data.notification`

| Field | Meaning |
| --- | --- |
| `source` | What started the check: `cron`, `manual`, `cli` or `test`. |
| `is_test` | `true` for `updates.test`. |
| `is_reminder` | `true` for `updates.reminder`. |

### `data.items[]`

Each item has every field of `WPUN\Notification_Item`, except `notified_at` (see the resolved items below).

| Field | Meaning |
| --- | --- |
| `type` | The item type: `core`, `plugin`, `theme`, or one an add-on registered. |
| `key` | What identifies the item: for example `akismet/akismet.php`, or the theme's directory name. |
| `name` | The display name. |
| `current_version`, `new_version` | The installed version and the version waiting. |
| `links` | An object of label to address. It holds `details` and `changelog` where known, and `download` only when the site has turned download links on. |
| `notes` | An array of short texts. |
| `flags` | An object of facts. Plugin and theme items have a boolean `active`. Core, plugin and theme items have a boolean `auto_update`. Plugins also have `tested`, `requires_php` and `requires_wp`. An add-on's items carry their own flags. |

### Compatibility

Within `schema_version` 1, changes are backwards compatible: fields are added, never removed or retyped. A receiver must ignore fields it does not know. A change that breaks this gets a new `schema_version`.

Overlapping checks can, rarely, deliver the same update twice ([ADR 0009](../decisions/0009-no-lock-against-overlapping-update-checks.md)). `webhook-id` is per message and does not catch it. A receiver that must not act twice should deduplicate on `type` + `key` + `new_version`.

## Resolved messages

When an update the channel reported is installed, or its plugin or theme removed, the channel sends one `updates.resolved` message for the check, with all the items. It builds on the [`sc_wpun_item_resolved`](hooks.md#sc_wpun_item_resolved) action ([ADR 0024](../decisions/0024-resolved-items-detected-by-the-update-check.md)).

```json
{
  "type": "updates.resolved",
  "timestamp": "2026-11-02T10:00:00+00:00",
  "schema_version": 1,
  "data": {
    "site": {
      "name": "Example Site",
      "url": "https://example.com",
      "updates_url": "https://example.com/wp-admin/update-core.php",
      "network": false
    },
    "notification": {
      "source": "cron",
      "is_test": false,
      "is_reminder": false
    },
    "items": [
      {
        "type": "plugin",
        "key": "akismet/akismet.php",
        "name": "Akismet Anti-spam",
        "current_version": "5.3",
        "new_version": "5.4",
        "links": { "details": "https://wordpress.org/plugins/akismet/" },
        "notes": [],
        "flags": { "active": true, "auto_update": false },
        "resolution": "updated",
        "notified_at": "2026-11-01T09:00:00+00:00"
      }
    ]
  }
}
```

- The items have the same fields, plus `resolution` and `notified_at`.
- `resolution` is `updated`, `removed` or `core`: the `$how` of `sc_wpun_item_resolved`.
- `notified_at` is when the channel was told about the update, in ISO 8601 and UTC. It is `null` for entries stored before 3.0.0.
- `links` never has `download`, because the stored state does not keep it ([ADR 0024](../decisions/0024-resolved-items-detected-by-the-update-check.md)).
- `notification.source` is the source of the check that noticed the change. The `Notification` passed to the filters below is that check's notification with only the resolved items in it.
- The module collects items per instance while the check runs and sends once on `sc_wpun_after_update_check`. It sends only for enabled instances that have **Also send a message when a reported update is installed** on, are not waiting after a `Retry-After`, and have no problem (`sc_wpun_instance_problems()`: the module is registered and no supplied value is invalid). It is never sent in a dry run or a test send.
- The latency is up to one check interval, as for the hook.
- It is **best effort**: the check has already forgotten the items, so a failed message is not retried. The module cannot record it as the instance's last result, because that is not public API, so a failure does not show on the Channels tab.
- It does not go through the dispatcher, so [`sc_wpun_pre_send`](hooks.md#sc_wpun_pre_send) does not apply to it. It uses the same request path as the other messages: the same envelope, signing, headers, timeout, redirect and error rules, and the payload filters.
- Receivers deduplicate it on `type` + `key` + `new_version`, like the other types.

## Changing the message from code

| Hook | What it changes |
| --- | --- |
| [`sc_wpun_channel_payload`](hooks.md#sc_wpun_channel_payload) and [`sc_wpun_channel_payload_webhook`](hooks.md#sc_wpun_channel_payload_type) | The payload array before it is encoded, for all message types, `updates.resolved` included. If the result is not an array, the unfiltered payload is used. The filtered payload is the one that is encoded and signed. |
| [`sc_wpun_webhook_headers`](hooks.md#sc_wpun_webhook_headers) | The final headers. |
| [`sc_wpun_webhook_resolved_sent`](hooks.md#sc_wpun_webhook_resolved_sent) | Nothing: an action to log or react to an `updates.resolved` attempt. |

`sc_wpun_webhook_headers` receives `( array $headers, Notification $notification, Channel_Instance $instance, string $body )`. It runs last, on the final header set including the signature headers, just before sending. Header names are not part of the signed content, so renaming or adding a header there is safe. The body cannot be changed there: change the payload with the filters above, which run before signing.

```php
add_filter(
	'sc_wpun_webhook_headers',
	function ( $headers, $notification, $instance, $body ) {
		$headers['X-Site'] = 'example';
		return $headers;
	},
	10,
	4
);
```

`sc_wpun_webhook_resolved_sent` receives `( Delivery_Result $result, Notification_Item[] $items, Channel_Instance $instance )` after every attempt, for success and for failure. It is the only place a failed `updates.resolved` message is visible. It is separate from `sc_wpun_notification_sent` and `sc_wpun_notification_failed` on purpose: listeners to those assume a pending-updates notification from the dispatcher.

```php
add_action(
	'sc_wpun_webhook_resolved_sent',
	function ( $result, $items, $instance ) {
		if ( ! $result->is_success() ) {
			error_log( 'WPUN: updates.resolved was not delivered.' );
		}
	},
	10,
	3
);
```

`$instance` holds the resolved settings, the address and the secret included. Do not log or dump it. The reference for each hook is in the [hook reference](hooks.md).

## JSON Schema

The document is described by a JSON Schema (draft 2020-12): [`webhook-payload.schema.json`](webhook-payload.schema.json). It is published with this page, at `https://wpun.cariss.dev/latest/developers/webhook-payload.schema.json` (replace `latest` with a version for an older release), and the plugin's tests validate every message type against it. Use it to generate types or to validate in a receiver.
