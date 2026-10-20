#!/bin/sh
#
# `make assets`: write every export from the SVG sources in .wordpress-org/src, so they cannot drift. Runs in the
# assets image (docker/assets) from the repository root. Output is deterministic: no timestamps or metadata chunks.
#
#   .wordpress-org/   what WordPress.org shows (light): icon-128x128.png, icon-256x256.png, icon.svg, banner-772x250.png,
#                     banner-1544x500.png
#   docs/assets/brand/ what the documentation site uses: favicon.svg (follows the system scheme), logo.svg and
#                     logo-dark.svg (the 24px header logo), banner.png and banner-dark.png (the home page)

set -eu

SRC=.wordpress-org/src
OUT=.wordpress-org
BRAND=docs/assets/brand

# The banner's text is Liberation Sans: fail rather than render it in a fallback font.
[ "$(fc-match -f '%{family}' 'Liberation Sans')" = 'Liberation Sans' ] || { echo 'Liberation Sans is not installed.' >&2; exit 1; }

mkdir -p "$OUT" "$BRAND"

# png <source.svg> <width> <height> <target.png>
png() {
	rsvg-convert --width "$2" --height "$3" --format png "$1" --output "$4"
	oxipng --quiet --opt 4 --strip all --alpha "$4"
}

png "$SRC/icon.svg" 128 128 "$OUT/icon-128x128.png"
png "$SRC/icon.svg" 256 256 "$OUT/icon-256x256.png"
cp "$SRC/icon.svg" "$OUT/icon.svg"
png "$SRC/banner.svg" 772 250 "$OUT/banner-772x250.png"
png "$SRC/banner.svg" 1544 500 "$OUT/banner-1544x500.png"

png "$SRC/banner.svg" 1544 500 "$BRAND/banner.png"
png "$SRC/banner-dark.svg" 1544 500 "$BRAND/banner-dark.png"
cp "$SRC/icon-small.svg" "$BRAND/logo.svg"
cp "$SRC/icon-small-dark.svg" "$BRAND/logo-dark.svg"

# The browser tab icon: one SVG with both small icons, light by default and dark when the system prefers it. The inner
# SVG markup is the sources' own, without their <svg> and <title> wrappers.
inner() {
	sed -e '/^<svg /d' -e '/^<title>/d' -e '/^<\/svg>/d' "$1"
}
{
	echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16">'
	echo '<title>WP Updates Notifier</title>'
	echo '<style>.d{display:none}@media (prefers-color-scheme:dark){.l{display:none}.d{display:inline}}</style>'
	echo '<g class="l">'
	inner "$SRC/icon-small.svg"
	echo '</g>'
	echo '<g class="d">'
	inner "$SRC/icon-small-dark.svg"
	echo '</g>'
	echo '</svg>'
} >"$BRAND/favicon.svg"
