# Shared by the run.sh of every browser suite (tests/e2e, tests/flows, tests/flows/upgrade, tests/dast,
# tests/screenshots, tests/blueprint-smoke, tests/e2e/docs). Source it from the repository root, with `set -eu` on:
#
#   . tests/lib/suite.sh
#   SUITE_FILES='-f tests/e2e/compose.yml'
#   suite_start e2e 'make a11y' wpun-e2e "${E2E_KEEP:-}"
#
# It gives the run two things.
#
# 1. A project name that is the base name plus the checkout token (SUITE_PROJECT, for example wpun-e2e-12345678). The
#    token is the one the Makefile exports as WPUN_TOKEN: 8 digits from the checkout's path. Two worktrees get two
#    projects, so one never removes the other's site. A script run by hand computes the same token.
# 2. A lock, build/locks/<lock>, for the files the suites of one checkout share: every suite that mounts tests/e2e
#    shares the lock "e2e" (they share its node_modules, and the a11y run deletes its reports), and the DAST scan has
#    "dast". A second run in the same checkout fails at once, before any docker call.
#
# The lock is a directory, because mkdir either creates it or fails, in one step. It holds the file "suite" (the make
# command that owns it) and the file "pid" (the PID of the run.sh). The pid file is written to a temporary name and
# renamed, so a reader sees all of it or none of it. A lock whose PID is gone belongs to a killed run and is taken
# over. The window between mkdir and the rename is a few microseconds, so a lock without a pid file is given two
# seconds to get one before it counts as stale.
#
# The script defines teardown() (it removes its compose project). Its EXIT trap runs it, unless the keep argument is
# not empty, and always releases the lock. INT and TERM exit, so the EXIT trap runs once. A kept site stays up, and
# the lock does not: the next run removes the kept site first. A kept run prints the command that removes it.

suite_token() {
	if [ -z "${WPUN_TOKEN:-}" ]; then
		# The same formula as the Makefile (its CURDIR is the physical path).
		WPUN_TOKEN="$(printf '%s' "$(pwd -P)" | cksum | tr -d ' ' | cut -c1-8)"
	fi
	export WPUN_TOKEN
}

# Prints the PID in the lock, or nothing when the lock has none after two seconds.
suite_lock_pid() {
	_n=0
	while :; do
		if [ -f "$SUITE_LOCK_DIR/pid" ]; then
			_pid="$(cat "$SUITE_LOCK_DIR/pid" 2>/dev/null || true)"
			[ -z "$_pid" ] || { printf '%s' "$_pid"; return 0; }
		fi
		[ "$_n" -lt 2 ] || return 0
		_n=$((_n + 1))
		sleep 1
	done
}

suite_lock_fail() {
	_owner="$(cat "$SUITE_LOCK_DIR/suite" 2>/dev/null || true)"
	[ -n "$_owner" ] || _owner='another browser suite'
	echo "$SUITE_LABEL cannot start: $_owner is already running in this checkout (pid ${1:-unknown}). Wait for it, or run it in another worktree." >&2
	exit 1
}

suite_lock_take() {
	mkdir -p build/locks
	if mkdir "$SUITE_LOCK_DIR" 2>/dev/null; then
		:
	else
		_pid="$(suite_lock_pid)"
		if [ -n "$_pid" ] && kill -0 "$_pid" 2>/dev/null; then
			suite_lock_fail "$_pid"
		fi
		# A killed run left it. Take it over; losing the second mkdir means another run just did.
		rm -rf "$SUITE_LOCK_DIR"
		mkdir "$SUITE_LOCK_DIR" 2>/dev/null || suite_lock_fail "${_pid:-unknown}"
	fi
	printf '%s\n' "$SUITE_LABEL" > "$SUITE_LOCK_DIR/suite"
	printf '%s\n' "$$" > "$SUITE_LOCK_DIR/pid.tmp"
	mv "$SUITE_LOCK_DIR/pid.tmp" "$SUITE_LOCK_DIR/pid"
}

suite_lock_release() {
	# Only the owner removes it: a run that failed to take it must not remove the other run's lock.
	if [ "$(cat "$SUITE_LOCK_DIR/pid" 2>/dev/null || true)" = "$$" ]; then
		rm -rf "$SUITE_LOCK_DIR"
	fi
}

suite_exit() {
	if [ -n "$SUITE_KEEP" ]; then
		echo "The site is still up. Remove it with: docker compose -p $SUITE_PROJECT $SUITE_FILES down -v"
	else
		teardown
	fi
	suite_lock_release
}

# suite_start LOCK LABEL BASE KEEP: LABEL is the make command, as the message names it.
suite_start() {
	SUITE_LABEL="$2"
	SUITE_KEEP="${4:-}"
	SUITE_LOCK_DIR="build/locks/$1"
	suite_token
	SUITE_PROJECT="$3-$WPUN_TOKEN"
	SUITE_FILES="${SUITE_FILES:-}"
	suite_lock_take
	trap suite_exit EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
}
