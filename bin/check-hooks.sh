#!/bin/sh
# Every hook the plugin fires must have a doc block with @since and an sc_wpun_ name.
# Run with `make check-hooks`.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

php bin/check-hooks.php wp-updates-notifier.php includes modules
