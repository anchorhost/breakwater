<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Guard;
use Breakwater\Log;
use Breakwater\Release;
use Breakwater\Submission;

/**
 * Forminator forms. Uses Forminator's own spam filter. Spam we stop is saved as a spam entry
 * (Forminator's "mark as spam" behaviour, which skips its emails and integrations and never
 * lists the entry), so "Not spam" can restore it to the submissions and run what a normal
 * submission runs. Forms that don't store submissions, take payments, or log people in or
 * register them are refused with the form's spam message instead, as Forminator does.
 */
final class Forminator extends Adapter {

    private const ACTION = 'Forminator_CForm_Front_Action';

    /** Log row of the submission we just held as a spam entry in this request, until it is saved. */
    private int $held = 0;

    public function source(): string { return 'forminator'; }
    public function label(): string { return 'Forminator'; }
    public function detected(): bool { return defined( 'FORMINATOR_VERSION' ); }
    public function keepsSpam(): bool { return self::canRelease(); }

    public function register(): void {
        add_filter( 'forminator_spam_protection', $this->check( ... ), 20, 4 );
        add_action( 'forminator_custom_form_submit_before_set_fields', $this->link( ... ), 10, 1 );
    }

    /**
     * Out of spam, then what Forminator runs after saving a normal submission: integrations,
     * post creation, its notification emails. The same path Forminator takes when a pending
     * Stripe entry completes, with the entry's data rebuilt from what was saved.
     */
    public function release( object $row ): ?string {
        global $wpdb;
        $entry_id = (int) $row->ref;
        if ( ! $entry_id || ! class_exists( 'Forminator_Form_Entry_Model' ) || ! class_exists( 'Forminator_Base_Form_Model' ) || ! class_exists( 'Forminator_Database_Tables' ) ) {
            return null;
        }
        $entry = new \Forminator_Form_Entry_Model( $entry_id );
        $model = ! empty( $entry->entry_id ) && ! empty( $entry->form_id ) ? \Forminator_Base_Form_Model::get_model( (int) $entry->form_id ) : null;
        if ( ! $model ) {
            return null; // Entry or form deleted.
        }
        $table   = \Forminator_Database_Tables::get_table_name( \Forminator_Database_Tables::FORM_ENTRY );
        $claimed = 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_spam = 0 WHERE entry_id = %d AND status = 'spam'", $entry_id ) ); // phpcs:ignore WordPress.DB
        if ( ! $claimed && 'active' !== $entry->status ) {
            return null;
        }
        $entry->is_spam = 0;
        if ( $claimed ) {
            $entry->update_status( 'active' ); // Clears Forminator's caches and assigns its custom submission ID.
        }

        if ( ! self::canRelease() ) {
            return $this->emailOnly( $entry, $model )
                ? 'Restored to the Forminator submissions, and its notification emails were sent. Its integrations did not run: this Forminator version can\'t run them later.'
                : 'Restored to the Forminator submissions. Its emails and integrations did not run: this Forminator version can\'t run them later.';
        }
        $action = ( self::ACTION )::get_instance();
        $state  = self::resetState();
        try {
            if ( ! ( new \ReflectionMethod( $action, 'init_properties_to_process_webhooks' ) )->invoke( $action, $entry ) ) {
                return 'Restored to the Forminator submissions. Its emails and integrations did not run: Forminator could not load the form.';
            }
            if ( method_exists( $action, 'maybe_update_hidden_fields' ) ) {
                ( new \ReflectionMethod( $action, 'maybe_update_hidden_fields' ) )->invoke( $action, $entry );
            }
            ( new \ReflectionMethod( $action, 'post_submission_process' ) )->invoke( $action, $entry );
        } catch ( \Throwable $e ) {
            error_log( sprintf( 'Breakwater: Forminator entry %d was restored, but running its actions failed: %s', $entry_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
            return 'Restored to the Forminator submissions, but its emails and integrations stopped with an error: ' . wp_strip_all_tags( $e->getMessage() );
        } finally {
            $state();
        }
        return 'Restored to the Forminator submissions, and its emails and integrations ran.';
    }

    /** The form's admin notification recipients, routed ones included. */
    public function recipients( string $form ): array {
        $model = class_exists( 'Forminator_Base_Form_Model' ) ? \Forminator_Base_Form_Model::get_model( self::formId( $form ) ) : null;
        $to    = [];
        foreach ( (array) ( $model->notifications ?? [] ) as $notification ) {
            if ( ! is_array( $notification ) ) {
                continue;
            }
            $to = array_merge( $to, self::emails( (string) ( $notification['recipients'] ?? '' ) ) );
            foreach ( (array) ( $notification['routing'] ?? [] ) as $route ) {
                $to = array_merge( $to, self::emails( is_array( $route ) ? (string) ( $route['email'] ?? '' ) : '' ) );
            }
        }
        return array_values( array_unique( $to ) );
    }

    /** Forminator internals a release needs; a version without them gets refused spam, as before. */
    private static function canRelease(): bool {
        return class_exists( self::ACTION ) && method_exists( self::ACTION, 'get_instance' )
            && method_exists( self::ACTION, 'init_properties_to_process_webhooks' ) && method_exists( self::ACTION, 'post_submission_process' )
            && class_exists( 'Forminator_Front_Action' ) && property_exists( 'Forminator_Front_Action', 'module_settings' );
    }

    /**
     * Forminator keeps the submission being processed in static properties. Start a replay from
     * a clean slate, and put back the webhook flag the replay sets.
     *
     * @return callable(): void Restores what the replay changed.
     */
    private static function resetState(): callable {
        $set = static function ( string $class, string $name, mixed $value ): void {
            if ( property_exists( $class, $name ) ) {
                ( new \ReflectionProperty( $class, $name ) )->setValue( null, $value );
            }
        };
        foreach ( [ 'is_draft', 'is_spam', 'is_abandoned', 'is_leads' ] as $name ) {
            $set( 'Forminator_Front_Action', $name, false );
        }
        $set( 'Forminator_Front_Action', 'info', [ 'stripe_field' => [], 'paypal_field' => [], 'captcha_settings' => [], 'field_data_array' => [], 'select_field_value' => [], 'upload_in_customfield' => [] ] );
        $set( self::ACTION, 'is_fields_visibility_checked', false );
        $set( self::ACTION, 'hidden_fields', [] );
        return static fn () => $set( self::ACTION, 'is_processing_webhook', false );
    }

    /** Forminator's own "Resend notification email", for versions without the replay path. */
    private function emailOnly( object $entry, object $model ): bool {
        if ( ! class_exists( 'Forminator_CForm_Front_Mail' ) || ! function_exists( 'recreate_prepared_data' ) || ! class_exists( 'Forminator_Front_Action' ) ) {
            return false;
        }
        \Forminator_Front_Action::$module_id       = (int) $entry->form_id;
        \Forminator_Front_Action::$module_object   = $model;
        \Forminator_Front_Action::$prepared_data   = recreate_prepared_data( $model, $entry );
        \Forminator_Front_Action::$module_settings = method_exists( $model, 'get_form_settings' ) ? $model->get_form_settings() : $model->settings;
        if ( method_exists( self::ACTION, 'check_fields_visibility' ) ) {
            ( self::ACTION )::check_fields_visibility();
        }
        ( new \Forminator_CForm_Front_Mail() )->process_mail( $model, $entry );
        return true;
    }

    /** Remember which log row the saved spam entry came from. */
    private function link( mixed $entry ): void {
        if ( $this->held && is_object( $entry ) && ! empty( $entry->entry_id ) && 'spam' === ( $entry->status ?? '' ) && ! Release::running() ) {
            Log::link( $this->held, (string) (int) $entry->entry_id );
        }
        $this->held = 0;
    }

    /**
     * Whether a held submission to this form can be stored as a spam entry and handed back
     * later: it stores submissions, takes no payment (a spam entry still goes to Stripe or
     * PayPal) and doesn't log anyone in or register them.
     */
    private static function storable( mixed $model ): bool {
        if ( ! is_object( $model ) || ! self::canRelease() || ! is_array( \Forminator_Front_Action::$module_settings ) ) {
            return false;
        }
        $type = (string) ( $model->settings['form-type'] ?? 'default' );
        return ! in_array( $type, [ 'login', 'registration' ], true )
            && ! ( method_exists( $model, 'is_prevent_store' ) && $model->is_prevent_store() )
            && ! ( method_exists( $model, 'has_stripe_or_paypal' ) && $model->has_stripe_or_paypal() );
    }

    /**
     * @param list<array<string, mixed>> $rows name, value, field_type, field_array per field.
     */
    private function check( mixed $is_spam, mixed $rows = [], mixed $form_id = 0, mixed $form_type = 'form' ): mixed {
        if ( $is_spam || 'form' !== $form_type || ! is_array( $rows ) ) {
            return $is_spam;
        }
        if ( class_exists( 'Forminator_Front_Action' ) && 'login' === ( \Forminator_Front_Action::$module_settings['form-type'] ?? '' ) ) {
            return $is_spam; // A member logging in is never held.
        }
        // phpcs:ignore WordPress.Security.NonceVerification
        if ( filter_var( $_POST['save_draft'] ?? false, FILTER_VALIDATE_BOOLEAN ) || ! empty( $_POST['abandoned_form'] ) ) {
            return $is_spam; // Draft saves and abandonment pings are not submissions.
        }
        $fields = [];
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && ! empty( $row['field_type'] ) ) {
                $fields[] = [ 'type' => (string) $row['field_type'], 'label' => (string) ( $row['field_array']['field_label'] ?? $row['name'] ?? '' ), 'value' => $row['value'] ?? '', 'key' => (string) ( $row['name'] ?? '' ) ];
            }
        }
        $model    = class_exists( 'Forminator_Base_Form_Model' ) ? \Forminator_Base_Form_Model::get_model( (int) $form_id ) : null;
        $title    = is_object( $model ) ? (string) ( $model->settings['formName'] ?? '' ) : '';
        $s        = Submission::fromFields( $this->source(), (int) $form_id . ': ' . $title, $fields );
        $s->other = false;
        $verdict  = $this->evaluate( $s );
        if ( ! $verdict->act ) {
            return $is_spam;
        }
        if ( self::storable( $model ) ) {
            // Forminator then saves a spam entry (emails and integrations skipped) and shows its usual success.
            \Forminator_Front_Action::$module_settings['akismet-protection-behavior'] = 'mark_spam';
            $this->held = $verdict->log_id ?: Guard::lastLogId();
        }
        return true;
    }
}
