#!/bin/sh
# Creates the signed, annotated release tag <tag> at <sha> and checks the
# signature against .github/release-signers. Run by the release job in
# .github/workflows/release-please.yml, in a checkout of <sha>; it does not push.
#
# The v* tag ruleset forbids moving or deleting a release tag, so a re-run must
# not need to: when <tag> already exists on origin, it is fetched and only
# checked (right commit, valid signature). Anything else fails.
#
# Needs: git configured to sign with SSH (gpg.format ssh, user.signingkey,
# user.name, user.email). Usage: sign-release-tag.sh <tag> <sha>
set -eu

tag="${1:?usage: sign-release-tag.sh <tag> <sha>}"
sha="${2:?usage: sign-release-tag.sh <tag> <sha>}"
root="$(cd "$(dirname "$0")/.." && pwd)"
signers="$root/.github/release-signers"

fail() { echo "FAIL: $*" >&2; exit 1; }

grep -q '^[^#]' "$signers" 2>/dev/null || fail "no release signing key in .github/release-signers"

verify() {
	[ "$(git -C "$root" cat-file -t "refs/tags/$tag")" = tag ] || fail "$tag is not an annotated tag"
	[ "$(git -C "$root" rev-parse "refs/tags/$tag^{commit}")" = "$sha" ] || fail "$tag does not point at $sha"
	git -C "$root" -c gpg.ssh.allowedSignersFile="$signers" tag -v "$tag" || fail "$tag has no valid signature from .github/release-signers"
}

# Exit code 2 means "no such ref"; anything else non-zero is an error, not a missing tag.
found=0
git -C "$root" ls-remote --exit-code --tags origin "refs/tags/$tag" > /dev/null || found=$?
if [ "$found" = 0 ]; then
	git -C "$root" fetch --no-tags origin "refs/tags/$tag:refs/tags/$tag"
	verify
	echo "exists: $tag"
	exit 0
fi
[ "$found" = 2 ] || fail "could not list the tags on origin"

git -C "$root" tag -s -m "WP Updates Notifier ${tag#v}" "$tag" "$sha"
verify
echo "created: $tag"
