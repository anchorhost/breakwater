<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Avada Forms (Fusion Builder). Checked once Avada has parsed the submission, before it is
 * saved, mailed or sent to HubSpot or Mailchimp; spam gets the form's error notice, the
 * same way Avada's own reCAPTCHA check refuses.
 */
final class Avada extends Adapter {

    public function source(): string { return 'avada'; }
    public function label(): string { return 'Avada Forms'; }
    public function detected(): bool { return class_exists( 'Fusion_Form_Submit' ); }

    public function register(): void {
        add_filter( 'fusion_builder_form_submission_data', $this->check( ... ), 5 );
    }

    /**
     * @param array<string, mixed> $data data, field_types, field_labels, submission.
     */
    private function check( mixed $data ): mixed {
        if ( ! is_array( $data ) || ! is_array( $data['data'] ?? null ) ) {
            return $data;
        }
        $fields = [];
        $trap   = '';
        foreach ( $data['data'] as $name => $value ) {
            $type = (string) ( $data['field_types'][ $name ] ?? 'text' );
            if ( 'honeypot' === $type ) {
                $trap = is_scalar( $value ) ? (string) $value : 'filled'; // Avada only checks it in the browser.
                continue;
            }
            $fields[] = [ 'type' => $type, 'label' => (string) ( $data['field_labels'][ $name ] ?? $name ), 'value' => $value, 'key' => (string) $name ];
        }
        $form_id = absint( $_POST['form_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
        $s       = Submission::fromFields( $this->source(), $form_id . ': ' . get_the_title( $form_id ), $fields );
        if ( '' !== $trap ) {
            $s->trap = $trap;
        }
        if ( $this->evaluate( $s )->act ) {
            die( wp_json_encode( [ 'status' => 'error', 'info' => [ 'spam' => $this->message() ], 'message' => $this->message() ] ) );
        }
        return $data;
    }
}
