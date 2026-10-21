<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Release;
use Breakwater\Submission;

/**
 * Breakdance Form Builder. Checked in Breakdance's own field validation, after its CSRF,
 * reCAPTCHA and honeypot checks and before any action runs; spam is refused with a form
 * error the way Breakdance refuses an invalid field. Its stored submissions have no spam
 * status (only unread and read), so nothing is saved for a held one; what a replay needs is
 * kept in the Breakwater log. Released, the submission goes through the form's current
 * actions the way handleSubmission() runs them: Store Submission, Email, webhooks, mailing
 * lists...
 */
final class Breakdance extends Adapter {

    /** Actions that only run in the visitor's browser: nothing happens when they run later. */
    private const BROWSER = [ 'custom_javascript', 'popup' ];

    /** Third-party actions that log someone in, create an account or take a payment are never replayed. */
    private const AUTH = '/login|register|signup|sign_up|user|password|pay|stripe|checkout|order/i';

    /** Forms already checked in this request ("post:form"), so each is judged once, not per field. */
    private array $checked = [];

    /** True while this adapter runs Breakdance's validation itself. */
    private bool $validating = false;

    public function source(): string { return 'breakdance'; }
    public function label(): string { return 'Breakdance'; }
    public function detected(): bool { return defined( 'BREAKDANCE_MODE' ) && 'breakdance' === BREAKDANCE_MODE; }

    public function register(): void {
        add_filter( 'breakdance_form_validate_field', $this->check( ... ), 99, 4 );
    }

    /**
     * What handleSubmission() does after its validation, from the form's current settings:
     * its actions, then the actions log on the stored submission.
     */
    public function release( object $row ): ?string {
        $data    = Log::replay( $row );
        $post_id = (int) ( $data['post_id'] ?? 0 );
        $form_id = (int) ( $data['form_id'] ?? 0 );
        if ( ! $post_id || ! $form_id || ! is_array( $data['fields'] ?? null ) || ! self::available() ) {
            return null;
        }
        $settings = \Breakdance\Forms\getFormSettings( $post_id, $form_id );
        if ( ! is_array( $settings ) || empty( $settings['form']['fields'] ) || ! is_array( $settings['form']['fields'] ) ) {
            return null; // The form was deleted or emptied.
        }
        $fields = $data['fields'];
        $form   = \Breakdance\Forms\getFormData( $settings['form']['fields'], $fields );
        foreach ( $form as $field ) {
            if ( in_array( $field['type'] ?? '', [ 'file', 'password' ], true ) && ! in_array( $field['value'] ?? '', [ '', [] ], true ) ) {
                return null; // A field that was added since and can't be filled from what was kept.
            }
        }
        if ( is_wp_error( \Breakdance\Forms\validateFormData( $form, [], $form_id, $post_id ) ) ) {
            return null; // The form changed and the kept values no longer pass.
        }
        $actions = array_map( self::fresh( ... ), \Breakdance\Forms\getFormActions( $settings ) );
        foreach ( $actions as $action ) {
            if ( preg_match( self::AUTH, (string) $action::slug() ) ) {
                return null;
            }
        }
        $extra = [
            'formId'    => $form_id,
            'postId'    => $post_id,
            'fields'    => $fields,
            'uploads'   => [],
            'files'     => [],
            'ip'        => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
            'referer'   => (string) ( $data['referer'] ?? '' ),
            'userAgent' => (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
            'userId'    => (int) ( $data['user_id'] ?? 0 ),
        ];
        $responses = \Breakdance\Forms\executeActions( $actions, [ $form, $settings, $extra ] );
        \Breakdance\Forms\handleSubmissionCleanup( $responses, [], false );
        return self::outcome( (int) $row->id, $responses );
    }

    /**
     * The form's Email action addresses (To, CC, BCC), leaving out the field tags that send a
     * copy to the visitor.
     */
    public function recipients( string $form ): array {
        preg_match( '/\[(\d+)\]$/', $form, $m );
        $settings = isset( $m[1] ) && function_exists( '\Breakdance\Forms\getFormSettings' ) ? \Breakdance\Forms\getFormSettings( self::formId( $form ), (int) $m[1] ) : null;
        if ( ! is_array( $settings ) || ! in_array( 'email', (array) ( $settings['actions']['actions'] ?? [] ), true ) ) {
            return [];
        }
        $to = [];
        foreach ( (array) ( $settings['actions']['email']['emails'] ?? [] ) as $email ) {
            if ( is_array( $email ) ) {
                foreach ( [ 'to', 'cc', 'bcc' ] as $key ) {
                    $to = array_merge( $to, self::emails( (string) ( $email[ $key ] ?? '' ) ) );
                }
            }
        }
        return array_values( array_unique( $to ) );
    }

    /**
     * Runs once per form and request, on the first field Breakdance validates: the whole form
     * is judged, after Breakdance's own validation of it passes, so its own errors still win.
     *
     * @param \WP_Error $errors  Breakdance's error bag for this field.
     * @param array     $field   The field with its value.
     */
    private function check( mixed $errors, mixed $field = null, mixed $form_id = 0, mixed $post_id = 0 ): mixed {
        if ( ! $errors instanceof \WP_Error || $this->validating || Release::running() || ! is_numeric( $form_id ) || ! is_numeric( $post_id ) || ! self::available() ) {
            return $errors;
        }
        $key = (int) $post_id . ':' . (int) $form_id;
        if ( isset( $this->checked[ $key ] ) ) {
            return $errors;
        }
        $this->checked[ $key ] = true;
        $settings = \Breakdance\Forms\getFormSettings( (int) $post_id, (int) $form_id );
        $posted   = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        if ( ! is_array( $settings ) || ! is_array( $settings['form']['fields'] ?? null ) ) {
            return $errors;
        }
        unset( $posted['csrfToken'], $posted['hpname'] );
        $form    = \Breakdance\Forms\getFormData( $settings['form']['fields'], $posted );
        $uploads = \Breakdance\Forms\extractUploadedFiles();
        $this->validating = true;
        try {
            $valid = \Breakdance\Forms\validateFormData( $form, $uploads, (int) $form_id, (int) $post_id );
        } finally {
            $this->validating = false;
        }
        if ( is_wp_error( $valid ) ) {
            return $errors; // Breakdance refuses it itself.
        }
        $list = [];
        foreach ( $form as $item ) {
            $type = (string) ( $item['type'] ?? '' );
            if ( in_array( $type, [ 'step', 'html' ], true ) ) {
                continue;
            }
            $list[] = [
                'type'  => $type,
                'label' => (string) ( $item['label'] ?? '' ),
                'value' => $item['originalValue'] ?? $item['value'] ?? '',
                'key'   => (string) \Breakdance\Forms\getIdFromField( $item ),
            ];
        }
        $name    = (int) $post_id . ': ' . ( (string) ( $settings['form']['form_name'] ?? '' ) ?: get_the_title( (int) $post_id ) ) . ' [' . (int) $form_id . ']';
        $verdict = $this->evaluate( Submission::fromFields( $this->source(), $name, $list ) );
        if ( ! $verdict->act ) {
            return $errors;
        }
        self::stash( $verdict->log_id, (int) $post_id, (int) $form_id, $form, $posted, $uploads );
        $errors->add( 'breakwater', $this->message() );
        return $errors;
    }

    /**
     * The values as Breakdance received them, keyed by field ID, without passwords. Nothing is
     * kept for a submission with files: they are PHP temp files here and gone after the request.
     *
     * @param list<array<string, mixed>> $form
     * @param array<string, mixed>       $posted
     * @param array<string, mixed>       $uploads
     */
    private static function stash( int $log_id, int $post_id, int $form_id, array $form, array $posted, array $uploads ): void {
        foreach ( $uploads as $files ) {
            foreach ( (array) $files as $file ) {
                if ( UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
                    return;
                }
            }
        }
        foreach ( $form as $field ) {
            if ( 'password' === ( $field['type'] ?? '' ) ) {
                if ( '' !== (string) ( $field['value'] ?? '' ) ) {
                    return; // Only for a custom action that makes accounts: never replayed.
                }
                unset( $posted[ \Breakdance\Forms\getIdFromField( $field ) ] );
            }
        }
        $referer = wp_get_referer();
        Log::stash( $log_id, [
            'post_id' => $post_id,
            'form_id' => $form_id,
            'fields'  => $posted,
            'referer' => false !== $referer ? (string) $referer : '',
            'user_id' => get_current_user_id(),
        ] );
    }

    /**
     * What Breakdance reported, in a sentence; null when nothing kept it or sent it anywhere,
     * so Breakwater emails it instead.
     *
     * @param array<string, array<string, mixed>> $responses action slug => type, message, id, executed_at
     */
    private static function outcome( int $log_id, array $responses ): ?string {
        $saved  = 0;
        $ran    = [];
        $failed = [];
        foreach ( $responses as $slug => $response ) {
            $action = \Breakdance\Forms\Actions\getAction( (string) $slug );
            $name   = $action ? (string) $action::name() : (string) $slug;
            if ( 'success' !== ( $response['type'] ?? '' ) ) {
                $failed[] = $name;
            } elseif ( 'store_submission' === $slug ) {
                $saved = (int) ( $response['id'] ?? 0 );
            } elseif ( ! empty( $response['executed_at'] ) && ! in_array( $slug, self::BROWSER, true ) ) {
                $ran[] = $name; // Not counted: skipped by its conditions, or only shown in the browser.
            }
        }
        if ( $saved ) {
            Log::link( $log_id, (string) $saved );
        }
        if ( ! $saved && ! $ran ) {
            return null;
        }
        $last    = array_pop( $ran );
        $actions = null === $last ? '' : ( $ran ? implode( ', ', $ran ) . ' and ' . $last . ' actions' : $last . ' action' );
        $outcome = match ( true ) {
            $saved && '' !== $actions => 'Saved to Breakdance\'s form submissions, and the form\'s ' . $actions . ' ran.',
            (bool) $saved             => 'Saved to Breakdance\'s form submissions.',
            default                   => 'Sent through the Breakdance form\'s ' . $actions . '.',
        };
        return $failed ? $outcome . ' These actions failed: ' . implode( ', ', $failed ) . '.' : $outcome;
    }

    /**
     * A copy of the action with an empty context log: Breakdance keeps one instance of each
     * action per request and each run appends to it.
     */
    private static function fresh( object $action ): object {
        $copy = clone $action;
        if ( property_exists( \Breakdance\Forms\Actions\Action::class, 'context' ) ) {
            \Closure::bind( function (): void { $this->context = []; }, $copy, \Breakdance\Forms\Actions\Action::class )(); // phpcs:ignore
        }
        return $copy;
    }

    /** Breakdance 2.x form functions this adapter calls; older versions get no check. */
    private static function available(): bool {
        foreach ( [ 'getFormSettings', 'getFormData', 'validateFormData', 'extractUploadedFiles', 'getIdFromField', 'getFormActions', 'executeActions', 'handleSubmissionCleanup', 'Actions\getAction' ] as $function ) {
            if ( ! function_exists( '\Breakdance\Forms\\' . $function ) ) {
                return false;
            }
        }
        return class_exists( '\Breakdance\Forms\Actions\Action' );
    }
}
