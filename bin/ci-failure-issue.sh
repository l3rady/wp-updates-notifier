#!/bin/sh
# Opens, updates or closes the issue for a failed scheduled run. The report jobs of ci.yml, security.yml and docs.yml
# run it after their gate job. There is one open issue, labelled ci-failure, for each workflow and schedule, so a
# passing run of one schedule never closes the issue of another.
#
#   GATE_RESULT=failure FAILED='phpstan: failure' WORKFLOW=ci SCHEDULE='17 3 * * *' RUN_URL=... sh bin/ci-failure-issue.sh
#
# Environment (the workflow passes all of it from run data, never from a branch name or a commit message):
#   GATE_RESULT  the result of the gate job: failure opens or updates the issue, success closes it, anything else
#                (cancelled, skipped) does nothing
#   FAILED       the jobs that did not pass, for the comment
#   WORKFLOW     the workflow's name
#   SCHEDULE     the cron string of the schedule
#   RUN_URL      the address of the run
# gh reads GH_TOKEN and GH_REPO.
set -eu

: "${GATE_RESULT:?}" "${WORKFLOW:?}" "${SCHEDULE:?}" "${RUN_URL:?}"
failed="${FAILED:-}"
label=ci-failure
title="Scheduled $WORKFLOW run failed ($SCHEDULE)"

case "$GATE_RESULT" in
	failure | success) ;;
	*) echo "The gate result is '$GATE_RESULT': nothing to report."; exit 0 ;;
esac

# The number of the open issue with exactly this title, or nothing. gh's search is fuzzy, so the title is compared here.
find_issue() {
	list="$(gh issue list --state open --label "$label" --limit 100 --json number,title --jq '.[] | [.number, .title] | @tsv')" || return 1
	printf '%s\n' "$list" | TITLE="$title" awk -F '\t' '$2 == ENVIRON["TITLE"] { print $1; exit }'
}

number="$(find_issue)"

if [ "$GATE_RESULT" = success ]; then
	if [ -z "$number" ]; then
		echo "Passed, and no open issue: nothing to close."
		exit 0
	fi
	gh issue comment "$number" --body "Passed again in $RUN_URL"
	gh issue close "$number"
	echo "Closed issue #$number."
	exit 0
fi

body="The $WORKFLOW workflow failed on its schedule ($SCHEDULE).

Run: $RUN_URL

Jobs that did not pass:

\`\`\`
${failed:-none listed}
\`\`\`"

if [ -n "$number" ]; then
	gh issue comment "$number" --body "$body"
	echo "Commented on issue #$number."
	exit 0
fi

# gh label create fails when the label exists, which is fine; any other failure is not.
if ! out="$(gh label create "$label" --color B60205 --description 'A scheduled CI run failed' 2>&1)"; then
	case "$out" in
		*'already exists'*) ;;
		*) echo "$out" >&2; exit 1 ;;
	esac
fi
gh issue create --title "$title" --label "$label" --body "$body"
