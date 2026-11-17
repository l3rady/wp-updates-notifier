#!/bin/sh
# Print the PHPUnit --filter pattern for `make test-wp FILE=tests/wp/test-foo.php FILTER=pattern`, from the environment
# variables FILE and FILTER (they are read here and never put on a command line, so no shell reads a regex).
# FILTER alone is the pattern. FILE alone is its test class. Both: the class, then FILTER as a pattern for the method.
# The class is the one that extends a `*Test_Case` base: a file may also hold helper classes that extend other things.
# The filter for a class is exact: it does not match a class whose name only starts with the same words. It fails when
# the file does not exist or holds no such class or several.
set -eu

file="${FILE:-}"
filter="${FILTER:-}"
if [ -z "$file" ]; then
	printf '%s\n' "$filter"
	exit 0
fi
if [ ! -f "$file" ]; then
	echo "FILE: no such file: $file" >&2
	exit 1
fi

classes="$(sed -n 's/^\(final \|abstract \)\{0,1\}class \([A-Za-z0-9_]*\) extends [A-Za-z0-9_\\]*Test_Case\( .*\)\{0,1\}$/\2/p' "$file")"
count="$(printf '%s\n' "$classes" | grep -c . || true)"
if [ "$count" -ne 1 ]; then
	echo "FILE: expected one class that extends a *Test_Case base in $file, found $count" >&2
	exit 1
fi

# PHPUnit matches the pattern as a regex against "Class::method" (a data provider adds " with data set ...").
printf '^%s::%s\n' "$classes" "$filter"
