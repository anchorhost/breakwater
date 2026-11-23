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
    public const CERTAIN = [ 'honeypot', 'no_js', 'pow_invalid', 'webdriver', 'headless_browser', 'injection' ];

    /** URL shorteners and messaging redirects commonly used to hide spam destinations. */
    private const SHORT_LINKS = '#(?<![\w.-])(?:bit\.ly|tinyurl\.com|rb\.gy|cutt\.ly|shorturl\.at|is\.gd|rebrand\.ly|ow\.ly|t\.me|wa\.me)/[^\s<>"\']+#i';

    public const PHRASES = [
        'seo services', 'backlink', 'first page of google', 'page 1 of google', 'rank your website', 'ranking on google',
        'increase your traffic', 'website traffic', 'guest post', 'link building', 'domain authority',
        'web design services', 'redesign your website', 'lead generation', 'more leads', 'grow your business online',
        'crypto', 'bitcoin', 'forex', 'binary option', 'casino', 'betting', 'viagra', 'cialis', 'porn',
        'make money online', 'work from home', 'loan offer', 'payday', 'telegram', 'whatsapp', 'unsubscribe here',
        'opt out', 'promote your', 'video explainer', 'ai chatbot for your website', 'virtual assistant',
    ];

    /** Script injection: a person's message never holds these. */
    public const INJECTION = '/<script|javascript:|\bon[a-z]+\s*=\s*["\'][^"\']*\(|fromCharCode|document\.cookie|<iframe|<svg[^>]*\bon/i';

    /** Chrome 138 shipped 2025-06-24 (version, timestamp), and a new major version every four weeks since. */
    private const CHROME_ANCHOR = [ 138, 1750723200 ];

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
            $inconsistencies = self::inconsistencies( $s->agent, $s->headers, $s->meta, time() );
            $checker->browser( $inconsistencies );
            $checker->consistency( $inconsistencies );
        }
        $checker->agent();
        $checker->content();
        $checker->sender();
        $checker->repeats();
        $checker->fleet();

        // A sender marked not spam before gets the benefit of the doubt, never a pass for what only automation does.
        if ( isset( $checker->signals['trusted_sender'] ) && array_intersect_key( $checker->signals, array_flip( self::CERTAIN ) ) ) {
            $checker->signals['trusted_sender'] = [ 0, 'marked not spam on this site before, but sent by a bot' ];
        }
        $signals = (array) apply_filters( 'breakwater_signals', $checker->signals, $s );
        $score   = array_sum( array_map( fn ( array $signal ): int => (int) $signal[0], $signals ) );
        return new Verdict( $score >= self::threshold(), $score, $signals );
    }

    private function add( string $code, int $points, string $detail = '' ): void {
        $this->signals[ $code ] = [ $points, $detail ];
    }

    /** @param array<string, string> $inconsistencies */
    private function browser( array $inconsistencies ): void {
        if ( '' !== $this->s->trap ) {
            $this->add( 'honeypot', 10, 'hidden field filled' );
        }
        $proof = Challenge::verify( $this->s->token );
        match ( $proof ) {
            // No script ran at all (or a forged bw_meta with missing/contradicting headers): bot posting straight to the form.
            // Script ran from a real browser but the work was not done yet (or the REST API is blocked): weaker.
            Proof::Missing => self::genuineMeta( $this->s->meta, $this->s->agent, $inconsistencies )
                ? $this->add( 'pow_pending', 2, 'proof of work not finished' )
                : $this->add( 'no_js', 6, 'no browser proof' ),
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
        if ( ( ! isset( $meta['e'] ) || $elapsed < 2500 ) && ! $this->s->continued ) {
            $this->add( 'too_fast', 3, isset( $meta['e'] ) ? sprintf( 'sent %.1fs after the page loaded', $elapsed / 1000 ) : 'no elapsed time reported' );
        }
        if ( 0 === $touches && ! $this->s->continued ) {
            $this->add( 'no_interaction', 2, 'no clicks, taps or keys' );
        }
        if ( ! empty( $meta['w'] ) ) {
            $this->add( 'webdriver', 6, 'automated browser' );
        }
        // Desktop browser automation (Playwright fill(), DevTools insertText) puts text straight
        // into a textarea without firing keydown. Mobile is skipped (iOS voice dictation does this).
        $typed = (int) ( $meta['t'] ?? 0 );
        $keys  = (int) ( $meta['k'] ?? 0 );
        if ( $typed >= 50 && 0 === $keys && ! $this->s->continued && ! preg_match( '/Mobile|Android|iPhone|iPad|iPod/i', $this->s->agent ) ) {
            $this->add( 'filled_without_keys', 2, 'message filled without typing' );
        }
    }

    /**
     * Whether bw_meta looks like it came from our script in a real browser, rather than a bot
     * posting a stub bw_meta to turn no_js (6) into pow_pending (2).
     *
     * @param array<string, mixed>|null $meta
     * @param array<string, string>     $inconsistencies
     */
    private static function genuineMeta( ?array $meta, string $agent, array $inconsistencies ): bool {
        if ( null === $meta || (int) ( $meta['v'] ?? 0 ) < 1 ) {
            return false;
        }
        if ( (int) $meta['v'] >= 3 && '' !== $agent && strlen( $agent ) < 300 && ! is_string( $meta['u'] ?? null ) ) {
            return false;
        }
        return ! array_intersect_key( $inconsistencies, array_flip( [ 'no_fetch_metadata', 'no_language', 'client_hints', 'headless_browser', 'agent_mismatch' ] ) );
    }

    /**
     * Does the browser match what its user agent claims? Scored from fleet CleanTalk telemetry.
     *
     * @param array<string, string> $inconsistencies
     */
    private function consistency( array $inconsistencies ): void {
        foreach ( $inconsistencies as $code => $detail ) {
            $points = match ( $code ) {
                'headless_browser'  => 6,
                'no_fetch_metadata' => 3,
                'no_language'       => 3,
                'agent_mismatch'    => 3,
                'client_hints'      => str_starts_with( $detail, 'no client hints' ) ? 3 : 4,
                'stale_browser'     => 1,
                default             => 0,
            };
            $this->add( $code, $points, $detail );
        }
    }

    /**
     * Where a browser's own headers or script contradict its user agent, or the version it
     * claims is long out of date (most spam claims a Chrome a year or more old; people update).
     *
     * @param array<string, string>     $headers As Submission::$headers.
     * @param array<string, mixed>|null $meta    The script's browser proof.
     * @return array<string, string> signal code => detail
     */
    public static function inconsistencies( string $agent, array $headers, ?array $meta, int $now ): array {
        $out     = [];
        $browser = self::claimedBrowser( $agent );
        $https   = '1' === ( $headers['https'] ?? '' );

        if ( $browser && 'chromium' === $browser['engine'] ) {
            $behind = self::CHROME_ANCHOR[0] + intdiv( $now - self::CHROME_ANCHOR[1], 28 * DAY_IN_SECONDS ) - $browser['version'];
            if ( $behind >= 12 ) {
                $out['stale_browser'] = sprintf( 'Chrome %d, %d versions old', $browser['version'], $behind );
            }
        }

        // Fetch metadata: Chrome 80+, Firefox 90+, Safari 16.4+, on secure pages only.
        $fetches = $browser && match ( $browser['engine'] ) {
            'chromium' => $browser['version'] >= 80,
            'gecko'    => $browser['version'] >= 90,
            'webkit'   => $browser['version'] >= 1604,
        };
        if ( $https && $fetches && ! isset( $headers['fetch_mode'] ) && ! isset( $headers['fetch_site'] ) ) {
            $out['no_fetch_metadata'] = sprintf( '%s sent no fetch metadata', $browser['name'] );
        }

        // Client hints: only Chromium sends them, and from 90 on, it always does on secure pages
        // (Android's in-app web views aside).
        // Every browser sends the visitor's languages; Playwright's headless Chromium doesn't.
        if ( $https && $browser && ! isset( $headers['language'] ) ) {
            $out['no_language'] = sprintf( 'no Accept-Language from %s', $browser['name'] );
        }

        $hints = $headers['ch_ua'] ?? '';
        if ( str_contains( $hints, 'HeadlessChrome' ) ) {
            $out['headless_browser'] = 'client hints name HeadlessChrome';
        } elseif ( '' !== $hints && $browser && 'chromium' !== $browser['engine'] ) {
            $out['client_hints'] = sprintf( 'client hints from %s, which never sends them', $browser['name'] );
        } elseif ( '' === $hints && $https && $browser && 'chromium' === $browser['engine'] && $browser['version'] >= 90 && ! str_contains( $agent, '; wv)' ) ) {
            $out['client_hints'] = sprintf( 'no client hints from %s', $browser['name'] );
        } elseif ( '' !== $hints && $browser ) {
            $platform = self::platform( $agent );
            $said     = trim( $headers['ch_platform'] ?? '', '" ' );
            $mobile   = $headers['ch_mobile'] ?? '';
            if ( '' !== $said && '' !== $platform && strcasecmp( $said, $platform ) !== 0 ) {
                $out['client_hints'] = sprintf( 'client hints say %s, the user agent %s', $said, $platform );
            } elseif ( '' !== $mobile && ( '?1' === $mobile ) !== str_contains( $agent, 'Mobile' ) ) {
                $out['client_hints'] = '?1' === $mobile ? 'client hints say mobile, the user agent desktop' : 'client hints say desktop, the user agent mobile';
            } elseif ( preg_match( '/"Chromium";\s*v="(\d+)"/', $hints, $m ) && abs( (int) $m[1] - $browser['version'] ) > 2 ) {
                $out['client_hints'] = sprintf( 'client hints say Chrome %d, the user agent %d', $m[1], $browser['version'] );
            }
        }

        // The page's script hashes navigator.userAgent; a tool that sets the header alone differs.
        $seen = is_string( $meta['u'] ?? null ) ? $meta['u'] : '';
        if ( '' !== $seen && '' !== $agent && strlen( $agent ) < 300 && ! hash_equals( hash( 'fnv1a32', $agent ), $seen ) ) {
            $out['agent_mismatch'] = "the page's script saw a different user agent";
        }
        return $out;
    }

    /**
     * The engine and version a user agent claims: Chromium and Gecko by major version, WebKit
     * as major * 100 + minor (every iOS browser is WebKit, whatever it calls itself).
     *
     * @return array{engine:string, version:int, name:string}|null
     */
    public static function claimedBrowser( string $agent ): ?array {
        if ( preg_match( '/\((?:iPhone|iPad|iPod)[^)]*? OS (\d+)_(\d+)/', $agent, $m ) ) {
            return [ 'engine' => 'webkit', 'version' => (int) $m[1] * 100 + (int) $m[2], 'name' => 'iOS ' . $m[1] . '.' . $m[2] ];
        }
        if ( preg_match( '#Firefox/(\d+)#', $agent, $m ) ) {
            return [ 'engine' => 'gecko', 'version' => (int) $m[1], 'name' => 'Firefox ' . $m[1] ];
        }
        if ( preg_match( '#Chrome/(\d+)#', $agent, $m ) && ! str_contains( $agent, ' Edge/' ) ) {
            return [ 'engine' => 'chromium', 'version' => (int) $m[1], 'name' => 'Chrome ' . $m[1] ];
        }
        if ( preg_match( '#Version/(\d+)\.(\d+).*Safari/#', $agent, $m ) ) {
            return [ 'engine' => 'webkit', 'version' => (int) $m[1] * 100 + (int) $m[2], 'name' => 'Safari ' . $m[1] . '.' . $m[2] ];
        }
        return null;
    }

    /** The platform as client hints name it, from the user agent. */
    private static function platform( string $agent ): string {
        return match ( true ) {
            str_contains( $agent, 'Android' )    => 'Android',
            str_contains( $agent, 'Windows NT' ) => 'Windows',
            str_contains( $agent, 'CrOS' )       => 'Chrome OS',
            str_contains( $agent, 'Macintosh' )  => 'macOS',
            str_contains( $agent, 'Linux' )      => 'Linux',
            default                              => '',
        };
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

        // Strip invisible zero-width characters spammers insert to evade phrase and link checks.
        $utf8  = function_exists( 'wp_check_invalid_utf8' ) ? wp_check_invalid_utf8( $text, true ) : $text;
        $clean = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $utf8 ) ?? $utf8;
        if ( preg_match( '/(?<![\p{L}\p{N}])(?:(?=[\p{L}\p{N}]*\p{Latin})(?=[\p{L}\p{N}]*\p{Cyrillic})[\p{L}\p{N}]{3,}|(?=\p{L}*\p{Latin})(?=\p{L}*\p{Greek})\p{L}{4,})(?![\p{L}\p{N}])/u', $clean ) ) {
            $this->add( 'obfuscated_text', 4, 'mixed-script characters inside a word' );
        }

        // Links to the site itself (consent text, tracking fields, page URLs) say nothing.
        $outside = self::foreignLinks( $clean );
        $hrefs   = preg_match_all( '/<a\s[^>]*href\s*=\s*["\']?([^"\'\s>]+)/i', $clean, $m ) ? $m[1] : [];
        if ( preg_match( '/\[(url|link)[=\]]/i', $clean ) || self::foreignLinks( implode( ' ', $hrefs ) ) ) {
            $this->add( 'markup_links', 4, 'BBCode or HTML links' );
        }
        if ( $outside >= 3 ) {
            $this->add( 'many_links', 3, $outside . ' links' );
        } elseif ( $outside > 0 ) {
            $this->add( 'links', 1, $outside . ' link' . ( $outside > 1 ? 's' : '' ) );
        }
        if ( preg_match( self::SHORT_LINKS, $clean ) ) {
            $this->add( 'short_link', 2, 'shortened or messaging redirect link' );
        }

        $name = $this->s->name;
        if ( '' !== $name && preg_match( '#https?://|www\.|\.(com|net|ru|xyz|top)\b#i', $name ) ) {
            $this->add( 'link_in_name', 3, 'link in the name field' );
        } elseif ( self::botName( $name ) ) {
            $this->add( 'bot_name', 3, 'generated name' );
        }
        if ( preg_match( self::INJECTION, $text ) ) {
            $this->add( 'injection', 6, 'script injection attempt' );
        }

        $hits = array_values( array_filter(
            (array) apply_filters( 'breakwater_phrases', self::PHRASES ),
            fn ( $phrase ): bool => is_string( $phrase ) && '' !== $phrase && (bool) preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?:s|es|ing)?(?![\p{L}\p{N}])/iu', $clean )
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

        $address = self::siteAddress( $text, (string) wp_parse_url( home_url(), PHP_URL_HOST ), (string) get_bloginfo( 'name' ) );
        if ( $address ) {
            $this->add( 'site_address', $address[0], $address[1] );
        }
    }

    /**
     * Mail-merge outreach puts the site's address where a person would put its name: "Hello
     * Example Com", "Hey team example.com", or a link to another site carrying it
     * ("directoryinspector.com/example.com"). People write to a business by its name, so this is
     * skipped when the site's name is its address. Only the strongest use counts.
     *
     * @return array{0:int, 1:string}|null
     */
    public static function siteAddress( string $text, string $host, string $site_name ): ?array {
        $host = self::bareHost( $host );
        if ( ! str_contains( $host, '.' ) || str_contains( strtolower( str_replace( ' ', '', $site_name ) ), $host ) ) {
            return null;
        }
        $quoted = preg_quote( $host, '/' );
        $words  = implode( '[\s.]+', array_map( fn ( string $label ): string => preg_quote( $label, '/' ), explode( '.', $host ) ) );
        if ( preg_match( '/\b(?:hi|hello|hey|dear|good\s+(?:day|morning|afternoon|evening)|greetings)[\s,]+(?:team\s+)?(?:https?:\/\/)?(?:www\.)?' . $words . '(?![\w-])/i', $text ) ) {
            return [ 4, 'greets the site by its address' ];
        }
        if ( preg_match_all( '#(?:https?://)?(?:www\.)?([a-z0-9-]+(?:\.[a-z0-9-]+)+)[/?\#](\S*)#i', $text, $links, PREG_SET_ORDER ) ) {
            foreach ( $links as $link ) {
                $other = self::bareHost( $link[1] );
                if ( $other !== $host && ! str_ends_with( $other, '.' . $host ) && preg_match( '/(?<![\w-])' . $quoted . '(?![\w-])/i', $link[2] ) ) {
                    return [ 3, "a link to another site carrying this site's address" ];
                }
            }
        }
        $prose = (string) preg_replace( '#<[^>]+>|(?:https?://|www\.)\S+|\S+@\S+#i', ' ', $text );
        if ( preg_match( '/(?<![\w.\/@-])' . $quoted . '(?![\w-])/i', $prose ) ) {
            return [ 1, "names this site's address" ];
        }
        return null;
    }

    /** Already spam: no need for a DNS lookup a visitor could make slow on purpose. */
    private function decided(): bool {
        return array_sum( array_map( fn ( array $signal ): int => (int) $signal[0], $this->signals ) ) >= self::threshold()
            || array_intersect_key( $this->signals, array_flip( self::CERTAIN ) );
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
        if ( Log::reportedSender( $email ) ) {
            $this->add( 'reported_sender', 5, 'an earlier message from this sender was reported as spam' );
        }
        $domain = strtolower( substr( (string) strrchr( $email, '@' ), 1 ) );
        if ( self::disposable( $domain ) ) {
            $this->add( 'disposable_email', 3, $domain . ' is a throwaway mail service' );
        } elseif ( ! $this->decided() && ! self::domainReceivesMail( $domain ) ) {
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
        if ( isset( $list[ $domain ] ) ) {
            return true;
        }
        // Catch subdomains of throwaway mail services (e.g. sub.mailinator.com).
        $parts = explode( '.', $domain );
        return count( $parts ) > 2 && isset( $list[ implode( '.', array_slice( $parts, -2 ) ) ] );
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
