#!/bin/sh
# Tests the map of changed files to CI jobs (bin/ci-changes.sh) and that it agrees with the workflows, so the two
# cannot drift apart. Only the gate jobs are required checks: a job left out of its gate's `needs` would never block a
# merge. Run with `make test-ci-changes`; it needs only sh, awk, sed and grep.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$here"
script=bin/ci-changes.sh
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Jobs that do not use the map: they always run, or never run on a pull request. Each is still in its gate's needs.
exempt='workflows secrets images wordpress-trunk deploy'
workflows='ci lint docs security'
gate_of() {
	case "$1" in
		ci) echo ci-gate ;;
		lint) echo lint-gate ;;
		docs) echo docs-gate ;;
		security) echo security-gate ;;
	esac
}

# ----- The map's output -----

all_jobs="$(sed -n "s/^ALL_JOBS='\(.*\)'$/\1/p" "$script")"
[ -n "$all_jobs" ] || fail "no ALL_JOBS line in $script"
php_set='phpstan rector smoke wordpress coverage dist dist-repro plugin-check a11y e2e phpcs sast taint'

# The job ids of a "jobs=[...]" line, sorted, one line.
ids() { echo "$1" | sed -n 's/^jobs=//p' | tr -d '[]"' | tr ',' '\n' | sort | tr '\n' ' ' | sed 's/ $//'; }
sorted() { echo "$1" | tr ' ' '\n' | grep -v '^$' | sort | tr '\n' ' ' | sed 's/ $//'; }

# check NAME "FILES" "EXPECTED JOBS" EXPECTED_FULL [ENV=value ...]
check() {
	name="$1"; files="$2"; want="$(sorted "$3")"; want_full="$4"; shift 4
	out="$(printf '%s\n' "$files" | env -u CI_EVENT -u CI_HEAD_REF -u GITHUB_OUTPUT "$@" sh "$script" - 2> /dev/null)" || fail "$name: the script failed"
	got="$(ids "$out")"
	[ "$got" = "$want" ] || fail "$name: jobs are '$got', expected '$want'"
	got_full="$(echo "$out" | sed -n 's/^full=//p')"
	[ "$got_full" = "$want_full" ] || fail "$name: full is '$got_full', expected '$want_full'"
}

check "Playwright bump" 'tests/e2e/package.json
tests/e2e/package-lock.json' 'a11y docs e2e dependencies dependency-review' false
check "docs only" 'docs/users/x.md' 'docs links' false
check "unmatched path" 'newdir/file.txt' "$all_jobs" false
check "an unmatched path among matched ones" 'docs/users/x.md
newdir/file.txt' "$all_jobs" false
check "workflow" '.github/workflows/ci.yml' "$all_jobs" false
check "Makefile" 'Makefile' "$all_jobs" false
check "the script itself" 'bin/ci-changes.sh' "$all_jobs" false
check "plugin PHP" 'includes/class-plugin.php' "$php_set" false
check "WordPress tests" 'tests/wp/test-x.php' 'wordpress coverage phpcs smoke' false
check "WordPress.org assets" '.wordpress-org/screenshot-1.png' 'plugin-check' false
check "flow tests" 'tests/flows/specs/runs.spec.js' 'e2e' false
check "upgrade test" 'tests/flows/upgrade/specs/update-cli.spec.js' '' false
check "upgrade test PHP" 'tests/flows/upgrade/reset.php' 'phpcs' false
check "fake updates" '.wordpress-org/blueprint/fake-updates.php' 'phpcs smoke plugin-check e2e' false
check "local-only DAST" 'tests/dast/compose.yml' '' false
check "no changes" '' '' false
check "several rules apply" 'docs/developers/hooks.md
phpcs.xml.dist' 'phpcs docs links' false
check "push" 'docs/users/x.md' "$all_jobs" true CI_EVENT=push
check "schedule" 'docs/users/x.md' "$all_jobs" true CI_EVENT=schedule
check "plain pull request" 'docs/users/x.md' 'docs links' false CI_EVENT=pull_request CI_HEAD_REF=feature/x
check "Release PR" 'CHANGELOG.md' "$all_jobs" true CI_EVENT=pull_request CI_HEAD_REF=release-please--branches--main
echo "ok: map output"

# A push must not read the diff: HEAD^1 does not exist in a shallow checkout.
out="$(CI_EVENT=push sh "$script" HEAD^1 HEAD 2> /dev/null < /dev/null)" || fail "a push needed the diff"
[ "$(ids "$out")" = "$(sorted "$all_jobs")" ] || fail "a push without a diff did not run everything"

# $GITHUB_OUTPUT gets the same two lines.
printf 'docs/users/x.md\n' | GITHUB_OUTPUT="$work/output" sh "$script" - > /dev/null 2>&1
[ "$(cat "$work/output")" = 'jobs=["docs","links"]
full=false' ] || fail "GITHUB_OUTPUT does not hold the jobs and full lines"
echo "ok: GITHUB_OUTPUT"

# ----- The map against the workflows -----

# "workflow job" lines for a workflow file, from its jobs: section: the job ids, and for each job whether its `if`
# names the map ("mapped <id> <id in the contains() call>") and its needs line.
parse() {
	awk '
		/^jobs:/ { injobs = 1; next }
		injobs && /^[^ #]/ { injobs = 0 }
		!injobs { next }
		/^  [A-Za-z0-9_-]+:[ ]*$/ { job = $1; sub(/:$/, "", job); print "job", job; next }
		job != "" && /^    needs:/ { line = $0; sub(/^    needs:[ ]*/, "", line); print "needs", job, line; next }
		job != "" && /needs\.changes\.outputs\.jobs/ {
			line = $0
			sub(/.*needs\.changes\.outputs\.jobs\), \x27/, "", line)
			sub(/\x27.*/, "", line)
			print "mapped", job, line
		}
	' ".github/workflows/$1.yml"
}

mapped_all=""
for wf in $workflows; do
	gate="$(gate_of "$wf")"
	parse "$wf" > "$work/$wf.parsed"
	jobs="$(sed -n 's/^job //p' "$work/$wf.parsed")"
	echo "$jobs" | grep -qx "$gate" || fail "$wf.yml has no $gate job"
	echo "$jobs" | grep -qx changes || fail "$wf.yml has no changes job"

	# The gate needs every other job.
	gate_needs="$(sed -n "s/^needs $gate \[\(.*\)\]$/\1/p" "$work/$wf.parsed" | tr -d ',')"
	[ -n "$gate_needs" ] || fail "$gate needs must be a one-line flow list: needs: [changes, ...]"
	for job in $jobs; do
		[ "$job" != "$gate" ] || continue
		case " $gate_needs " in *" $job "*) ;; *) fail "$job is missing from the needs of $gate in $wf.yml" ;; esac
	done
	for job in $gate_needs; do
		echo "$jobs" | grep -qx "$job" || fail "$gate needs $job, which is not a job of $wf.yml"
		[ "$job" != "$gate" ] || fail "$gate needs itself"
	done

	# A job that uses the map needs the changes job and asks for its own id; any other job is on the exempt list.
	for job in $jobs; do
		[ "$job" != "$gate" ] && [ "$job" != changes ] || continue
		asked="$(sed -n "s/^mapped $job //p" "$work/$wf.parsed" | head -n 1)"
		if [ -n "$asked" ]; then
			[ "$asked" = "$job" ] || fail "$job in $wf.yml asks the map for '$asked'"
			sed -n "s/^needs $job //p" "$work/$wf.parsed" | grep -qx changes || fail "$job in $wf.yml uses the map without 'needs: changes'"
			mapped_all="$mapped_all $job"
		else
			case " $exempt " in *" $job "*) ;; *) fail "$job in $wf.yml does not use the map and is not on the exempt list in this test (add it to the map, or to the list if it is always on or never runs on a pull request)" ;; esac
		fi
	done
	echo "ok: $wf.yml and $gate"
done

# Job ids are unique across the workflows, as required checks match by name.
dups="$(for f in .github/workflows/*.yml; do awk '/^jobs:/ { j = 1; next } j && /^[^ #]/ { j = 0 } j && /^  [A-Za-z0-9_-]+:[ ]*$/ { print $1 }' "$f"; done | grep -v '^changes:$' | sort | uniq -d)"
[ -z "$dups" ] || fail "job ids used by more than one workflow: $dups"

# The map's job list is exactly the jobs that use it, and every rule names jobs from the list.
[ "$(sorted "$mapped_all")" = "$(sorted "$all_jobs")" ] || fail "ALL_JOBS ($(sorted "$all_jobs")) differs from the workflow jobs that use the map ($(sorted "$mapped_all"))"
table="$(sed -n "/^	cat <<'RULES'$/,/^RULES$/p" "$script" | grep -Ev "^[[:space:]]*(#|$)|RULES" | awk '{ for (i = 2; i <= NF; i++) print $i }' | sort -u)"
[ -n "$table" ] || fail "found no rules in $script"
for id in $table; do
	case "$id" in ALL|-) continue ;; esac
	case " $all_jobs " in *" $id "*) ;; *) fail "the rule table names '$id', which is not a job of the map" ;; esac
done
for id in $all_jobs; do
	echo "$table" | grep -qx "$id" || fail "no rule triggers $id"
done
echo "ok: the map and the workflows agree"
echo "OK"
