<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Ultimate Member registration. Checked after UM's own validation and before the user is
 * created; spam shows an error on the email field and keeps what was typed.
 */
final class UltimateMember extends Adapter {

    public function source(): string { return 'ultimate-member'; }
    public function label(): string { return 'Ultimate Member registration'; }
    public function detected(): bool { return function_exists( 'UM' ); }

    public function register(): void {
        add_action( 'um_submit_form_register', $this->check( ... ), 5, 2 );
    }

    /**
     * @param array<string, mixed> $args      Submitted values keyed by meta key.
     * @param array<string, mixed> $form_data form_id, custom_fields.
     */
    private function check( mixed $args, mixed $form_data = [] ): void {
        if ( ! is_array( $args ) || ! is_array( $form_data ) || ! function_exists( 'UM' ) || isset( UM()->form()->errors ) ) {
            return;
        }
        $email_key = 'user_email';
        $fields    = [];
        foreach ( (array) maybe_unserialize( $form_data['custom_fields'] ?? [] ) as $key => $def ) {
            if ( ! is_array( $def ) || ! array_key_exists( $key, $args ) ) {
                continue;
            }
            $type = (string) ( $def['type'] ?? 'text' );
            if ( in_array( $def['validate'] ?? '', [ 'unique_email', 'unique_username_or_email' ], true ) || 'user_email' === $key ) {
                $type      = 'email';
                $email_key = (string) $key;
            } elseif ( in_array( $key, [ 'first_name', 'last_name', 'user_login', 'user_url' ], true ) ) {
                $type = (string) $key;
            }
            $fields[] = [ 'type' => $type, 'label' => (string) ( $def['label'] ?? $def['title'] ?? $key ), 'value' => $args[ $key ], 'key' => (string) $key ];
        }
        $form_id = (int) ( $form_data['form_id'] ?? 0 );
        if ( $this->evaluate( Submission::fromFields( $this->source(), $form_id . ': ' . get_the_title( $form_id ), $fields ) )->act ) {
            UM()->form()->add_error( $email_key, $this->message() );
        }
    }
}
