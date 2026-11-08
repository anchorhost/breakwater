<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * "Spam? Report it" in the emails a form sends its owner.
 *
 * Spam that gets past every check lands in someone's inbox, and nothing labels it there: the
 * site keeps it as a clean entry and the host's fleet list never hears of it. So every email
 * sent while a submission Breakwater let through is being handled carries a footer link signed
 * for its log row. The link opens a confirmation page (mail scanners open every link, so a GET
 * never changes anything) whose button marks the row as spam through Feedback::spam(): the
 * sender loses any trust, their next message here is held (reported_sender), and on a host
 * with a fleet list the sender and message are reported with the next sync.
 *
 * Emails to the person who sent the form (auto-replies) never carry the link, and neither do
 * comment notifications (WordPress has its own Spam link) or sign-ups. Plugins that send their
 * notifications from a later request (Gravity Forms' Background Notifications) name the row
 * themselves with expect().
 */
final class Report {

    /** Sources whose emails are not an owner reading a message. */
    private const SKIP = [ 'comment', 'registration', 'woocommerce', 'ultimate-member', 'mc4wp', 'mailpoet', 'constant-contact' ];

    /** The row whose notifications go out now. */
    private static int $log_id = 0;

    public static function register(): void {
        add_action( 'admin_post_nopriv_breakwater_report', [ self::class, 'handle' ] );
        add_action( 'admin_post_breakwater_report', [ self::class, 'handle' ] );
        if ( self::enabled() ) {
            add_action( 'breakwater_checked', [ self::class, 'checked' ], 10, 2 );
            add_filter( 'wp_mail', [ self::class, 'mail' ], 99 );
        }
    }

    public static function enabled(): bool {
        $on = defined( 'BREAKWATER_REPORT_LINK' ) ? (bool) BREAKWATER_REPORT_LINK : true;
        return (bool) apply_filters( 'breakwater_report_enabled', $on );
    }

    /** A submission that will be delivered: its notifications may carry the link. */
    public static function checked( mixed $verdict, mixed $s ): void {
        if ( ! $verdict instanceof Verdict || ! $s instanceof Submission || $verdict->act || ! $verdict->log_id || in_array( $s->source, self::SKIP, true ) ) {
            return;
        }
        self::expect( $verdict->log_id );
    }

    /** The emails that follow are about this log row (0: about none). */
    public static function expect( int $log_id ): void {
        self::$log_id = $log_id;
    }

    /**
     * @param mixed $atts wp_mail()'s to, subject, message, headers, attachments.
     */
    public static function mail( mixed $atts ): mixed {
        if ( ! self::$log_id || ! is_array( $atts ) || ! is_string( $atts['message'] ?? null ) ) {
            return $atts;
        }
        $row = Log::get( self::$log_id );
        if ( ! $row || 'blocked' === $row->action || in_array( $row->source, self::SKIP, true ) ) {
            return $atts;
        }
        $sender = strtolower( trim( (string) $row->email ) );
        $to     = is_array( $atts['to'] ?? null ) ? $atts['to'] : explode( ',', (string) ( $atts['to'] ?? '' ) );
        foreach ( $to as $address ) {
            $address = strtolower( trim( (string) $address ) );
            if ( preg_match( '/<([^>]+)>/', $address, $m ) ) {
                $address = trim( $m[1] );
            }
            if ( '' !== $sender && $address === $sender ) {
                return $atts; // An auto-reply to the sender.
            }
        }
        if ( ! apply_filters( 'breakwater_report_link', true, $row, $atts ) ) {
            return $atts;
        }
        $url     = self::url( $row );
        $message = $atts['message'];
        if ( self::html( $atts ) ) {
            $link = '<p style="color:#888;font-size:12px;margin-top:24px">Spam? <a href="' . esc_url( $url ) . '" style="color:#888">Report it to Breakwater</a></p>';
            $end  = strripos( $message, '</body>' );
            $atts['message'] = false === $end ? $message . $link : substr_replace( $message, $link, $end, 0 );
        } else {
            $atts['message'] = rtrim( $message ) . "\n\n--\nSpam? Report it to Breakwater: " . $url . "\n";
        }
        return $atts;
    }

    /** @param array<string, mixed> $atts */
    private static function html( array $atts ): bool {
        $headers = $atts['headers'] ?? [];
        $headers = is_array( $headers ) ? implode( "\n", array_map( 'strval', $headers ) ) : (string) $headers;
        if ( preg_match( '#content-type:\s*text/(html|plain)#i', $headers, $m ) ) {
            return 'html' === strtolower( $m[1] );
        }
        if ( 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' ) ) {
            return true;
        }
        return 1 === preg_match( '#<(?:html|body|p|br|div|table)\b#i', (string) $atts['message'] );
    }

    public static function url( object $row ): string {
        return add_query_arg( [ 'action' => 'breakwater_report', 'id' => (int) $row->id, 'sig' => self::signature( $row ) ], admin_url( 'admin-post.php' ) );
    }

    /** Signs the row's time too, so a log table made again (new rows, same ids) can't reuse a link. */
    private static function signature( object $row ): string {
        return substr( hash_hmac( 'sha256', 'report|' . (int) $row->id . '|' . $row->created_at, Plugin::secret() ), 0, 32 );
    }

    /** GET shows the message and a button, POST reports it. */
    public static function handle(): never {
        $id  = absint( $_REQUEST['id'] ?? 0 );
        $sig = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['sig'] ?? '' ) ) );
        $row = $id ? Log::get( $id ) : null;
        if ( ! $row || ! hash_equals( self::signature( $row ), $sig ) ) {
            self::page( 'Link not valid', '<p>This link is not valid, or the message is older than ' . Log::keepDays() . ' days and was removed.</p>', 404 );
        }
        $sender = (string) $row->email ?: 'this sender';
        if ( 'spam' === $row->feedback ) {
            self::page( 'Already reported', '<p>This message was already reported as spam.</p>' );
        }
        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            Feedback::spam( (int) $row->id );
            self::page( 'Reported', '<p>Thanks. Breakwater will hold further messages from ' . esc_html( $sender ) . ' on this site' . ( Reputation::list() ? ', and your host\'s other sites learn from it too' : '' ) . '.</p><p>The entry itself stays where the form saved it.</p>' );
        }
        $html = '<p><strong>' . esc_html( (string) $row->form ) . '</strong>, ' . esc_html( (string) $row->created_at ) . ' UTC<br>From: ' . esc_html( (string) $row->email ?: 'no email given' ) . '</p>'
            . '<blockquote style="white-space:pre-wrap;border-left:3px solid #ccc;padding-left:12px">' . esc_html( mb_substr( (string) $row->excerpt, 0, 500 ) ) . '</blockquote>'
            . '<form method="post"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="sig" value="' . esc_attr( $sig ) . '">'
            . '<p>Breakwater will hold further messages from ' . esc_html( $sender ) . ' and learn from this one.</p>'
            . '<p><button type="submit" class="button button-primary" style="font-size:16px;line-height:1.5;height:auto;padding:6px 18px">Report as spam</button></p></form>';
        self::page( 'Report this message as spam?', $html );
    }

    private static function page( string $title, string $html, int $status = 200 ): never {
        wp_die( '<h1>' . esc_html( $title ) . '</h1>' . $html, esc_html( $title ), [ 'response' => $status ] );
    }
}
