# Writing a notification module

A notification module adds a channel type: a new way of delivering update notifications, such as a chat service, an SMS gateway or a ticketing system. Site owners then create any number of instances of it on the **Channels** tab, each with its own settings and routing.

This guide builds one step by step. The finished result is the example add-on in [`examples/wpun-example-channel/`](https://github.com/l3rady/wp-updates-notifier/tree/main/examples/wpun-example-channel/), which posts to a chat webhook. Copy it as a starting point. Two more references:

- [`modules/email/`](https://github.com/l3rady/wp-updates-notifier/tree/main/modules/email/): the built-in email channel. It is written exactly like an add-on, using only the public API.
- [`tests/fixtures/plugins/wpun-test-addon/`](https://github.com/l3rady/wp-updates-notifier/tree/main/tests/fixtures/plugins/wpun-test-addon/): the test add-on, which exercises every extension point (custom field types, configuration sources, short-circuited sends).

## 1. The plugin file

A module is an ordinary WordPress plugin. Its header declares the dependency, so WordPress 6.5 and later will not activate it without WP Updates Notifier:

```php
/**
 * Plugin Name:       My Chat Channel
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Requires Plugins:  wp-updates-notifier
 * Requires WPUN API: ^1.0
 */
```

`Requires WPUN API` is optional. It takes the same constraint syntax as `sc_wpun_api_satisfies()`, and WP Updates Notifier shows a notice below the add-on on the **Plugins** screen, before and after activation, when the site's API version does not satisfy it or the value cannot be read. It does not block activation.

`Requires Plugins` makes WordPress check that WP Updates Notifier is active, not its version. Three checks cover the version: the header (the Plugins screen notice), the boot check below (code outside the channel, with your own notice) and `get_api_constraint()` (the channel itself, enforced by the registry, see [Declare the API version you need](#declare-the-api-version-you-need)). Check the API version before using anything, and register the channel on the `sc_wpun_register_channels` action:

```php
namespace My_Chat_Channel;

function boot(): void {
	if ( ! function_exists( 'sc_wpun_api_satisfies' ) || ! \sc_wpun_api_satisfies( '^1.0' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\api_notice' );
		return;
	}

	require_once __DIR__ . '/class-my-chat-channel.php';

	add_action(
		'sc_wpun_register_channels',
		static function ( \WPUN\Channel_Registry $registry ) {
			$registry->register( new My_Chat_Channel() );
		}
	);
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );
```

`sc_wpun_api_satisfies( '^1.0' )` is true for any 1.x API from 1.0.0 on. Ask for the oldest version that has everything you use, and use a wider constraint such as `>=1.0 <3.0` only for versions you have tested. See [Stability and deprecation](stability.md).

The check in `boot()` is for everything outside the channel. The registry already checks the channel itself (see the next step), but only your code knows what else it does: hooks your add-on adds on its own, or its own settings screen, would still run against an API that is too old. Keep the explicit `sc_wpun_api_satisfies()` check for those, and for showing your own notice.

Do the work on `plugins_loaded`, not when the file is included. By then every active plugin is loaded, whatever order WordPress loaded them in.

## 2. The channel class

Extend `WPUN\Channel`. Three methods are required:

```php
use WPUN\Channel;
use WPUN\Channel_Instance;
use WPUN\Delivery_Result;
use WPUN\Notification;

class My_Chat_Channel extends Channel {

	public function get_type(): string {
		return 'my_chat';
	}

	public function get_label(): string {
		return __( 'My Chat', 'my-chat-channel' );
	}

	public function send( Notification $notification, Channel_Instance $instance ): Delivery_Result {
		// See step 4.
	}
}
```

- `get_type()` is the unique key of the channel: lower case letters, digits and underscores. Instances are stored under it, so never change it once people use your module. Registration fails (returns false) for an invalid or duplicate type.
- `get_label()` is the name site owners pick from when adding a channel.

The optional methods:

| Method | Default | Override to |
| --- | --- | --- |
| `get_description()` | empty | Describe the channel in one sentence on the "Add channel" screen. |
| `get_fields()` | no fields | Describe the settings of an instance. See step 3. |
| `get_api_constraint()` | empty: no check | Declare the API versions the channel needs, for example `'^1.0'`. The registry refuses the channel on a site whose API does not satisfy it. See "Declare the API version you need" below. |
| `supports( $feature )` | false | Return true for `test_message` to get a "Send test message" button. |
| `validate_settings( $settings, $stored )` | checks every field against its schema | Add checks that span several fields. Call `parent::validate_settings()` first. |
| `render_settings( $instance )` | null: the form is drawn from the schema | Draw the fields yourself. Rarely needed, and you then take over the accessibility of the fields: see [Accessibility](#accessibility). |

### Declare the API version you need

Override `get_api_constraint()` to say which API versions the channel works with. It takes the same constraint syntax as `sc_wpun_api_satisfies()`:

```php
public function get_api_constraint(): string {
	return '^1.0';
}
```

When the channel is registered, the registry checks the constraint against `sc_wpun_api_version()`. If it is not satisfied, or cannot be read, `register()` returns false, the channel is not available, and the `sc_wpun_channel_rejected` action fires. Site owners see "Unavailable: ..." for the channel's instances on the **Channels** tab, a notice on the plugin's settings page and a Site Health recommendation. Their settings are kept, so everything works again once WP Updates Notifier is updated. See [Stability and deprecation](stability.md#the-api-version).

## 3. Settings: the field schema

`get_fields()` returns an array keyed by setting name. The plugin draws the form on the Channels tab, validates what is submitted, stores it and supplies it to `send()` through the instance. You write no form, security token or saving code.

```php
public function get_fields(): array {
	return array(
		'webhook_url' => array(
			'type'        => 'url',
			'label'       => __( 'Webhook address', 'my-chat-channel' ),
			'description' => __( 'The address the chat service gave you.', 'my-chat-channel' ),
			'required'    => true,
			'secret'      => true,
		),
		'username'    => array(
			'type'  => 'text',
			'label' => __( 'Name to post as', 'my-chat-channel' ),
		),
	);
}
```

The types are `text`, `textarea`, `url`, `email_list`, `secret`, `number`, `boolean` and `enum` (with `checkbox` and `select` as aliases). The full list of keys (`default`, `required`, `secret`, `show_external`, `options`, `multiple`, `display`, `min`, `max`, `sanitize_callback`, `validate_callback` and more) is documented on the `WPUN\Channel` class in [`includes/abstract-class-channel.php`](https://github.com/l3rady/wp-updates-notifier/blob/main/includes/abstract-class-channel.php).

A field with `'required' => true` is also marked in the form: "(required)" after its label (not for a `boolean`, which is always valid) and `aria-required="true"` on its control. For a group of radio buttons the fieldset gets `role="radiogroup"` to carry it; a group of checkboxes has only the text. A `secret` that already has a saved value keeps the text but not the attribute, as leaving it empty is valid. A custom field type registered with `sc_wpun_field_types` receives the schema in its `render` callable, so read `required` there and do the same.

Every instance also has a label, an on/off switch and routing (including the choice of reminders, see below). The plugin adds these; don't put them in `get_fields()`.

### Reminders

A site owner can ask for reminders of updates that are still waiting (the `remind` routing key). Your `send()` then receives a `Notification` whose `is_reminder()` is true. It lists every update that is still waiting for the instance, so most of its items were delivered before.

- Don't drop an item because you have sent it already. If your channel de-duplicates by item, skip that check when `is_reminder()` is true.
- The built-in renderers only change the first line. If you build your own message, say that it is a reminder, for example in the title.
- In every other way a reminder is a normal notification. `is_test()` is false.

### Validation

The default `validate_settings()` runs, for every field: its `sanitize_callback`, the sanitiser of its type, then `required` and its `validate_callback`. An error is a `WP_Error` whose data names the field, and the form shows it next to that field. Override `validate_settings()` only for rules that involve several fields:

```php
public function validate_settings( array $settings, array $stored = array() ) {
	$clean = parent::validate_settings( $settings, $stored );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	if ( '' !== ( $clean['username'] ?? '' ) && '' === ( $clean['icon_url'] ?? '' ) ) {
		return new \WP_Error( 'invalid_field', __( 'Set an icon when you set a name.', 'my-chat-channel' ), array( 'field' => 'icon_url' ) );
	}
	return $clean;
}
```

### Secrets

Mark anything that grants access as secret: API tokens, passwords, and webhook URLs that carry a token. Use the `secret` type, or `'secret' => true` on a field of any type, including a custom one. A secret:

- is never written back into the form once saved. The form shows that a value is saved, leaving the field empty keeps it, and a "Clear" checkbox empties it;
- is never handed to the field's renderer: a custom type's `render` callable gets `'1'` as the value when one is saved and `''` when not;
- is still stored in the database in plain text, like other plugin settings. Site owners who want it out of the database can supply it from `wp-config.php`, an environment variable or a secrets service instead. See [Configuration sources](configuration-sources.md). Your fields support this automatically, for example `SC_WPUN_CHANNEL_OPS_CHAT__WEBHOOK_URL`.

A value that comes from outside the database (a constant, an environment variable or a source) is not printed on the edit screen unless the field sets `'show_external' => true`: the screen then only says "Set by ...". This holds for every type except `number`, `boolean` and `enum`, so a custom type registered with `sc_wpun_field_types` is not given the value either. Flag secrets with `secret`, and set `show_external` only for harmless values such as an address or a name.

Instances that reach hooks and your `send()` hold the resolved settings, secrets included. `var_dump()`, `print_r()` and `serialize()` of a `Channel_Instance` show the setting names with `[redacted]` values (`var_export()` and Reflection don't), but don't log or dump the settings yourself.

Your own code must keep secrets secret too. Never put one in a `Delivery_Result` message, an admin notice or a log. Results are shown to administrators and stored. The error messages of the HTTP API can contain the URL that was requested, so don't pass them on as they are.

### Custom field types

If none of the types fits, register one with the `sc_wpun_field_types` filter. Each entry has a `render` callable that returns the escaped markup of the control and a `sanitize` callable that returns the clean value or a `WP_Error`. The test add-on's `test_colour` type in [`class-test-form-channel.php`](https://github.com/l3rady/wp-updates-notifier/blob/main/tests/fixtures/plugins/wpun-test-addon/class-test-form-channel.php) is a complete example, and the [hook reference](hooks.md#sc_wpun_field_types) documents both signatures.

Other plugins can add fields to your channel with `sc_wpun_channel_fields`. The fields your code receives are those of `get_form_fields()`, which applies that filter, so read settings by name and don't assume the list is only yours.

## 4. Delivering: `send()`

`send()` is called once per enabled instance, with a `WPUN\Notification` holding only the items routed to that instance and not yet delivered to it. Read the instance's settings with `$instance->get_setting( 'name', $fallback )`.

It must always return a `WPUN\Delivery_Result`:

```php
return Delivery_Result::success();

return Delivery_Result::failure( $message, $code, $http_status, $retry_after, $context );
```

On success, the plugin records the items as delivered to this instance. On failure it records nothing, so the same items go out again on the next scheduled check. Other instances are not affected. The message of the latest result, success or failure, is shown on the Channels tab, so write it for the site owner: say what happened, in a sentence.

If `send()` throws, the dispatcher catches the exception, records a failure with the code `exception` and goes on with the other instances. Don't rely on it: catch what you expect and return a failure with a message the site owner can act on.

### The message

`$notification` gives you the site name and URL, the link to the Updates screen, the items (`get_items()`, each a `WPUN\Notification_Item` with name, versions, links and notes), and `is_test()` for a test message. `WPUN\Notification_Renderer` turns a notification into `plain_text()`, `markdown()` or `html()`, with the values escaped for that format. Use it unless your service needs something special. `markdown()` targets CommonMark (backslash escapes). A service with its own dialect, for example Slack mrkdwn, which ignores backslashes and needs `&`, `<` and `>` turned into entities, must escape for that dialect itself; a Slack renderer comes with the Slack module, not in 2.0.0. Links whose URL is not http or https are rendered as plain text. Site code can change the plain text with `sc_wpun_message_text`.

Pass what you are about to send through `$this->filter_payload( $payload, $notification, $instance )`. It applies the `sc_wpun_channel_payload` and `sc_wpun_channel_payload_{type}` filters, so site code can adjust your payload without forking your module. A filter can return anything, so check the result still has the shape you need. Document the shape of your payload for your users.

### HTTP

- Use `wp_safe_remote_post()` for any address a site owner typed in. It refuses addresses on the server's own network, which stops your webhook field from being used to reach internal services. Use `wp_remote_post()` only for your service's fixed API address. It also refuses ports other than 80, 443 and 8080. To try your module against a receiver on a local network, allow that host on your development site with the `http_request_host_is_external` filter. Don't ship that filter.
- Set a short `timeout` (10 seconds or less). The check delivers to every instance in turn, during a cron request.
- Check the HTTP status. A `WP_Error` or a status outside 2xx is a failure.

```php
$response = wp_safe_remote_post(
	$url,
	array(
		'timeout' => 10,
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( $payload ),
	)
);

if ( is_wp_error( $response ) ) {
	return Delivery_Result::failure( __( 'The request to the webhook failed.', 'my-chat-channel' ), 'http_error' );
}

$status = (int) wp_remote_retrieve_response_code( $response );
if ( $status < 200 || $status >= 300 ) {
	return Delivery_Result::failure( sprintf( __( 'The webhook answered with HTTP %d.', 'my-chat-channel' ), $status ), 'bad_status', $status );
}

return Delivery_Result::success();
```

### Rate limits and retries

When the service answers HTTP 429, return a failure with the status and the wait from the `Retry-After` header. `Delivery_Result::retry_after_from_response( $response )` reads it, as seconds or as an HTTP date, and gives null if there is none or it is not usable:

```php
if ( 429 === $status ) {
	return Delivery_Result::failure(
		__( 'The webhook is limiting requests.', 'my-chat-channel' ),
		'rate_limited',
		429,
		Delivery_Result::retry_after_from_response( $response )
	);
}
```

A failure with a `retry_after` defers the instance: update checks skip it until that long after the failure, and its items stay unsent and go out on the first check after. Other instances are not affected, and nothing extra is scheduled. The plugin caps the wait at 7 days. A test send ignores the deferral and does not set it, and a later success, or a failure without `retry_after`, clears it. Without a `retry_after`, the items are retried on the next scheduled check, which is at least an hour away. Don't retry inside `send()`, and don't sleep.

### Holding a delivery until later

To hold a delivery without an error, return `Delivery_Result::deferred( $until )`, with `$until` a Unix time (not seconds, unlike `retry_after`). A channel can return it, and so can a `sc_wpun_pre_send` callback, which is the easy way to add your own quiet hours. The instance is held until then, at most 7 days from now, and a time in the past holds nothing. Unlike a failure, a hold fires `sc_wpun_notification_deferred` and not `sc_wpun_notification_failed`, raises no Site Health warning, and leaves the last delivery result alone. Nothing is recorded as notified, so the first check after `$until` delivers everything still pending. A test send ignores the hold and says so in its result.

This holds the email channel outside weekdays 09:00 to 18:00, site time:

```php
add_filter(
	'sc_wpun_pre_send',
	function ( $pre, $notification, $instance ) {
		if ( 'email' !== $instance->get_type() || $notification->is_test() ) {
			return $pre;
		}

		$now  = new DateTimeImmutable( 'now', wp_timezone() );
		$day  = (int) $now->format( 'N' ); // 1 = Monday, 7 = Sunday.
		$hour = (int) $now->format( 'G' );
		if ( $day <= 5 && $hour >= 9 && $hour < 18 ) {
			return $pre;
		}

		// The next 09:00 on a weekday: today if it is early on a weekday, otherwise the next weekday.
		$next = $now->setTime( 9, 0 );
		if ( $day > 5 || $next <= $now ) {
			do {
				$next = $next->modify( '+1 day' );
			} while ( (int) $next->format( 'N' ) > 5 );
		}

		return \WPUN\Delivery_Result::deferred( $next->getTimestamp(), 'Outside office hours.' );
	},
	10,
	3
);
```

`sc_wpun_instance_waiting_until( $id )` tells when an instance may next be delivered to, for a `sc_wpun_item_resolved` listener or a dashboard.

### Length limits

Most services limit the size of a message. Respect the limit by sending a summary rather than a message the service will refuse. `Notification_Renderer` does this for you: pass the limits to `plain_text()`, `markdown()` or `html()`.

```php
$text = Notification_Renderer::plain_text( $notification, 4000, 50 );
```

- `$max_length` is the longest result in characters, and `$max_items` the most items to list. 0 (the default) means no limit. Output is the same as without limits when everything fits.
- The renderer lists the largest number of items that fits and adds a line "…and N more" (translatable, with a plural form) when some are left out. The line sits before the closing "Please visit…" line; in Markdown it follows the list after a blank line, in HTML it is a paragraph after the list. Nothing is added when no item is left out.
- If even a message without any item is longer than `$max_length`, it is returned anyway (title, "more" line and closing line), never an empty string, so the result can then exceed the limit.
- Lengths are characters (`mb_strlen()`). Some services count bytes or UTF-16 code units, so leave a margin or measure the result yourself.
- Site code can change the "more" line with the `sc_wpun_more_items_text` filter (return plain text, it is escaped for the format). The `sc_wpun_message_text` filter still applies to plain text, and the length is measured after it. With a limit, it may run several times per message and receives the number of items left out as its third argument.

The example channel's `message()` method does this.

Bear in mind that a successful send counts every item as delivered, including the ones summarised as "…and N more". They are not sent again.

### Duplicate deliveries

The plugin does not lock update checks against each other. If a check started with `sc_wpun_run_check` or `wp updates-notifier check` overlaps a scheduled one, both can send the same update before either records it, so a notification can occasionally arrive twice. For update notices that is a fair trade for a simpler plugin. If your service must never receive the same message twice, keep your own record in `send()`, for example the notification's item keys and versions per instance, and skip what you already sent.

## 5. Testing

The plugin's own tests show how to test a module with WordPress's PHPUnit test suite and mocked HTTP. [`tests/wp/test-example-channel.php`](https://github.com/l3rady/wp-updates-notifier/blob/main/tests/wp/test-example-channel.php) tests the example add-on:

- It loads the add-on's main file the way WordPress would, calls `boot()`, and registers the channel.
- It answers HTTP requests with the `pre_http_request` filter, so no request leaves the machine, and it checks what was sent.
- It covers success, an error status, a `WP_Error`, a 429 with and without `Retry-After`, the summary of a long list (by count and by length), and that no failure message contains the webhook URL.
- It runs a whole update check to show a failed send is retried on the next run.

A minimal mocked request in your own tests:

```php
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$sent ) {
		$sent = array( 'url' => $url, 'body' => json_decode( $args['body'], true ) );
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$result = ( new My_Chat_Channel() )->send( $notification, $instance );
$this->assertTrue( $result->is_success() );
```

Return a `WP_Error` from the filter to test a failed connection.

### Checking your code

You can run the plugin's own checks on your add-on:

- **Coding standards.** Copy [`examples/wpun-example-channel/phpcs.xml.dist`](https://github.com/l3rady/wp-updates-notifier/blob/main/examples/wpun-example-channel/phpcs.xml.dist) and change the text domain and prefixes. It is the WordPress ruleset, including the escaping, security token and sanitising sniffs, with the same PHP and WordPress versions as the plugin.
- **Semgrep.** Run Semgrep CE with the plugin's rules on your code: `semgrep scan --config path/to/wp-updates-notifier/.semgrep/ --metrics=off your-addon/`. The rules ban `eval`, `unserialize`, `$_REQUEST` and unprepared `$wpdb` queries, and they require `wp_safe_remote_*` for HTTP. Your fixed API address may use `wp_remote_post()` with `// nosemgrep: wpun-outbound-http-safe-only -- <reason>`. The rules are GPL, like the plugin.

## Accessibility

The settings screen is checked against WCAG 2.2 AA, and a module is part of it.

### Prefer schema fields

Fields drawn from `get_fields()` get the accessible markup for free:

- a label for each control, or a fieldset with a legend for a group of radio buttons or checkboxes;
- the `description` tied to the control with `aria-describedby`;
- "(required)" after the label of a required field, and `aria-required="true"` on its control (a group of checkboxes only has the text; a saved secret keeps the text but not the attribute);
- on an error, `aria-invalid="true"`, the message under the field with a bold "Error:", and a summary at the top of the form with a link to each field. The link goes to the first control of a group of radio buttons or checkboxes;
- a note on a saved secret ("A value is saved. Leave this empty to keep it, ..."), a clear checkbox named after the field, and a "Set by ..." note, tied to the control, for a value that comes from a constant or an environment variable.

A page title that starts with "Error:" after a failed save and the headings are the plugin's job too. Use `render_settings()` only when no schema field can do it.

### If you draw your own fields

Your markup gets none of the above, so it has to do it:

- Give every control a `<label for="...">`. For a group of radio buttons or checkboxes use a `<fieldset>` with a `<legend>` instead.
- Tie help text to its control with `aria-describedby`.
- Start headings at `<h4>`: your markup sits under the form's `<h3>Settings</h3>`.
- Never carry meaning by colour alone: say "Error:" or "(required)" in text. Check that the text is readable in forced-colours mode.

### Errors from custom markup

Return errors from `validate_settings()` as a `WP_Error` keyed by field, as with schema fields (see [Validation](#validation)). The summary at the top of the form lists every message. It can only link to a control it knows, and with `render_settings()` it knows none, so the messages are listed **without a link**. Make your own controls show and announce the error: put the message next to the control, set `aria-invalid="true"` and point `aria-describedby` at the message.

### Choosing a display

- Use `checkboxes` rather than a `select` with `multiple` for a choice of several: a multiple select is hard to use with a keyboard and a screen reader.
- Use radio buttons for a single choice of up to five options. The plugin does this by default and falls back to a select above five.

### Labels and text

- Labels are short, unique within the form and visible. Never use a placeholder as the label: it disappears when the user types.
- In notification text avoid symbols such as arrows (read out as "right arrow" or not at all), and give a link a name that makes sense out of context, such as "Akismet changelog" rather than "Details". `Notification_Renderer` already writes "Akismet from version 5.6 to 5.7.2" and links named after the item; use it for the message, as the [example channel](https://github.com/l3rady/wp-updates-notifier/tree/main/examples/wpun-example-channel/) does.

### Checking

`make a11y` in the plugin repository runs axe and keyboard checks of the settings screen on a throwaway site, including a form with every field type. Also tab through your form and listen to it with a screen reader.

## Linking to the settings screen

The settings screen is `options-general.php` on a single site and in the network admin on a multisite network. Use `sc_wpun_settings_url()` (API 1.1.0) for a link, and `sc_wpun_is_network()` to know which mode you are in. Never write `admin_url( 'options-general.php?page=…' )`.

```php
$url = sc_wpun_settings_url( 'channels' ); // The Channels tab, in either mode.
```

## Checklist

- The header has `Requires Plugins: wp-updates-notifier` and `Requires WPUN API` with the constraint, and `boot()` checks `sc_wpun_api_satisfies( '^1.0' )` for code outside the channel.
- `get_api_constraint()` returns the API versions the channel needs.
- The type is unique and never changes.
- Every credential is a secret field, and no secret reaches a message, notice or log.
- `send()` always returns a `Delivery_Result` and never retries or sleeps.
- HTTP uses `wp_safe_remote_post()` for user-supplied addresses, a short timeout and a status check.
- 429 returns a failure with `Retry-After`.
- Fields are schema fields where possible; custom markup has labels, described help text and its own error display (see [Accessibility](#accessibility)).
- Long messages are summarised with "…and N more".
- The payload goes through `filter_payload()`.
- Only the public API is used: nothing tagged `@internal`, not `sc_wpun()`, and not the plugin's options directly.
