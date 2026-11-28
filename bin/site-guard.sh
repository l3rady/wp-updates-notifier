#!/bin/sh
# Stops a local site target (`make up`, `site-setup`, `wp`, `wp-ms`, `down`, `clean`) in a git worktree. There is one
# local site, whichever directory the target runs from: `make up` in a worktree re-creates its containers with the
# worktree's files mounted, so the site silently serves another branch, and `make down` or `make clean` stops or wipes
# it. ALLOW_WORKTREE_SITE=1 lets the target run anyway. A worktree is what bin/git-mount.sh sees as one. The argument
# is the target's name, for the message. Tested by tests/bin/test-git-mount.sh.
set -eu

[ "${ALLOW_WORKTREE_SITE:-}" = 1 ] && exit 0
[ -n "$(sh "$(dirname "$0")/git-mount.sh")" ] || exit 0

echo "make ${1:-this target} acts on the one local site, and this is a git worktree: run it in the main checkout, or set ALLOW_WORKTREE_SITE=1 to run it here." >&2
exit 1
