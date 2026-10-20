<?php

declare(strict_types=1);

namespace Breakwater;

use Breakwater\Adapters\Registry;

/**
 * Handing a held submission back. Every "Not spam" ends here, wherever it was clicked: the
 * Breakwater log, a Deliver link in the digest, or the form plugin's own button. The adapter
 * hands it to its plugin as if it had never been held (saved, emails sent, integrations run);
 * when it can't, a submission Breakwater stopped is emailed to the form's recipients instead.
 */
final class Release {

    private static bool $running = false;

    /** True while an adapter replays a submission: Guard lets it through, plugin hooks stay quiet. */
    public static function running(): bool {
        return self::$running;
    }

    /**
     * @return string|null What happened, in a sentence for the site owner; null when it could
     *                     not be sent and can be tried again.
     */
    public static function run( object $row ): ?string {
        $id   = (int) $row->id;
        $held = 'blocked' === $row->action || 'spam' === $row->other_verdict;
        if ( ! $held ) {
            Feedback::notSpam( $id );
            return 'Marked not spam.';
        }
        if ( ! Log::claim( $id ) ) {
            Feedback::notSpam( $id );
            return (string) ( Log::get( $id )->outcome ?? '' ) ?: 'It was already delivered.';
        }
        $row     = Log::get( $id ) ?? $row;
        $adapter = Registry::forSource( (string) $row->source );
        $outcome = null;
        if ( $adapter ) {
            try {
                $outcome = self::asVisitor( $row, fn (): ?string => $adapter->release( $row ) );
            } catch ( \Throwable $e ) {
                error_log( sprintf( 'Breakwater: handing back log row %d to %s failed: %s', $id, $adapter->label(), $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            }
        }
        if ( null === $outcome && 'blocked' === $row->action ) {
            $outcome = Delivery::email( $row ) ? 'Emailed to ' . implode( ', ', Delivery::recipients( $row ) ) . '.' : null;
            if ( null === $outcome ) {
                Log::unclaim( $id );
                return null;
            }
        }
        Log::released( $id, $outcome ?? 'Marked not spam.' );
        Feedback::notSpam( $id );
        return $outcome ?? 'Marked not spam.';
    }

    /**
     * Run a replay the way the visitor's request ran: their IP, browser and page, logged out.
     * Plugins record these on the entry and some refuse without them.
     */
    private static function asVisitor( object $row, callable $replay ): mixed {
        $server  = $_SERVER;
        $user    = get_current_user_id();
        $payload = json_decode( (string) $row->payload, true ) ?: [];
        unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CLIENT_IP'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_TRUE_CLIENT_IP'] );
        $_SERVER['REMOTE_ADDR']     = (string) $row->ip ?: (string) ( $server['REMOTE_ADDR'] ?? '' );
        $_SERVER['HTTP_USER_AGENT'] = (string) ( $payload['agent'] ?? '' );
        $_SERVER['HTTP_REFERER']    = (string) ( $payload['url'] ?? '' );
        self::$running              = true;
        wp_set_current_user( 0 );
        try {
            return $replay();
        } finally {
            $_SERVER       = $server;
            self::$running = false;
            wp_set_current_user( $user );
        }
    }

    /**
     * Replay data without secrets: any key that names a password, card or account number is
     * dropped, at any depth.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function scrub( array $data ): array {
        foreach ( $data as $key => $value ) {
            if ( is_string( $key ) && preg_match( Submission::SECRET, $key ) ) {
                unset( $data[ $key ] );
            } elseif ( is_array( $value ) ) {
                $data[ $key ] = self::scrub( $value );
            }
        }
        return $data;
    }
}
