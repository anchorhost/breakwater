<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Checker;
use Breakwater\Feedback;
use Breakwater\Guard;
use Breakwater\Log;
use Breakwater\Submission;

/**
 * Gravity Forms. Spam is saved as a spam entry with the reason in its notes, notifications
 * and feeds are skipped, and "Not Spam" (here, in the log or from the digest) restores it and
 * sends what was skipped.
 *
 * Multi-page forms are judged once, on the final submit (gform_entry_is_spam never fires on
 * Next or Previous), with every page's fields in the entry. Save and Continue is checked too:
 * a draft makes Gravity Forms email a resume link to any address typed in, so bots abuse it.
 */
final class GravityForms extends Adapter {

    public function source(): string { return 'gravityforms'; }
    public function label(): string { return 'Gravity Forms'; }
    public function detected(): bool { return class_exists( 'GFForms' ); }
    public function keepsSpam(): bool { return true; }

    public function register(): void {
        add_filter( 'gform_entry_is_spam', $this->check( ... ), 99, 3 );
        add_filter( 'gform_abort_submission_with_confirmation', $this->checkDraft( ... ), 99, 2 );
        add_action( 'gform_entry_created', $this->link( ... ), 10, 1 );
        add_action( 'gform_post_update_entry_property', $this->statusChanged( ... ), 10, 4 );
        add_action( 'breakwater_not_spam', $this->release( ... ) );
    }

    /**
     * "Not spam" from the log or a Deliver link takes the entry out of the Spam folder; the
     * status change then sends its notifications and feeds (statusChanged).
     */
    public function release( object $row ): bool {
        global $wpdb;
        if ( $this->source() !== $row->source || ! class_exists( 'GFFormsModel' ) ) {
            return false;
        }
        $entry_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT m.entry_id FROM {$wpdb->prefix}gf_entry_meta m JOIN {$wpdb->prefix}gf_entry e ON e.id = m.entry_id WHERE m.meta_key = 'breakwater_log_id' AND m.meta_value = %d AND e.status = 'spam' LIMIT 1",
            (int) $row->id
        ) );
        if ( $entry_id ) {
            \GFFormsModel::update_entry_property( $entry_id, 'status', 'active' );
        }
        return (bool) ( Log::get( (int) $row->id )->delivered_at ?? false );
    }

    /**
     * Gravity Forms skips notifications and feeds for a spam entry and doesn't run them when
     * it is marked Not Spam, so a released entry would sit in the list unseen. Send them once,
     * and only for an entry that was saved as spam (by us or the other spam service).
     */
    private function send( int $entry_id, int $log_id ): void {
        $row = Log::get( $log_id );
        if ( ! $row || $row->delivered_at || ( 'blocked' !== $row->action && 'spam' !== $row->other_verdict ) || ! class_exists( 'GFAPI' ) ) {
            return;
        }
        $entry = \GFAPI::get_entry( $entry_id );
        $form  = is_array( $entry ) ? \GFAPI::get_form( (int) $entry['form_id'] ) : false;
        if ( ! is_array( $entry ) || ! is_array( $form ) ) {
            return;
        }
        Log::markDelivered( $log_id );
        \GFAPI::send_notifications( $form, $entry );
        if ( method_exists( 'GFAPI', 'maybe_process_feeds' ) ) { // 2.9.2+
            \GFAPI::maybe_process_feeds( $entry, $form );
        }
    }

    public function recipients( string $form ): array {
        $gf = class_exists( 'GFAPI' ) ? \GFAPI::get_form( self::formId( $form ) ) : null;
        $to = [];
        foreach ( (array) ( $gf['notifications'] ?? [] ) as $notification ) {
            $active = ! isset( $notification['isActive'] ) || $notification['isActive'];
            if ( $active && 'email' === ( $notification['toType'] ?? 'email' ) && 'form_submission' === ( $notification['event'] ?? 'form_submission' ) ) {
                $to = array_merge( $to, self::emails( (string) ( $notification['to'] ?? '' ) ) );
            }
        }
        return array_values( array_unique( $to ) );
    }

    /** Remember which log row the saved entry came from. */
    private function link( mixed $entry ): void {
        if ( is_array( $entry ) && ! empty( $entry['id'] ) && Guard::lastLogId() && function_exists( 'gform_update_meta' ) ) {
            gform_update_meta( (int) $entry['id'], 'breakwater_log_id', Guard::lastLogId() );
        }
    }

    /** Gravity Forms' own "Not Spam" and "Spam" buttons count as feedback. */
    private function statusChanged( mixed $entry_id, mixed $property, mixed $value, mixed $previous ): void {
        if ( 'status' !== $property || ! function_exists( 'gform_get_meta' ) ) {
            return;
        }
        $log_id = (int) gform_get_meta( (int) $entry_id, 'breakwater_log_id' );
        if ( ! $log_id ) {
            return;
        }
        if ( 'spam' === $previous && 'active' === $value ) {
            $this->send( (int) $entry_id, $log_id );
            Feedback::notSpam( $log_id );
        } elseif ( 'spam' === $value && 'spam' !== $previous ) {
            Feedback::spam( $log_id );
        }
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $entry
     */
    private function check( mixed $is_spam, mixed $form, mixed $entry ): mixed {
        if ( ! is_array( $form ) || ! is_array( $entry ) ) {
            return $is_spam;
        }
        $form_id = (int) ( $form['id'] ?? 0 );
        if ( ! $form_id || ! isset( $_POST[ 'is_submit_' . $form_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return (bool) $is_spam; // Entries created from code or the REST API.
        }
        $s            = Submission::fromFields( $this->source(), $form_id . ': ' . ( $form['title'] ?? '' ), self::fields( $form, $entry ) );
        $s->other     = (bool) $is_spam;
        $s->continued = self::resumed( $form_id );

        $verdict = $this->evaluate( $s );
        if ( ! $verdict->act ) {
            return (bool) $is_spam;
        }
        if ( method_exists( 'GFCommon', 'set_spam_filter' ) ) {
            \GFCommon::set_spam_filter( $form_id, 'Breakwater', $verdict->reason() );
        }
        return true;
    }

    /**
     * Save and Continue. Nothing is stored for a held draft (there is no entry yet), so only
     * signals no person trips stop it; the visitor sees the form's confirmation, as for spam.
     *
     * @param array<string, mixed> $form
     */
    private function checkDraft( mixed $abort, mixed $form ): mixed {
        $form_id = is_array( $form ) ? (int) ( $form['id'] ?? 0 ) : 0;
        if ( $abort || ! $form_id || empty( $_POST['gform_save'] ) || ! isset( $_POST[ 'is_submit_' . $form_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return $abort;
        }
        $s            = Submission::fromRequest( $this->source(), $form_id . ': ' . ( $form['title'] ?? '' ) . ' (saved draft)' );
        $s->continued = self::resumed( $form_id );
        $verdict      = $this->evaluate( $s );
        return $verdict->act && array_intersect( Checker::CERTAIN, array_keys( $verdict->signals ) ) ? true : $abort;
    }

    /** The visitor resumed a draft Gravity Forms saved for this form (it still exists until the entry is saved). */
    private static function resumed( int $form_id ): bool {
        $token = isset( $_POST['gform_resume_token'] ) && is_string( $_POST['gform_resume_token'] ) ? sanitize_key( $_POST['gform_resume_token'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if ( '' === $token || ! class_exists( 'GFFormsModel' ) ) {
            return false;
        }
        $draft = \GFFormsModel::get_draft_submission_values( $token );
        return is_array( $draft ) && $form_id === (int) ( $draft['form_id'] ?? 0 );
    }

    /**
     * Entry values by field type. GF stores multi-input fields (name, address) as 1.3, 1.6, ...
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $entry
     * @return list<array{type:string, label:string, value:mixed, key:string}>
     */
    private static function fields( array $form, array $entry ): array {
        $out = [];
        foreach ( (array) ( $form['fields'] ?? [] ) as $field ) {
            $id     = (string) ( $field->id ?? '' );
            $values = [];
            foreach ( $entry as $key => $value ) {
                if ( (string) $key === $id || str_starts_with( (string) $key, $id . '.' ) ) {
                    $values[] = $value;
                }
            }
            $out[] = [ 'type' => (string) ( $field->type ?? '' ), 'label' => (string) ( $field->label ?? '' ), 'value' => $values, 'key' => $id ];
        }
        return $out;
    }
}
