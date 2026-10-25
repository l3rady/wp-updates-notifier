#!/bin/sh
# Rehearses the scripts the release workflow runs on the Release PR's branch,
# on a throwaway copy of the repository files. Run with `make test-release`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/bin"
cp "$here"/bin/*.sh "$work/bin/"
cp "$here/wp-updates-notifier.php" "$here/uninstall.php" "$work/"

# Copyright: a single year becomes a range, a range is moved on, a same-year range collapses.
sh "$work/bin/update-copyright-year.sh" 2031
grep -q 'Copyright 2015-2031' "$work/wp-updates-notifier.php" || fail "range not set in the main file"
grep -q 'Copyright 2015-2031' "$work/uninstall.php" || fail "range not set in uninstall.php"
sh "$work/bin/update-copyright-year.sh" 2032
grep -q 'Copyright 2015-2032' "$work/uninstall.php" || fail "range not moved on"
grep -q '2031' "$work/uninstall.php" && fail "old end year left behind"
sed -E -i 's/Copyright 2015-2032/Copyright 2032/' "$work/uninstall.php"
sh "$work/bin/update-copyright-year.sh" 2032
grep -q 'Copyright 2032[^-]' "$work/uninstall.php" || fail "same-year range not collapsed"
echo "ok: copyright year"

# The commit request: an existing file is added with its contents in base64, a missing one is deleted, and the
# branch, expected head and message are passed through.
cp "$here/bin/commit-payload.php" "$work/bin/"
(
	cd "$work"
	php bin/commit-payload.php owner/repo release-branch abc123 "chore: a message" uninstall.php gone.txt > payload.json
	php -r '
		$p = json_decode( file_get_contents( "payload.json" ), true );
		$i = $p["variables"]["input"];
		$ok = false !== strpos( $p["query"], "createCommitOnBranch" )
			&& "owner/repo" === $i["branch"]["repositoryNameWithOwner"]
			&& "release-branch" === $i["branch"]["branchName"]
			&& "abc123" === $i["expectedHeadOid"]
			&& "chore: a message" === $i["message"]["headline"]
			&& "uninstall.php" === $i["fileChanges"]["additions"][0]["path"]
			&& file_get_contents( "uninstall.php" ) === base64_decode( $i["fileChanges"]["additions"][0]["contents"] )
			&& array( array( "path" => "gone.txt" ) ) === $i["fileChanges"]["deletions"];
		exit( $ok ? 0 : 1 );
	' || fail "commit payload is wrong"
	if php bin/commit-payload.php owner/repo branch abc123 2>/dev/null; then fail "commit payload without files was accepted"; fi
)
echo "ok: commit payload"

# The plugin header still has exactly one parseable Version line between the markers.
sed -n '/x-release-please-start-version/,/x-release-please-end/p' "$here/wp-updates-notifier.php" | grep -Eq '^ \* Version: [0-9]+\.[0-9]+\.[0-9]+$' || fail "no semver Version line between the release-please markers"
echo "ok: version markers"

# Hook documentation check: a documented hook passes; a missing @since, a wrong prefix and a missing doc block fail.
hooks="$work/hooks"
mkdir -p "$hooks/good" "$hooks/bad"
cp "$here/bin/check-hooks.php" "$here/bin/hooks-scan.php" "$work/bin/"
cat > "$hooks/good/ok.php" <<'PHP'
<?php
/**
 * Documented.
 *
 * @since 2.0.0
 */
do_action( 'sc_wpun_documented' );
/** This filter is documented in elsewhere.php */
$value = apply_filters( 'sc_wpun_other', 1 );
do_action( 'wp_version_check' );
PHP
php "$work/bin/check-hooks.php" "$hooks/good" >/dev/null || fail "documented hooks were rejected"
printf '<?php\n/** No version. */\ndo_action( %s );\n' "'sc_wpun_no_since'" > "$hooks/bad/a.php"
printf '<?php\n/** @since 2.0.0 */\ndo_action( %s );\n' "'other_prefix'" > "$hooks/bad/b.php"
printf '<?php\n$x = 1;\n\n\n\n\napply_filters( %s, $x );\n' "'sc_wpun_undocumented'" > "$hooks/bad/c.php"
for f in a b c; do
	if php "$work/bin/check-hooks.php" "$hooks/bad/$f.php" >/dev/null 2>&1; then fail "bad hook fixture $f was accepted"; fi
done
# The generated reference: a current file passes, a stale one fails.
php -r 'require $argv[1]; echo wpun_render_hook_docs( array( $argv[2] ) );' "$work/bin/hooks-scan.php" "$hooks/good" > "$hooks/hooks.md"
php "$work/bin/check-hooks.php" --reference="$hooks/hooks.md" "$hooks/good" >/dev/null || fail "a current hook reference was rejected"
echo "stale" >> "$hooks/hooks.md"
if php "$work/bin/check-hooks.php" --reference="$hooks/hooks.md" "$hooks/good" >/dev/null 2>&1; then fail "a stale hook reference was accepted"; fi
echo "ok: hook documentation check"

# Release tag: created signed at the given commit, re-runs only check an existing tag on origin, and a tag at the wrong
# commit, a signature from another key or an empty signers file fails. A throwaway key, repository and origin.
tags="$work/tags"
mkdir -p "$tags/repo/bin" "$tags/repo/.github"
ssh-keygen -q -t ed25519 -N '' -C release -f "$tags/key"
ssh-keygen -q -t ed25519 -N '' -C other -f "$tags/other"
cp "$here/bin/sign-release-tag.sh" "$tags/repo/bin/"
(
	cd "$tags/repo"
	git init -q -b main .
	git config user.name Release
	git config user.email release@example.com
	git config gpg.format ssh
	git config user.signingkey "$tags/key"
	git config commit.gpgsign false
	echo "release@example.com namespaces=\"git\" $(cat "$tags/key.pub")" > .github/release-signers
	git add . && git commit -qm one && first="$(git rev-parse HEAD)"
	git commit -qm two --allow-empty && second="$(git rev-parse HEAD)"
	git init -q --bare "$tags/origin.git" && git remote add origin "$tags/origin.git" && git push -q origin main

	sh bin/sign-release-tag.sh v9.9.9 "$first" | grep -qx 'created: v9.9.9' || fail "release tag not created"
	[ "$(git rev-parse 'v9.9.9^{commit}')" = "$first" ] || fail "release tag at the wrong commit"
	git push -q origin refs/tags/v9.9.9
	git tag -d v9.9.9 > /dev/null
	sh bin/sign-release-tag.sh v9.9.9 "$first" | grep -qx 'exists: v9.9.9' || fail "existing release tag not reused"
	git tag -d v9.9.9 > /dev/null
	if sh bin/sign-release-tag.sh v9.9.9 "$second" > /dev/null 2>&1; then fail "a release tag at another commit was accepted"; fi

	git config user.signingkey "$tags/other"
	if sh bin/sign-release-tag.sh v9.9.8 "$first" > /dev/null 2>&1; then fail "a tag signed by another key was accepted"; fi
	git tag -d v9.9.8 > /dev/null 2>&1 || true
	git config user.signingkey "$tags/key"
	echo '# no key yet' > .github/release-signers
	if sh bin/sign-release-tag.sh v9.9.7 "$first" > /dev/null 2>&1; then fail "an empty release-signers file was accepted"; fi
)
echo "ok: release tag"

echo "OK"
