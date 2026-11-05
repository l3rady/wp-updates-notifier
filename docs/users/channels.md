# Channels

A channel is a place where notifications are sent. WP Updates Notifier comes with two kinds, **Email** and **Webhook**. You can have as many channels as you like, for example one that sends everything to you and another that sends only WordPress updates to a client.

Open them on the **Channels** tab of **Settings → Updates Notifier**. The table shows, for each channel:

- **Label**: the name you gave it, with the actions underneath. A channel set up in code also says **Defined in code**.
- **Module**: the kind of channel, such as Email or Webhook.
- **Enabled**: **On** or **Off**.
- **Routing**: what the channel receives. **All updates** means no limit.
- **Last delivery**: **Never**, **Sent** with the date and time, or **Failed** with the date and time and the reason.

A test message does not count as a delivery, so it never changes **Last delivery**.

## Add a channel

1. Click **Add channel**.
2. Choose a module from the list: **Email** or **Webhook**.
3. Give it a **Label**. The channel's id is made from the label when you save it and cannot be changed afterwards.
4. Fill in the settings (see [the email options](#the-email-options) or [the webhook options](#the-webhook-channel)) and choose its [routing](#choose-what-each-channel-receives).
5. Click **Add channel**.

On the form, **Enabled** is **Send notifications through this channel**. Untick it to save the channel without using it yet.

### Updates that are already waiting

A new channel has been told about nothing, so its first check sends it everything that is waiting for an update, even if other channels were told about those updates long ago.

If you only want updates that appear from now on, tick **Don't send the updates that are already waiting**. This box appears when you add a channel or edit one that is switched off, and it applies when you save the channel switched on.

## Edit a channel

Click **Edit** under the channel's label, change what you need and click **Save channel**.

## Send a test message

Click **Send test** under a channel. A notice at the top says whether it was sent, and if it failed, why. The channel must be switched on.

A test goes only to that channel and is not cut down by its routing.

## Switch a channel off or on

Click **Disable** under the channel. Nothing is sent through a switched-off channel, and nothing is recorded as sent, so when you click **Enable** again it receives everything that is waiting. To avoid that, edit the channel while it is off and tick **Don't send the updates that are already waiting** before you switch it on.

If a constant or environment variable sets whether the channel is enabled, the channel shows "Set by" and the source, and there is no **Disable** or **Enable** action. See [Settings in code](settings-in-code.md).

## Duplicate a channel

Click **Duplicate** to copy a channel's settings and routing, for example to send the same options to another address. The copy is called "label (copy)" and is switched off. Edit it, change what you need and tick **Enabled** when it is ready.

## Delete a channel

Click **Delete** and confirm. The channel's settings and delivery history are removed, and this cannot be undone. If you add a channel again later it starts with no history.

## Channels defined in code

A channel defined in code is read-only. It shows **Defined in code** and only offers **Send test**. See [Settings in code](settings-in-code.md).

## Choose what each channel receives

Each channel has a **Routing** section. Use it to send some kinds of update to some channels only.

- **Item types**: tick **WordPress**, **Plugins** or **Themes** to receive only those. Tick nothing to receive every type, including any that an add-on adds later.
- **Plugins and themes**: **All**, or **Only active ones**.
- **WordPress updates**: **All**, or **Only major releases**.
- **Reminders**: **Never** (the default), **Daily**, **Weekly** or **Every check**. See [Reminders](#reminders).

Routing works on top of the [General settings](settings.md). Those decide what the plugin looks at, and routing then narrows what each channel gets. If **Notify about plugin updates?** is **No**, no channel can receive plugin updates. If it is **Yes, but only active plugins**, choosing **All** here still gives you only the active ones. The form adds a note under an option when a General setting limits it.

### Reminders

By default a channel is told about each update once. Set **Reminders** to **Daily**, **Weekly** or **Every check** to be reminded about updates you have not installed yet.

- A reminder is one message that lists every update still waiting for that channel. It is marked as a reminder: the email subject is "WP Updates Notifier: reminder of updates waiting for" followed by your site name, and the first line says "Reminder: these updates are still waiting for" followed by your site name and its address.
- A new update is still sent at once, as usual. If a new update turns up in the same check as a reminder that is due, you get one message, the reminder, with the new update in it.
- The first reminder comes one interval after an update starts waiting for the channel. With **Every check** it comes at the next check. Reminders stop when nothing is waiting any more.
- Each channel has its own setting, so you can, for example, remind only one address each week.
- The **Routing** column shows it, for example "Reminders: Weekly".

Reminders are worked out at each check, so they can not come more often than your [frequency to check](settings.md#frequency-to-check). See [How notifications work](how-notifications-work.md#reminders).

## The email options

The form puts **Send to** under **Recipients** and the other options under **Message**.

Below the options, **Preview** shows the recipients, the subject and the body of an example notification with the current settings, so you can see the effect of the format and the prefix. Open it to read it. It shows what was saved, or what you typed if the last save failed, and sends nothing.

| Option | What it does |
| --- | --- |
| **Send to** | Required. One or more email addresses, separated by commas. |
| **From address** | The address the email comes from. Leave it empty to use the WordPress default. Only set an address at a domain this site is allowed to send mail for. If you set it, the sender name is "WP Updates Notifier". |
| **Reply-To address** | Where replies go. Optional. |
| **Format** | **Plain text** (the default) or **HTML**. |
| **Subject prefix** | Text put before the subject, followed by a space. Optional. |

The subject is "WP Updates Notifier: updates available for" followed by your site name. With the prefix `[Client]` it becomes "[Client] WP Updates Notifier: updates available for My Site".

Why the From address matters is covered under [Why does the email not arrive?](troubleshooting.md#why-does-the-email-not-arrive).

## The webhook channel

A webhook channel sends each notification as a JSON document to an address you choose, so another service can act on it: Zapier, Make, n8n, Home Assistant, or your own code. The message is signed, so the receiver can check that it came from your site. The fields of the document are described in the [webhook payload reference](../developers/webhook-payload.md).

### The webhook options

| Option | What it does |
| --- | --- |
| **Webhook address** | Required. The address that receives the messages. It must start with `https://`. The address often holds a token, so it is kept secret: the form never shows it again, and changing it means typing it again. |
| **Signing secret** | Optional, but recommended. Used to sign each message. It must look like `whsec_` followed by base64 text. See [make a signing secret](#make-a-signing-secret). Never shown again once saved. |
| **Installed updates**: **Also send a message when a reported update is installed** | A tick box, on by default. See [Updates that are installed](#updates-that-are-installed). |

The **Advanced** group is closed until you open it (it opens by itself if one of its fields has an error):

| Option | What it does |
| --- | --- |
| **Signature headers** | **Standard** (the default) uses the names `webhook-id`, `webhook-timestamp` and `webhook-signature`. **Custom names** lets you choose the three names below. |
| **ID header**, **Timestamp header**, **Signature header** | The names to use when **Signature headers** is **Custom names**. Each starts with the standard name. They must be valid header names of at most 64 characters, different from each other, and not `Content-Type`, `Content-Length`, `Host` or `User-Agent`. |
| **Extra headers** | Headers to send with every message, one `Name: value` per line, for example `Authorization: Bearer abc123` or `X-Api-Key: abc123`. At most 20 lines. The values are usually credentials, so the field is secret: changing one header means typing the whole list again. An error names the line, never the value. |
| **Timeout** | Seconds to wait for the receiver, from 1 to 10. The default is 5. |

Renaming the headers changes only their names. The values and what is signed stay the same, so it does not make the messages fit another provider's format, such as the `X-Hub-Signature-256: sha256=...` header of GitHub. A receiver that needs that must be changed, or use a small proxy.

**Send test** sends a message of the type `updates.test`. Without a signing secret, messages still carry `webhook-id` and `webhook-timestamp`, but no `webhook-signature`.

Like the email options, each webhook option can be set in code. For example `SC_WPUN_CHANNEL_<ID>__SIGNING_SECRET` sets the signing secret of the channel with that id, and `SC_WPUN_CHANNEL_<ID>__URL` sets its address. See [Settings in code](settings-in-code.md) and [Configuration sources](../developers/configuration-sources.md).

### Make a signing secret

The secret must start with `whsec_`, followed by base64 text that decodes to 24 to 64 bytes. This is the format of [Standard Webhooks](https://www.standardwebhooks.com/), so the receiving side can use its libraries. A plain password is refused. Make one in a terminal:

```sh
printf 'whsec_%s\n' "$(openssl rand -base64 32)"
```

The form never shows a saved secret, so paste the same value into the **Signing secret** field and into the receiver. Keep it out of version control.

Without a signing secret, messages carry no signature and anyone who learns the address can send fake messages to the receiver. Treat an unsigned endpoint as public.

### Check the signature on the receiver

Every message has three headers:

| Header | Value |
| --- | --- |
| `webhook-id` | `msg_` and a random text, new for every message. |
| `webhook-timestamp` | When the message was signed, in seconds since 1970. |
| `webhook-signature` | `v1,` and the base64 of an HMAC-SHA256, or a space-separated list of such entries. |

The easy way is one of the [Standard Webhooks libraries](https://github.com/standard-webhooks/standard-webhooks#reference-implementations), which check the signature and the time for you. Give them the signing secret, the three header values and the raw body, exactly as received.

To do it by hand:

1. Read the **raw** body. Do not parse and re-encode it: the signature covers the exact bytes.
2. Join the id, the timestamp and the body with dots: `{webhook-id}.{webhook-timestamp}.{body}`.
3. Take the secret without its `whsec_` prefix and decode it from base64. Those bytes are the key.
4. Work out the HMAC-SHA256 of step 2 with that key, and encode it in base64.
5. Split `webhook-signature` at the spaces. Accept the message if one of the entries is `v1,` followed by your result. Compare in constant time.
6. Reject the message if the timestamp is more than 5 minutes from your clock, in either direction. This stops a captured message from being sent again later.

In PHP:

```php
function verify_webhook( string $secret, array $headers, string $body, ?int $now = null ): bool {
	$id         = $headers['webhook-id'] ?? '';
	$timestamp  = $headers['webhook-timestamp'] ?? '';
	$signatures = $headers['webhook-signature'] ?? '';

	if ( '' === $id || '' === $signatures || ! ctype_digit( $timestamp ) ) {
		return false;
	}

	// Reject a timestamp more than 5 minutes from now.
	if ( abs( ( $now ?? time() ) - (int) $timestamp ) > 300 ) {
		return false;
	}

	$key      = base64_decode( substr( $secret, strlen( 'whsec_' ) ), true );
	$expected = base64_encode( hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $body, $key, true ) );

	// The header is a space-separated list such as "v1,abc= v1,def=".
	foreach ( explode( ' ', $signatures ) as $entry ) {
		$parts = explode( ',', $entry, 2 );
		if ( 2 === count( $parts ) && 'v1' === $parts[0] && hash_equals( $expected, $parts[1] ) ) {
			return true;
		}
	}

	return false;
}
```

Call it with the lower-case header names, and `file_get_contents( 'php://input' )` as the body.

In Node.js:

```js
const crypto = require('node:crypto');

function verify(secret, headers, body, now = Date.now() / 1000) {
  const id = headers['webhook-id'];
  const timestamp = headers['webhook-timestamp'];
  const signatures = headers['webhook-signature'];
  if (!id || !timestamp || !signatures) return false;

  // Reject a timestamp more than 5 minutes from now.
  if (Math.abs(now - Number(timestamp)) > 300) return false;

  const key = Buffer.from(secret.replace(/^whsec_/, ''), 'base64');
  const expected = crypto
    .createHmac('sha256', key)
    .update(`${id}.${timestamp}.${body}`)
    .digest();

  // The header is a space-separated list such as "v1,abc= v1,def=".
  return signatures.split(' ').some((entry) => {
    const [version, signature] = entry.split(',');
    if (version !== 'v1' || !signature) return false;
    const given = Buffer.from(signature, 'base64');
    return given.length === expected.length && crypto.timingSafeEqual(given, expected);
  });
}
```

Pass the body as the raw string or buffer, not the parsed JSON. With Express, use `express.raw({ type: 'application/json' })` for the route.

To test your code, use this example from the Standard Webhooks specification: the secret `whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw`, the id `msg_p5jXN8AQM9LWM0D4loKWxJek`, the timestamp `1614265330` and the body `{"test": 2432232314}` give the signature `v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=`. (Pass a `now` near that timestamp, or the age check will refuse it.)

### Message types, repeats and reminders

The `type` field of the document says what the message is. A receiver can filter on it.

| Type | When |
| --- | --- |
| `updates.available` | A normal message: updates that were not reported before. |
| `updates.reminder` | A [reminder](#reminders) of updates that are still waiting. |
| `updates.test` | **Send test**. |
| `updates.resolved` | Reported updates have been installed or removed. See below. |

`webhook-id` is new for every message, so it does not tell you that you have seen an update before. Overlapping checks can, rarely, send the same update twice, and a reminder lists updates that were already reported. A receiver that must not act twice should remember `type` + `key` + `new_version` of each item it has handled. A reminder is not new: do not raise a second alert for it.

### Updates that are installed

When an update that was reported to a channel is installed (or the plugin or theme is removed), the webhook channel can tell the receiver with a message of the type `updates.resolved`. Home Assistant or a ticket system can use it to clear the alert it raised. Each item says how it was resolved: `updated`, `removed` or `core`.

- It is sent after the check that notices the change, so it can come up to one check interval late (see [frequency to check](settings.md#frequency-to-check)). One message lists everything resolved in that check.
- It is best effort and is not retried. The check has already forgotten the items, so if the receiver is down at that moment the message is lost. The reports of updates that are waiting are not affected: they stay reliable.
- A failure of this message is not shown on the Channels tab, and it does not change **Last delivery**. Site code can log it with the `sc_wpun_webhook_resolved_sent` action (see the [payload reference](../developers/webhook-payload.md#resolved-messages)).
- Nothing is sent when the channel is switched off, is waiting after a `Retry-After`, or has a value set in code that is not valid.
- Turn it off for a channel with **Also send a message when a reported update is installed**.

### Ports and private addresses

The plugin sends with WordPress's `wp_safe_remote_post()`, which protects your site from being used to reach internal services. It only allows ports 80, 443 and 8080, and refuses addresses on your own network (such as `localhost`, `192.168.x.x` and `10.x.x.x`). Redirects are not followed: a redirect is a failure, because the signature would no longer fit the new request.

So Home Assistant on its default port 8123, and a self-hosted n8n on 5678, are refused. The usual ways round it:

- Put a reverse proxy in front of the receiver, on port 443 with HTTPS.
- Use a public address instead: a tunnel (Cloudflare Tunnel, Tailscale Funnel, ngrok) or the cloud address of the service, such as Home Assistant Cloud.
- If you run the site and accept the risk, allow the port and the host in code, for example in a small plugin:

```php
add_filter( 'http_allowed_safe_ports', function ( $ports ) {
	$ports[] = 8123;
	return $ports;
} );

add_filter( 'http_request_host_is_external', function ( $external, $host ) {
	return 'homeassistant.local' === $host ? true : $external;
}, 10, 2 );
```

These are WordPress filters and they apply to every safe request your site makes, so keep them as narrow as you can.

### Plain http

A webhook address must use `https://`, so the messages and the token in the address are not sent in clear text. If a receiver on a trusted network only speaks http, a site can turn the rule off for the webhook channel with the `sc_wpun_channel_fields` filter:

```php
add_filter( 'sc_wpun_channel_fields', function ( $fields, $type ) {
	if ( 'webhook' === $type && isset( $fields['url'] ) ) {
		$fields['url']['https_only'] = false;
	}
	return $fields;
}, 10, 2 );
```

The port and private address rules above still apply.

### Recipes

In each of these, create the receiving side first, copy its address into **Webhook address**, and use **Send test** to see a message arrive.

**Zapier.** Make a Zap that starts with **Webhooks by Zapier**, trigger **Catch Hook**. Copy the custom webhook URL it gives you. Zapier does not check a Standard Webhooks signature for you, so either leave **Signing secret** empty (the address itself is then the only protection) or verify in a **Code by Zapier** step. Use `type` to filter, and `data.items` for the updates. Zapier addresses use HTTPS on port 443, so they fit the rules above.

**Make.** Add a **Webhooks** module, **Custom webhook**, and copy its address. Checking the signature there needs your own code step, so the address is the main protection. Use a filter on `type` between modules. To read the items, send one sample message first so that Make learns the structure, then use an **Iterator** on `data.items`.

**n8n.** Add a **Webhook** node, method `POST`, and use its production URL (not the test URL) in the channel. A self-hosted n8n on port 5678 is refused unless it sits behind a reverse proxy on 443. To check the signature, use a **Code** node with the Node.js example above, on the raw body (turn on **Raw Body** in the node's options). A **Switch** node on `type` splits the message types.

**Home Assistant.** Make an automation with a **Webhook** trigger, which gives you an address like `https://<your-address>/api/webhook/<id>`. Home Assistant answers on port 8123 by default, so it needs a reverse proxy, Home Assistant Cloud or a tunnel (see [Ports and private addresses](#ports-and-private-addresses)). Set the trigger to allow only `POST`. The body is available as `trigger.json`, for example `trigger.json.data.items`. Raise a notification or a persistent notification when `trigger.json.type` is `updates.available` or `updates.reminder`, and dismiss it when it is `updates.resolved`. Home Assistant does not check the signature, so keep the webhook id secret.
