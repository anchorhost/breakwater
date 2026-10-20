<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Submission;

/**
 * Ninja Forms. Checked before Ninja processes the submission; spam gets an error on the email
 * field, which makes Ninja answer before running its actions, so nothing is saved or emailed.
 * Released, the stashed submission goes through Ninja's own processing again: fields, then its
 * actions (Save, emails, add-ons) and ninja_forms_after_submission.
 */
final class NinjaForms extends Adapter {

    /** Field types whose values are secrets or single-use tokens: never stashed. */
    private const SECRET = [ 'password', 'passwordconfirm', 'creditcard', 'creditcardnumber', 'creditcardcvc', 'creditcardexpiration', 'creditcardfullname', 'creditcardzip' ];

    /** Captcha fields: their token was spent on the first try, so a replay skips their check. */
    private const CAPTCHA = [ 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'turnstile' ];

    public function source(): string { return 'ninja-forms'; }
    public function label(): string { return 'Ninja Forms'; }
    public function detected(): bool { return class_exists( 'Ninja_Forms' ); }

    public function register(): void {
        add_filter( 'ninja_forms_submit_data', $this->check( ... ), 99 );
    }

    /**
     * Ninja's submission controller, run on the stashed data without its AJAX wrapper: the
     * nonce was checked on the first try, and its response (JSON, then exit) is caught instead.
     * Validation runs again, apart from captchas; Akismet and reCAPTCHA actions are skipped.
     */
    public function release( object $row ): ?string {
        $replay  = Log::replay( $row );
        $form_id = (int) ( $replay['id'] ?? 0 );
        if ( ! $form_id || empty( $replay['fields'] ) || ! is_array( $replay['fields'] ) || ! self::replayable( $form_id ) || ! class_exists( 'NF_AJAX_Controllers_Submission' ) ) {
            return null;
        }
        $controller = new class() extends \NF_AJAX_Controllers_Submission {
            private bool $responded = false;

            /** The parent registers the AJAX hooks and may resume a session: neither applies. */
            public function __construct() {}

            /**
             * @param array<string, mixed> $form_data
             * @return array{0: array<string, mixed>, 1: array<mixed>}
             */
            public function replay( int $form_id, array $form_data ): array {
                $this->_form_id   = $form_id;
                $this->_form_data = $form_data;
                if ( class_exists( 'WPN_Helper' ) && \WPN_Helper::use_cache() ) {
                    $this->_form_cache = \WPN_Helper::get_nf_cache( $form_id );
                }
                try {
                    $this->process();
                } catch ( \Throwable $e ) {
                    if ( ! $this->responded ) {
                        throw $e;
                    }
                }
                return [ $this->_data, $this->_errors ];
            }

            /** Ninja ends every outcome here, errors and success alike. */
            protected function _respond( $data = array() ) {
                $this->responded = true;
                throw new \RuntimeException( 'Ninja Forms responded.' );
            }
        };
        $skip   = fn ( $settings ) => is_array( $settings ) && in_array( $settings['type'] ?? '', self::CAPTCHA, true ) ? array_merge( $settings, [ 'type' => null ] ) : $settings;
        $filters = [ 'ninja_forms_run_action_type_akismet' => '__return_false', 'ninja_forms_run_action_type_recaptcha' => '__return_false', 'ninja_forms_pre_validate_field_settings' => $skip ];
        foreach ( $filters as $hook => $callback ) {
            add_filter( $hook, $callback, 99 );
        }
        try {
            $replay['settings']['is_preview'] = false; // Save skips previews.
            unset( $replay['errors'] );
            [ $data, $errors ] = $controller->replay( $form_id, $replay );
        } finally {
            foreach ( $filters as $hook => $callback ) {
                remove_filter( $hook, $callback, 99 );
            }
        }
        if ( $errors || ! empty( $data['errors'] ) || ! empty( $data['halt'] ) ) {
            return null; // A field error now (say a unique value taken since), or a payment step.
        }
        $sub_id = (int) ( $data['actions']['save']['sub_id'] ?? 0 );
        if ( $sub_id ) {
            update_post_meta( $sub_id, 'breakwater_log_id', (int) $row->id );
            Log::link( (int) $row->id, (string) $sub_id );
            return 'Saved to the Ninja Forms submissions, and the form\'s actions ran.';
        }
        return 'The form\'s actions ran in Ninja Forms (it has no Save action, so there is no stored submission).';
    }

    public function recipients( string $form ): array {
        if ( ! function_exists( 'Ninja_Forms' ) || ! self::formId( $form ) ) {
            return [];
        }
        $to = [];
        foreach ( (array) Ninja_Forms()->form( self::formId( $form ) )->get_actions() as $action ) {
            if ( 'email' === $action->get_setting( 'type' ) && $action->get_setting( 'active' ) ) {
                $to = array_merge( $to, self::emails( str_ireplace( [ '{system:admin_email}', '{wp:admin_email}' ], '{admin_email}', (string) $action->get_setting( 'to' ) ) ) );
            }
        }
        return array_values( array_unique( $to ) );
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
        $types  = [];
        $first  = null;
        $email  = null;
        foreach ( (array) $form->get_fields() as $field_id => $field ) {
            $first ??= $field_id;
            if ( 'email' === $field->get_setting( 'type' ) ) {
                $email ??= $field_id;
            }
            $types[ $field_id ] = (string) $field->get_setting( 'type' );
            $fields[] = [
                'type'  => (string) $field->get_setting( 'type' ),
                'label' => (string) $field->get_setting( 'label' ),
                'value' => $form_data['fields'][ $field_id ]['value'] ?? '',
                'key'   => (string) $field_id,
            ];
        }
        $title = (string) $form->get()->get_setting( 'title' );
        $error_on = $email ?? $first; // The error shows under this field.
        if ( null === $error_on ) {
            return $form_data;
        }
        $verdict = $this->evaluate( Submission::fromFields( $this->source(), $id . ': ' . $title, $fields ) );
        if ( $verdict->act ) {
            if ( self::replayable( $id ) ) {
                Log::stash( $verdict->log_id, self::stashable( $form_data, $types ) );
            }
            // Ninja checks for field errors as it processes each field and responds with them.
            $form_data['errors']['fields'][ $error_on ] = $this->message();
        }
        return $form_data;
    }

    /**
     * The submission as Ninja will process it (after the submit filters ran), without
     * passwords, card numbers or captcha tokens.
     *
     * @param array<string, mixed>  $form_data
     * @param array<int|string, string> $types field id => type
     * @return array<string, mixed>
     */
    private static function stashable( array $form_data, array $types ): array {
        unset( $form_data['errors'] );
        foreach ( (array) $form_data['fields'] as $field_id => $field ) {
            $type = $types[ $field_id ] ?? '';
            if ( is_array( $field ) && ( in_array( $type, self::SECRET, true ) || in_array( $type, self::CAPTCHA, true ) ) ) {
                $form_data['fields'][ $field_id ]['value'] = '';
            }
        }
        $form_data['settings']['is_preview'] = false;
        return $form_data;
    }

    /** Uploads and payments can't be handed back: the file is gone and the payment step needs the visitor. */
    private static function replayable( int $form_id ): bool {
        foreach ( (array) Ninja_Forms()->form( $form_id )->get_fields() as $field ) {
            $type = (string) $field->get_setting( 'type' );
            if ( 'file_upload' === $type || str_starts_with( $type, 'creditcard' ) ) {
                return false;
            }
        }
        foreach ( (array) Ninja_Forms()->form( $form_id )->get_actions() as $action ) {
            $type = (string) $action->get_setting( 'type' );
            if ( $action->get_setting( 'active' ) && ( str_contains( $type, 'payment' ) || in_array( $type, [ 'stripe', 'paypal_express', 'elavon', 'square' ], true ) ) ) {
                return false;
            }
        }
        return true;
    }
}
