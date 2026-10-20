<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Feedback;
use Breakwater\Log;
use Breakwater\Release;
use Breakwater\Submission;

/**
 * Contact Form 7. Marked as spam the CF7 way, so Flamingo (when active) keeps it in its Spam
 * folder. Released (here, from the digest or with Flamingo's own Not Spam), the form is
 * submitted again with the visitor's values and CF7's spam checks skipped: validation, mail,
 * Mail (2) and every wpcf7_mail_sent / wpcf7_submit integration run as they would have.
 */
final class ContactForm7 extends Adapter {

    /** Form-tag types whose answer can't be given twice or doesn't belong in a replay. */
    private const NOT_REPLAYED = [ 'captchar', 'captchac' ];

    /** This request's held row, for linking the spam message Flamingo saves for it. */
    private static int $log_id = 0;

    public function source(): string { return 'contact-form-7'; }
    public function label(): string { return 'Contact Form 7'; }
    public function detected(): bool { return defined( 'WPCF7_VERSION' ); }
    public function keepsSpam(): bool { return class_exists( 'Flamingo_Inbound_Message' ); }

    public function register(): void {
        add_filter( 'wpcf7_spam', $this->check( ... ), 99, 2 );
        add_action( 'wpcf7_after_flamingo', $this->link( ... ), 10, 1 );
        add_action( 'transition_post_status', $this->statusChanged( ... ), 10, 3 );
    }

    /**
     * Submit the form again as the visitor did. With Flamingo, its spam message is moved to
     * the inbox (or already was, by Flamingo's own Not Spam) and Flamingo's save is skipped for
     * the replay, so there is one message, not two.
     */
    public function release( object $row ): ?string {
        $data         = Log::replay( $row );
        $contact_form = class_exists( 'WPCF7_ContactForm' ) && class_exists( 'WPCF7_Submission' ) && ! empty( $data['form'] ) ? \WPCF7_ContactForm::get_instance( (int) $data['form'] ) : null;
        if ( ! $contact_form || ! is_array( $data['post'] ?? null ) || ! method_exists( $contact_form, 'submit' ) || ! property_exists( 'WPCF7_Submission', 'instance' ) ) {
            return null;
        }
        $inbound = self::inbound( (string) $row->ref );
        if ( $inbound && $inbound->spam ) {
            $inbound->unspam();
        }
        $meta     = (array) ( $data['meta'] ?? [] );
        $time     = (int) ( $meta['timestamp'] ?? 0 );
        $tags     = fn ( mixed $output, mixed $name ): mixed => match ( $name ) { // The visitor's page and time, not the replay's.
            '_url'  => (string) ( $meta['url'] ?? '' ) ?: $output,
            '_date' => $time ? wp_date( (string) get_option( 'date_format' ), $time ) : $output,
            '_time' => $time ? wp_date( (string) get_option( 'time_format' ), $time ) : $output,
            default => $output,
        };
        $flamingo = fn ( mixed $args ): mixed => is_array( $args ) && $time ? array_merge( $args, [ 'timestamp' => $time ] ) : $args;
        $post     = $_POST; // phpcs:ignore WordPress.Security.NonceVerification
        $_POST    = wp_slash( array_merge( (array) ( $data['hidden'] ?? [] ), $data['post'] ) );
        add_filter( 'wpcf7_skip_spam_check', '__return_true', 99 );
        add_filter( 'wpcf7_special_mail_tags', $tags, 20, 2 );
        add_filter( 'wpcf7_flamingo_inbound_message_parameters', $flamingo, 99 );
        if ( $inbound ) {
            add_filter( 'wpcf7_flamingo_submit_if', '__return_empty_array', 99 );
        }
        self::$log_id = (int) $row->id; // Links the message Flamingo saves when there was none.
        self::reset();
        try {
            $result = $contact_form->submit();
            $status = (string) ( $result['status'] ?? '' );
            $mailed = 'mail_sent' === $status;
            // A quiz or captcha can't be answered twice: send the form's own mail with the values.
            $fallback = 'validation_failed' === $status && \WPCF7_Submission::get_instance() && self::mail( $contact_form );
        } finally {
            $_POST        = $post;
            self::$log_id = 0;
            remove_filter( 'wpcf7_skip_spam_check', '__return_true', 99 );
            remove_filter( 'wpcf7_special_mail_tags', $tags, 20 );
            remove_filter( 'wpcf7_flamingo_inbound_message_parameters', $flamingo, 99 );
            remove_filter( 'wpcf7_flamingo_submit_if', '__return_empty_array', 99 );
            self::reset();
        }
        if ( ! $mailed && ! $fallback ) {
            return null;
        }
        if ( $fallback ) {
            return ( $inbound ? 'Moved to Flamingo\'s inbound messages, and the' : 'The' ) . ' form\'s mail was sent, but it did not pass the form\'s checks a second time (a quiz or captcha can\'t be answered twice), so its other integrations did not run.';
        }
        if ( $inbound ) {
            self::delivered( $inbound, $time );
        }
        $saved = (string) ( Log::get( (int) $row->id )->ref ?? '' ) !== (string) $row->ref; // Flamingo saved a new message.
        $where = $inbound ? 'Moved to Flamingo\'s inbound messages' : ( $saved ? 'Saved to Flamingo\'s inbound messages' : 'Sent through Contact Form 7' );
        $mail  = $contact_form->in_demo_mode() || $contact_form->is_true( 'skip_mail' ) ? 'the form is set to skip mail, so none was sent' : 'the form\'s mail was sent';
        return $where . ', and ' . $mail . '.';
    }

    public function recipients( string $form ): array {
        $cf7 = class_exists( 'WPCF7_ContactForm' ) ? \WPCF7_ContactForm::get_instance( self::formId( $form ) ) : null;
        return $cf7 ? self::emails( (string) ( $cf7->prop( 'mail' )['recipient'] ?? '' ) ) : [];
    }

    private function check( mixed $spam, mixed $submission = null ): mixed {
        $contact_form = $submission instanceof \WPCF7_Submission ? $submission->get_contact_form() : null;
        if ( ! $contact_form ) {
            return (bool) $spam;
        }
        $posted = (array) $submission->get_posted_data();
        $fields = [];
        foreach ( $contact_form->scan_form_tags() as $tag ) {
            if ( '' !== $tag->name && isset( $posted[ $tag->name ] ) ) {
                $fields[] = [ 'type' => $tag->basetype, 'label' => $tag->name, 'value' => $posted[ $tag->name ] ];
            }
        }
        $s        = Submission::fromFields( $this->source(), $contact_form->id() . ': ' . $contact_form->title(), $fields );
        $s->other = (bool) $spam;

        $verdict = $this->evaluate( $s );
        if ( $verdict->act || $spam ) { // Held either way: keep what "Not spam" needs.
            self::$log_id = $verdict->log_id;
            self::stash( $verdict->log_id, $contact_form, $submission );
        }
        if ( ! $verdict->act ) {
            return (bool) $spam;
        }
        $submission->add_spam_log( [ 'agent' => 'breakwater', 'reason' => $verdict->reason() ] );
        return true;
    }

    /**
     * The visitor's raw values for each named form-tag, the hidden fields CF7 reads (the page
     * it was sent from, quiz answers) and the request details CF7 puts in its mail. Nothing is
     * kept for a form with a file attached (CF7 deletes it after the request) or a payment.
     *
     * @param \WPCF7_ContactForm $contact_form
     * @param \WPCF7_Submission  $submission
     */
    private static function stash( int $log_id, object $contact_form, object $submission ): void {
        $post = [];
        foreach ( $contact_form->scan_form_tags() as $tag ) {
            if ( 'stripe' === $tag->basetype || ( 'file' === $tag->basetype && '' !== implode( '', array_map( 'strval', (array) ( $_FILES[ $tag->name ]['name'] ?? [] ) ) ) ) ) {
                return;
            }
            if ( '' !== $tag->name && ! in_array( $tag->basetype, self::NOT_REPLAYED, true ) && isset( $_POST[ $tag->name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                $post[ $tag->name ] = wp_unslash( $_POST[ $tag->name ] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
            }
        }
        $hidden = [];
        foreach ( (array) $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification
            if ( is_string( $key ) && is_string( $value ) && ( in_array( $key, [ '_wpcf7', '_wpcf7_version', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post' ], true ) || str_starts_with( $key, '_wpcf7_quiz_answer_' ) ) ) {
                $hidden[ $key ] = wp_unslash( $value );
            }
        }
        Log::stash( $log_id, [
            'form'   => (int) $contact_form->id(),
            'post'   => $post,
            'hidden' => $hidden,
            'meta'   => [
                'timestamp' => (int) $submission->get_meta( 'timestamp' ),
                'url'       => (string) $submission->get_meta( 'url' ),
            ],
        ] );
    }

    /**
     * The form's Mail and Mail (2) with the values the replay posted, for when its validation
     * can't pass a second time.
     *
     * @param \WPCF7_ContactForm $contact_form
     */
    private static function mail( object $contact_form ): bool {
        if ( ! class_exists( 'WPCF7_Mail' ) || ! \WPCF7_Mail::send( $contact_form->prop( 'mail' ), 'mail' ) ) {
            return false;
        }
        $mail_2 = $contact_form->prop( 'mail_2' );
        if ( is_array( $mail_2 ) && ! empty( $mail_2['active'] ) ) {
            \WPCF7_Mail::send( $mail_2, 'mail_2' );
        }
        return true;
    }

    /**
     * CF7 allows one submission per request (WPCF7_Submission::get_instance() returns null for a
     * second form), so each replay starts from none: a bulk Not Spam releases several.
     */
    private static function reset(): void {
        \Closure::bind( static function (): void { self::$instance = null; }, null, \WPCF7_Submission::class )();
    }

    /** Flamingo's message for this row, unless it was deleted or trashed. */
    private static function inbound( string $ref ): ?\Flamingo_Inbound_Message {
        $post = '' !== $ref && class_exists( 'Flamingo_Inbound_Message' ) ? get_post( (int) $ref ) : null;
        return $post && \Flamingo_Inbound_Message::post_type === $post->post_type && 'trash' !== $post->post_status ? new \Flamingo_Inbound_Message( $post ) : null;
    }

    /**
     * What Flamingo records for a message that was sent: its status, and the sender in its
     * address book (wpcf7_flamingo_submit() adds them only for mail that went out).
     */
    private static function delivered( \Flamingo_Inbound_Message $inbound, int $time ): void {
        $id = (int) $inbound->id; // A method since Flamingo 2.2, a property before; __get covers both.
        update_post_meta( $id, '_submission_status', 'mail_sent' );
        // Flamingo's own Not Spam is still saving the message: keep its "spam" status from landing on top.
        add_filter( 'update_post_metadata', static fn ( mixed $check, mixed $object_id, mixed $key, mixed $value ): mixed => (int) $object_id === $id && '_submission_status' === $key && 'mail_sent' !== $value ? true : $check, 10, 4 );
        if ( class_exists( 'Flamingo_Contact' ) && is_email( (string) $inbound->from_email ) ) {
            \Flamingo_Contact::add( [
                'email'          => (string) $inbound->from_email,
                'name'           => (string) $inbound->from_name,
                'last_contacted' => $time ? wp_date( 'Y-m-d H:i:s', $time ) : '0000-00-00 00:00:00',
            ] );
        }
    }

    /** Remember which Flamingo message a held submission was saved as. */
    private function link( mixed $result ): void {
        if ( self::$log_id && is_array( $result ) && ! empty( $result['flamingo_inbound_id'] ) ) {
            Log::link( self::$log_id, (string) (int) $result['flamingo_inbound_id'] );
        }
    }

    /** Flamingo's own "Not spam" releases it like ours does; "Spam" counts as feedback. */
    private function statusChanged( mixed $new, mixed $old, mixed $post ): void {
        if ( Release::running() || $new === $old || ! $post instanceof \WP_Post || ! class_exists( 'Flamingo_Inbound_Message' ) || \Flamingo_Inbound_Message::post_type !== $post->post_type ) {
            return;
        }
        $row = Log::byRef( $this->source(), (string) $post->ID );
        if ( ! $row ) {
            return;
        }
        if ( \Flamingo_Inbound_Message::spam_status === $old && 'publish' === $new ) {
            Release::run( $row );
        } elseif ( \Flamingo_Inbound_Message::spam_status === $new ) {
            Feedback::spam( (int) $row->id );
        }
    }
}
