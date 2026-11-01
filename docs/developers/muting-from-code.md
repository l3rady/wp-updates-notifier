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

// Snooze for a week: the fifth argument is a Unix time. A time that has passed is refused.
$result = sc_wpun_mutes()->mute( 'plugin', 'noisy/noisy.php', null, 'api', time() + WEEK_IN_SECONDS );

if ( is_wp_error( $result ) ) {
	error_log( $result->get_error_message() );
}

sc_wpun_mutes()->unmute( 'plugin', 'noisy/noisy.php' );

$mute = sc_wpun_mutes()->get( 'plugin', 'noisy/noisy.php' ); // Mute or null.
```

The key is the one `Notification_Item::get_key()` returns: the plugin file or the theme stylesheet. Any type registered with `sc_wpun_item_types` can be muted, so an add-on's own item types can be too. WordPress core cannot be muted. The screens and WP-CLI offer plugins and themes only.

A skip mute covers the stored version and anything not newer, by `version_compare()`. `Mute::applies_to( $offered )` tells you whether it still covers a version.

## Snoozes

A mute with an expiry is a snooze. The `Mute` has `get_expires()` (a Unix time, or null), `is_expired( $now = null )` (true from the expiry on) and `get_source()` (where it was set from, `''` for a mute stored before 3.0.0). `applies_to()` looks at the version only. The store checks the time.

- `is_muted()` is false from the expiry on, and `get()` and `all()` leave the mute out. `find()` still returns it, which the update check uses.
- A mute with a version and an expiry ends at whichever comes first.
- Nothing is scheduled. The update check never writes the mutes. An expired snooze is removed the next time a mute is saved or removed.
- While a snooze is in force the check forgets the item's reported version for every channel. When the snooze ends, the waiting version is sent again as new, or in a reminder that is due. Unmuting a snooze early does the same.

`sc_wpun_snooze_durations` sets the lengths the Plugins screen offers, as `seconds => label`. The Plugins screen accepts only these lengths. Entries with other keys or empty labels are dropped.

```php
add_filter(
	'sc_wpun_snooze_durations',
	function ( $durations ) {
		return array( 4 * HOUR_IN_SECONDS => __( '4 hours', 'my-plugin' ) ) + $durations;
	}
);
```

`Mute_Store::source_label( $source )` gives the name the Muted tab shows for a source. `sc_wpun_mute_source_label` changes it, for example for the name of an add-on.

```php
add_filter(
	'sc_wpun_mute_source_label',
	function ( $label, $source ) {
		return 'helpdesk' === $source ? __( 'Helpdesk', 'my-plugin' ) : $label;
	},
	10,
	2
);
```

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

Use `'until' => '2.1.0'` to skip a version only. Add `'expires'` with a Unix time to end the mute at that time, for example `'expires' => strtotime( '2026-12-01 09:00 UTC' )`. Use a fixed time: a `time() + …` in the filter moves the end on every request, so the mute never ends. A stored mute cannot be hidden by this filter. To report an item that is muted, use `sc_wpun_should_notify_item`.

## Apply a policy

`sc_wpun_pre_mute` runs before a mute is saved. Return a `WP_Error` to refuse it. The message is shown on the screen or by WP-CLI. You may also return a changed `Mute`, for example to cap a long mute, if it is for the same item. A mute that comes back already expired is refused.

```php
// No snooze longer than 30 days.
add_filter(
	'sc_wpun_pre_mute',
	function ( $mute ) {
		$latest = time() + 30 * DAY_IN_SECONDS;
		if ( $mute instanceof \WPUN\Mute && null !== $mute->get_expires() && $mute->get_expires() > $latest ) {
			return new \WPUN\Mute(
				array(
					'type'    => $mute->get_type(),
					'key'     => $mute->get_key(),
					'until'   => $mute->get_until(),
					'time'    => $mute->get_time(),
					'user'    => $mute->get_user_id(),
					'source'  => $mute->get_source(),
					'expires' => $latest,
				)
			);
		}
		return $mute;
	}
);
```

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

`sc_wpun_mute_ended` fires once when a mute ends while an update is waiting. The third argument, `$reason`, is `version` when a newer version ended a skip mute, and `expired` when the time of a snooze passed. A mute that ended both ways is reported once, as `expired`. The check records it in the run state (`ended_snoozes` for a snooze), so it does not fire again. It can be missed: if someone saves or removes any mute after the mute ended and before the next check, the ended mute is dropped first and the action does not fire. The snoozed update is still sent again. It receives the `Mute`, the `Notification_Item` that is waiting and the reason, and never fires in a dry run.

```php
add_action(
	'sc_wpun_mute_ended',
	function ( $mute, $item, $reason ) {
		error_log( sprintf( '%s is no longer muted (%s): %s is out.', $item->get_name(), $reason, $item->get_new_version() ) );
	},
	10,
	3
);
```
