<?php

declare(strict_types=1);

namespace Breakwater;

use Breakwater\Adapters\Registry;

/**
 * Releasing a stopped submission to the people who would have received it. Each one in the
 * digest carries a signed link to a confirmation page; the page's button does the sending.
 * (A plain one-click link would be "clicked" by the mail scanners that open every link.)
 */
final class Delivery {

    public static function register(): void {
        add_action( 'admin_post_nopriv_breakwater_deliver', [ self::class, 'handle' ] );
        add_action( 'admin_post_breakwater_deliver', [ self::class, 'handle' ] );
    }

    public static function url( int $id ): string {
        return add_query_arg( [ 'action' => 'breakwater_deliver', 'id' => $id, 'sig' => self::signature( $id ) ], admin_url( 'admin-post.php' ) );
    }

    private static function signature( int $id ): string {
        return substr( hash_hmac( 'sha256', 'deliver|' . $id, Plugin::secret() ), 0, 32 );
    }

    /**
     * Who gets it: the form's own notification recipients, else the site admin.
     *
     * @return list<string>
     */
    public static function recipients( object $row ): array {
        $adapter = Registry::forSource( (string) $row->source );
        $to      = $adapter ? $adapter->recipients( (string) $row->form ) : [];
        $to      = $to ?: [ (string) get_option( 'admin_email' ) ];
        return array_values( array_filter( (array) apply_filters( 'breakwater_recipients', $to, $row ), 'is_email' ) );
    }

    /** Send it on, and count it as "Not spam". */
    public static function send( object $row ): bool {
        $payload = json_decode( (string) $row->payload, true ) ?: [];
        $lines   = [];
        foreach ( (array) ( $payload['fields'] ?? [] ) as $key => $value ) {
            $lines[] = $key . ': ' . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) );
        }
        $adapter = Registry::forSource( (string) $row->source );
        $subject = sprintf( '[%s] %s submission (released from spam)', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $adapter ? $adapter->label() : $row->source );
        $body    = "This submission was held as possible spam on {$row->created_at} UTC and has now been released.\n\n"
            . 'Form: ' . $row->form . "\n"
            . 'Page: ' . ( $payload['url'] ?? '' ) . "\n\n"
            . implode( "\n", $lines );
        $headers = $row->email && is_email( $row->email ) ? [ 'Reply-To: ' . $row->email ] : [];
        if ( ! wp_mail( self::recipients( $row ), $subject, $body, $headers ) ) {
            return false;
        }
        Log::markDelivered( (int) $row->id );
        Feedback::notSpam( (int) $row->id );
        return true;
    }

    /** The digest's link: GET shows what will be sent, POST sends it. */
    public static function handle(): never {
        $id  = absint( $_REQUEST['id'] ?? 0 );
        $sig = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['sig'] ?? '' ) ) );
        $row = $id ? Log::get( $id ) : null;
        if ( ! $row || ! hash_equals( self::signature( $id ), $sig ) ) {
            self::page( 'Link not valid', '<p>This link is not valid, or the submission is older than 30 days and was removed.</p>', 404 );
        }
        $to = implode( ', ', self::recipients( $row ) );
        if ( $row->delivered_at ) {
            self::page( 'Already delivered', '<p>This submission was already delivered to ' . esc_html( $to ) . '.</p>' );
        }
        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            self::send( $row )
                ? self::page( 'Delivered', '<p>Sent to ' . esc_html( $to ) . '. Future messages from ' . esc_html( (string) $row->email ) . ' get the benefit of the doubt.</p>' )
                : self::page( 'Not sent', '<p>The email could not be sent. Please try again later.</p>', 500 );
        }
        $html = '<p><strong>' . esc_html( (string) $row->form ) . '</strong>, ' . esc_html( (string) $row->created_at ) . ' UTC<br>From: ' . esc_html( (string) $row->email ?: 'no email given' ) . '</p>'
            . '<blockquote style="white-space:pre-wrap;border-left:3px solid #ccc;padding-left:12px">' . esc_html( mb_substr( (string) $row->excerpt, 0, 500 ) ) . '</blockquote>'
            . '<form method="post"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="sig" value="' . esc_attr( $sig ) . '">'
            . '<p><button type="submit" class="button button-primary" style="font-size:16px;padding:8px 16px">Deliver to ' . esc_html( $to ) . '</button></p></form>';
        self::page( 'Deliver this message?', $html );
    }

    private static function page( string $title, string $html, int $status = 200 ): never {
        wp_die( '<h1>' . esc_html( $title ) . '</h1>' . $html, esc_html( $title ), [ 'response' => $status ] );
    }
}
