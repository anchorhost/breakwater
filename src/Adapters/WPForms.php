<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Feedback;
use Breakwater\Log;
use Breakwater\Release;
use Breakwater\Submission;

/**
 * WPForms (Lite and Pro). Checked once the fields are valid and formatted. With Pro and a form
 * that stores spam entries, spam is saved as a spam entry (WPForms sends no emails and runs no
 * integrations for it) and WPForms' own "Not Spam" releases it; otherwise it is refused with a
 * form error. Released, it gets what WPForms does for a clean submission: the entry (Pro),
 * the notification emails and the wpforms_process_complete integrations.
 */
final class WPForms extends Adapter {

    /** This request's held row, for linking the spam entry WPForms Pro saves for it. */
    private static int $log_id = 0;

    /** WPForms' single "Mark as Not Spam" is running: it sends the emails and runs the integrations itself. */
    private static bool $native = false;

    public function source(): string { return 'wpforms'; }
    public function label(): string { return 'WPForms'; }
    public function detected(): bool { return function_exists( 'wpforms' ); }
    public function keepsSpam(): bool { return function_exists( 'wpforms' ) && wpforms()->is_pro(); }

    public function register(): void {
        add_action( 'wpforms_process', $this->check( ... ), 99, 3 );
        add_action( 'wpforms_process_entry_saved', $this->link( ... ), 10, 4 );
        add_action( 'wpforms_pro_anti_spam_entry_set_as_not_spam', $this->notSpam( ... ), 10, 2 );
        add_action( 'wpforms_pro_anti_spam_entry_marked_as_spam', $this->markedSpam( ... ), 10, 2 );
    }

    public function release( object $row ): ?string {
        $process = function_exists( 'wpforms' ) ? wpforms()->obj( 'process' ) : null;
        if ( ! is_object( $process ) || ! method_exists( $process, 'process_complete' ) || ! method_exists( $process, 'entry_email' ) ) {
            return null; // Before WPForms 1.8.3.
        }
        return '' !== (string) $row->ref ? $this->restore( (int) $row->ref, $process ) : $this->replay( $row, $process );
    }

    public function recipients( string $form ): array {
        $data = function_exists( 'wpforms' ) ? wpforms()->obj( 'form' )->get( self::formId( $form ), [ 'content_only' => true ] ) : [];
        if ( empty( $data['settings']['notification_enable'] ) ) {
            return [];
        }
        $to = [];
        foreach ( (array) ( $data['settings']['notifications'] ?? [] ) as $notification ) {
            if ( ! isset( $notification['enable'] ) || (int) $notification['enable'] !== 0 ) {
                $to = array_merge( $to, self::emails( (string) ( $notification['email'] ?? '' ) ) );
            }
        }
        return array_values( array_unique( $to ) );
    }

    /**
     * A Pro spam entry: out of spam the way WPForms' "Not Spam" does it, then what its single
     * Not Spam runs (SpamEntry::process_complete()). Its bulk Not Spam runs neither, so after
     * that we do; after the single one WPForms does.
     */
    private function restore( int $entry_id, object $process ): ?string {
        $handler = wpforms()->obj( 'entry' );
        $entry   = $handler ? $handler->get( $entry_id ) : null;
        if ( ! is_object( $entry ) || 'trash' === $entry->status ) {
            return null;
        }
        $form_data = wpforms()->obj( 'form' )->get( (int) $entry->form_id, [ 'content_only' => true ] );
        if ( ! is_array( $form_data ) || ! $form_data || self::payment( $form_data ) ) {
            return null;
        }
        if ( self::$native ) {
            return 'Restored to the WPForms entries, and WPForms sent its notifications and ran its integrations.';
        }
        if ( 'spam' === $entry->status ) {
            $spam = wpforms()->obj( 'spam_entry' );
            if ( ! $spam || ! method_exists( $spam, 'set_as_not_spam' ) ) {
                return null;
            }
            $spam->set_as_not_spam( $entry );
        }
        $fields = (array) wpforms_decode( $entry->fields );
        $meta   = wpforms()->obj( 'entry_meta' );
        $raw    = $meta ? $meta->get_meta( [ 'entry_id' => $entry_id, 'type' => 'post_data_raw', 'number' => 1 ] ) : [];
        $form_data['post_data_raw'] = ! empty( $raw[0] ) ? (array) json_decode( (string) $raw[0]->data, true ) : [];
        $process->entry_id          = $entry_id;
        $process->process_complete( (int) $entry->form_id, $form_data, $fields, [], $entry_id );
        $form_data = apply_filters( 'wpforms_entries_single_process_notifications_form_data', $form_data, $entry );
        $process->entry_email( $fields, [], $form_data, $entry_id, 'entry' );
        return 'Restored to the WPForms entries, and its notifications and integrations ran.';
    }

    /**
     * A refused submission: from the formatted fields on, what WPForms_Process::process() does
     * for a clean one (entry save, entry_saved, notifications, process_complete).
     */
    private function replay( object $row, object $process ): ?string {
        $data    = Log::replay( $row );
        $form_id = (int) ( $data['form'] ?? 0 );
        $fields  = $data['fields'] ?? null;
        $form    = $form_id && is_array( $fields ) ? wpforms()->obj( 'form' )->get( $form_id ) : null;
        if ( ! $form instanceof \WP_Post || 'publish' !== $form->post_status ) {
            return null;
        }
        $entry     = (array) ( $data['entry'] ?? [] );
        $request   = array_map( 'strval', (array) ( $data['request'] ?? [] ) );
        $form_data = (array) apply_filters( 'wpforms_process_before_form_data', wpforms_decode( $form->post_content ), $entry );
        if ( empty( $form_data['fields'] ) || self::payment( $form_data ) ) {
            return null;
        }
        $form_data['created']       = $form->post_date;
        $form_data['post_data_raw'] = [ 'page_url' => esc_url_raw( $request['page_url'] ?? '' ) ];
        $form_data['entry_meta']    = [
            'page_url'    => esc_url_raw( $request['page_url'] ?? '' ),
            'page_title'  => sanitize_text_field( $request['page_title'] ?? '' ),
            'page_id'     => absint( $request['page_id'] ?? 0 ) ?: '',
            'url_referer' => esc_url_raw( $request['url_referer'] ?? '' ),
            'user_id'     => 0,
        ];
        $post  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification
        $_POST = array_merge( $_POST, wp_slash( $request ), [ 'wpforms' => wp_slash( $entry ) ] ); // phpcs:ignore WordPress.Security.NonceVerification
        $process->errors    = [];
        $process->fields    = $fields;
        $process->form_data = $form_data;
        $process->entry_id  = 0;
        try {
            $entry_id = (int) $process->entry_save( $fields, $entry, $form_id, $form_data );
            if ( $entry_id && method_exists( $process, 'save_meta' ) ) {
                // Pro: the page it was sent from, on the entry. Protected, so called from inside.
                \Closure::bind( function () use ( $entry_id, $form_id ): void { $this->save_meta( $entry_id, $form_id ); }, $process, get_class( $process ) )();
            }
            do_action( 'wpforms_process_entry_saved', $fields, $entry, $form_data, $entry_id, 0 );
            $process->entry_email( $fields, $entry, $form_data, $entry_id, 'entry' );
            $process->process_complete( $form_id, $form_data, $fields, $entry, $entry_id );
        } finally {
            $_POST = $post;
        }
        if ( $entry_id ) {
            Log::link( (int) $row->id, (string) $entry_id );
        }
        $notified = ! empty( $form_data['settings']['notification_enable'] );
        return match ( true ) {
            $entry_id && $notified => 'Saved to the WPForms entries, and its notifications and integrations ran.',
            (bool) $entry_id       => 'Saved to the WPForms entries, and its integrations ran.',
            $notified              => 'Sent through WPForms, and its notifications and integrations ran.',
            default                => null, // Lite with no notification: nothing would show it. Breakwater emails it.
        };
    }

    /**
     * @param array<int|string, mixed> $fields    Formatted, as WPForms saves and emails them.
     * @param array<string, mixed>     $entry     The raw $_POST['wpforms'].
     * @param array<string, mixed>     $form_data
     */
    private function check( mixed $fields, mixed $entry, mixed $form_data ): void {
        $process = function_exists( 'wpforms' ) ? wpforms()->obj( 'process' ) : null;
        $form_id = is_array( $form_data ) ? (int) ( $form_data['id'] ?? 0 ) : 0;
        if ( ! is_array( $fields ) || ! is_object( $process ) || ! $form_id || ! empty( $process->errors[ $form_id ] ) ) {
            return; // Invalid, or already refused by WPForms' own checks.
        }
        $list = [];
        foreach ( $fields as $id => $field ) {
            $list[] = [ 'type' => $field['type'] ?? '', 'label' => $field['name'] ?? '', 'value' => $field['value'] ?? '', 'key' => (string) $id ];
        }
        $s        = Submission::fromFields( $this->source(), $form_id . ': ' . ( $form_data['settings']['form_title'] ?? '' ), $list );
        $s->other = ! empty( $process->spam_reason );

        $verdict = $this->evaluate( $s );
        if ( $verdict->act || $s->other ) {
            self::$log_id = $verdict->log_id;
        }
        if ( ! $verdict->act ) {
            return;
        }
        if ( self::storesSpam( $form_data ) ) {
            if ( ! $process->spam_reason ) {
                // Read by Pro when it saves the entry: spam status, its spam note, no emails, no process_complete.
                $process->spam_reason              = 'Breakwater';
                $process->form_data['spam_reason'] = 'Breakwater';
            }
            return;
        }
        self::stash( $verdict->log_id, $fields, is_array( $entry ) ? $entry : [], $form_data );
        $process->errors[ $form_id ]['header'] = $this->message();
    }

    /**
     * The formatted fields and the raw entry without passwords, and the page it was sent from.
     * Nothing is kept for uploads (WPForms only moves them into place for a saved entry) or
     * payments.
     *
     * @param array<int|string, mixed> $fields
     * @param array<string, mixed>     $entry
     * @param array<string, mixed>     $form_data
     */
    private static function stash( int $log_id, array $fields, array $entry, array $form_data ): void {
        if ( self::payment( $form_data ) ) {
            return;
        }
        $raw = (array) ( $entry['fields'] ?? [] );
        foreach ( $fields as $id => $field ) {
            $type = (string) ( $field['type'] ?? '' );
            if ( in_array( $type, [ 'file-upload', 'camera' ], true ) && ! empty( $field['value'] ) ) {
                return;
            }
            if ( 'password' === $type ) {
                unset( $fields[ $id ], $raw[ $id ] );
            }
        }
        $request = [];
        foreach ( [ 'page_url', 'page_title', 'page_id', 'url_referer' ] as $key ) {
            if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                $request[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
            }
        }
        Log::stash( $log_id, [
            'form'    => (int) $form_data['id'],
            'fields'  => $fields,
            'entry'   => [ 'id' => $entry['id'] ?? $form_data['id'], 'post_id' => $entry['post_id'] ?? '', 'author' => $entry['author'] ?? '', 'fields' => $raw ],
            'request' => $request,
        ] );
    }

    /**
     * Pro saves this form's spam as spam entries. Never for a payment form: the visitor would
     * see a confirmation for a payment that was never taken.
     *
     * @param array<string, mixed> $form_data
     */
    private static function storesSpam( array $form_data ): bool {
        return wpforms()->is_pro() && wpforms()->obj( 'spam_entry' )
            && ! empty( $form_data['settings']['store_spam_entries'] ) && empty( $form_data['settings']['disable_entries'] )
            && ! self::payment( $form_data );
    }

    /** @param array<string, mixed> $form_data */
    private static function payment( array $form_data ): bool {
        return function_exists( 'wpforms_has_payment' ) && wpforms_has_payment( 'form', $form_data );
    }

    /** Remember which log row the spam entry Pro saved came from. */
    private function link( mixed $fields, mixed $entry, mixed $form_data, mixed $entry_id ): void {
        if ( self::$log_id && (int) $entry_id && ! Release::running() ) {
            Log::link( self::$log_id, (string) (int) $entry_id );
            self::$log_id = 0;
        }
    }

    /**
     * WPForms' own "Not Spam" releases it like ours does. Its single Not Spam runs
     * process_complete and the emails itself right after this; its bulk one doesn't.
     */
    private function notSpam( mixed $entry_id, mixed $form_id ): void {
        $row = Release::running() ? null : Log::byRef( $this->source(), (string) (int) $entry_id );
        if ( ! $row ) {
            return;
        }
        self::$native = 'mark_not_spam' === ( $_REQUEST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
        try {
            Release::run( $row );
        } finally {
            self::$native = false;
        }
    }

    private function markedSpam( mixed $entry_id, mixed $form_id ): void {
        $row = Log::byRef( $this->source(), (string) (int) $entry_id );
        if ( $row ) {
            Feedback::spam( (int) $row->id );
        }
    }
}
