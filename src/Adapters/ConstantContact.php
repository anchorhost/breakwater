<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Submission;

/**
 * Constant Contact Forms. Uses the plugin's own spam verdict filter, so spam gets its
 * spam message and never reaches the Constant Contact API or the admin email. Released, it
 * goes where the plugin would have sent it: the contact to Constant Contact when the visitor
 * opted in, and the plugin's notification email.
 */
final class ConstantContact extends Adapter {

    private const TYPES = [ 'email' => 'email', 'first_name' => 'first_name', 'last_name' => 'last_name', 'website' => 'url', 'custom_text_area' => 'textarea' ];

    /** Captcha answers and the plugin's honeypot: single-use, and never needed again. */
    private const DROP = [ 'g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response', 'ctct_usage_field', 'bw_url', 'bw_token', 'bw_meta' ];

    public function source(): string { return 'constant-contact'; }
    public function label(): string { return 'Constant Contact Forms'; }
    public function detected(): bool { return class_exists( 'Constant_Contact' ); }

    public function register(): void {
        add_filter( 'constant_contact_maybe_spam', $this->check( ... ), 20, 2 );
    }

    /**
     * The rest of the plugin's process_form() after its spam filter: the form and its verify
     * key, the list and field checks, then what it does for an opted-in contact (connected:
     * add to Constant Contact, then the notification; not connected: queue the contact for
     * when it reconnects, and the notification) or a plain submission (the notification).
     * Its captcha and honeypot aren't run again: the answers were single-use.
     */
    public function release( object $row ): ?string {
        $data    = Log::replay( $row );
        $form_id = absint( $data['ctct-id'] ?? 0 );
        if ( ! $form_id || 'ctct_forms' !== get_post_type( $form_id ) || 'publish' !== get_post_status( $form_id ) || ! function_exists( 'constant_contact' ) ) {
            return null;
        }
        $verify = (string) get_post_meta( $form_id, '_ctct_verify_key', true );
        if ( '' === $verify || ! hash_equals( $verify, (string) ( $data['ctct-verify'] ?? '' ) ) ) {
            return null;
        }
        $plugin = constant_contact();
        if ( ! method_exists( $plugin, 'get_process_form' ) || ! method_exists( $plugin, 'get_mail' ) || ! method_exists( $plugin, 'get_api' ) ) {
            return null;
        }
        $process = $plugin->get_process_form();
        $mail    = $plugin->get_mail();
        $api     = $plugin->get_api();
        if ( ! method_exists( $process, 'clean_values' ) || ! method_exists( $process, 'get_field_errors' ) || ! method_exists( $mail, 'submit_form_values' ) || ! method_exists( $mail, 'opt_in_user' ) || ! method_exists( $mail, 'get_user_email_from_submission' ) ) {
            return null;
        }
        $data   = self::formLists( $data, $form_id );
        $values = self::values( $data, $form_id );
        $clean  = $process->clean_values( $values );
        $lists  = (array) ( $clean['ctct-lists']['value'] ?? [] );
        $email  = (string) $mail->get_user_email_from_submission( $clean );
        if ( ! $values || ( $api->is_connected() && empty( $lists[0] ) ) || $process->get_field_errors( $clean, true ) ) {
            return null;
        }

        // The plugin reads the opt-in fields and the chosen lists straight from $_POST.
        $post  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification
        $_POST = $data;
        try {
            if ( ! isset( $data['ctct-opt-in'] ) ) {
                $sent = $mail->submit_form_values( $values );
                return $sent ? 'Sent as the plugin\'s notification email to ' . $this->to( $form_id ) . '.' : null;
            }
            if ( $api->is_connected() ) {
                $result = $mail->opt_in_user( $clean );
                if ( ! in_array( $result['action'] ?? '', [ 'created', 'updated' ], true ) ) {
                    return null; // Constant Contact refused it: the owner gets the fields instead.
                }
                Log::link( (int) $row->id, (string) ( $result['contact_id'] ?? '' ) );
                $notified = $mail->submit_form_values( $values );
                // Constant Contact decides on a confirmation email (its Confirmed Opt-in setting), not the plugin.
                return sprintf( 'Added %s to the Constant Contact list%s', $email, count( $lists ) > 1 ? 's' : '' )
                    . ( $notified && ! constant_contact_emails_disabled( $form_id ) ? ', and the notification email went to ' . $this->to( $form_id ) . '.' : '.' );
            }
            if ( ! empty( $lists[0] ) && method_exists( $api, 'log_missed_api_request' ) ) {
                foreach ( $lists as $list ) {
                    $api->log_missed_api_request( 'contact_add_update', [ 'list' => $list, 'email' => $email, 'contact' => self::contact( $clean ), 'form_id' => $form_id ] );
                }
            }
            $sent = $mail->submit_form_values( $values, true );
            return $sent ? 'Sent as the plugin\'s notification email to ' . $this->to( $form_id ) . ( ! empty( $lists[0] ) ? ', and queued for Constant Contact for when the plugin reconnects.' : '.' ) : null;
        } finally {
            $_POST = $post;
        }
    }

    public function recipients( string $form ): array {
        if ( ! function_exists( 'constant_contact' ) || ! method_exists( constant_contact(), 'get_mail' ) ) {
            return [];
        }
        return self::emails( (string) constant_contact()->get_mail()->get_email( (string) self::formId( $form ) ) );
    }

    private function to( int $form_id ): string {
        return implode( ', ', $this->recipients( (string) $form_id ) ) ?: 'the site admin';
    }

    /**
     * Only lists the form itself offers, as the form was set up when it was released.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function formLists( array $data, int $form_id ): array {
        $allowed = array_map( 'strval', array_filter( (array) get_post_meta( $form_id, '_ctct_list', true ) ) );
        foreach ( $data as $key => $value ) {
            if ( str_starts_with( (string) $key, 'lists___' ) ) {
                $data[ $key ] = is_array( $value ) ? array_values( array_intersect( array_map( 'strval', $value ), $allowed ) ) : ( in_array( (string) $value, $allowed, true ) ? $value : '' );
            }
        }
        return $data;
    }

    /**
     * The submitted values the way process_form() builds them, before clean_values().
     *
     * @param array<string, mixed> $data
     * @return list<array{key:string, value:mixed}>
     */
    private static function values( array $data, int $form_id ): array {
        $ignored = (array) apply_filters(
            'constant_contact_ignored_post_form_values',
            [ 'ctct-submitted', 'ctct_form', '_wp_http_referer', 'ctct-verify', 'ctct_time', 'ctct_usage_field', 'g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response', 'ctct_must_opt_in', 'ctct-instance' ],
            $form_id
        );
        $values = [];
        foreach ( $data as $key => $value ) {
            if ( ( is_string( $value ) || is_array( $value ) ) && ! in_array( $key, $ignored, true ) ) {
                $values[] = [
                    'key'   => sanitize_text_field( (string) $key ),
                    'value' => is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value ),
                ];
            }
        }
        return $values;
    }

    /**
     * The contact the plugin queues while it isn't connected.
     *
     * @param array<string, array<string, mixed>> $clean
     * @return array<string, mixed>
     */
    private static function contact( array $clean ): array {
        $contact = [];
        foreach ( $clean as $field ) {
            $key  = sanitize_text_field( (string) ( $field['key'] ?? '' ) );
            $orig = sanitize_text_field( (string) ( $field['orig_key'] ?? '' ) );
            $val  = is_string( $field['value'] ?? null ) ? sanitize_text_field( $field['value'] ) : '';
            if ( '' === $key || in_array( $key, [ 'ctct-opt-in', 'ctct-id', 'ctct-lists' ], true ) ) {
                continue;
            }
            $contact[ $orig ] = [ 'key' => $key, 'val' => $val ];
            if ( 'email' === $key ) {
                $contact['email'] = $val;
            }
        }
        return $contact;
    }

    /**
     * @param array<string, mixed> $data Keys look like "{type}___{md5}".
     */
    private function check( mixed $is_spam, mixed $data ): mixed {
        if ( true === $is_spam || ! is_array( $data ) ) {
            return $is_spam;
        }
        $fields = [];
        foreach ( $data as $key => $value ) {
            if ( ! is_string( $value ) || ! str_contains( (string) $key, '___' ) ) {
                continue;
            }
            $type = strstr( (string) $key, '___', true );
            if ( in_array( $type, [ 'lists', 'birthday', 'anniversary' ], true ) || str_starts_with( $type, 'address' ) ) {
                continue;
            }
            $fields[] = [ 'type' => self::TYPES[ $type ] ?? 'text', 'label' => $type, 'value' => $value ];
        }
        $id       = absint( $data['ctct-id'] ?? 0 );
        $s        = Submission::fromFields( $this->source(), $id . ': ' . get_the_title( $id ), $fields );
        $s->other = (bool) $is_spam;
        $verdict  = $this->evaluate( $s );
        if ( ! $verdict->act ) {
            return $is_spam;
        }
        // The plugin's filter runs before its own form ID and verify key checks: release() makes them.
        Log::stash( $verdict->log_id, array_diff_key( $data, array_flip( self::DROP ) ) );
        $message = $this->message();
        add_filter( 'constant_contact_custom_spam_message', static fn (): string => esc_html( $message ), 99 ); // This request only.
        return true;
    }
}
