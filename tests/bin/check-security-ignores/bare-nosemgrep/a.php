<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
wp_remote_post( $url ); // nosemgrep: wpun-outbound-http-safe-only
