# Configuration sources

Any global setting and any field of a channel instance, including the fields of your own channel, can be supplied from outside the database. This keeps secrets such as webhook URLs and API tokens out of `wp_options`, and lets a deployment pin settings that the admin screen then shows as read-only.

## Where values come from

For each value the plugin asks, in order:

1. **`wp-config.php` constants.** Always on.
2. **Environment variables.** Only when the environment variable `SC_WPUN_LOAD_FROM_ENV` is `1`, `true`, `yes` or `on`, or the `sc_wpun_load_from_env` filter returns true. This keeps variables a host happens to set from changing the plugin's behaviour.
3. **Sources added by add-ons**, through the `sc_wpun_config_sources` filter.

The first source with a value wins. The `sc_wpun_config_value` filter then sees the result (or null) and has the last word. If nothing supplies a value, the one saved in the database is used.

## Names

Constants and environment variables share the same names:

| What | Name | Example |
| --- | --- | --- |
| A global setting | `SC_WPUN_SETTING_<SETTING>` | `SC_WPUN_SETTING_FREQUENCY` |
| A field of a channel instance | `SC_WPUN_CHANNEL_<INSTANCE_ID>__<FIELD>` | `SC_WPUN_CHANNEL_OPS_TEAM__WEBHOOK_URL` |

The instance id and the field name are upper-cased, with a double underscore between them. Instance ids are lower case letters and digits, in words joined by single underscores (`ops_team`), so every id makes a valid constant name.

Your channel's fields get these names automatically. You don't need to do anything to support them, but do say in your docs which fields are worth setting this way.

Whole instances can be defined in code too, with the `SC_WPUN_CHANNELS` constant (a JSON list) or the `sc_wpun_channels` filter. See the `sc_wpun_channels` entry in the [hook reference](hooks.md).

## Values and types

Sources return scalars: a string, bool, int or float. The plugin casts the value to the type of the setting or field and runs it through the same validation as a value typed into the form. A value that fails is reported on the settings screen and in Site Health, and is not used.

- A `boolean` field accepts `1`, `true`, `yes` and `on`, or `0`, `false`, `no`, `off` and an empty value, in any case.
- An `enum` field takes the option key; a multiple-choice `enum` takes keys separated by commas, such as `core,plugin`.

## On the settings screen

A value supplied from outside is read-only on the settings screen, which names where it came from ("Set by ..."). The screen never prints the value of a secret field, nor of any field of a type other than `number`, `boolean` and `enum` (the text types and the types add-ons register), unless that field sets `'show_external' => true`. The built-in email channel does this for its recipients, From address, Reply-To and subject prefix. Booleans, numbers and enum choices are shown. Duplicating an instance doesn't copy secrets that came from outside the database. Reports and Site Health name the source of a rejected value, never the value. **Tools → Site Health → Info → WP Updates Notifier** shows where each setting and channel field comes from (`database`, or the constant, environment variable or source), with secrets only as set or not set.

## Hiding the About tab

The About tab of the settings screen (links to the documentation, support and the author) is shown unless you hide it. The `show_about` setting is code only: it has no field on the settings screen, it is never saved to the database, and a value posted from the form is ignored. Supply it like any other setting, with `SC_WPUN_SETTING_SHOW_ABOUT`; it takes the same boolean values as other on/off settings, and a value that is not valid is reported and the default (shown) is used.

```php
// wp-config.php
define( 'SC_WPUN_SETTING_SHOW_ABOUT', false );
```

Or from a plugin, with the `sc_wpun_config_value` filter:

```php
add_filter(
	'sc_wpun_config_value',
	function ( $value, $context ) {
		return 'show_about' === $context->get_setting() ? false : $value;
	},
	10,
	2
);
```

If you hide the tab, please consider a donation or a review in return.

## Writing a source

Implement `WPUN\Config_Source` and add an instance with `sc_wpun_config_sources`:

```php
class My_Vault_Source implements \WPUN\Config_Source {

	public function get( string $key, \WPUN\Config_Context $context ) {
		// Only answer for the channel fields you manage.
		if ( \WPUN\Config_Context::SCOPE_CHANNEL !== $context->get_scope() ) {
			return null;
		}

		return my_vault_lookup( 'wpun/' . $key ); // A scalar, or null for "not here".
	}

	public function get_label( string $key ): string {
		return 'My Vault'; // Never include the value.
	}
}

add_filter(
	'sc_wpun_config_sources',
	function ( $sources ) {
		$sources[] = new My_Vault_Source();
		return $sources;
	}
);
```

`get()` receives the constant-style name as `$key` and a `WPUN\Config_Context` that tells you what is being asked for:

- `get_scope()`: `Config_Context::SCOPE_SETTING` or `Config_Context::SCOPE_CHANNEL`.
- `get_setting()`: for a setting, its name, such as `frequency`.
- `get_instance_id()` and `get_field()`: for a channel field.
- `get_site_id()`: the site being configured.
- `get_key()`: the same name as `$key`.

Rules for a source:

- Return null when you have no value, so the next source is asked.
- Never log, print or put the value in `get_label()`. It may be a secret. The plugin keeps values in `WPUN\Config_Value` objects, which hide the value from `var_dump()`, `print_r()` and serialisation.
- A source may throw. The plugin records the class of the exception (not its message, which could contain a secret) and asks the next source.
- Results are cached for the request, so `get()` is not called again for the same key.

The test add-on in [`tests/fixtures/plugins/wpun-test-addon/`](https://github.com/l3rady/wp-updates-notifier/blob/main/tests/fixtures/plugins/wpun-test-addon/class-addon-config-source.php) has a working source.
