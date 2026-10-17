#!/bin/sh
# Write docs/developers/hooks.md from the doc blocks of the hooks the plugin fires.
# Run with `make hook-docs`.
set -eu

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

php bin/hook-docs.php wp-updates-notifier.php includes modules
