#!/bin/sh
# Run the single-site WordPress suite with a JUnit log and print the 15 slowest tests. Runs inside the wp-tests
# container (make test-slowest). The log is build/junit/junit.xml on the host. The container is root, so the log is
# handed back to HOST_UID:HOST_GID however the run ends.
set -u

trap '[ -z "${HOST_UID:-}" ] || chown -R "$HOST_UID:${HOST_GID:-$HOST_UID}" /junit' EXIT

sh /opt/wp-updates-notifier/docker/wp-tests.sh --log-junit /junit/junit.xml "$@" > /junit/run.txt
code=$?
if [ "$code" -ne 0 ]; then
	cat /junit/run.txt
	exit "$code"
fi
tail -n 3 /junit/run.txt

php -r '
$xml = simplexml_load_file( "/junit/junit.xml" );
$rows = array();
foreach ( $xml->xpath( "//testcase" ) as $case ) {
	$rows[ $case["class"] . "::" . $case["name"] ] = (float) $case["time"];
}
arsort( $rows );
echo "\nThe 15 slowest tests (seconds):\n";
foreach ( array_slice( $rows, 0, 15, true ) as $name => $time ) {
	printf( "  %7.3f  %s\n", $time, $name );
}
'
