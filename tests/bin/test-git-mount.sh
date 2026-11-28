#!/bin/sh
# Tests bin/git-mount.sh (the docker option that mounts the git directory of a worktree), bin/site-guard.sh (the local
# site targets refuse to run in a worktree) and bin/secrets-history.sh
# (a gitleaks scan of 0 commits in a range that has some is a failure) with throwaway repositories and a stub gitleaks.
# Run by `make test-git-mount`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
# The php image marks /app only: the throwaway repositories are owned by the same user, so git accepts them.

main="$work/main"
git init -q "$main"
git -C "$main" config user.email test@example.com
git -C "$main" config user.name Test
git -C "$main" config commit.gpgsign false
git -C "$main" commit -q --allow-empty -m one
git -C "$main" commit -q --allow-empty -m two
git -C "$main" worktree add -q --detach "$work/wt"

mount() { (cd "$1" && sh "$here/bin/git-mount.sh" ${2:+"$2"}); }

common="$(cd "$main/.git" && pwd -P)"
[ -z "$(mount "$main")" ] || fail "main checkout prints '$(mount "$main")'"
[ -z "$(mount "$main" rw)" ] || fail "main checkout (rw) prints something"
# A subdirectory of a normal checkout is still a normal checkout.
mkdir "$main/sub"
[ -z "$(mount "$main/sub")" ] || fail "subdirectory of the main checkout prints something"

want="-v $common:$common:ro"
[ "$(mount "$work/wt")" = "$want" ] || fail "worktree prints '$(mount "$work/wt")', wanted '$want'"
want="-v $common:$common"
[ "$(mount "$work/wt" rw)" = "$want" ] || fail "worktree (rw) prints '$(mount "$work/wt" rw)', wanted '$want'"

mkdir "$work/plain"
[ -z "$(cd "$work/plain" && GIT_CEILING_DIRECTORIES="$work" sh "$here/bin/git-mount.sh")" ] || fail "outside a repository prints something"
echo "ok: git-mount.sh"

guard() { (cd "$1" && sh "$here/bin/site-guard.sh" up >"$work/out" 2>&1); }

guard "$main" || fail "site guard stops the main checkout"
guard "$main/sub" || fail "site guard stops a subdirectory of the main checkout"
if guard "$work/wt"; then fail "site guard lets a worktree run"; fi
grep -q 'make up acts on the one local site' "$work/out" || fail "site guard does not say why: $(cat "$work/out")"
grep -q 'ALLOW_WORKTREE_SITE=1' "$work/out" || fail "site guard does not name ALLOW_WORKTREE_SITE=1"
ALLOW_WORKTREE_SITE=1 guard "$work/wt" || fail "ALLOW_WORKTREE_SITE=1 does not let a worktree run"
if ALLOW_WORKTREE_SITE=0 guard "$work/wt"; then fail "ALLOW_WORKTREE_SITE=0 lets a worktree run"; fi
(cd "$work/plain" && GIT_CEILING_DIRECTORIES="$work" sh "$here/bin/site-guard.sh" up) || fail "site guard stops a directory outside a repository"
echo "ok: site-guard.sh"

# secrets-history.sh with a stub that prints what gitleaks prints.
stub() {
	printf '#!/bin/sh\necho "%s" >&2\nexit %s\n' "$1" "$2" > "$work/gitleaks"
	chmod +x "$work/gitleaks"
}
run() { (cd "$main" && sh "$here/bin/secrets-history.sh" "$1" "$work/gitleaks" >"$work/out" 2>&1); }

stub '10:00AM INF 2 commits scanned.' 0
run '' || fail "2 commits scanned of 2 should pass"
stub '10:00AM INF 1 commits scanned.' 0
run 'HEAD~1..HEAD' || fail "a range of 1 commit with 1 scanned should pass"
stub '10:00AM INF 0 commits scanned.' 0
if run '' ; then fail "0 commits scanned of all history should fail"; fi
grep -q 'not a pass' "$work/out" || fail "no explanation for the 0-commit failure"
if run 'HEAD~1..HEAD'; then fail "0 commits scanned of a range of 1 should fail"; fi
stub 'no summary line at all' 0
if run ''; then fail "a missing summary should fail"; fi
# An empty range is not a failure: nothing was there to scan.
stub '10:00AM INF 0 commits scanned.' 0
run 'HEAD..HEAD' || fail "0 commits scanned of an empty range should pass"
# gitleaks' own exit code is kept, and its output is shown.
stub '10:00AM INF 2 commits scanned.' 3
status=0
run '' || status=$?
[ "$status" -eq 3 ] || fail "exit code $status, wanted 3"
grep -q '2 commits scanned' "$work/out" || fail "output of gitleaks not shown"
# A range git cannot resolve fails closed.
stub '10:00AM INF 2 commits scanned.' 0
if run 'nope..HEAD'; then fail "an unknown range should fail"; fi
echo "ok: secrets-history.sh"
