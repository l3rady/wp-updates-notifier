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
cp "$here/wp-updates-notifier.php" "$here/uninstall.php" "$here/readme.txt" "$work/"

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

# Stable tag: replaced, once, nothing else touched. 9.9.9 is never the real tag, so
# this also passes on the Release PR's branch, where the tag is already set.
sh "$work/bin/set-stable-tag.sh" 9.9.9
[ "$(grep -c '^Stable tag: 9.9.9$' "$work/readme.txt")" = 1 ] || fail "stable tag not set"
[ "$(diff "$here/readme.txt" "$work/readme.txt" | grep -c '^>')" = 1 ] || fail "readme.txt changed in more than one line"
echo "ok: stable tag"

# The commit request: an existing file is added with its contents in base64, a missing one is deleted, and the
# branch, expected head and message are passed through.
cp "$here/bin/commit-payload.php" "$work/bin/"
(
	cd "$work"
	php bin/commit-payload.php owner/repo release-branch abc123 "chore: a message" readme.txt gone.txt > payload.json
	php -r '
		$p = json_decode( file_get_contents( "payload.json" ), true );
		$i = $p["variables"]["input"];
		$ok = false !== strpos( $p["query"], "createCommitOnBranch" )
			&& "owner/repo" === $i["branch"]["repositoryNameWithOwner"]
			&& "release-branch" === $i["branch"]["branchName"]
			&& "abc123" === $i["expectedHeadOid"]
			&& "chore: a message" === $i["message"]["headline"]
			&& "readme.txt" === $i["fileChanges"]["additions"][0]["path"]
			&& file_get_contents( "readme.txt" ) === base64_decode( $i["fileChanges"]["additions"][0]["contents"] )
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

echo "OK"
