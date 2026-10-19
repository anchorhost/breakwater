<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * Stateless proof of work. The browser fetches a signed challenge, finds the number whose
 * SHA-256 with the salt matches, and submits it with the form. Nothing is stored until a
 * solution is spent, so cached pages and multiple tabs just work.
 */
final class Challenge {

    public const LIFETIME = 12 * HOUR_IN_SECONDS;

    public static function difficulty(): int {
        return max( 1000, (int) apply_filters( 'breakwater_difficulty', 20000 ) );
    }

    /** @return array{algorithm:string, salt:string, challenge:string, signature:string, max:int} */
    public static function issue(): array {
        $now       = time();
        $salt      = bin2hex( random_bytes( 8 ) ) . '.' . $now . '.' . ( $now + self::LIFETIME );
        $max       = self::difficulty();
        $challenge = hash( 'sha256', $salt . random_int( 0, $max ) );
        return [
            'algorithm' => 'SHA-256',
            'salt'      => $salt,
            'challenge' => $challenge,
            'signature' => hash_hmac( 'sha256', $challenge, Plugin::secret() ),
            'max'       => $max,
        ];
    }

    /**
     * @param string $token Base64 JSON from the browser: salt, challenge, signature, number.
     */
    public static function verify( string $token ): Proof {
        if ( '' === $token ) {
            return Proof::Missing;
        }
        $data = json_decode( base64_decode( $token, true ) ?: '', true );
        if ( ! is_array( $data ) || ! isset( $data['salt'], $data['challenge'], $data['signature'], $data['number'] ) ) {
            return Proof::Malformed;
        }
        $salt      = (string) $data['salt'];
        $challenge = (string) $data['challenge'];
        $parts     = explode( '.', $salt );
        if ( 3 !== count( $parts ) ) {
            return Proof::Malformed;
        }
        if ( ! hash_equals( hash_hmac( 'sha256', $challenge, Plugin::secret() ), (string) $data['signature'] ) ) {
            return Proof::Forged;
        }
        if ( ! hash_equals( $challenge, hash( 'sha256', $salt . (int) $data['number'] ) ) ) {
            return Proof::Wrong;
        }
        if ( time() > (int) $parts[2] ) {
            return Proof::Expired;
        }
        // One solution per submission, remembered until the challenge would expire anyway.
        $key = 'breakwater_used_' . substr( $challenge, 0, 32 );
        if ( get_transient( $key ) ) {
            return Proof::Replayed;
        }
        set_transient( $key, 1, max( 60, (int) $parts[2] - time() ) );
        return Proof::Ok;
    }
}
