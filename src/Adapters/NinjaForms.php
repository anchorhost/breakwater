<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Ninja Forms. Checked before Ninja processes the submission; spam gets an error on the email
 * field, which makes Ninja answer before running its actions, so nothing is saved or emailed.
 */
final class NinjaForms extends Adapter {

    public function source(): string { return 'ninja-forms'; }
    public function label(): string { return 'Ninja Forms'; }
    public function detected(): bool { return class_exists( 'Ninja_Forms' ); }

    public function register(): void {
        add_filter( 'ninja_forms_submit_data', $this->check( ... ), 99 );
    }

    /**
     * @param array<string, mixed> $form_data The decoded submission: id, fields[id][value], settings.
     */
    private function check( mixed $form_data ): mixed {
        $id = is_array( $form_data ) ? (int) ( $form_data['id'] ?? 0 ) : 0;
        if ( ! $id || empty( $form_data['fields'] ) || ! function_exists( 'Ninja_Forms' ) ) {
            return $form_data;
        }
        // Field types and labels from the server's copy of the form: the posted ones are the visitor's.
        $form   = Ninja_Forms()->form( $id );
        $fields = [];
        $first  = null;
        $email  = null;
        foreach ( (array) $form->get_fields() as $field_id => $field ) {
            $first ??= $field_id;
            if ( 'email' === $field->get_setting( 'type' ) ) {
                $email ??= $field_id;
            }
            $fields[] = [
                'type'  => (string) $field->get_setting( 'type' ),
                'label' => (string) $field->get_setting( 'label' ),
                'value' => $form_data['fields'][ $field_id ]['value'] ?? '',
                'key'   => (string) $field_id,
            ];
        }
        $title = (string) $form->get()->get_setting( 'title' );
        $error_on = $email ?? $first; // The error shows under this field.
        if ( null !== $error_on && $this->evaluate( Submission::fromFields( $this->source(), $id . ': ' . $title, $fields ) )->act ) {
            // Ninja checks for field errors as it processes each field and responds with them.
            $form_data['errors']['fields'][ $error_on ] = $this->message();
        }
        return $form_data;
    }
}
