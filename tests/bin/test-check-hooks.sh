#!/bin/sh
# Tests the hook checker and the hook reference against small known-good and known-bad code, so that the
# rule for hooks the plugin only listens to cannot pass the real scan by finding nothing.
# Run by `make check-hooks`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$here"
fixtures=tests/bin/check-hooks
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# A fired, documented hook passes, and so does a listener whose name a fired dynamic hook makes.
php bin/check-hooks.php "$fixtures/good" > /dev/null || fail "the good fixture was reported"

# A listener of a public hook that nothing fires needs a doc block with @hook-listened and @since.
if php bin/check-hooks.php "$fixtures/unlisted" 2> "$work/report" > /dev/null; then
	fail "an undocumented listened-only hook passed"
fi
grep -q "hooks.php:2 add_action( 'sc_wpun_callable' ): listened to but never fired" "$work/report" || fail "the undocumented listened-only hook was not reported on its line"

php bin/check-hooks.php "$fixtures/listed" > /dev/null || fail "a documented listened-only hook was reported"

# It is in the reference, under "Actions you can fire", from its doc block.
php -r 'require "bin/hooks-scan.php"; echo wpun_render_hook_docs( array( $argv[1] ) );' "$fixtures/listed" > "$work/reference"
for expected in '## Actions you can fire' '### `sc_wpun_callable`' 'Does the callable thing.' '| `$frequency` | `string` |' 'Handled in `tests/bin/check-hooks/listed/hooks.php`.'; do
	grep -qF -- "$expected" "$work/reference" || fail "the reference lacks: $expected"
done
grep -q 'hook-listened' "$work/reference" && fail "the @hook-listened tag leaked into the reference"

# The tag on a hook that is fired would document it twice.
if php bin/check-hooks.php "$fixtures/fired-listed" 2> "$work/report" > /dev/null; then
	fail "@hook-listened on a fired hook passed"
fi
grep -q "hooks.php:16 add_action( 'sc_wpun_thing' ): @hook-listened is for hooks that are never fired" "$work/report" || fail "@hook-listened on a fired hook was not reported on its line"

echo "ok: hook checker"
