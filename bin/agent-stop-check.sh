#!/bin/sh
# The Claude Code Stop hook (.claude/settings.json). At the end of a turn that changed plugin code it runs the quick
# checks; a failure goes back to Claude (exit 2, stderr) so it is fixed before the turn ends. It costs nothing when no
# plugin code changed, and skips the steps that already passed on the same tree (bin/check.sh stamps). It never fails for
# environmental reasons: no Docker or no vendor/ is a one-line note and exit 0.
#
# Reads the hook input (JSON) on stdin. STOP_CHECK_DOCKER is the command that tells whether Docker runs (default
# `docker info`); CHECK_MAKE passes through to bin/check.sh. The self-test is tests/bin/test-agent-stop-check.sh.
set -eu

cd "$(dirname "$0")/.."

# Claude Code sets stop_hook_active when the turn is already continuing after a block: never loop.
if tr -d ' \t\r\n' | grep -q '"stop_hook_active":true'; then
	exit 0
fi

# Plugin code changed, untracked files included (the plugin's runtime paths, as in .distignore).
status="$(git status --porcelain 2>/dev/null)" || exit 0
printf '%s\n' "$status" | grep -Eq '^.. "?(includes/|modules/|languages/|wp-updates-notifier\.php|uninstall\.php)' || exit 0

if [ ! -f vendor/autoload.php ]; then
	echo 'agent-stop-check: skipped, run make deps first'
	exit 0
fi
if ! ${STOP_CHECK_DOCKER:-docker info} > /dev/null 2>&1; then
	echo 'agent-stop-check: skipped, Docker is not running'
	exit 0
fi

out="$(mktemp)"
trap 'rm -f "$out"' EXIT
if CHECK_SKIP_PASSED=1 sh bin/check.sh lint check-hooks check-public-api check-translations > "$out" 2>&1; then
	cat "$out"
	exit 0
fi
{
	echo 'The quick checks failed on the changed plugin code. Fix this before finishing (the full log is named below):'
	cat "$out"
} >&2
exit 2
