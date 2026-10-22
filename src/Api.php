<?php

declare(strict_types=1);

namespace Breakwater;

use Breakwater\Adapters\Registry;

/**
 * What another admin screen (Minn Admin) builds on: plain strings and arrays, no enum or
 * class of ours leaves, and the same answers Tools → Breakwater gives. A screen may read the
 * log table itself (Api::table(), its columns as Log::install() makes them); the meaning of
 * a row, the counts and the two writes come from here, so they never drift from ours.
 *
 * VERSION goes up when an answer changes shape. The writes check the caller themselves.
 */
final class Api {

    public const VERSION = 1;

    /** Who may read the log and say "Not spam" or "Was spam": the Tools screen's own rule. */
    public static function canManage(): bool {
        return current_user_can( 'manage_options' );
    }

    public static function table(): string {
        return Log::table();
    }

    /** Whether the log table exists yet (it is made on the first load after activation). */
    public static function ready(): bool {
        global $wpdb;
        return (int) get_option( 'breakwater_db_version' ) >= 1
            && self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
    }

    /**
     * The summary the Tools screen opens with.
     *
     * @return array{mode:string, mode_label:string, mode_help:string, configured:string, pinned:bool, other:string, protecting:list<string>, days:int, checked:int, spam:int, held:int, released:int, ham:int}
     */
    public static function status(): array {
        $mode       = Plugin::mode();
        $configured = Plugin::configuredMode();
        return [
            'mode'       => $mode->value,
            'mode_label' => $mode->label(),
            'mode_help'  => $mode->help(),
            'configured' => $configured->value,
            'pinned'     => defined( 'BREAKWATER_MODE' ),
            'other'      => Plugin::otherChecker(),
            'protecting' => array_values( array_map( fn ( $adapter ): string => $adapter->label(), Registry::detected() ) ),
            'days'       => Log::keepDays(),
        ] + Log::counts( Log::keepDays() );
    }

    /** @return array<string, string> every mode a site can be set to, value => label */
    public static function modes(): array {
        $out = [];
        foreach ( Mode::cases() as $case ) {
            $out[ $case->value ] = $case->label();
        }
        return $out;
    }

    /** @return array<string, string> every integration, log source => label */
    public static function sources(): array {
        return array_map( fn ( $adapter ): string => $adapter->label(), Registry::all() );
    }

    /**
     * Where a row stands: held (stopped, waiting), logged (spam let through in monitor mode),
     * released (marked not spam or delivered), clean, or was_spam (clean, then marked spam).
     *
     * @param array<string, mixed>|object $row A log row.
     */
    public static function state( array|object $row ): string {
        $row = (object) $row;
        if ( 'ham' === $row->verdict ) {
            return 'spam' === $row->feedback ? 'was_spam' : 'clean';
        }
        if ( ! empty( $row->delivered_at ) || 'not_spam' === $row->feedback ) {
            return 'released';
        }
        return 'blocked' === $row->action ? 'held' : 'logged';
    }

    /** @return array<string, string> state => the Tools screen's word for it */
    public static function states(): array {
        return [ 'held' => 'Held', 'logged' => 'Spam, logged', 'released' => 'Not spam', 'clean' => 'Clean', 'was_spam' => 'Was spam' ];
    }

    /** @param array<string, mixed>|object $row */
    public static function canRelease( array|object $row ): bool {
        $row = (object) $row;
        return 'spam' === $row->verdict && 'not_spam' !== $row->feedback && empty( $row->delivered_at );
    }

    /** @param array<string, mixed>|object $row */
    public static function canMarkSpam( array|object $row ): bool {
        $row = (object) $row;
        return 'ham' === $row->verdict && 'spam' !== $row->feedback;
    }

    /**
     * Why it scored what it did, strongest first.
     *
     * @param array<string, mixed>|object $row
     * @return list<array{code:string, points:int, detail:string}>
     */
    public static function reasons( array|object $row ): array {
        $signals = json_decode( (string) ( (object) $row )->signals, true );
        $out     = [];
        foreach ( is_array( $signals ) ? $signals : [] as $code => $signal ) {
            if ( is_array( $signal ) ) {
                $out[] = [ 'code' => (string) $code, 'points' => (int) ( $signal[0] ?? 0 ), 'detail' => (string) ( $signal[1] ?? '' ) ];
            }
        }
        usort( $out, fn ( array $a, array $b ): int => $b['points'] <=> $a['points'] );
        return $out;
    }

    /**
     * What was sent, field by field, as logged (passwords and card data were never kept).
     *
     * @param array<string, mixed>|object $row
     * @return array<string, string>
     */
    public static function fields( array|object $row ): array {
        $payload = json_decode( (string) ( (object) $row )->payload, true );
        $fields  = is_array( $payload ) && is_array( $payload['fields'] ?? null ) ? $payload['fields'] : [];
        return array_map( fn ( $value ): string => is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ), $fields );
    }

    /** The page it was sent from, as logged. */
    public static function page( array|object $row ): string {
        $payload = json_decode( (string) ( (object) $row )->payload, true );
        return is_array( $payload ) ? (string) ( $payload['url'] ?? '' ) : '';
    }

    /**
     * "Not spam": hand a held submission back to its form, as the Tools screen's link does.
     *
     * @return array{ok:bool, outcome:string} outcome is the sentence kept on the row, or why nothing happened
     */
    public static function release( int $id ): array {
        $row = self::canManage() ? Log::get( $id ) : null;
        if ( ! $row || ! self::canRelease( $row ) ) {
            return [ 'ok' => false, 'outcome' => $row ? 'This submission is not waiting for a decision.' : 'Not allowed.' ];
        }
        $outcome = Release::run( $row );
        return null === $outcome
            ? [ 'ok' => false, 'outcome' => 'The email could not be sent. Check the site can send mail, then try again.' ]
            : [ 'ok' => true, 'outcome' => $outcome ];
    }

    /** "Was spam": a clean submission that was spam after all. */
    public static function markSpam( int $id ): bool {
        $row = self::canManage() ? Log::get( $id ) : null;
        if ( ! $row || ! self::canMarkSpam( $row ) ) {
            return false;
        }
        Feedback::spam( $id );
        return true;
    }

    /**
     * Set the mode, as the Tools screen's Save does. Nothing changes while BREAKWATER_MODE pins it.
     */
    public static function setMode( string $mode ): bool {
        $mode = Mode::tryFrom( $mode );
        if ( ! $mode || ! self::canManage() || defined( 'BREAKWATER_MODE' ) ) {
            return false;
        }
        update_option( Plugin::OPTION, array_merge( Plugin::settings(), [ 'mode' => $mode->value ] ) );
        return true;
    }
}
