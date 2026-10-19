<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Forminator forms. Uses Forminator's own spam filter, so the form's spam setting decides:
 * refuse with its message (the default), or save as a spam entry with emails skipped.
 */
final class Forminator extends Adapter {

    public function source(): string { return 'forminator'; }
    public function label(): string { return 'Forminator'; }
    public function detected(): bool { return defined( 'FORMINATOR_VERSION' ); }

    public function register(): void {
        add_filter( 'forminator_spam_protection', $this->check( ... ), 20, 4 );
    }

    /**
     * @param list<array<string, mixed>> $rows name, value, field_type, field_array per field.
     */
    private function check( mixed $is_spam, mixed $rows = [], mixed $form_id = 0, mixed $form_type = 'form' ): mixed {
        if ( $is_spam || 'form' !== $form_type || ! is_array( $rows ) ) {
            return $is_spam;
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( filter_var( $_POST['save_draft'] ?? false, FILTER_VALIDATE_BOOLEAN ) || ! empty( $_POST['abandoned_form'] ) ) {
            return $is_spam; // Draft saves and abandonment pings are not submissions.
        }
        $fields = [];
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && ! empty( $row['field_type'] ) ) {
                $fields[] = [ 'type' => (string) $row['field_type'], 'label' => (string) ( $row['field_array']['field_label'] ?? $row['name'] ?? '' ), 'value' => $row['value'] ?? '', 'key' => (string) ( $row['name'] ?? '' ) ];
            }
        }
        $model    = class_exists( 'Forminator_Base_Form_Model' ) ? \Forminator_Base_Form_Model::get_model( (int) $form_id ) : null;
        $title    = is_object( $model ) ? (string) ( $model->settings['formName'] ?? '' ) : '';
        $s        = Submission::fromFields( $this->source(), (int) $form_id . ': ' . $title, $fields );
        $s->other = false;
        return $this->evaluate( $s )->act ? true : $is_spam;
    }
}
