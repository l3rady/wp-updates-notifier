#!/bin/sh
#
# `make dast`: build an isolated copy of the site, scan it with OWASP ZAP and throw it away.
#
# Runs as its own compose project (wpun-dast) with no published ports and no shared volumes or container names, so a
# running `make up` site is never touched. The project is removed, volumes included, when the script ends, whatever
# the outcome. Reports go to security-reports/ (zap-report.html, zap.sarif).
#
# Run from the repository root, by the Makefile. COMPOSE_EXTRA adds compose options (tests only).

set -eu

compose() {
	# shellcheck disable=SC2086 -- COMPOSE_EXTRA is a list of options.
	docker compose --progress quiet -p wpun-dast -f tests/dast/compose.yml ${COMPOSE_EXTRA:-} "$@"
}

teardown() {
	compose down --volumes --remove-orphans >/dev/null 2>&1 || true
}
# DAST_KEEP=1 leaves the site up afterwards, to look at it (remove it with `docker compose -p wpun-dast down -v`).
[ -n "${DAST_KEEP:-}" ] || trap teardown EXIT INT TERM

mkdir -p security-reports
rm -f security-reports/zap-report.html security-reports/zap.sarif.json

# Start from nothing, in case an earlier run was killed.
teardown

compose up -d --build db wordpress
compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-site-setup
compose run --rm -T --entrypoint sh wp-cli /usr/local/bin/wpun-dast-seed

status=0
# WordPress's nonce field is named _wpnonce, which is not among ZAP's default anti-CSRF token names (it would report
# every form as unprotected). Token 15 is the first free slot after the 15 defaults.
compose run --rm -T zap zap.sh -cmd -silent \
	-config 'anticsrf.tokens.token(15).name=_wpnonce' -config 'anticsrf.tokens.token(15).enabled=true' \
	-autorun /zap/wrk/zap-plan.yaml || status=$?

case "$status" in
0) echo 'DAST: no Medium or High alerts, every test passed.' ;;
*) echo "DAST: failed (ZAP exit status $status). See security-reports/zap-report.html." >&2 ;;
esac
exit "$status"
