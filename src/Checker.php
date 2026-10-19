<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * Scores a submission. Each signal adds points; at the threshold it is spam.
 * Browser proof weighs most, content and sender signals add up behind it.
 */
final class Checker {

    public const THRESHOLD = 5;

    /** Signals only automation produces: no person trips them, so they can be acted on alone. */
    public const CERTAIN = [ 'honeypot', 'no_js', 'pow_invalid', 'webdriver', 'injection' ];

    public const PHRASES = [
        'seo services', 'backlink', 'first page of google', 'page 1 of google', 'rank your website', 'ranking on google',
        'increase your traffic', 'website traffic', 'guest post', 'link building', 'domain authority',
        'web design services', 'redesign your website', 'lead generation', 'more leads', 'grow your business online',
        'crypto', 'bitcoin', 'forex', 'binary option', 'casino', 'betting', 'viagra', 'cialis', 'porn',
        'make money online', 'work from home', 'loan offer', 'payday', 'telegram', 'whatsapp', 'unsubscribe here',
        'opt out', 'promote your', 'video explainer', 'ai chatbot for your website', 'virtual assistant',
    ];

    /** Locales written in a script other than Latin: the foreign-script signal is skipped for them. */
    private const NON_LATIN_LOCALES = '/^(ru|uk|bg|sr|mk|be|kk|zh|ja|ko|ar|fa|ur|he|th|hi|mr|ne|el)/';

    /** @var array<string, array{0:int, 1:string}> */
    private array $signals = [];

    private function __construct( private readonly Submission $s ) {}

    public static function threshold(): int {
        return (int) apply_filters( 'breakwater_threshold', self::THRESHOLD );
    }

    /**
     * @param bool $with_browser False when there is no browser proof to judge (CLI, backtests).
     */
    public static function check( Submission $s, bool $with_browser = true ): Verdict {
        $checker = new self( $s );
        if ( $with_browser ) {
            $checker->browser();
        }
        $checker->agent();
        $checker->content();
        $checker->sender();
        $checker->repeats();
        $checker->fleet();

        $signals = (array) apply_filters( 'breakwater_signals', $checker->signals, $s );
        $score   = array_sum( array_map( fn ( array $signal ): int => (int) $signal[0], $signals ) );
        return new Verdict( $score >= self::threshold(), $score, $signals );
    }

    private function add( string $code, int $points, string $detail = '' ): void {
        $this->signals[ $code ] = [ $points, $detail ];
    }

    private function browser(): void {
        if ( '' !== $this->s->trap ) {
            $this->add( 'honeypot', 10, 'hidden field filled' );
        }
        match ( Challenge::verify( $this->s->token ) ) {
            // No script ran at all: almost always a bot posting straight to the form.
            // Script ran but the work was not done yet (or the REST API is blocked): weaker.
            Proof::Missing => null === $this->s->meta
                ? $this->add( 'no_js', 6, 'no browser proof' )
                : $this->add( 'pow_pending', 2, 'proof of work not finished' ),
            Proof::Malformed, Proof::Forged, Proof::Wrong => $this->add( 'pow_invalid', 8, 'proof of work invalid' ),
            Proof::Replayed => $this->add( 'pow_replayed', 3, 'proof of work reused' ),
            Proof::Expired  => $this->add( 'pow_expired', 1, 'proof of work older than 12 hours' ),
            Proof::Ok       => null,
        };

        $meta = $this->s->meta;
        if ( null === $meta ) {
            return;
        }
        $elapsed = (int) ( $meta['e'] ?? 0 );
        $touches = (int) ( $meta['i'] ?? 0 ) + (int) ( $meta['k'] ?? 0 );
        // A visitor resuming a saved form has already done the typing, so time on this page says little.
        if ( $elapsed > 0 && $elapsed < 2500 && ! $this->s->continued ) {
            $this->add( 'too_fast', 3, sprintf( 'sent %.1fs after the page loaded', $elapsed / 1000 ) );
        }
        if ( 0 === $touches && ! $this->s->continued ) {
            $this->add( 'no_interaction', 2, 'no clicks, taps or keys' );
        }
        if ( ! empty( $meta['w'] ) ) {
            $this->add( 'webdriver', 4, 'automated browser' );
        }
    }

    private function agent(): void {
        $agent = $this->s->agent;
        if ( '' === $agent ) {
            $this->add( 'no_agent', 3, 'no user agent' );
        } elseif ( preg_match( '/curl|wget|python|go-http|java\/|okhttp|libwww|scrapy|headlesschrome|phantomjs|httpclient|axios|node-fetch/i', $agent, $m ) ) {
            $this->add( 'bot_agent', 4, 'user agent ' . $m[0] );
        }
    }

    private function content(): void {
        $text = $this->s->content;
        if ( '' === trim( $text ) ) {
            return;
        }

        // Links to the site itself (consent text, tracking fields, page URLs) say nothing.
        $outside = self::foreignLinks( $text );
        $hrefs   = preg_match_all( '/<a\s[^>]*href\s*=\s*["\']?([^"\'\s>]+)/i', $text, $m ) ? $m[1] : [];
        if ( preg_match( '/\[(url|link)[=\]]/i', $text ) || self::foreignLinks( implode( ' ', $hrefs ) ) ) {
            $this->add( 'markup_links', 4, 'BBCode or HTML links' );
        }
        if ( $outside >= 3 ) {
            $this->add( 'many_links', 3, $outside . ' links' );
        } elseif ( $outside > 0 ) {
            $this->add( 'links', 1, $outside . ' link' . ( $outside > 1 ? 's' : '' ) );
        }

        $name = $this->s->name;
        if ( '' !== $name && preg_match( '#https?://|www\.|\.(com|net|ru|xyz|top)\b#i', $name ) ) {
            $this->add( 'link_in_name', 3, 'link in the name field' );
        } elseif ( self::botName( $name ) ) {
            $this->add( 'bot_name', 3, 'generated name' );
        }
        if ( preg_match( '/<script|javascript:|\bon[a-z]+\s*=\s*["\'][^"\']*\(|fromCharCode|document\.cookie|<iframe|<svg[^>]*\bon/i', $text ) ) {
            $this->add( 'injection', 6, 'script injection attempt' );
        }

        $lower = strtolower( $text );
        $hits  = array_values( array_filter(
            (array) apply_filters( 'breakwater_phrases', self::PHRASES ),
            fn ( $phrase ): bool => is_string( $phrase ) && str_contains( $lower, $phrase )
        ) );
        if ( $hits ) {
            $this->add( 'phrases', min( 3, count( $hits ) ), implode( ', ', array_slice( $hits, 0, 5 ) ) );
        }

        // A site written in a Latin script getting a message mostly in another one.
        $letters = (int) preg_match_all( '/\p{L}/u', $text );
        if ( $letters >= 20 && ! preg_match( self::NON_LATIN_LOCALES, get_locale() ) ) {
            $foreign = (int) preg_match_all( '/[\p{Cyrillic}\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Arabic}\p{Thai}\p{Hebrew}\p{Devanagari}]/u', $text );
            if ( $foreign / $letters > 0.3 ) {
                $this->add( 'foreign_script', 4, 'message mostly in a script the site does not use' );
            }
        }

        if ( strlen( $text ) > 40 && strtoupper( $text ) === $text && preg_match( '/[A-Z]/', $text ) ) {
            $this->add( 'all_caps', 1, 'all capitals' );
        }
    }

    private function sender(): void {
        $email = $this->s->email;
        if ( '' === $email || ! str_contains( $email, '@' ) ) {
            return;
        }
        if ( Trust::has( $email ) ) {
            $this->add( 'trusted_sender', -10, 'marked not spam on this site before' );
            return;
        }
        $domain = strtolower( substr( (string) strrchr( $email, '@' ), 1 ) );
        if ( self::disposable( $domain ) ) {
            $this->add( 'disposable_email', 3, $domain . ' is a throwaway mail service' );
        } elseif ( ! self::domainReceivesMail( $domain ) ) {
            $this->add( 'dead_email_domain', 3, $domain . ' cannot receive mail' );
        }
    }

    private function repeats(): void {
        $recent = Log::recent( $this->s );
        if ( $recent['spam_content'] >= 1 ) {
            $this->add( 'repeat_spam', 3, 'same message already caught as spam today' );
        }
        if ( $recent['spam_ip'] >= 2 ) {
            $this->add( 'spam_ip', 2, 'this IP sent spam ' . $recent['spam_ip'] . ' times in the last hour' );
        }
    }

    /**
     * The fleet list, when the host provides one: senders and messages seen on several sites.
     * Thresholds count every site including this one, a step above the backtest's "other
     * sites" tiers, so they never fire earlier than measured: one or two sites is ordinary
     * (people ask a few businesses the same question), three or more is a campaign.
     */
    private function fleet(): void {
        if ( ! Reputation::list() ) {
            return;
        }
        $ip = Reputation::sites( 'ip', Reputation::ip( $this->s->ip ) );
        if ( $ip >= 4 ) {
            $this->add( 'fleet_ip', $ip >= 11 ? 3 : 2, sprintf( 'IP sent spam to %d sites', $ip ) );
        }
        $email = Reputation::sites( 'email', Reputation::email( $this->s->email ) );
        if ( $email >= 3 ) {
            $this->add( 'fleet_email', $email >= 6 ? 5 : 3, sprintf( 'sender sent spam to %d sites', $email ) );
        }
        $message = Reputation::sites( 'message', Reputation::messageFor( $this->s ) );
        $similar = 0;
        foreach ( Reputation::bandsFor( $this->s ) as $band ) {
            $similar = max( $similar, Reputation::sites( 'mband', $band ) );
        }
        $seen = max( $message, $similar );
        if ( $seen >= 4 ) {
            $this->add( 'fleet_message', $seen >= 11 ? 5 : 3, sprintf( $message >= $similar ? 'same message sent to %d sites' : 'near-identical message sent to %d sites', $seen ) );
        }
    }

    /**
     * Links whose host is not this site. Own links come from consent labels and tracking fields.
     */
    public static function foreignLinks( string $text ): int {
        if ( ! preg_match_all( '#(?:https?://|www\.)([a-z0-9.-]*[a-z0-9])#i', $text, $m ) ) {
            return 0;
        }
        $own = self::bareHost( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        return count( array_filter(
            $m[1],
            fn ( string $host ): bool => '' === $own || ( self::bareHost( $host ) !== $own && ! str_ends_with( self::bareHost( $host ), '.' . $own ) )
        ) );
    }

    private static function bareHost( string $host ): string {
        return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
    }

    /**
     * Names spam tools generate: "DennisTew DennisTewOV", or a long random token like "zwusalme3njhdklGP".
     */
    public static function botName( string $name ): bool {
        $name = trim( $name );
        if ( '' === $name ) {
            return false;
        }
        if ( preg_match( '/^(\p{L}{3,})\s+\1[A-Z]{2}$/u', $name ) ) {
            return true;
        }
        foreach ( preg_split( '/\s+/', $name ) ?: [] as $part ) {
            if ( strlen( $part ) >= 12 && preg_match( '/^[a-z0-9]+[A-Z]{2}$/', $part ) && preg_match( '/\d|[bcdfghjklmnpqrstvwxz]{5}/', $part ) ) {
                return true;
            }
        }
        return false;
    }

    public static function disposable( string $domain ): bool {
        static $list = null;
        if ( null === $list ) {
            $list = array_flip( Assets::disposableDomains() );
        }
        return isset( $list[ $domain ] );
    }

    public static function domainReceivesMail( string $domain ): bool {
        if ( '' === $domain || ! function_exists( 'checkdnsrr' ) ) {
            return true;
        }
        $key    = 'breakwater_mx_' . md5( $domain );
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return 'yes' === $cached;
        }
        $ok = checkdnsrr( $domain . '.', 'MX' ) || checkdnsrr( $domain . '.', 'A' ) || checkdnsrr( $domain . '.', 'AAAA' );
        set_transient( $key, $ok ? 'yes' : 'no', DAY_IN_SECONDS );
        return $ok;
    }
}
