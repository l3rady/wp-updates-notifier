#!/bin/sh
# Tests docker/wp-tests-prefix.php: the table prefix of a test process, the cleaning of a run token and the LIKE
# pattern that drops the tables of one run (decision 0032). Run by `make test-isolation`.
set -eu

fail() { echo "FAIL: $*" >&2; exit 1; }

here="$(cd "$(dirname "$0")/../.." && pwd)"
file="$here/docker/wp-tests-prefix.php"

# Evaluate a PHP expression that uses the functions of the prefix file and print its value.
php_eval() {
	php -r "require \$argv[1]; echo $1;" "$file"
}

check() {
	got="$(php_eval "$1")"
	[ "$got" = "$2" ] || fail "$1 gave '$got', wanted '$2'"
}

check "wpun_tests_table_prefix( '12345678s_a1b2c3', '2' )" 'wptests_12345678s_a1b2c3_2_'
check "wpun_tests_table_prefix( '12345678s_a1b2c3', false )" 'wptests_12345678s_a1b2c3_'
check "wpun_tests_table_prefix( '12345678s_a1b2c3' )" 'wptests_12345678s_a1b2c3_'
check "wpun_tests_table_prefix( '12345678s_a1b2c3', '' )" 'wptests_12345678s_a1b2c3_'
# Infection numbers its workers from 0: "0" is a worker, not "no worker".
check "wpun_tests_table_prefix( '12345678u_a1b2c3', '0' )" 'wptests_12345678u_a1b2c3_0_'
check "wpun_tests_table_prefix( '12345678u_a1b2c3', 0 )" 'wptests_12345678u_a1b2c3_0_'
echo "ok: prefix with and without a worker, worker 0"

# Only [0-9a-z_] stays, in the run token and in the worker.
check "wpun_tests_table_prefix( 'AB-c.d/e f_G' )" 'wptests_abcdef_g_'
check "wpun_tests_table_prefix( 'a', 'W-1;DROP' )" 'wptests_a_w1drop_'
check "wpun_tests_clean( 'x' . chr( 39 ) . chr( 96 ) . chr( 37 ) . chr( 92 ) )" 'x'
echo "ok: run token and worker are cleaned"

# No run token: the hand-run base.
check "wpun_tests_table_prefix( '' )" 'wptests_x_'
check "wpun_tests_table_prefix( false, false )" 'wptests_x_'
check "wpun_tests_table_prefix( '-./' )" 'wptests_x_'
echo "ok: empty run token gives x"

# The underscore is a LIKE wildcard: it is escaped, so the pattern does not match another run's tables.
check "wpun_tests_like_pattern( '12345678s_a1b2c3' )" 'wptests\_12345678s\_a1b2c3\_%'
check "wpun_tests_like_pattern( '' )" 'wptests\_x\_%'
check "wpun_tests_like_pattern( 'A-b' )" 'wptests\_ab\_%'
check "wpun_tests_like_escape( 'a_b%c' . chr( 92 ) )" 'a\_b\%c\\'
echo "ok: like pattern escapes the wildcards"

# The pattern matches the tables of its run, with and without a worker, and not those of another run.
got="$(php -r '
	require $argv[1];
	$re = function ( $run ) {
		$like = wpun_tests_like_pattern( $run );
		// A LIKE pattern as a regular expression: \_ is a literal underscore, % any string.
		return "/^" . str_replace( "%", ".*", preg_quote( str_replace( "\\_", "_", $like ), "/" ) ) . "$/";
	};
	$mine = $re( "11111111s_aaaaaa" );
	echo preg_match( $mine, "wptests_11111111s_aaaaaa_options" ) ? "1" : "0";
	echo preg_match( $mine, "wptests_11111111s_aaaaaa_3_options" ) ? "1" : "0";
	echo preg_match( $mine, "wptests_11111111s_aaaaab_options" ) ? "1" : "0";
	echo preg_match( $mine, "wptests_11111111s_aaaaaab_options" ) ? "1" : "0";
	echo preg_match( $mine, "wptests_11111111m_aaaaaa_options" ) ? "1" : "0";
' "$file")"
[ "$got" = "11000" ] || fail "the pattern matched '$got', wanted '11000' (own, own worker, other, longer, other suite)"
echo "ok: pattern selects one run"

# A table name is at most 64 characters: the longest multisite table of a run, with the longest token the Makefile
# makes (8 digits, a letter, the random part) and a two-digit worker and site.
php -r '
	require $argv[1];
	$prefix = wpun_tests_table_prefix( "12345678s_a1b2c3", "12" );
	$name   = $prefix . "99_term_relationships";
	if ( strlen( $name ) > 64 ) {
		fwrite( STDERR, "table name too long (" . strlen( $name ) . "): $name\n" );
		exit( 1 );
	}
	echo "ok: longest table name is " . strlen( $name ) . " characters\n";
' "$file" || fail "table name length"

echo "OK"
