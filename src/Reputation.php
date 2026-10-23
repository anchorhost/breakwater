<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * Fleet reputation: how an IP, an email or a message becomes the hash a fleet counts across
 * sites, and the list of hashes the fleet has seen on many sites, which the checker scores.
 * Only hashes ever leave a site.
 *
 * Messages get two fingerprints: an exact one, and eight MinHash bands over three-word
 * shingles. Two messages share a band when they share most of their wording, so a pitch reworded
 * per site (names, greetings, a sentence swapped) still matches. Backtest (75k GF entries):
 * exact matching on 3+ other sites caught 25% of spam, bands 45%.
 *
 * CaptainCore's collector (fetch-site-data, plugins skipped) carries a copy of normalize(),
 * message() and bands(); tests/reputation-parity.php keeps them identical.
 */
final class Reputation {

    /** Changing this starts a new reputation history: every hash changes. */
    public const SALT = 'breakwater-reputation-v1|';

    /** Messages shorter than this (after normalising) are too generic to fingerprint exactly. */
    public const MIN_MESSAGE = 40;

    /** MinHash layout: 8 bands of 4 rows over 3-word shingles, for messages of 8+ words. */
    public const BANDS     = 8;
    public const ROWS      = 4;
    public const SHINGLE   = 3;
    public const MIN_WORDS = 8;
    public const MAX_WORDS = 400;

    /** Where the fleet list is kept, and how old it may get before it is ignored. */
    public const OPTION  = 'breakwater_reputation';
    public const MAX_AGE = 7 * DAY_IN_SECONDS;

    public static function hash( string $kind, string $value ): string {
        return hash( 'sha256', self::SALT . $kind . '|' . $value );
    }

    public static function ip( string $ip ): string {
        $packed = @inet_pton( trim( $ip ) );
        return false === $packed ? '' : self::hash( 'ip', (string) inet_ntop( $packed ) );
    }

    public static function email( string $email ): string {
        $email = strtolower( trim( $email ) );
        return str_contains( $email, '@' ) ? self::hash( 'email', $email ) : '';
    }

    /**
     * The words that make a pitch a pitch: markup, links, digits and the receiving site's own
     * name ("Hello Foresthillinn Net") are removed, so the same pitch normalises the same everywhere.
     */
    public static function normalize( string $text, string $home ): string {
        $host = strtolower( (string) preg_replace( '/^www\./', '', (string) wp_parse_url( $home, PHP_URL_HOST ) ) );
        $name = explode( '.', $host )[0] ?? '';
        $text = (string) preg_replace( '/<[^>]*>/', ' ', $text );
        $text = strtolower( $text );
        $text = (string) preg_replace( '#https?://\S+|www\.\S+|\d+#', ' ', $text );
        if ( strlen( $name ) >= 3 ) {
            $text = str_replace( $name, ' ', $text );
        }
        return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
    }

    public static function message( string $text, string $home ): string {
        $text = self::normalize( $text, $home );
        return mb_strlen( $text ) >= self::MIN_MESSAGE ? self::hash( 'message', $text ) : '';
    }

    /**
     * MinHash bands of the message's three-word shingles.
     *
     * @return list<string>
     */
    public static function bands( string $text, string $home ): array {
        $words = array_slice( preg_split( '/\s+/', self::normalize( $text, $home ), self::MAX_WORDS + 1, PREG_SPLIT_NO_EMPTY ) ?: [], 0, self::MAX_WORDS );
        if ( count( $words ) < self::MIN_WORDS ) {
            return [];
        }
        $shingles = [];
        for ( $i = 0; $i <= count( $words ) - self::SHINGLE; $i++ ) {
            $shingles[ implode( ' ', array_slice( $words, $i, self::SHINGLE ) ) ] = true;
        }
        $shingles = array_keys( $shingles );
        $bands    = [];
        for ( $b = 0; $b < self::BANDS; $b++ ) {
            $mins = [];
            for ( $r = 0; $r < self::ROWS; $r++ ) {
                $seed = $b * self::ROWS + $r;
                $min  = PHP_INT_MAX;
                foreach ( $shingles as $shingle ) {
                    $min = min( $min, crc32( $seed . '|' . $shingle ) );
                }
                $mins[] = $min;
            }
            $bands[] = self::hash( 'mband', $b . '|' . implode( ',', $mins ) );
        }
        return $bands;
    }

    /** The visitor's message: their message field when the form says which it is, else the longest field. */
    public static function messageText( Submission $s ): string {
        if ( '' !== $s->message ) {
            return $s->message;
        }
        $values = array_filter( $s->fields, fn ( $value ): bool => is_string( $value ) && '[redacted]' !== $value && ! is_email( $value ) );
        usort( $values, fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
        return (string) ( $values[0] ?? $s->content );
    }

    public static function messageFor( Submission $s ): string {
        return self::message( self::messageText( $s ), home_url() );
    }

    /** @return list<string> */
    public static function bandsFor( Submission $s ): array {
        return self::bands( self::messageText( $s ), home_url() );
    }

    /**
     * The fleet list a host pushes to the site: hashes (first 16 hex characters) seen on several
     * sites, with how many. Null when there is none, or it is too old to trust.
     *
     * @return array{generated:string, ip?:array<string,int>, email?:array<string,int>, message?:array<string,int>, mband?:array<string,int>}|null
     */
    public static function list(): ?array {
        static $list = false;
        if ( false === $list ) {
            $stored = get_option( self::OPTION );
            $time   = is_array( $stored ) ? strtotime( (string) ( $stored['generated'] ?? '' ) ) : false;
            $list   = $time && time() - $time < self::MAX_AGE ? $stored : null;
        }
        return $list;
    }

    /** How many sites the fleet saw this hash on, 0 when it isn't listed. */
    public static function sites( string $kind, string $hash ): int {
        $list = self::list();
        return '' !== $hash && $list ? (int) ( $list[ $kind ][ substr( $hash, 0, 16 ) ] ?? 0 ) : 0;
    }

    /**
     * Store a list (from the host's sync, or `wp breakwater reputation --import`).
     *
     * @param array<string, mixed> $list
     */
    public static function store( array $list ): bool {
        if ( ! strtotime( (string) ( $list['generated'] ?? '' ) ) ) {
            return false;
        }
        $clean = [ 'generated' => (string) $list['generated'] ];
        foreach ( [ 'ip', 'email', 'message', 'mband' ] as $kind ) {
            $clean[ $kind ] = [];
            foreach ( (array) ( $list[ $kind ] ?? [] ) as $hash => $sites ) {
                if ( preg_match( '/^[a-f0-9]{16}$/', (string) $hash ) ) {
                    $clean[ $kind ][ (string) $hash ] = (int) $sites;
                }
            }
        }
        update_option( self::OPTION, $clean, false );
        return true;
    }
}
