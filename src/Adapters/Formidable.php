<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Formidable Forms. On Formidable 7+ spam is saved as a spam entry (Entries → Spam) with its
 * actions and emails skipped, unless the site set our source to Block; older versions get
 * a form error instead.
 */
final class Formidable extends Adapter {

    public function source(): string { return 'formidable'; }
    public function label(): string { return 'Formidable Forms'; }
    public function detected(): bool { return class_exists( 'FrmAppHelper' ); }
    public function keepsSpam(): bool { return method_exists( 'FrmSpamEntriesHelper', 'maybe_flag_submission' ); }

    public function register(): void {
        add_filter( 'frm_spam_entry_sources', $this->addSource( ... ) );
        add_filter( 'frm_validate_entry', $this->check( ... ), 99, 3 );
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
        if ( $this->keepsSpam() && \FrmSpamEntriesHelper::maybe_flag_submission( $form_id, 'breakwater', $verdict->reason() ) ) {
            return $errors; // Saved as a spam entry; the visitor sees the usual success message.
        }
        $errors['spam'] = $this->message();
        return $errors;
    }
}
