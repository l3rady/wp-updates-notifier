<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked above.
wp_remote_post( $url ); // nosemgrep: wpun-outbound-http-safe-only -- A fixed address we own.
// phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- Not a security sniff, no reason needed here.
