<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Release;
use Breakwater\Submission;

/**
 * Avada Forms (Fusion Builder). Checked once Avada has parsed the submission, before it is
 * saved, mailed or sent to HubSpot or Mailchimp; spam gets the form's error notice, the
 * same way Avada's own reCAPTCHA check refuses.
 *
 * Avada has no spam folder, so a held submission keeps the data Avada parsed; released, it
 * goes through the rest of Avada's own submit: HubSpot, Mailchimp, the form's actions
 * (database, URL, email, custom) and its notifications.
 */
final class Avada extends Adapter {

    /** Actions that log someone in or change an account: never replayed later, from another request. */
    private const AUTH = [ 'login', 'register', 'lost_password', 'reset_password' ];

    public function source(): string { return 'avada'; }
    public function label(): string { return 'Avada Forms'; }
    public function detected(): bool { return class_exists( 'Fusion_Form_Submit' ); }

    public function register(): void {
        add_filter( 'fusion_builder_form_submission_data', $this->check( ... ), 5 );
    }

    /**
     * Picks up Avada's submit where Breakwater stopped it (right after its parsing), with the
     * visitor's original time, page, IP and browser.
     */
    public function release( object $row ): ?string {
        $stash = Log::replay( $row );
        $id    = (int) ( $stash['post_id'] ?? 0 );
        $data  = $stash['data'] ?? null;
        $form  = $id ? get_post( $id ) : null;
        if ( ! is_array( $data ) || ! is_array( $data['data'] ?? null ) || ! $form || 'fusion_form' !== $form->post_type || 'publish' !== $form->post_status
            || ! class_exists( 'Fusion_Form_Submit' ) || ! class_exists( 'Fusion_Builder_Form_Helper' ) || ! class_exists( 'Fusion_Form_DB_Forms' ) || ! function_exists( 'fusion_data' )
            || ! method_exists( 'Fusion_Form_Submit', 'handle_form_actions' ) || ! method_exists( 'Fusion_Form_Submit', 'handle_form_notifications' ) ) {
            return null;
        }
        $meta = \Fusion_Builder_Form_Helper::fusion_form_get_form_meta( $id );
        if ( array_intersect( self::AUTH, (array) ( $meta['form_actions'] ?? [] ) ) || self::uploads( $data ) ) {
            return null;
        }
        $args  = array_merge( (array) ( $stash['args'] ?? [] ), [ 'form_id' => (string) $id ] );
        $saved = $_POST;
        $_POST = wp_slash( $args ); // Avada reads form_id from $_POST, the way WordPress hands it over.
        try {
            $result = self::submitter()->replay( $data, $id, $args );
        } finally {
            $_POST = $saved;
        }
        if ( $result['submission'] ) {
            Log::link( (int) $row->id, (string) $result['submission'] );
        }
        return self::outcome( $result['responses'], $result['crm'] );
    }

    /**
     * Who the form's notifications go to (Avada 3.7+), or its old single Email setting.
     */
    public function recipients( string $form ): array {
        $id = self::formId( $form );
        if ( ! $id || ! function_exists( 'fusion_data' ) ) {
            return [];
        }
        $to    = [];
        $meta  = fusion_data()->post_meta( $id );
        $list  = $meta->get( 'notifications' );
        $list  = is_string( $list ) ? json_decode( $list, true ) : $list;
        if ( '0' !== (string) $meta->get( 'notifications_enabled' ) ) {
            foreach ( is_array( $list ) ? $list : [] as $notification ) {
                if ( ! is_array( $notification ) || '0' === (string) ( $notification['notification_status'] ?? '1' ) ) {
                    continue;
                }
                $email = (string) ( $notification['email'] ?? '' );
                $to    = array_merge( $to, '' === $email ? [ (string) get_option( 'admin_email' ) ] : self::emails( $email ), self::emails( (string) ( $notification['email_cc'] ?? '' ) ), self::emails( (string) ( $notification['email_bcc'] ?? '' ) ) );
            }
        }
        if ( class_exists( 'Fusion_Builder_Form_Helper' ) ) {
            $form_meta = \Fusion_Builder_Form_Helper::fusion_form_get_form_meta( $id );
            $legacy    = in_array( 'email', (array) ( $form_meta['form_actions'] ?? [] ), true ) || 'email' === (string) $meta->get( 'form_type' ) || 'database_email' === (string) $meta->get( 'form_type' );
            if ( $legacy ) {
                $to = array_merge( $to, self::emails( (string) ( $form_meta['email'] ?? '' ) ) );
            }
        }
        return array_values( array_unique( $to ) );
    }

    /**
     * Avada's submit handler without its AJAX hooks, running the steps of
     * Fusion_Form_Submit::get_submit_data() after the filter, then ajax_form_submit()'s actions,
     * notifications and submission count.
     */
    private static function submitter(): object {
        return new class() extends \Fusion_Form_Submit {

            public function __construct() {} // The parent adds the AJAX handlers.

            /**
             * @param array<string, mixed> $data
             * @param array<string, mixed> $args
             * @return array{responses: array<string, mixed>, submission: int, crm: list<string>}
             */
            public function replay( array $data, int $id, array $args ): array {
                $data['submission']['form_id'] = absint( ( new \Fusion_Form_DB_Forms() )->insert( [ 'form_id' => $id, 'views' => 0 ] ) );
                $data                          = apply_filters( 'fusion_builder_form_submission_data', $data );
                $labels                        = (array) ( $data['field_labels'] ?? [] );

                $private = [];
                if ( isset( $data['data']['fusion-fields-hold-private-data'] ) ) {
                    $private = explode( ',', (string) $data['data']['fusion-fields-hold-private-data'] );
                    unset( $data['data']['fusion-fields-hold-private-data'] );
                }
                $data['fields_holding_privacy_data'] = $private;
                unset( $data['data']['fusion_privacy_store_ip_ua'], $data['data']['fusion_privacy_expiration_interval'], $data['data']['privacy_expiration_action'], $data['data'][ 'fusion-form-nonce-' . $id ] );

                $crm = [];
                if ( class_exists( 'Fusion_Hubspot' ) && function_exists( 'Fusion_Hubspot' ) && 'contact' === fusion_data()->post_meta( $id )->get( 'hubspot_action' ) ) {
                    $response = '' !== get_post_meta( $id, 'form_hubspot_map', true )
                        ? Fusion_Hubspot()->submit_form( $data, $labels, $id )
                        : Fusion_Hubspot()->create_contact( $data, fusion_data()->post_meta( $id )->get( 'hubspot_map' ), $labels );
                    $data['submission']['data']['hubspot_response'] = ! empty( $response ) ? $response : wp_json_encode( [ 'notice' => __( 'Not connected to HubSpot API.', 'fusion-builder' ) ] );
                    $crm[] = 'HubSpot';
                }
                if ( class_exists( 'Fusion_Mailchimp' ) && function_exists( 'Fusion_Mailchimp' ) && 'contact' === fusion_data()->post_meta( $id )->get( 'mailchimp_action' ) && ( ! method_exists( $this, 'check_mailchimp_consent' ) || $this->check_mailchimp_consent( $id, $data ) ) ) {
                    $data['submission']['data']['mailchimp_response'] = Fusion_Mailchimp()->create_contact( $data, $id, $labels );
                    $crm[] = 'Mailchimp';
                }
                do_action( 'fusion_form_submission_data', $data, $id );

                $responses  = array_merge( (array) $this->handle_form_actions( $data, $id, $args ), (array) $this->handle_form_notifications( $data, $id ) );
                $submission = (int) $this->db_submission_id;
                $failed     = array_filter( $responses, fn ( mixed $r ): bool => is_array( $r ) && 'error' === ( $r['status'] ?? '' ) );
                if ( ! $failed ) {
                    ( new \Fusion_Form_DB_Forms() )->increment_submissions_count( $data['submission']['form_id'] );
                }
                return [ 'responses' => $responses, 'submission' => $submission, 'crm' => $crm ];
            }
        };
    }

    /**
     * What Avada reported doing, in a sentence. Null when nothing it tried worked, so the
     * fields are emailed instead.
     *
     * @param array<string, mixed> $responses action or "email_N" => [status, info]
     * @param list<string>         $crm
     */
    private static function outcome( array $responses, array $crm ): ?string {
        $done   = [];
        $failed = [];
        $emails = [ 0, 0 ];
        foreach ( $responses as $key => $response ) {
            $ok = is_array( $response ) && in_array( $response['status'] ?? '', [ 'success', 'info', 'redirect' ], true );
            if ( 'email' === $key || str_starts_with( (string) $key, 'email_' ) ) {
                $emails[ $ok ? 0 : 1 ]++;
                continue;
            }
            [ $what, $name ] = match ( (string) $key ) {
                'database' => [ 'saved to the form\'s submissions', 'saving to the submissions' ],
                'url'      => [ 'sent to the form\'s URL', 'sending to the URL' ],
                default    => [ 'the "' . $key . '" action ran', 'the "' . $key . '" action' ],
            };
            if ( $ok ) {
                $done[] = $what;
            } else {
                $failed[] = $name;
            }
        }
        if ( $emails[0] ) {
            $done[] = 1 === $emails[0] ? 'its notification email went out' : 'its ' . $emails[0] . ' notification emails went out';
        }
        if ( $emails[1] ) {
            $failed[] = 1 === $emails[1] ? 'a notification email' : $emails[1] . ' notification emails';
        }
        foreach ( $crm as $name ) {
            $done[] = 'the contact was sent to ' . $name;
        }
        if ( ! $done ) {
            return $failed ? null : 'Handed back to the Avada form, which has no actions or notifications to run.';
        }
        $last     = array_pop( $done );
        $sentence = ucfirst( $done ? implode( ', ', $done ) . ' and ' . $last : $last );
        return $sentence . ( $failed ? ', but ' . implode( ' and ', $failed ) . ' failed.' : '.' );
    }

    /** @param array<string, mixed> $data */
    private static function uploads( array $data ): bool {
        foreach ( (array) ( $data['field_types'] ?? [] ) as $name => $type ) {
            if ( 'upload' === $type && ! empty( $data['data'][ $name ] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $data data, field_types, field_labels, submission.
     */
    private function check( mixed $data ): mixed {
        if ( ! is_array( $data ) || ! is_array( $data['data'] ?? null ) || Release::running() ) {
            return $data;
        }
        $form_id = absint( $_POST['form_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
        $meta    = class_exists( 'Fusion_Builder_Form_Helper' ) ? \Fusion_Builder_Form_Helper::fusion_form_get_form_meta( $form_id ) : [];
        if ( array_intersect( [ 'login', 'lost_password', 'reset_password' ], (array) ( $meta['form_actions'] ?? [] ) ) ) {
            return $data; // A member logging in or resetting a password is never held.
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
        $s       = Submission::fromFields( $this->source(), $form_id . ': ' . get_the_title( $form_id ), $fields );
        if ( '' !== $trap ) {
            $s->trap = $trap;
        }
        $verdict = $this->evaluate( $s );
        if ( $verdict->act ) {
            $this->stash( $verdict->log_id, $form_id, $data );
            die( wp_json_encode( [ 'status' => 'error', 'info' => [ 'spam' => $this->message() ], 'message' => $this->message() ] ) );
        }
        return $data;
    }

    /**
     * What Avada had parsed, without its nonce and captcha tokens. Forms that log in or
     * register aren't kept: they carry passwords and can't be replayed later anyway.
     *
     * @param array<string, mixed> $data
     */
    private function stash( int $log_id, int $form_id, array $data ): void {
        $meta = class_exists( 'Fusion_Builder_Form_Helper' ) ? \Fusion_Builder_Form_Helper::fusion_form_get_form_meta( $form_id ) : [];
        if ( ! $log_id || array_intersect( self::AUTH, (array) ( $meta['form_actions'] ?? [] ) ) ) {
            return;
        }
        $types        = (array) ( $data['field_types'] ?? [] );
        $data['data'] = array_filter(
            $data['data'],
            fn ( mixed $key ): bool => 'password' !== ( $types[ $key ] ?? '' ) && ! preg_match( '/^(fusion-form-nonce-|g-recaptcha-response|fusion-form-recaptcha-response|cf-turnstile-response|h-captcha-response)/', (string) $key ),
            ARRAY_FILTER_USE_KEY
        );
        $args = [];
        foreach ( [ 'post_id', 'field_labels', 'field_types', 'hidden_field_names' ] as $key ) {
            if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                $args[ $key ] = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
            }
        }
        Log::stash( $log_id, [ 'post_id' => $form_id, 'data' => $data, 'args' => $args ] );
    }
}
