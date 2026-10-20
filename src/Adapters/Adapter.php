<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Guard;
use Breakwater\Submission;
use Breakwater\Verdict;

/**
 * One integration with a form, comment or registration flow. It hooks the flow's own
 * server-side processing, builds a Submission, and stops spam the way that flow does
 * natively: its spam folder where it has one, a visible error otherwise.
 */
abstract class Adapter {

    /** Key stored in the log's source column. */
    abstract public function source(): string;

    /** Name shown to people. */
    abstract public function label(): string;

    /** Whether the integrated plugin or theme is present on this site. */
    abstract public function detected(): bool;

    /** Add the hooks. Called for every adapter: hooks for absent plugins never fire. */
    abstract public function register(): void;

    /**
     * Whether stopped spam lands somewhere the site owner can restore it (a spam folder).
     * When false, the log offers "Email to admin" instead.
     */
    public function keepsSpam(): bool {
        return false;
    }

    /**
     * Hand a held submission back to the plugin as if it had never been held: saved where the
     * plugin saves it, with its emails and integrations run. Called once per row, through
     * Release::run(), with the visitor's request restored. Returns what happened in a sentence
     * for the site owner, or null when it can't, and Breakwater emails the fields instead.
     */
    public function release( object $row ): ?string {
        return null;
    }

    /** Whether this adapter hands submissions back to its plugin rather than emailing them. */
    public function handsBack(): bool {
        return ( new \ReflectionMethod( $this, 'release' ) )->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Who normally receives this form's submissions, for delivering a held one. Empty means
     * the site admin. $form is the log's form column ("<id>: <title>").
     *
     * @return list<string>
     */
    public function recipients( string $form ): array {
        return [];
    }

    /** The numeric form ID at the start of the log's form column. */
    protected static function formId( string $form ): int {
        return (int) strtok( $form, ':' );
    }

    /**
     * Email addresses in a recipient setting, with the admin-email tags form plugins use resolved.
     *
     * @return list<string>
     */
    protected static function emails( string $setting ): array {
        $setting = str_ireplace( [ '{admin_email}', '[_site_admin_email]' ], (string) get_option( 'admin_email' ), $setting );
        preg_match_all( '/[^\s,;<>"\'{}\[\]]+@[^\s,;<>"\'{}\[\]]+/', $setting, $m );
        return array_values( array_unique( array_filter( $m[0], 'is_email' ) ) );
    }

    protected function evaluate( Submission $s ): Verdict {
        return Guard::evaluate( $s );
    }

    protected function message( string $kind = 'message' ): string {
        return Guard::message( $kind );
    }
}
