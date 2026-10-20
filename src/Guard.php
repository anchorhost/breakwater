<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * The one decision every adapter asks for: check, log, and say whether to act.
 */
final class Guard {

    /** @var array<string, Verdict> Some plugins ask more than once, and a proof of work is single-use. */
    private static array $seen = [];

    /** The log row of this request's most recent check, for adapters that link it to the entry they save. */
    private static int $last_log_id = 0;

    public static function lastLogId(): int {
        return self::$last_log_id;
    }

    public static function evaluate( Submission $s ): Verdict {
        $key = $s->source . '|' . $s->form;
        if ( isset( self::$seen[ $key ] ) ) {
            return self::$seen[ $key ];
        }
        if ( Release::running() ) {
            return Verdict::clean(); // The owner said it isn't spam: a replay is never judged again.
        }
        if ( apply_filters( 'breakwater_skip', self::trusted(), $s ) ) {
            return self::$seen[ $key ] = Verdict::clean();
        }
        if ( '' === Plugin::otherChecker() ) {
            $s->other = null; // Nothing else judged it, so there is nothing to compare.
        }

        $verdict = Checker::check( $s );
        $act     = $verdict->spam && Mode::Protect === Plugin::mode();
        $action  = $verdict->spam ? ( $act ? 'blocked' : 'logged' ) : 'allowed';
        $verdict = $verdict->acted( $act, Log::insert( $s, $verdict, $action ) );
        self::$last_log_id = $verdict->log_id;

        do_action( 'breakwater_checked', $verdict, $s );
        return self::$seen[ $key ] = $verdict;
    }

    /** People who can already publish or manage the site are never checked. */
    public static function trusted(): bool {
        return is_user_logged_in() && ( current_user_can( 'edit_posts' ) || current_user_can( 'moderate_comments' ) );
    }

    public static function message(): string {
        return (string) apply_filters(
            'breakwater_message',
            __( 'Your message looks like spam, so it was not sent. Please try again, or contact us another way.', 'breakwater' )
        );
    }
}
