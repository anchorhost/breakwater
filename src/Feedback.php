<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * What the site owner says about a verdict, from wherever they say it: the Breakwater log, a
 * Deliver link in the digest, or the form plugin's own "Not spam" / "Spam" buttons.
 * "Not spam" trusts the sender on this site and is reported to the fleet as a correction.
 */
final class Feedback {

    /** Once per row: releasing a held entry comes back here through the plugin's own hooks. */
    public static function notSpam( int $log_id ): void {
        $row = Log::get( $log_id );
        if ( ! $row || 'not_spam' === $row->feedback ) {
            return;
        }
        Log::setFeedback( $log_id, 'not_spam' );
        if ( '' !== (string) $row->email ) {
            Trust::add( (string) $row->email );
        }
        do_action( 'breakwater_not_spam', $row );
    }

    public static function spam( int $log_id ): void {
        $row = Log::get( $log_id );
        if ( ! $row ) {
            return;
        }
        Log::setFeedback( $log_id, 'spam' );
        if ( '' !== (string) $row->email ) {
            Trust::remove( (string) $row->email );
        }
        do_action( 'breakwater_marked_spam', $row );
    }
}
