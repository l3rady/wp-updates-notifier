# Test helpers

The WordPress tests extend `WPUN_Test_Case` (`tests/wp/class-wpun-test-case.php`). It blocks real HTTP, resets
the plugin before each test and has the helpers for settings, state and updates (`save_settings()`,
`set_updates()`, `fake_http()` and more).

The shared helpers below live in three traits in `tests/wp/traits/`, and `WPUN_Test_Case` uses all three. They
are loaded by `tests/bootstrap.php`. Every helper is `protected`. The tests for them are in
`tests/wp/test-harness.php`.

## Builders (`WPUN_Builders`)

- `make_item( array $overrides = [] ): Notification_Item` is the core update from 6.0 to 6.1. Overrides replace
  fields.
- `make_notification( $items = null, array $context = [] ): Notification` is a notification for
  `http://example.org`. Without items it has one `make_item()`.
- `make_instance( string $type, array $settings = [], ?string $id = null, bool $save = false ): Channel_Instance`
  is an enabled instance. The id is the type unless given. `$save` stores it.

## Markup (`WPUN_Markup`)

- `dom( string $html ): DOMXPath` parses markup. Invalid markup raises no warning, and the libxml setting is put
  back.
- `render( callable $callback ): string` returns what the callback prints. The buffer is closed when the
  callback throws.
- `assertMarkupHas( string $needle, string $html, string $message = '' )` and `assertMarkupLacks( ... )` check
  for a node or a piece of text. A needle that starts with `/`, `(` or `./` is XPath, anything else is text.
  A failure shows your message, the needle, the first match (for `assertMarkupLacks`) cut to 300 characters and
  the page size, never the page.

## Environment (`WPUN_Environment`)

- `become_admin(): int` sets a plain administrator as the current user and returns the id.
- `fixture_plugin( string $file, string $version, string $name = 'Fixture Plugin' )` writes a plugin under the
  plugins directory. `tear_down()` removes it. Writing the same file again changes the version.
- `remove_dir( string $dir )` deletes a fixture directory and the files in it.
- `require_multisite()` and `require_single_site()` skip the test on the wrong kind of site.
- `save_network_settings( array $overrides = [] )` stores network settings and the email instance.

## Add a helper or keep a local one

Add a helper to a trait when two test files need the same thing in the same shape. Put it in the trait that
fits, give it a test in `tests/wp/test-harness.php` and a line here.

Keep a private helper in the test file when it needs a different shape, for example a notification with a
different site name or a different way to run the update check. Say why in its doc block. Do not give a local
helper the name of a shared one: PHP stops with a fatal error.
