<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Feedback;
use Breakwater\Guard;
use Breakwater\Log;
use Breakwater\Release;
use Breakwater\Submission;

/**
 * Jetpack Forms (contact form block). Spam is saved as a spam feedback entry and its email is
 * skipped. "Not spam" (here, in the log, from the digest or on Jetpack's Feedback screen) moves
 * it to the inbox and runs what holding it skipped: the form's webhooks and integrations, and
 * its email. Jetpack deletes spam feedback after 15 days; after that the fields are emailed.
 */
final class Jetpack extends Adapter {

    private const FORM     = 'Automattic\Jetpack\Forms\ContactForm\Contact_Form';
    private const PLUGIN   = 'Automattic\Jetpack\Forms\ContactForm\Contact_Form_Plugin';
    private const FEEDBACK = 'Automattic\Jetpack\Forms\ContactForm\Feedback';
    private const RENDERER = 'Automattic\Jetpack\Forms\ContactForm\Feedback_Email_Renderer';

    /** The form being submitted, handed to us just before the spam check. */
    private ?object $form = null;

    /** Log row of the submission we just held in this request, until its feedback post is saved. */
    private int $held = 0;

    /** Subject and headers Jetpack built for the held submission's email, which it doesn't store. */
    private string $subject = '';
    private string $headers = '';

    /** Feedback post Jetpack's own "Not spam" is restoring: Jetpack sends its email itself. */
    private int $native = 0;

    public function source(): string { return 'jetpack'; }
    public function label(): string { return 'Jetpack Forms'; }
    public function detected(): bool { return class_exists( self::FORM ) || class_exists( 'Grunion_Contact_Form' ); }
    public function keepsSpam(): bool { return true; }

    public function register(): void {
        add_filter( 'jetpack_forms_extra_webhooks', $this->remember( ... ), 1, 2 );
        add_filter( 'jetpack_contact_form_is_spam', $this->check( ... ), 20, 2 );
        add_filter( 'contact_form_subject', $this->rememberSubject( ... ), PHP_INT_MAX, 1 );
        add_filter( 'jetpack_contact_form_email_headers', $this->rememberHeaders( ... ), PHP_INT_MAX, 1 );
        add_action( 'grunion_after_feedback_post_inserted', $this->link( ... ), 1, 3 );
        add_filter( 'rest_request_before_callbacks', $this->restUpdate( ... ), 10, 3 );
        add_action( 'transition_post_status', $this->statusChanged( ... ), 10, 3 );
    }

    /**
     * Out of spam, then what a normal submission does after saving its feedback post: the
     * integrations on grunion_after_feedback_post_inserted (webhooks, MailPoet, Salesforce...)
     * with the form rebuilt from its signed token, and the notification email, rendered again
     * as not spam. After Jetpack's own "Not spam" Jetpack resends the email itself.
     */
    public function release( object $row ): ?string {
        $replay  = Log::replay( $row );
        $post_id = (int) ( $replay['post_id'] ?? 0 ) ?: (int) $row->ref;
        $post    = $post_id && class_exists( self::FEEDBACK ) ? get_post( $post_id ) : null;
        if ( ! $post instanceof \WP_Post || 'feedback' !== $post->post_type || (int) get_post_meta( $post_id, '_breakwater_log_id', true ) !== (int) $row->id ) {
            return null; // Deleted: Jetpack empties spam after 15 days.
        }
        if ( ! in_array( $post->post_status, [ 'spam', 'publish', 'jp-temp-feedback' ], true ) ) {
            return null; // Trashed.
        }
        $rebuilt = $this->rebuild( is_array( $replay['post'] ?? null ) ? $replay['post'] : [] );
        $form    = $rebuilt['form'] ?? null;
        $status  = $post->post_status;
        if ( 'spam' === $status ) {
            $status = $form && 'no' === $form->get_attribute( 'saveResponses' ) ? 'jp-temp-feedback' : 'publish';
            wp_update_post( [ 'ID' => $post_id, 'post_status' => $status ] );
            if ( method_exists( self::FEEDBACK, 'clear_cache' ) ) {
                ( self::FEEDBACK )::clear_cache();
            }
            if ( 'publish' === $status && method_exists( self::PLUGIN, 'recalculate_unread_count' ) ) {
                ( self::PLUGIN )::recalculate_unread_count();
            }
        }
        $response = ( self::FEEDBACK )::get( $post_id );
        if ( ! $response ) {
            return null;
        }
        if ( $form ) {
            do_action( 'grunion_after_feedback_post_inserted', $post_id, $rebuilt['fields'], false, $response->get_entry_values() );
        }

        $native = $this->native === $post_id;
        $sent   = $this->email( $post_id, $response, $form, $status, $replay, $native );
        $email  = match ( true ) {
            $native => 'Jetpack sent its email',
            $sent   => 'its email was sent',
            default => 'no email was sent (the form\'s notifications are off)',
        };
        $where = 'publish' === $status ? 'Moved to the Jetpack Forms inbox' : 'Handed back to Jetpack Forms';
        return $form
            ? $where . ': its integrations ran and ' . $email . '.'
            : $where . ' and ' . $email . '. Its webhooks and integrations did not run: the form could not be rebuilt.';
    }

    /** Who Jetpack emails: the form's "to" setting, else the author of the page it is on. */
    public function recipients( string $form ): array {
        $post = get_post( self::formId( $form ) );
        if ( ! $post ) {
            return [];
        }
        if ( preg_match( '/\[contact-form[^\]]*\bto=["\']([^"\']+)|"to":"([^"]+)"/', (string) $post->post_content, $m ) ) {
            $to = self::emails( (string) ( ( $m[2] ?? '' ) ?: $m[1] ) );
            if ( $to ) {
                return $to;
            }
        }
        $author = get_userdata( (int) $post->post_author );
        return $author && is_email( $author->user_email ) ? [ $author->user_email ] : [];
    }

    /**
     * The form as Jetpack processed it: rebuilt from its signed token with the visitor's post,
     * with the same destination checks, extra webhooks and services the submission had.
     *
     * @param array<string, mixed> $post The visitor's $_POST, as stashed.
     * @return array{form:object, fields:array<mixed>}|null
     */
    private function rebuild( array $post ): ?array {
        $jwt = is_string( $post['jetpack_contact_form_jwt'] ?? null ) ? sanitize_text_field( wp_unslash( $post['jetpack_contact_form_jwt'] ) ) : '';
        if ( '' === $jwt || ! method_exists( self::FORM, 'get_instance_from_jwt' ) || ! class_exists( self::PLUGIN ) ) {
            return null;
        }
        $saved_post   = $_POST; // phpcs:ignore WordPress.Security.NonceVerification
        $saved_global = $GLOBALS['post'] ?? null;
        $_POST        = $post; // Fields read their values from the request as the form is built.
        $GLOBALS['post'] = null;
        try {
            $form = ( self::FORM )::get_instance_from_jwt( $jwt );
            if ( ! is_a( $form, self::FORM ) ) {
                return null;
            }
            $plugin = ( self::PLUGIN )::init();
            if ( method_exists( $plugin, 'reconcile_content_destinations' ) ) {
                ( new \ReflectionMethod( $plugin, 'reconcile_content_destinations' ) )->invoke( $plugin, $form );
            } elseif ( method_exists( 'Automattic\Jetpack\Forms\Jetpack_Forms', 'should_honor_content_destinations' ) ) {
                $form->attributes['webhooks']       = []; // A newer Jetpack moved its check: don't send anywhere unchecked.
                $form->attributes['postToUrl']      = [];
                $form->attributes['salesforceData'] = null;
            }
            if ( ! empty( $form->attributes['salesforceData'] ) && class_exists( 'Automattic\Jetpack\Forms\Service\Post_To_Url' ) ) {
                \Automattic\Jetpack\Forms\Service\Post_To_Url::init();
            }
            $extra = apply_filters( 'jetpack_forms_extra_webhooks', [], $form );
            if ( is_array( $extra ) && $extra ) {
                $form->attributes['webhooks'] = array_merge( (array) ( $form->attributes['webhooks'] ?? [] ), $extra );
            }
            if ( ! empty( $form->attributes['webhooks'] ) && class_exists( 'Automattic\Jetpack\Forms\Service\Form_Webhooks' )
                && ( ! method_exists( 'Automattic\Jetpack\Forms\Jetpack_Forms', 'is_webhooks_enabled' ) || \Automattic\Jetpack\Forms\Jetpack_Forms::is_webhooks_enabled() ) ) {
                \Automattic\Jetpack\Forms\Service\Form_Webhooks::init();
            }
            $fields = (array) $form->fields;
            if ( method_exists( $form, 'get_resolved_field_visibility' ) ) {
                foreach ( (array) $form->get_resolved_field_visibility() as $field_id => $visible ) {
                    if ( false === $visible ) {
                        unset( $fields[ $field_id ] ); // Integrations never see a field the visitor wasn't shown.
                    }
                }
            }
            return [ 'form' => $form, 'fields' => $fields ];
        } catch ( \Throwable ) {
            return null;
        } finally {
            $_POST           = $saved_post;
            $GLOBALS['post'] = $saved_global;
        }
    }

    /**
     * The notification email, rendered again as not spam (the stored copy was rendered as spam,
     * without its Mark as spam link) and stored back where Jetpack's resend reads it.
     *
     * @param array<string, mixed> $replay
     * @return bool Whether we sent it.
     */
    private function email( int $post_id, object $response, ?object $form, string $status, array $replay, bool $native ): bool {
        $stored  = get_post_meta( $post_id, '_feedback_email', true );
        $to      = is_array( $stored ) ? ( $stored['to'] ?? [] ) : [];
        $message = is_array( $stored ) ? (string) ( $stored['message'] ?? '' ) : '';
        if ( ! $to ) {
            return false;
        }
        if ( $form && method_exists( self::RENDERER, 'build_email_content' ) ) {
            /* translators: the time of the form submission. */
            $format  = sprintf( _x( '%1$s \a\t %2$s', '{$date_format} \a\t {$time_format}', 'jetpack-forms' ), get_option( 'date_format' ), get_option( 'time_format' ) );
            $email   = ( self::RENDERER )::build_email_content( $post_id, $form, $response, [
                'time'                 => wp_date( $format, get_post_timestamp( $post_id ) ?: time() ),
                'url'                  => method_exists( $response, 'get_entry_permalink' ) ? $response->get_entry_permalink() : home_url( '/' ),
                'comment_author'       => $response->get_author() ?: $response->get_author_email(),
                'comment_author_email' => $response->get_author_email(),
                'comment_author_ip'    => method_exists( $response, 'get_ip_address' ) ? $response->get_ip_address() : null,
                'is_spam'              => false,
                'is_test'              => false,
                'feedback_status'      => $status,
            ] );
            $message = (string) ( $email['message'] ?? $message );
            update_post_meta( $post_id, '_feedback_email', wp_slash( compact( 'to', 'message' ) ) );
        }
        $send = apply_filters( 'grunion_should_send_email', null, $post_id );
        $send = is_bool( $send ) ? $send : ! $form || 'no' !== $form->get_attribute( 'emailNotifications' );
        if ( ! $send && ! $native ) {
            return false;
        }
        $all   = (array) $response->get_all_values( 'submit' );
        $extra = method_exists( $response, 'get_legacy_extra_values' ) ? (array) $response->get_legacy_extra_values( 'submit' ) : [];
        $all['email_marketing_consent'] = (bool) $response->has_consent();
        do_action( 'grunion_pre_message_sent', $post_id, $all, $extra );
        if ( $native ) {
            return false; // Jetpack's "Not spam" sends it right after this.
        }
        $subject = (string) ( $replay['subject'] ?? '' ) ?: (string) $response->get_subject();
        $subject = method_exists( self::FEEDBACK, 'decode_special_chars' ) ? ( self::FEEDBACK )::decode_special_chars( $subject ) : $subject;
        $headers = (string) ( $replay['headers'] ?? '' );
        if ( '' === $headers ) {
            $author  = $response->get_author() ?: $response->get_author_email();
            $headers = 'From: ' . $author . ' <wordpress@' . wp_parse_url( site_url(), PHP_URL_HOST ) . ">\r\n"
                . ( $response->get_author_email() ? 'Reply-To: ' . $author . ' <' . $response->get_author_email() . ">\r\n" : '' );
        }
        ( self::FORM )::wp_mail( $to, $subject, $message, $headers );
        do_action( 'grunion_after_message_sent', $post_id, $to, $subject, $message, $headers, $all, $extra );
        return true;
    }

    private function remember( mixed $webhooks, mixed $form = null ): mixed {
        $this->form = is_object( $form ) ? $form : null;
        return $webhooks;
    }

    private function rememberSubject( mixed $subject ): mixed {
        if ( $this->held && is_string( $subject ) ) {
            $this->subject = $subject;
        }
        return $subject;
    }

    private function rememberHeaders( mixed $headers ): mixed {
        if ( $this->held && is_string( $headers ) ) {
            $this->headers = $headers;
        }
        return $headers;
    }

    /**
     * The held submission's feedback post is saved: link it to its row and keep what a release
     * needs, including the visitor's post with the form's signed token (its recipients, webhooks
     * and integrations).
     */
    private function link( mixed $post_id, mixed $fields = [], mixed $is_spam = false ): void {
        $log_id     = $this->held;
        $this->held = 0;
        if ( ! $log_id || true !== $is_spam || ! is_numeric( $post_id ) || ! (int) $post_id || Release::running() ) {
            return;
        }
        update_post_meta( (int) $post_id, '_breakwater_log_id', $log_id );
        Log::link( $log_id, (string) (int) $post_id );
        $post = array_filter(
            (array) $_POST, // phpcs:ignore WordPress.Security.NonceVerification
            fn ( $key ): bool => is_string( $key ) && ! preg_match( Submission::SKIP, $key ),
            ARRAY_FILTER_USE_KEY
        );
        Log::stash( $log_id, [ 'post_id' => (int) $post_id, 'post' => $post, 'subject' => $this->subject, 'headers' => $this->headers ] );
    }

    /** Jetpack's "Not spam" is a REST update of the feedback post, which resends its email. */
    private function restUpdate( mixed $response, mixed $handler, mixed $request ): mixed {
        if ( $request instanceof \WP_REST_Request && in_array( $request->get_method(), [ 'POST', 'PUT', 'PATCH' ], true )
            && preg_match( '#^/wp/v2/feedback/(\d+)$#', $request->get_route(), $m ) ) {
            $this->native = (int) $m[1];
        }
        return $response;
    }

    /** Jetpack's own "Not spam" releases it like ours does; "Spam" counts as feedback. */
    private function statusChanged( mixed $new, mixed $old, mixed $post ): void {
        if ( Release::running() || ! $post instanceof \WP_Post || 'feedback' !== $post->post_type || $new === $old ) {
            return;
        }
        $log_id = (int) get_post_meta( $post->ID, '_breakwater_log_id', true );
        $row    = $log_id ? Log::get( $log_id ) : null;
        if ( ! $row ) {
            return;
        }
        if ( 'spam' === $old && 'publish' === $new ) {
            $this->native = $this->native === $post->ID ? $post->ID : 0;
            try {
                Release::run( $row );
            } finally {
                $this->native = 0;
            }
        } elseif ( 'spam' === $new ) {
            Feedback::spam( $log_id );
        }
    }

    /**
     * @param bool|\WP_Error       $is_spam
     * @param array<string, mixed> $values Jetpack's normalised submission (the Akismet shape).
     */
    private function check( mixed $is_spam, mixed $values ): mixed {
        if ( true === $is_spam || is_wp_error( $is_spam ) || ! is_array( $values ) ) {
            return $is_spam;
        }
        $fields = [
            [ 'type' => 'name', 'label' => 'Name', 'value' => $values['comment_author'] ?? '' ],
            [ 'type' => 'email', 'label' => 'Email', 'value' => $values['comment_author_email'] ?? '' ],
            [ 'type' => 'url', 'label' => 'Website', 'value' => $values['comment_author_url'] ?? '' ],
            [ 'type' => 'text', 'label' => 'Subject', 'value' => $values['contact_form_subject'] ?? '' ],
            [ 'type' => 'textarea', 'label' => 'Message', 'value' => $values['comment_content'] ?? '' ],
        ];
        foreach ( $values as $key => $value ) {
            if ( str_starts_with( (string) $key, 'contact_form_field_' ) ) {
                $fields[] = [ 'type' => 'text', 'label' => substr( (string) $key, 19 ), 'value' => $value ];
            }
        }
        $s        = Submission::fromFields( $this->source(), $this->formName(), $fields );
        $s->other = false;
        $verdict  = $this->evaluate( $s );
        if ( ! $verdict->act ) {
            return $is_spam;
        }
        $this->held    = $verdict->log_id ?: Guard::lastLogId();
        $this->subject = $this->headers = '';
        return true;
    }

    private function formName(): string {
        $id    = sanitize_text_field( wp_unslash( (string) ( $_POST['contact-form-id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
        $title = '';
        if ( $this->form && method_exists( $this->form, 'get_attribute' ) ) {
            $id    = (string) ( $this->form->get_attribute( 'ref' ) ?: $id );
            $title = (string) $this->form->get_attribute( 'formTitle' );
        }
        if ( '' === $title && is_numeric( $id ) ) {
            $title = get_the_title( (int) $id );
        }
        return trim( $id . ': ' . $title, ': ' );
    }
}
