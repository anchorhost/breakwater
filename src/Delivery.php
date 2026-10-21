<?php

declare(strict_types=1);

namespace Breakwater;

use Breakwater\Adapters\Registry;

/**
 * The digest's Deliver link, and the email used when a plugin can't take a submission back.
 * Each held submission in the digest carries a signed link to a confirmation page; the page's
 * button releases it (Release::run). A plain one-click link would be "clicked" by the mail
 * scanners that open every link.
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

    /** The fields, emailed to the form's recipients with the sender as Reply-To. */
    public static function email( object $row ): bool {
        $adapter = Registry::forSource( (string) $row->source );
        $payload = json_decode( (string) $row->payload, true ) ?: [];
        $lines   = [];
        foreach ( (array) ( $payload['fields'] ?? [] ) as $key => $value ) {
            $lines[] = $key . ': ' . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) );
        }
        $subject = sprintf( '[%s] %s submission (released from spam)', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $adapter ? $adapter->label() : $row->source );
        $body    = "This submission was held as possible spam on {$row->created_at} UTC and has now been released.\n\n"
            . 'Form: ' . $row->form . "\n"
            . 'Page: ' . ( $payload['url'] ?? '' ) . "\n\n"
            . implode( "\n", $lines );
        $headers = $row->email && is_email( $row->email ) ? [ 'Reply-To: ' . $row->email ] : [];
        return wp_mail( self::recipients( $row ), $subject, $body, $headers );
    }

    /** The digest's link: GET shows what will be sent, POST sends it. */
    public static function handle(): never {
        $id  = absint( $_REQUEST['id'] ?? 0 );
        $sig = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['sig'] ?? '' ) ) );
        $row = $id ? Log::get( $id ) : null;
        if ( ! $row || ! hash_equals( self::signature( $id ), $sig ) ) {
            self::page( 'Link not valid', '<p>This link is not valid, or the submission is older than ' . Log::keepDays() . ' days and was removed.</p>', 404 );
        }
        if ( $row->delivered_at ) {
            self::page( 'Already delivered', '<p>' . esc_html( (string) $row->outcome ?: 'This submission was already delivered.' ) . '</p>' );
        }
        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            $outcome = Release::run( $row );
            null !== $outcome
                ? self::page( 'Delivered', '<p>' . esc_html( $outcome ) . '</p><p>Future messages from ' . esc_html( (string) $row->email ?: 'this sender' ) . ' get the benefit of the doubt.</p>' )
                : self::page( 'Not sent', '<p>The email could not be sent. Please try again later.</p>', 500 );
        }
        $adapter = Registry::forSource( (string) $row->source );
        $what    = $adapter && $adapter->handsBack()
            ? 'It goes to ' . $adapter->label() . ' as if it had never been held: saved there, with the form\'s emails and integrations run.'
            : 'It will be emailed to ' . implode( ', ', self::recipients( $row ) ) . '.';
        $html = '<p><strong>' . esc_html( (string) $row->form ) . '</strong>, ' . esc_html( (string) $row->created_at ) . ' UTC<br>From: ' . esc_html( (string) $row->email ?: 'no email given' ) . '</p>'
            . '<blockquote style="white-space:pre-wrap;border-left:3px solid #ccc;padding-left:12px">' . esc_html( mb_substr( (string) $row->excerpt, 0, 500 ) ) . '</blockquote>'
            . '<form method="post"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="sig" value="' . esc_attr( $sig ) . '">'
            . '<p>' . esc_html( $what ) . '</p>'
            . '<p><button type="submit" class="button button-primary" style="font-size:16px;line-height:1.5;height:auto;padding:6px 18px">Deliver it</button></p></form>';
        self::page( 'Deliver this message?', $html );
    }

    private static function page( string $title, string $html, int $status = 200 ): never {
        wp_die( '<h1>' . esc_html( $title ) . '</h1>' . $html, esc_html( $title ), [ 'response' => $status ] );
    }
}
