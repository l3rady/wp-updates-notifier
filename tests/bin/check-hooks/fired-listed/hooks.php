<?php
/**
 * Fires a thing.
 *
 * @since 1.0.0
 */
do_action( 'sc_wpun_thing' );

/**
 * Handles a thing.
 *
 * @hook-listened
 *
 * @since 1.0.0
 */
add_action( 'sc_wpun_thing', 'handler' );
