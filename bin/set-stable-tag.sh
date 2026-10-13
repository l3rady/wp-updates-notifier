#!/bin/sh
# Sets "Stable tag" in readme.txt to the given version. WordPress.org serves the
# plugin from the tag with this name, so it must equal the released version.
# Run by the release workflow on the Release PR's branch; readme.txt is parsed by
# WordPress.org, so it carries no release-please markers.
set -eu

version="${1:?usage: set-stable-tag.sh <version>}"
file="$(dirname "$0")/../readme.txt"

sed -E -i "s/^(Stable tag:[[:space:]]*).*/\1${version}/" "$file"
