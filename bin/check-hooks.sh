#!/bin/sh
# Every hook the plugin fires must have a doc block with @since and an sc_wpun_ name.
# docs/developers/hooks.md must be what the doc blocks generate (`make hook-docs` writes it).
# The checker itself is tested against known-bad code so that it cannot pass by doing nothing.
# Run with `make check-hooks`.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

php bin/check-hooks.php --reference=docs/developers/hooks.md wp-updates-notifier.php includes modules
sh tests/bin/test-check-hooks.sh
