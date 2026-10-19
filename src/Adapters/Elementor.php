<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Elementor Pro forms. Elementor has no spam folder, so spam is refused with a message
 * and its full payload stays in the Breakwater log, where it can be sent on.
 */
final class Elementor extends Adapter {

    public function source(): string { return 'elementor'; }
    public function label(): string { return 'Elementor Pro forms'; }
    public function detected(): bool { return defined( 'ELEMENTOR_PRO_VERSION' ); }

    public function register(): void {
        add_action( 'elementor_pro/forms/validation', $this->check( ... ), 99, 2 );
    }

    /**
     * @param \ElementorPro\Modules\Forms\Classes\Form_Record  $record
     * @param \ElementorPro\Modules\Forms\Classes\Ajax_Handler $ajax_handler
     */
    private function check( mixed $record, mixed $ajax_handler ): void {
        if ( ! is_object( $record ) || ! is_object( $ajax_handler ) || ! empty( $ajax_handler->errors ) ) {
            return; // Another validator (honeypot, reCAPTCHA) already refused it.
        }
        $id     = (string) $record->get_form_settings( 'id' );
        $name   = (string) $record->get_form_settings( 'form_name' );
        $fields = array_map(
            fn ( array $field ): array => [ 'type' => $field['type'] ?? '', 'label' => $field['title'] ?? '', 'value' => $field['raw_value'] ?? $field['value'] ?? '', 'key' => $field['id'] ?? '' ],
            array_values( (array) $record->get( 'fields' ) )
        );
        if ( $this->evaluate( Submission::fromFields( $this->source(), trim( $id . ': ' . $name, ': ' ), $fields ) )->act ) {
            $ajax_handler->add_error_message( $this->message() );
            $ajax_handler->add_error( 'breakwater', '' );
        }
    }
}
