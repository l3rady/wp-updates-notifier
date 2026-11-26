#!/bin/sh
# Tests tests/lib/suite.sh through the real run.sh scripts, with a stub docker: the compose project name carries the
# checkout token, a second run in one checkout fails at once (and names the suite that holds the lock) without calling
# docker, suites that share tests/e2e share the lock, a lock left by a killed run is taken over, the lock is released
# after a pass, a failure and a kept site, and a kept site prints the command that removes it. Run by
# `make test-browser-suite`; it needs only sh, cksum and kill.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
work="$(mktemp -d)"
holder=''
cleanup() {
	[ -z "$holder" ] || kill "$holder" 2>/dev/null || true
	rm -rf "$work"
}
trap cleanup EXIT

# The scripts run from the repository root and write build/ there: use a scratch copy of the layout.
repo="$work/repo"
for f in tests/lib/suite.sh tests/e2e/run.sh tests/flows/run.sh tests/dast/run.sh; do
	mkdir -p "$repo/$(dirname "$f")"
	cp "$here/$f" "$repo/$f"
done
mkdir -p "$work/bin"

# A stub docker: records its arguments, and exits with 5 for a command that matches STUB_FAIL.
cat > "$work/bin/docker" <<'STUB'
#!/bin/sh
echo "$*" >> "$STUB_LOG"
case "${STUB_FAIL:-}" in
'') ;;
*) case "$*" in *"$STUB_FAIL"*) exit 5 ;; esac ;;
esac
exit 0
STUB
chmod +x "$work/bin/docker"
log="$work/docker.log"

# run SCRIPT: runs the script in the scratch repository with the stub on the PATH; the output is in $work/out.
run() {
	_s="$1"
	shift
	( cd "$repo" && PATH="$work/bin:$PATH" STUB_LOG="$log" sh "$_s" "$@" ) > "$work/out" 2>&1
}

# A process that is alive, to hold a lock, and a PID that is gone.
sleep 300 &
holder=$!
sleep 0 &
gone=$!
wait "$gone"

lock() { printf '%s\n' "$2" > "$repo/build/locks/$1/suite"; printf '%s\n' "$3" > "$repo/build/locks/$1/pid"; }
mklock() { mkdir -p "$repo/build/locks/$1"; lock "$1" "$2" "$3"; }

# ----- The project name -----

expected="$(cd "$repo" && printf '%s' "$(pwd -P)" | cksum | tr -d ' ' | cut -c1-8)"
grep -Fq "printf '%s' \"\$(CURDIR)\" | cksum | tr -d ' ' | cut -c1-8" "$here/Makefile" || fail "the Makefile formula of the token changed: change tests/lib/suite.sh too"

: > "$log"
( unset WPUN_TOKEN; run tests/e2e/run.sh ) || fail "a11y failed: $(cat "$work/out")"
grep -Fq -- "-p wpun-e2e-$expected -f tests/e2e/compose.yml" "$log" || fail "no project wpun-e2e-$expected (token computed from the path): $(head -n 3 "$log")"
! grep -qv -- "-p wpun-e2e-$expected " "$log" || fail "a docker call without the project name"

: > "$log"
( WPUN_TOKEN=12345678 run tests/e2e/run.sh ) || fail "a11y failed"
grep -Fq -- "-p wpun-e2e-12345678 " "$log" || fail "WPUN_TOKEN is not used for the project name"

: > "$log"
( WPUN_TOKEN=12345678 FLOWS_MULTISITE=1 run tests/flows/run.sh ) || fail "e2e-ms failed"
grep -Fq -- "-p wpun-flows-ms-12345678 -f tests/flows/compose.yml -f tests/flows/compose.multisite.yml" "$log" || fail "e2e-ms project or files wrong: $(head -n 2 "$log")"
: > "$log"
( WPUN_TOKEN=12345678 run tests/flows/run.sh ) || fail "e2e failed"
grep -Fq -- "-p wpun-flows-12345678 -f tests/flows/compose.yml " "$log" || fail "e2e project wrong"

: > "$log"
( WPUN_TOKEN=12345678 run tests/dast/run.sh ) || fail "dast failed"
grep -Fq -- "-p wpun-dast-12345678 -f tests/dast/compose.yml" "$log" || fail "dast project wrong"

# ----- The lock -----

[ -d "$repo/build/locks" ] || fail "no build/locks"
[ -z "$(ls "$repo/build/locks")" ] || fail "a lock is left after a pass: $(ls "$repo/build/locks")"

# A live lock: the second run fails at once, names the suite and the PID, and does not call docker.
mklock e2e 'make a11y' "$holder"
: > "$log"
status=0
run tests/e2e/run.sh || status=$?
[ "$status" -ne 0 ] || fail "a second a11y run passed beside a live lock"
grep -Fq "make a11y cannot start: make a11y is already running in this checkout (pid $holder)." "$work/out" || fail "wrong message: $(cat "$work/out")"
[ ! -s "$log" ] || fail "docker was called by a run that did not get the lock"
[ "$(cat "$repo/build/locks/e2e/pid")" = "$holder" ] || fail "the failed run changed the lock"

# make e2e (flows) is blocked by the a11y lock, and make e2e-ms and make dast say so by their own name.
status=0
run tests/flows/run.sh || status=$?
[ "$status" -ne 0 ] || fail "make e2e ran beside make a11y"
grep -Fq "make e2e cannot start: make a11y is already running" "$work/out" || fail "wrong message for e2e: $(cat "$work/out")"
status=0
FLOWS_MULTISITE=1 run tests/flows/run.sh || status=$?
[ "$status" -ne 0 ] || fail "make e2e-ms ran beside make a11y"
grep -Fq "make e2e-ms cannot start" "$work/out" || fail "wrong message for e2e-ms: $(cat "$work/out")"
[ ! -s "$log" ] || fail "docker was called by a blocked run"

# The dast lock is its own: it does not block on a11y, and it blocks a second dast.
run tests/dast/run.sh || fail "dast was blocked by the a11y lock"
mklock dast 'make dast' "$holder"
status=0
run tests/dast/run.sh || status=$?
[ "$status" -ne 0 ] || fail "a second dast run passed"
grep -Fq "make dast cannot start: make dast is already running" "$work/out" || fail "wrong message for dast: $(cat "$work/out")"
rm -rf "$repo/build/locks/dast"

# A lock from a killed run (its PID is gone) is taken over, and released at the end.
mklock e2e 'make a11y' "$gone"
: > "$log"
run tests/flows/run.sh || fail "a stale lock blocked the run: $(cat "$work/out")"
[ -s "$log" ] || fail "the run did not call docker"
[ ! -e "$repo/build/locks/e2e" ] || fail "the lock is left after the run that took it over"

# A lock with no pid file (a run killed between mkdir and the write) is stale after a short wait.
mkdir -p "$repo/build/locks/e2e"
run tests/e2e/run.sh || fail "a lock without a pid blocked the run: $(cat "$work/out")"

# The lock is released after a failure, and the project is removed.
: > "$log"
status=0
STUB_FAIL='run --rm -T playwright' run tests/e2e/run.sh || status=$?
[ "$status" -eq 5 ] || fail "a failing Playwright run gave status $status, not 5"
[ ! -e "$repo/build/locks/e2e" ] || fail "the lock is left after a failure"
grep -q ' down --volumes' "$log" || fail "the project was not removed after a failure"

# A killed run (TERM) releases the lock and removes the project.
: > "$log"
cat > "$work/bin/docker" <<'STUB'
#!/bin/sh
echo "$*" >> "$STUB_LOG"
case "$*" in *"run --rm -T playwright"*) echo "$$" > "$STUB_LOG.pid"; exec sleep 30 ;; esac
exit 0
STUB
( cd "$repo" && PATH="$work/bin:$PATH" STUB_LOG="$log" sh tests/e2e/run.sh > "$work/out" 2>&1 ) &
runner=$!
i=0
while ! grep -q 'run --rm -T playwright' "$log" 2>/dev/null; do
	i=$((i + 1))
	[ "$i" -lt 100 ] || fail "the run did not reach Playwright"
	sleep 0.1 2>/dev/null || sleep 1
done
[ -d "$repo/build/locks/e2e" ] || fail "no lock while the run is going"
# The shell runs its trap when the foreground command ends, so end the stub's sleep after the signal.
kill -TERM "$runner"
kill "$(cat "$log.pid")"
wait "$runner" 2>/dev/null || true
i=0
while [ -e "$repo/build/locks/e2e" ] && [ "$i" -lt 100 ]; do i=$((i + 1)); sleep 0.1 2>/dev/null || sleep 1; done
[ ! -e "$repo/build/locks/e2e" ] || fail "the lock is left after TERM"

# Restore the plain stub.
cat > "$work/bin/docker" <<'STUB'
#!/bin/sh
echo "$*" >> "$STUB_LOG"
exit 0
STUB
chmod +x "$work/bin/docker"

# ----- A kept site -----

: > "$log"
( WPUN_TOKEN=12345678 E2E_KEEP=1 run tests/e2e/run.sh ) || fail "a kept a11y run failed"
grep -Fxq "The site is still up. Remove it with: docker compose -p wpun-e2e-12345678 -f tests/e2e/compose.yml down -v" "$work/out" || fail "no down command: $(cat "$work/out")"
[ "$(grep -c ' down --volumes' "$log")" -eq 1 ] || fail "a kept run removed the site at the end (only the start may)"
[ ! -e "$repo/build/locks/e2e" ] || fail "the lock is left after a kept run"

( WPUN_TOKEN=12345678 E2E_KEEP=1 FLOWS_MULTISITE=1 run tests/flows/run.sh ) || fail "a kept e2e-ms run failed"
grep -Fxq "The site is still up. Remove it with: docker compose -p wpun-flows-ms-12345678 -f tests/flows/compose.yml -f tests/flows/compose.multisite.yml down -v" "$work/out" || fail "no multisite down command: $(cat "$work/out")"

( WPUN_TOKEN=12345678 DAST_KEEP=1 run tests/dast/run.sh ) || fail "a kept dast run failed"
grep -Fxq "The site is still up. Remove it with: docker compose -p wpun-dast-12345678 -f tests/dast/compose.yml down -v" "$work/out" || fail "no dast down command: $(cat "$work/out")"

echo "ok: browser suite project names and locks"
