<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * Once a day, tell the people who receive each form what was held back, with a Deliver link
 * for each. Obvious bots (honeypot, no browser, forged proof of work, automated browser,
 * script injection) are counted, not listed, so the digest stays short enough to read.
 */
final class Digest {

    public const HOOK = 'breakwater_digest';

    /** Signals that mean a bot beyond reasonable doubt. */

    public static function register(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    public static function enabled(): bool {
        $on = defined( 'BREAKWATER_DIGEST' ) ? (bool) BREAKWATER_DIGEST : true;
        return (bool) apply_filters( 'breakwater_digest_enabled', $on );
    }

    /**
     * @return array{sent:int, listed:int, bots:int} what went out
     */
    public static function run( bool $dry_run = false ): array {
        $out = [ 'sent' => 0, 'listed' => 0, 'bots' => 0 ];
        if ( ! self::enabled() ) {
            return $out;
        }
        $rows   = Log::undigested();
        $groups = [];
        $bots   = [];
        foreach ( $rows as $row ) {
            $signals = json_decode( (string) $row->signals, true ) ?: [];
            if ( array_intersect( Checker::CERTAIN, array_keys( $signals ) ) ) {
                $bots[] = (int) $row->id;
                continue;
            }
            $to = Delivery::recipients( $row );
            sort( $to );
            $groups[ implode( ',', $to ) ][] = $row;
        }
        $out['bots'] = count( $bots );
        $sent        = [];
        foreach ( $groups as $to => $held ) {
            $out['listed'] += count( $held );
            if ( ! $dry_run && wp_mail( explode( ',', $to ), self::subject( count( $held ) ), self::body( $held, count( $bots ) ), [ 'Content-Type: text/html; charset=UTF-8' ] ) ) {
                ++$out['sent'];
                $sent = array_merge( $sent, array_map( fn ( object $row ): int => (int) $row->id, $held ) );
            }
        }
        // A digest that failed to send is tried again tomorrow. Bots are only ever counted.
        if ( ! $dry_run && ( $sent || ! $groups ) ) {
            Log::markDigested( array_merge( $bots, $sent ) );
        }
        return $out;
    }

    private static function subject( int $count ): string {
        return sprintf( '[%s] %d %s held as possible spam', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $count, 1 === $count ? 'message' : 'messages' );
    }

    /** @param list<object> $held */
    private static function body( array $held, int $bots ): string {
        $items = '';
        foreach ( array_slice( $held, 0, 50 ) as $row ) {
            $why    = Verdict::describe( json_decode( (string) $row->signals, true ) ?: [] );
            $items .= '<tr><td style="padding:12px 0;border-bottom:1px solid #eee">'
                . '<div style="color:#666;font-size:13px">' . esc_html( (string) $row->created_at ) . ' UTC · ' . esc_html( (string) $row->form ) . '</div>'
                . '<div><strong>' . esc_html( (string) $row->email ?: 'no email given' ) . '</strong></div>'
                . '<div style="margin:6px 0;white-space:pre-wrap">' . esc_html( mb_substr( (string) $row->excerpt, 0, 300 ) ) . '</div>'
                . '<div style="color:#666;font-size:13px">Held because: ' . esc_html( $why ) . '</div>'
                . '<div style="margin-top:6px"><a href="' . esc_url( Delivery::url( $row ) ) . '">Not spam? Deliver it</a></div>'
                . '</td></tr>';
        }
        $more = count( $held ) > 50 ? '<p>' . ( count( $held ) - 50 ) . ' more are in Tools → Breakwater.</p>' : '';
        $bots = $bots ? '<p style="color:#666">' . $bots . ' more were obvious bots and are not listed.</p>' : '';
        return '<div style="font-family:-apple-system,Segoe UI,sans-serif;font-size:15px;max-width:640px">'
            . '<p>These messages to ' . esc_html( (string) get_bloginfo( 'name' ) ) . ' were held as possible spam and not delivered. If one is real, use its Deliver link and it will be sent to you as usual.</p>'
            . '<table style="width:100%;border-collapse:collapse">' . $items . '</table>' . $more . $bots
            . '<p style="color:#666;font-size:13px">Held messages are kept for ' . Log::keepDays() . ' days. Breakwater.</p></div>';
    }
}
