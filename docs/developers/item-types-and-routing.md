# Item types and routing

Out of the box a notification carries three kinds of item: `core`, `plugin` and `theme`. An add-on can report other things that need attention, such as a PHP version reaching end of life or a licence about to expire, by registering an item type and adding items of that type. Channel instances can then be routed to receive them or not.

## 1. Register the type

Use the `sc_wpun_item_types` filter. The key is lower case letters, digits and underscores, and the label is shown on the settings screen, where each instance's routing lists the registered types.

```php
add_filter(
	'sc_wpun_item_types',
	function ( $types ) {
		$types['php'] = __( 'PHP', 'my-addon' );
		return $types;
	}
);
```

Invalid entries are dropped, and `core`, `plugin` and `theme` cannot be removed or renamed.

Registering the type matters for the delivery history. Once an item of a registered type is no longer returned by your filter, the plugin forgets what it told each instance about it, so the item is new again if it comes back. The history of an item whose type is not registered is never pruned: it grows, and an item that goes away and returns with the same `new_version` is not sent again.

## 2. Add items

Add `WPUN\Notification_Item` objects with the `sc_wpun_update_items` filter. It runs on every update check, after core, plugins and themes were collected.

```php
add_filter(
	'sc_wpun_update_items',
	function ( $items ) {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			$items[] = new \WPUN\Notification_Item(
				array(
					'type'            => 'php',
					'key'             => 'php',
					'name'            => 'PHP',
					'current_version' => PHP_VERSION,
					'new_version'     => '8.4',
					'links'           => array( 'details' => 'https://www.php.net/supported-versions.php' ),
					'notes'           => array( __( 'Your PHP version no longer gets security fixes.', 'my-addon' ) ),
				)
			);
		}
		return $items;
	}
);
```

How the item's fields are used:

- `type` and `key` identify the item. Keep the key stable between runs.
- `new_version` is what the history remembers. An instance is told about an item once per `new_version`: the same item with the same `new_version` is not sent again, and a different `new_version` is.
- `name`, `current_version`, `links` and `notes` appear in the message.
- `flags` are machine-readable facts (scalars) for routing rules and channels. They are not shown in the message.

The check sets a bool `auto_update` flag on every core, plugin and theme item. It is true when WordPress is set to install the update by itself and can run on this site. The item then also has the note "Automatic updates are on, but WordPress has not installed this update yet." Items you add with `sc_wpun_update_items` get no flag from the plugin. Set it yourself if you want it.

The `sc_wpun_auto_update_enabled` filter (`$enabled`, `$type`, `$key`, `$new_version`) lets code mark updates that something outside WordPress installs, such as a host or a deployment pipeline. Returning false removes the flag and the note.

Scheduled checks run WordPress's automatic updates first, so a flagged item that is still waiting usually failed to install. To skip flagged items in checks where WordPress's updater did not run, use the veto:

```php
add_filter(
	'sc_wpun_should_notify_item',
	function ( $notify, $item ) {
		$flags = $item->get_flags();
		if ( ! empty( $flags['auto_update'] ) && 0 === did_action( 'wp_maybe_auto_update' ) ) {
			return false;
		}
		return $notify;
	},
	10,
	2
);
```

Only `Notification_Item` objects survive the filter. The constructor throws an `InvalidArgumentException` for an invalid type.

## 3. Route it

Each instance's routing is an array kept in `Channel_Instance::get_routing()`. `WPUN\Routing` knows four keys:

| Key | Values | Meaning |
| --- | --- | --- |
| `types` | list of item types | The types the instance receives. Empty or missing means every type, including yours. |
| `scope` | `all`, `active` | For `plugin` and `theme` items: `active` keeps only items whose `active` flag is true. |
| `core` | `all`, `major` | For `core` items: `major` keeps only updates that change the major.minor version. |
| `remind` | `never`, `daily`, `weekly` | Repeat all updates the instance receives that are still waiting, in one message, at that interval. New updates are still sent at once. Missing or invalid means `never`. |

Site owners choose these on the instance's form. `Routing::normalise()` fills in the defaults (`all`, `all`, `never`). `remind` does not decide which items an instance receives, so `should_receive()` ignores it. The update check reads it to decide when to send a reminder: see the Reminders paragraph of the [architecture](architecture.md). Other keys are stored and ignored by the plugin, so instances defined in code (the `SC_WPUN_CHANNELS` constant or the `sc_wpun_channels` filter) can carry rules of your own.

For anything the keys cannot express, use the `sc_wpun_channel_should_receive_item` filter. It runs after the instance's own rules, so it can block an item they let through or let through one they blocked:

```php
add_filter(
	'sc_wpun_channel_should_receive_item',
	function ( $receive, $item, $instance ) {
		// Only the "security_team" instance gets PHP items, and it gets nothing else.
		if ( 'security_team' === $instance->get_id() ) {
			return 'php' === $item->get_type();
		}
		return 'php' === $item->get_type() ? false : $receive;
	},
	10,
	3
);
```

To stop an item from going anywhere, use `sc_wpun_should_notify_item` instead. A vetoed item still counts as waiting, so it is not sent again as new when you stop vetoing it. Since 3.0.0 the default is false for a muted item, so a callback that returns `true` whatever it is given unmutes everything: pass `$notify` on instead. To mute an item, see [Muting from code](muting-from-code.md).

## In a channel

A channel receives only the items routed to its instance, in `$notification->get_items()`. `get_items_of_type( 'php' )` picks one type. A channel that formats messages itself should cope with item types it does not know, since any add-on can add one. `WPUN\Notification_Renderer` lists every item the same way, whatever its type: name, versions, links and notes.
