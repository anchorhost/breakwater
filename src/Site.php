<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * Starts the log afresh when the site's address changes. A site cloned from a template, or a
 * staging copy, would otherwise show the original's submissions and trust its senders, and its
 * digest would send the original's held messages to the new site's recipients.
 *
 * The address compared is the one stored in the database (the `home` option, which moves only
 * when a site is cloned, copied or moved), not home_url(): some sites build WP_HOME from the
 * request's host, and that changes from one request to the next.
 */
final class Site {

    private const OPTION = 'breakwater_site';
    public const  MOVED  = 'breakwater_moved';

    public static function check(): void {
        global $wpdb;
        // get_option( 'home' ) is free (autoloaded) but returns WP_HOME when that is defined,
        // so only then is the table asked directly.
        $home = defined( 'WP_HOME' )
            ? (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'home' LIMIT 1" ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            : (string) get_option( 'home' );
        $home = untrailingslashit( $home );
        if ( '' === $home ) {
            return;
        }
        $known = get_option( self::OPTION );
        if ( $known === $home ) {
            return;
        }
        update_option( self::OPTION, $home, true );
        if ( ! is_string( $known ) || '' === $known ) {
            return; // First run: nothing to compare with yet.
        }
        if ( ! apply_filters( 'breakwater_reset_on_move', true, $known, $home ) ) {
            return;
        }
        $rows = (int) $wpdb->query( 'DELETE FROM ' . Log::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        delete_option( 'breakwater_trusted' );
        update_option( self::MOVED, [ 'from' => $known, 'to' => $home, 'at' => time(), 'rows' => max( 0, $rows ) ], false );
    }

    /** @return array{from:string, to:string, at:int, rows:int}|null */
    public static function moved(): ?array {
        $moved = get_option( self::MOVED );
        return is_array( $moved ) && isset( $moved['from'], $moved['to'], $moved['at'] ) ? $moved : null;
    }
}
