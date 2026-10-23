<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * Senders this site has said are not spam. Once someone's message is marked "Not spam" or
 * delivered, their next ones get the benefit of the doubt (a -10 signal, dropped when a signal
 * only automation trips fires, so a bot that borrowed the address is still stopped).
 * Stored as hashes, for a year.
 */
final class Trust {

    private const OPTION = 'breakwater_trusted';
    private const LIMIT  = 2000;

    public static function add( string $email ): void {
        $hash = Reputation::email( $email );
        if ( '' === $hash ) {
            return;
        }
        $list          = self::all();
        $list[ $hash ] = time() + YEAR_IN_SECONDS;
        arsort( $list );
        update_option( self::OPTION, array_slice( $list, 0, self::LIMIT, true ), false );
    }

    public static function remove( string $email ): void {
        $list = self::all();
        unset( $list[ Reputation::email( $email ) ] );
        update_option( self::OPTION, $list, false );
    }

    public static function has( string $email ): bool {
        $hash = Reputation::email( $email );
        return '' !== $hash && ( self::all()[ $hash ] ?? 0 ) > time();
    }

    /** @return array<string, int> hash => expiry */
    private static function all(): array {
        $list = get_option( self::OPTION, [] );
        return is_array( $list ) ? $list : [];
    }
}
