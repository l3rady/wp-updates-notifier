# Muting from code

Since 3.0.0 a site owner can [mute](../users/muting.md) a plugin or theme. A mute is global: a muted item goes to no channel. The public API lets your code mute and unmute items, define mutes, set policy and react to changes. Every hook below is in the [hook reference](hooks.md#muting).

Muting is decided in the global veto. `sc_wpun_should_notify_item` receives `false` as its default for a muted item, so a muted item still counts as waiting and keeps its history. The mutes are stored in their own option, `sc_wpun_muted`, behind `WPUN\Mute_Store`. Do not read the option: use `sc_wpun_mutes()`.

!!! warning
    A `sc_wpun_should_notify_item` callback that returns `true` whatever it is given unmutes everything. Return the `$notify` it received instead, and change it only for the items you mean to.

## Mute and unmute

`sc_wpun_mutes()` returns the `WPUN\Mute_Store`. Both methods return a `WP_Error` when the change is refused, and take a `$source` that is passed to the actions. The Plugins screen uses `plugins_screen`, the Muted tab `settings`, WP-CLI `cli`, and code that does not say uses `api`.

```php
// Mute for good.
$result = sc_wpun_mutes()->mute( 'plugin', 'noisy/noisy.php' );

// Skip version 2.1.0 only. A newer version is notified again.
$result = sc_wpun_mutes()->mute( 'plugin', 'noisy/noisy.php', '2.1.0' );

if ( is_wp_error( $result ) ) {
	error_log( $result->get_error_message() );
}

sc_wpun_mutes()->unmute( 'plugin', 'noisy/noisy.php' );

$mute = sc_wpun_mutes()->get( 'plugin', 'noisy/noisy.php' ); // Mute or null.
```

The key is the one `Notification_Item::get_key()` returns: the plugin file or the theme stylesheet. Any type registered with `sc_wpun_item_types` can be muted, so an add-on's own item types can be too. WordPress core cannot be muted. The screens and WP-CLI offer plugins and themes only.

A skip mute covers the stored version and anything not newer, by `version_compare()`. `Mute::applies_to( $offered )` tells you whether it still covers a version.

## Define mutes in code

`sc_wpun_mutes` adds mutes that no screen can remove. They apply like stored ones and are listed on the Muted tab, in `wp updates-notifier status` and in Site Health, marked as set in code. This suits an mu-plugin that an agency or Composer manages.

```php
add_filter(
	'sc_wpun_mutes',
	function ( $mutes ) {
		$mutes[] = new \WPUN\Mute(
			array(
				'type' => 'plugin',
				'key'  => 'noisy/noisy.php',
			)
		);
		return $mutes;
	}
);
```

Use `'until' => '2.1.0'` to skip a version only. A stored mute cannot be hidden by this filter. To report an item that is muted, use `sc_wpun_should_notify_item`.

## Apply a policy

`sc_wpun_pre_mute` runs before a mute is saved. Return a `WP_Error` to refuse it. The message is shown on the screen or by WP-CLI. You may also return a changed `Mute`, for example to cap a long mute, if it is for the same item.

```php
add_filter(
	'sc_wpun_pre_mute',
	function ( $mute, $source ) {
		if ( $mute instanceof \WPUN\Mute && 'security/security.php' === $mute->get_key() ) {
			return new \WP_Error( 'my_policy', 'Security updates must be reported.' );
		}
		return $mute;
	},
	10,
	2
);
```

`sc_wpun_pre_unmute` is the same for removing a mute. Return a `WP_Error` to refuse. Deleting the plugin or theme removes its mute without asking.

```php
add_filter(
	'sc_wpun_pre_unmute',
	function ( $allow, $mute, $source ) {
		return 'maintained/maintained.php' === $mute->get_key() ? new \WP_Error( 'my_policy', 'Ask the maintainer.' ) : $allow;
	},
	10,
	3
);
```

`sc_wpun_mute_capability` decides who may mute and unmute from the Plugins screen and the Muted tab. It defaults to the capability of the settings page. WP-CLI and the PHP API do not check capabilities.

```php
// Let people who can update plugins mute and unmute.
add_filter( 'sc_wpun_mute_capability', fn() => 'update_plugins' );
```

## React to changes

`sc_wpun_item_muted` and `sc_wpun_item_unmuted` fire after a change, with the `Mute` and the source. Replacing a mute fires `sc_wpun_item_muted` again. Deleting a plugin or theme removes its mute and fires `sc_wpun_item_unmuted` with the source `deleted`, so a log can tell it from an unmute by a person.

```php
add_action(
	'sc_wpun_item_muted',
	function ( $mute, $source ) {
		error_log( sprintf( '%s %s muted from %s', $mute->get_type(), $mute->get_key(), $source ) );
	},
	10,
	2
);

add_action(
	'sc_wpun_item_unmuted',
	function ( $previous, $source ) {
		if ( 'deleted' === $source ) {
			error_log( $previous->get_key() . ' was deleted, so its mute went too.' );
		}
	},
	10,
	2
);
```

`sc_wpun_mute_ended` fires once when a skip mute stops applying because a newer version is offered. It can be missed: if someone saves or removes any mute after WordPress offers the newer version and before the next check, the ended mute is dropped first and the action does not fire. It receives the `Mute` and the `Notification_Item` with the newer version, and never fires in a dry run.

```php
add_action(
	'sc_wpun_mute_ended',
	function ( $mute, $item ) {
		error_log( sprintf( '%s is no longer skipped: %s is out.', $item->get_name(), $item->get_new_version() ) );
	},
	10,
	2
);
```
