#!/bin/sh
# Prints the docker run option that mounts the shared git directory of a git worktree, and nothing in a normal
# checkout (or when git is missing or this is not a repository). In a worktree `.git` is a file that points at
# <main checkout>/.git/worktrees/<name>, outside the directory the containers mount, so git fails in them. The
# Makefile puts the output in GIT_MOUNT (read-only) and GIT_MOUNT_RW (with the argument `rw`, for docs-deploy, whose
# mike commits). A path with a space is not supported. Tested by tests/bin/test-git-mount.sh.

common="$(git rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" || exit 0
top="$(git rev-parse --show-toplevel 2>/dev/null)" || exit 0
[ -n "$common" ] && [ -n "$top" ] || exit 0
[ "$common" = "$top/.git" ] && exit 0

mode=ro
[ "${1:-}" = rw ] && mode=rw
if [ "$mode" = rw ]; then
	printf '%s\n' "-v $common:$common"
else
	printf '%s\n' "-v $common:$common:ro"
fi
