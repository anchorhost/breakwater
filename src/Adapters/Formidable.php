<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Feedback;
use Breakwater\Guard;
use Breakwater\Log;
use Breakwater\Release;
use Breakwater\Submission;

/**
 * Formidable Forms. On Formidable 7+ spam is saved as a spam entry (Entries → Spam) with its
 * actions and emails skipped, unless the site set our source to Block; older versions get
 * a form error instead. "Not spam" (here, in the log, from the digest or Formidable's own)
 * restores the entry and runs the form actions a new entry runs.
 */
final class Formidable extends Adapter {

    /** Log row of the submission we just flagged in this request, until its entry is saved. */
    private int $held = 0;

    /** @var list<int>|null The actions Formidable's own "Not spam" window ran, while it releases. */
    private ?array $native = null;

    public function source(): string { return 'formidable'; }
    public function label(): string { return 'Formidable Forms'; }
    public function detected(): bool { return class_exists( 'FrmAppHelper' ); }

    public function keepsSpam(): bool {
        return method_exists( 'FrmSpamEntriesHelper', 'maybe_flag_submission' ) && \FrmSpamEntriesHelper::should_save( 'breakwater' );
    }

    public function register(): void {
        add_filter( 'frm_spam_entry_sources', $this->addSource( ... ) );
        add_filter( 'frm_validate_entry', $this->check( ... ), 99, 3 );
        add_action( 'frm_after_create_entry', $this->link( ... ), 1, 2 );
        add_action( 'frm_entry_marked_not_spam', $this->markedNotSpam( ... ), 10, 2 );
        add_action( 'frm_entry_marked_spam', $this->markedSpam( ... ), 10, 1 );
    }

    /**
     * Out of Spam, then the "create" form actions (emails, Zapier, MailChimp...) that Formidable
     * skipped for the spam entry, minus payments and on-submit ones, which can't run later.
     * After Formidable's own "Not spam", its window already ran the actions the owner ticked.
     */
    public function release( object $row ): ?string {
        if ( ! method_exists( 'FrmSpamEntriesHelper', 'restore_from_spam' ) || ! method_exists( 'FrmSpamEntriesController', 'get_pending_actions' ) || ! class_exists( 'FrmFormActionsController' ) ) {
            return null;
        }
        $entry_id = (int) $row->ref;
        $entry    = $entry_id ? \FrmEntry::getOne( $entry_id ) : null;
        $form     = $entry ? \FrmForm::getOne( (int) $entry->form_id ) : null;
        if ( ! $entry || ! $form ) {
            return null; // Deleted.
        }
        if ( null !== $this->native ) {
            return $this->native
                ? 'Restored to the Formidable entries, and the form actions chosen there ran.'
                : 'Restored to the Formidable entries; no form actions were chosen to run.';
        }
        if ( \FrmSpamEntriesHelper::is_spam( $entry ) && ! \FrmSpamEntriesHelper::restore_from_spam( $entry_id ) ) {
            return null;
        }
        $entry = \FrmEntry::getOne( $entry_id, true );
        if ( ! $entry || \FrmSpamEntriesHelper::is_spam( $entry ) || \FrmEntriesHelper::SUBMITTED_ENTRY_STATUS !== (int) $entry->is_draft ) {
            return null;
        }
        $pending = array_map( 'intval', array_keys( \FrmSpamEntriesController::get_pending_actions( $form ) ) );
        if ( $pending ) {
            $skip = fn ( mixed $skipped, mixed $args ): mixed => $skipped || ! is_array( $args ) || ! isset( $args['action']->ID ) || ! in_array( (int) $args['action']->ID, $pending, true );
            add_filter( 'frm_skip_form_action', $skip, 99, 2 );
            try {
                \FrmFormActionsController::trigger_actions( 'create', (int) $entry->form_id, $entry );
            } finally {
                remove_filter( 'frm_skip_form_action', $skip, 99 );
            }
        }
        do_action( 'frm_entry_marked_not_spam', $entry_id, $pending );
        return $pending
            ? 'Restored to the Formidable entries, and the form\'s actions ran.'
            : 'Restored to the Formidable entries (the form has no actions to run).';
    }

    /** The "To" of the form's email actions. */
    public function recipients( string $form ): array {
        if ( ! class_exists( 'FrmFormAction' ) ) {
            return [];
        }
        $to = [];
        foreach ( (array) \FrmFormAction::get_action_for_form( self::formId( $form ), 'email' ) as $action ) {
            $setting = preg_replace_callback(
                '/\[(admin_email|default-email)\]/',
                fn ( array $m ): string => method_exists( 'FrmFieldsHelper', 'dynamic_default_values' ) ? (string) \FrmFieldsHelper::dynamic_default_values( $m[1] ) : (string) get_option( 'admin_email' ),
                (string) ( $action->post_content['email_to'] ?? '' )
            );
            $to = array_merge( $to, self::emails( (string) $setting ) );
        }
        return array_values( array_unique( $to ) );
    }

    /**
     * @param array<string, array{label:string, default:string}> $sources
     */
    private function addSource( mixed $sources ): mixed {
        if ( is_array( $sources ) ) {
            $sources['breakwater'] = [ 'label' => 'Breakwater', 'default' => 'save' ];
        }
        return $sources;
    }

    /**
     * Remember which log row the saved spam entry came from (the hook fires for spam entries
     * too). Formidable's own checks may have flagged it first; it is still the entry we held.
     */
    private function link( mixed $entry_id, mixed $form_id = 0 ): void {
        if ( ! $this->held || ! is_numeric( $entry_id ) || Release::running() || ! class_exists( 'FrmEntry' ) ) {
            return;
        }
        $entry = \FrmEntry::getOne( (int) $entry_id );
        if ( \FrmSpamEntriesHelper::is_spam( $entry ) && empty( $entry->parent_item_id ) ) {
            Log::link( $this->held, (string) (int) $entry_id );
            $this->held = 0;
        }
    }

    /** Formidable's own "Not spam" releases it like ours does, without rerunning the actions its window ran. */
    private function markedNotSpam( mixed $entry_id, mixed $action_ids = [] ): void {
        $row = ! Release::running() && is_numeric( $entry_id ) ? Log::byRef( $this->source(), (string) (int) $entry_id ) : null;
        if ( ! $row ) {
            return;
        }
        $this->native = array_map( 'intval', is_array( $action_ids ) ? $action_ids : [] );
        try {
            Release::run( $row );
        } finally {
            $this->native = null;
        }
    }

    private function markedSpam( mixed $entry_id ): void {
        $row = is_numeric( $entry_id ) ? Log::byRef( $this->source(), (string) (int) $entry_id ) : null;
        if ( $row ) {
            Feedback::spam( (int) $row->id );
        }
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $values The posted entry: form_id, item_meta.
     * @param array<string, mixed>  $args   exclude, posted_fields (field rows).
     */
    private function check( mixed $errors, mixed $values = [], mixed $args = [] ): mixed {
        if ( ! is_array( $errors ) || $errors || ! is_array( $values ) || ! is_array( $args ) || ! empty( $args['exclude'] ) ) {
            return $errors;
        }
        if ( ! empty( $values['id'] ) || 'update' === ( $values['frm_action'] ?? '' ) ) {
            return $errors; // Editing an existing entry, not a new submission.
        }
        $fields = [];
        foreach ( (array) ( $args['posted_fields'] ?? [] ) as $field ) {
            $fields[] = [ 'type' => (string) ( $field->type ?? '' ), 'label' => (string) ( $field->name ?? '' ), 'value' => $values['item_meta'][ $field->id ?? 0 ] ?? '', 'key' => (string) ( $field->id ?? '' ) ];
        }
        $form_id = (int) ( $values['form_id'] ?? 0 );
        $form    = class_exists( 'FrmForm' ) ? \FrmForm::getOne( $form_id ) : null;
        $verdict = $this->evaluate( Submission::fromFields( $this->source(), $form_id . ': ' . ( $form->name ?? '' ), $fields ) );
        if ( ! $verdict->act ) {
            return $errors;
        }
        if ( method_exists( 'FrmSpamEntriesHelper', 'maybe_flag_submission' ) && \FrmSpamEntriesHelper::maybe_flag_submission( $form_id, 'breakwater', $verdict->reason() ) ) {
            $this->held = $verdict->log_id ?: Guard::lastLogId();
            return $errors; // Saved as a spam entry; the visitor sees the usual success message.
        }
        $errors['spam'] = $this->message();
        return $errors;
    }
}
