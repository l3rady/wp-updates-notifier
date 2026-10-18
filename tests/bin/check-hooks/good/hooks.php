<?php
/**
 * Fires a thing.
 *
 * @since 1.0.0
 *
 * @param string $thing The thing.
 */
do_action( 'sc_wpun_thing', 'x' );

/**
 * Fires a dynamic thing.
 *
 * The dynamic part, `$kind`, is the kind.
 *
 * @since 1.0.0
 */
do_action( "sc_wpun_kind_{$kind}" );

// Handled by the plugin itself: the name matches a dynamic fired hook, so it needs no doc block.
add_action( 'sc_wpun_kind_mine', 'handler' );
// Not a public hook.
add_action( 'init', 'handler' );
