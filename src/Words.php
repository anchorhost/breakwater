<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * The fleet word filter: how much a message's wording looks like spam the fleet has seen.
 *
 * A SpamBayes-style classifier (Robinson's smoothing, Fisher's chi-square combining) over the
 * message's words, word pairs, link domains and the sender's email domain. CaptainCore counts
 * those tokens, hashed, in every site's spam and kept messages; the Manager turns the counts into
 * each token's spam probability and sends the strongest to sites with the fleet list. A token
 * counts only once 3 sites and 5 messages have used it, so names and one-off words never do.
 *
 * It abstains when fewer than half of the message's words are known from kept messages: the
 * kept side is mostly English, so an enquiry in another language would otherwise look like spam.
 * Backtest (2026-10-21, Gravity Forms): local + fleet checks caught 46.9% of spam, with words 61.6%.
 *
 * CaptainCore's collector (fetch-site-data) carries a copy of tokens(); tests/words-parity.php
 * keeps them identical. tests/backtest/words.py is the same tokenizer and classifier in Python.
 */
final class Words {

    /** Changing this starts a new word history: every token hash changes. */
    public const SALT = 'breakwater-words-v1|';

    /** Where the model is kept, and how old it may get before it is ignored. */
    public const OPTION  = 'breakwater_words';
    public const MAX_AGE = 7 * DAY_IN_SECONDS;

    /** Only the start of a long message is read, on both sides. */
    public const MAX_TEXT = 5000;

    /** Tokens weaker than this (|p - .5|) are ignored; at most this many of the strongest are combined. */
    private const MIN_STRENGTH = 0.1;
    private const MAX_CLUES    = 150;

    /** Abstain when fewer than this share of the message's words are known from kept messages. */
    private const COVERAGE = 0.5;

    /**
     * The message's tokens, hashed: words (with a leading '='), word pairs, link domains and the
     * sender's email domain. The receiving site's own name is left out.
     *
     * @return list<string>
     */
    public static function tokens( string $text, string $email, string $home ): array {
        $text = mb_substr( $text, 0, self::MAX_TEXT );
        $out  = [];
        if ( preg_match_all( '#(?:https?://|www\.)([^/\s<>"\']+)#iu', $text, $links ) ) {
            foreach ( $links[1] as $host ) {
                $out[ 'link:' . (string) preg_replace( '/^www\./', '', mb_strtolower( $host ) ) ] = true;
            }
        }
        $text  = (string) preg_replace( '#(?:https?://|www\.)[^/\s<>"\']+#iu', ' ', mb_strtolower( $text ) );
        $own   = explode( '.', explode( '/', (string) preg_replace( '#^https?://(www\.)?#', '', mb_strtolower( $home ) ) )[0] )[0];
        $words = [];
        if ( preg_match_all( "/[a-z\x{e0}-\x{ff}][a-z\x{e0}-\x{ff}'\x{2019}-]{1,24}/u", $text, $found ) ) {
            foreach ( $found[0] as $word ) {
                $word = (string) preg_replace( "/^['\x{2019}-]+|['\x{2019}-]+$/u", '', $word );
                if ( mb_strlen( $word ) >= 2 && $word !== $own ) {
                    $words[] = $word;
                }
            }
        }
        foreach ( $words as $i => $word ) {
            $out[ $word ] = true;
            if ( isset( $words[ $i + 1 ] ) ) {
                $out[ $word . ' ' . $words[ $i + 1 ] ] = true;
            }
        }
        $email = trim( $email );
        if ( str_contains( $email, '@' ) ) {
            $out[ 'from:' . mb_strtolower( substr( $email, strrpos( $email, '@' ) + 1 ) ) ] = true;
        }
        $hashed = [];
        foreach ( array_keys( $out ) as $token ) {
            $token    = (string) $token;
            $hashed[] = ( str_contains( $token, ' ' ) || str_contains( $token, ':' ) ? '' : '=' ) . substr( hash( 'sha256', self::SALT . $token ), 0, 16 );
        }
        return $hashed;
    }

    /** @return list<string> */
    public static function tokensFor( Submission $s ): array {
        return self::tokens( Reputation::messageText( $s ), $s->email, home_url() );
    }

    /**
     * How spammy the tokens look, 0 (kept) to 1 (spam); null without a model, when the wording
     * is unfamiliar, or when no token carries any weight.
     *
     * @param list<string> $tokens
     */
    public static function score( array $tokens ): ?float {
        $model = self::model();
        if ( ! $model || ! $tokens ) {
            return null;
        }
        $words = array_filter( $tokens, fn ( string $t ): bool => str_starts_with( $t, '=' ) );
        if ( $words ) {
            $known = count( array_filter( $words, fn ( string $t ): bool => isset( $model['k'][ substr( $t, 1, 10 ) ] ) ) );
            if ( $known / count( $words ) < self::COVERAGE ) {
                return null;
            }
        }
        $clues = [];
        foreach ( $tokens as $token ) {
            $p = $model['p'][ substr( ltrim( $token, '=' ), 0, 12 ) ] ?? null;
            if ( null !== $p ) {
                $p = min( 0.999, max( 0.001, $p / 1000 ) );
                if ( abs( $p - 0.5 ) >= self::MIN_STRENGTH ) {
                    $clues[] = $p;
                }
            }
        }
        if ( ! $clues ) {
            return null;
        }
        usort( $clues, fn ( float $a, float $b ): int => abs( $b - 0.5 ) <=> abs( $a - 0.5 ) );
        $clues = array_slice( $clues, 0, self::MAX_CLUES );
        $n     = count( $clues );
        $ham   = self::chi2q( -2.0 * array_sum( array_map( 'log', $clues ) ), 2 * $n );
        $spam  = self::chi2q( -2.0 * array_sum( array_map( fn ( float $p ): float => log( 1.0 - $p ), $clues ) ), 2 * $n );
        return ( 1.0 + $ham - $spam ) / 2.0;
    }

    /** P(chi-square with $v (even) degrees of freedom >= $x2). */
    private static function chi2q( float $x2, int $v ): float {
        $m     = $x2 / 2.0;
        $total = $term = exp( -$m );
        for ( $i = 1; $i < intdiv( $v, 2 ); $i++ ) {
            $term  *= $m / $i;
            $total += $term;
        }
        return min( $total, 1.0 );
    }

    /**
     * The model a host pushed: each token's spam probability (per mille, keyed by the first 12 hex
     * characters of its hash) and the words known from kept messages (first 10 hex characters).
     * Null when there is none, or it is too old to trust.
     *
     * @return array{generated:string, p:array<string,int>, k:array<string,true>}|null
     */
    public static function model(): ?array {
        static $model = false;
        if ( false === $model ) {
            $model  = null;
            $stored = get_option( self::OPTION );
            $data   = is_string( $stored ) ? json_decode( $stored, true ) : null;
            $time   = is_array( $data ) ? strtotime( (string) ( $data['generated'] ?? '' ) ) : false;
            if ( $time && time() - $time < self::MAX_AGE && is_array( $data['p'] ?? null ) && is_array( $data['k'] ?? null ) ) {
                $model = [ 'generated' => (string) $data['generated'], 'p' => $data['p'], 'k' => array_fill_keys( array_map( 'strval', $data['k'] ), true ) ];
            }
        }
        return $model;
    }

    /**
     * Keep a model sent by the host (the JSON CaptainCore exports). Kept as the JSON string, not
     * autoloaded: it is a few hundred kilobytes and only read when a form is sent.
     */
    public static function store( string $json ): bool {
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || 1 !== (int) ( $data['v'] ?? 0 ) || ! strtotime( (string) ( $data['generated'] ?? '' ) ) || ! is_array( $data['p'] ?? null ) || ! is_array( $data['k'] ?? null ) ) {
            return false;
        }
        $p = [];
        foreach ( $data['p'] as $key => $value ) {
            $key = (string) $key; // A hash prefix of digits only comes back from json_decode as an integer key.
            if ( preg_match( '/^[0-9a-f]{12}$/', $key ) && is_int( $value ) && $value >= 0 && $value <= 1000 ) {
                $p[ $key ] = $value;
            }
        }
        $k = array_values( array_filter( array_map( fn ( mixed $key ): string => is_scalar( $key ) ? (string) $key : '', $data['k'] ), fn ( string $key ): bool => 1 === preg_match( '/^[0-9a-f]{10}$/', $key ) ) );
        update_option( self::OPTION, (string) wp_json_encode( [ 'v' => 1, 'generated' => (string) $data['generated'], 'p' => (object) $p, 'k' => $k ] ), false );
        return true;
    }

    /** What the site holds, for status screens: when it was made and how many tokens. */
    public static function summary(): string {
        $model = self::model();
        return $model ? sprintf( '%s, %d tokens, %d known words', $model['generated'], count( $model['p'] ), count( $model['k'] ) ) : 'none';
    }
}
