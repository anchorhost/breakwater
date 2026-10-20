<?php
/**
 * Plugin Name: Breakwater test mail log
 * Description: Sandbox only. Appends every wp_mail() call as a JSON line to wp-content/bw-mail.log,
 * so a test can see which emails a submission or a release sent. Copied into mu-plugins by hand.
 */

add_filter( 'wp_mail', function ( $args ) {
    $line = [
        'time'    => gmdate( 'c' ),
        'to'      => $args['to'] ?? '',
        'subject' => $args['subject'] ?? '',
        'headers' => $args['headers'] ?? '',
        'body'    => mb_substr( wp_strip_all_tags( (string) ( $args['message'] ?? '' ) ), 0, 2000 ),
    ];
    file_put_contents( WP_CONTENT_DIR . '/bw-mail.log', wp_json_encode( $line ) . "\n", FILE_APPEND | LOCK_EX );
    return $args;
}, 999 );
