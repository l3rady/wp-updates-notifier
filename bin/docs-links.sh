#!/bin/sh
# Checks the external links with lychee (`make docs-links`). Runs on the host, as it starts Docker.
#
# Pull requests and pushes: one run, as strict as lychee's own exit status.
# A scheduled run (LINKS_RETRY=1): a site that is briefly down answers 5xx, and that is not a broken link. The first run
# writes a JSON report; when every failed link answered 5xx, lychee runs again after a pause, on those URLs only, and
# only a link that fails twice fails the job. Any other failure (404, a timeout) fails at once.
#
# Environment: LINKS_RETRY (set for the retry), LINKS_RETRY_WAIT (the pause in seconds, default 60), COMPOSE (the docker
# compose command, for tests). GITHUB_TOKEN reaches lychee through docker-compose.yml.
set -eu

cd "$(dirname "$0")/.."
compose="${COMPOSE:-docker compose --progress quiet}"
wait="${LINKS_RETRY_WAIT:-60}"
inputs='build/docs README.md build/readme.txt CONTRIBUTING.md'

# lychee ARGS...: the lychee service with the repository's settings. build/links is where it may write.
lychee() {
	# shellcheck disable=SC2086 # The compose command is words.
	$compose --profile docs run --rm -T lychee --config docker/docs/lychee.toml --no-progress "$@"
}

if [ -z "${LINKS_RETRY:-}" ]; then
	# shellcheck disable=SC2086 # The inputs are words.
	lychee $inputs
	exit 0
fi

mkdir -p build/links
rm -f build/links/first.json build/links/retry.txt

status=0
# shellcheck disable=SC2086
lychee --format json --output /links/first.json $inputs || status=$?
case "$status" in
	0 | 2) ;;
	*) echo "lychee failed with status $status." >&2; exit "$status" ;;
esac

verdict=0
# shellcheck disable=SC2086
$compose run --rm -T php php bin/docs-links.php build/links/first.json build/links/retry.txt || verdict=$?
case "$verdict" in
	0)
		[ "$status" = 0 ] || { echo "lychee failed, but its report lists no failed link." >&2; exit 1; }
		exit 0
		;;
	3) ;;
	*) echo "A link failed with something other than a 5xx answer: no second try." >&2; exit 1 ;;
esac

echo "Only 5xx answers. Trying those links again in $wait seconds."
sleep "$wait"
lychee build/links/retry.txt
