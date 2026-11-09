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
 * request's host, and that changes from one request to the next. It is read from the autoloaded
 * options, so a page view costs no query and no filter can change it per request.
 *
 * Only a hash of the address is kept, without its scheme or a leading www: `wp search-replace`,
 * which most clone and staging tools run, would rewrite a stored address along with `home`, and
 * a switch to https is not a move.
 */
final class Site {

    private const OPTION = 'breakwater_site';
    public const  MOVED  = 'breakwater_moved';

    public static function check(): void {
        global $wpdb;
        $all  = wp_load_alloptions();
        $home = untrailingslashit( (string) ( $all['home'] ?? get_option( 'home' ) ) );
        if ( '' === $home ) {
            return;
        }
        $key   = self::key( $home );
        $known = get_option( self::OPTION );
        if ( $known === $key ) {
            return;
        }
        update_option( self::OPTION, $key, true );
        if ( ! is_string( $known ) || '' === $known ) {
            return; // First run: nothing to compare with yet.
        }
        if ( ! preg_match( '/^[0-9a-f]{32}$/', $known ) && self::key( $known ) === $key ) {
            return; // Stored as a plain address before 0.4.0: same site.
        }
        if ( ! apply_filters( 'breakwater_reset_on_move', true, $known, $home ) ) {
            return;
        }
        $rows = (int) $wpdb->query( 'DELETE FROM ' . Log::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        delete_option( 'breakwater_trusted' );
        update_option( self::MOVED, [ 'to' => $home, 'at' => time(), 'rows' => max( 0, $rows ) ], false );
    }

    /** The address without scheme, leading www or case, hashed. */
    private static function key( string $home ): string {
        $bare = strtolower( untrailingslashit( (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', trim( $home ) ) ) );
        return md5( (string) preg_replace( '/^www\./', '', $bare ) );
    }

    /** @return array{to:string, at:int, rows:int}|null */
    public static function moved(): ?array {
        $moved = get_option( self::MOVED );
        return is_array( $moved ) && isset( $moved['to'], $moved['at'] ) ? $moved : null;
    }
}
