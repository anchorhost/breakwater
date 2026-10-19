<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * WPForms (Lite and Pro). Checked once the fields are valid; spam is refused with a form error.
 */
final class WPForms extends Adapter {

    public function source(): string { return 'wpforms'; }
    public function label(): string { return 'WPForms'; }
    public function detected(): bool { return function_exists( 'wpforms' ); }

    public function register(): void {
        add_filter( 'wpforms_process_initial_errors', $this->check( ... ), 99, 2 );
    }

    public function recipients( string $form ): array {
        $data = function_exists( 'wpforms' ) ? wpforms()->obj( 'form' )->get( self::formId( $form ), [ 'content_only' => true ] ) : [];
        $to   = [];
        foreach ( (array) ( $data['settings']['notifications'] ?? [] ) as $notification ) {
            $to = array_merge( $to, self::emails( (string) ( $notification['email'] ?? '' ) ) );
        }
        return array_values( array_unique( $to ) );
    }

    /**
     * @param array<int|string, mixed> $errors
     * @param array<string, mixed>     $form_data
     */
    private function check( mixed $errors, mixed $form_data ): mixed {
        if ( ! is_array( $errors ) || ! is_array( $form_data ) ) {
            return $errors;
        }
        $form_id = (int) ( $form_data['id'] ?? 0 );
        if ( ! $form_id || ! empty( $errors[ $form_id ] ) ) {
            return $errors;
        }
        $posted = (array) ( wp_unslash( $_POST['wpforms']['fields'] ?? [] ) ); // phpcs:ignore WordPress.Security.NonceVerification
        $fields = [];
        foreach ( (array) ( $form_data['fields'] ?? [] ) as $id => $field ) {
            $fields[] = [ 'type' => $field['type'] ?? '', 'label' => $field['label'] ?? '', 'value' => $posted[ $id ] ?? '', 'key' => (string) $id ];
        }
        $s = Submission::fromFields( $this->source(), $form_id . ': ' . ( $form_data['settings']['form_title'] ?? '' ), $fields );
        if ( $this->evaluate( $s )->act ) {
            $errors[ $form_id ]['header'] = $this->message();
        }
        return $errors;
    }
}
