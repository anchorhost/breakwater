<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Submission;
use ElementorPro\Modules\Forms\Classes\Ajax_Handler;
use ElementorPro\Modules\Forms\Classes\Form_Record;
use ElementorPro\Modules\Forms\Module;

/**
 * Elementor Pro forms. Elementor has no spam folder, so spam is refused with a message and
 * what a replay needs is kept in the Breakwater log. Released, the submission goes through
 * the form's actions (Collect Submissions, Email, webhooks, mailing lists...) the way
 * Elementor's ajax handler runs them.
 */
final class Elementor extends Adapter {

    /** Field types Elementor's own validators take out of the record before the actions run. */
    private const STRIP = [ 'recaptcha', 'recaptcha_v3', 'honeypot', 'hcaptcha', 'turnstile' ];

    public function source(): string { return 'elementor'; }
    public function label(): string { return 'Elementor Pro forms'; }
    public function detected(): bool { return defined( 'ELEMENTOR_PRO_VERSION' ); }

    public function register(): void {
        add_action( 'elementor_pro/forms/validation', $this->check( ... ), 99, 2 );
    }

    /**
     * Rebuild the form as Elementor's ajax handler does, then run what follows its validation:
     * field processing, the submit actions, the activity log and the new_record hook. The
     * form's settings are read fresh, so its current actions run.
     */
    public function release( object $row ): ?string {
        $data = Log::replay( $row );
        if ( empty( $data['form_id'] ) || ! is_array( $data['sent'] ?? null ) || ! self::available() ) {
            return null;
        }
        $elementor = \ElementorPro\Plugin::elementor();
        $post      = $_POST; // phpcs:ignore WordPress.Security.NonceVerification
        // Collect Submissions, Slack and Discord read the page from the request.
        $_POST = array_merge( $_POST, wp_slash( [ // phpcs:ignore WordPress.Security.NonceVerification
            'post_id'       => (string) (int) $data['post_id'],
            'form_id'       => (string) $data['form_id'],
            'queried_id'    => (string) (int) $data['queried_id'],
            'referrer'      => (string) ( $data['referrer'] ?? '' ),
            'referer_title' => (string) ( $data['referer_title'] ?? '' ),
        ] ) );
        $elementor->db->switch_to_post( (int) $data['queried_id'] );
        try {
            $form    = self::form( (int) $data['post_id'], (string) $data['form_id'] );
            $handler = $form ? self::handler( $form ) : null;
            if ( ! $form || ! $handler ) {
                return null;
            }
            $record = new Form_Record( wp_slash( $data['sent'] ), $form );
            foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
                if ( in_array( $field['type'] ?? '', self::STRIP, true ) ) {
                    $record->remove_field( $id );
                }
            }
            if ( is_array( $data['meta'] ?? null ) ) {
                $record->set( 'meta', $data['meta'] ); // The original date, time, page and visitor.
            }
            $record->process_fields( $handler );
            if ( ! empty( $handler->errors ) ) {
                return null;
            }
            [ $ran, $failed, $submission ] = self::run( $record, $handler, $form );
        } finally {
            $_POST = $post;
            $elementor->db->restore_current_post();
        }
        if ( $submission ) {
            Log::link( (int) $row->id, (string) $submission );
        }
        $saved  = in_array( 'save-to-database', $ran, true );
        $others = array_diff( $ran, [ 'save-to-database' ] );
        if ( ! $saved && ! $others ) {
            return null; // Nothing kept it or sent it anywhere: Breakwater emails it instead.
        }
        $outcome = match ( true ) {
            $saved && (bool) $others => 'Saved to Elementor\'s submissions, and the form\'s actions ran.',
            $saved                   => 'Saved to Elementor\'s submissions.',
            default                  => 'Sent through the Elementor form\'s actions.',
        };
        return $failed ? $outcome . ' These actions failed: ' . implode( ', ', $failed ) . '.' : $outcome;
    }

    public function recipients( string $form ): array {
        global $wpdb;
        $element = sanitize_key( (string) strtok( $form, ':' ) );
        if ( '' === $element || ! self::available() ) {
            return [];
        }
        $posts = $wpdb->get_col( $wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_type != 'revision' AND m.meta_value LIKE %s LIMIT 5",
            '%' . $wpdb->esc_like( '"id":"' . $element . '"' ) . '%'
        ) );
        foreach ( $posts as $post_id ) {
            $found = self::form( (int) $post_id, $element );
            if ( $found ) {
                $settings = $found['settings'];
                return in_array( 'email', (array) ( $settings['submit_actions'] ?? [] ), true ) ? self::emails( (string) ( $settings['email_to'] ?? '' ) ) : [];
            }
        }
        return [];
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
            fn ( mixed $field ): array => is_array( $field ) ? [ 'type' => $field['type'] ?? '', 'label' => $field['title'] ?? '', 'value' => $field['raw_value'] ?? $field['value'] ?? '', 'key' => $field['id'] ?? '' ] : [],
            array_values( (array) $record->get( 'fields' ) )
        );
        $verdict = $this->evaluate( Submission::fromFields( $this->source(), trim( $id . ': ' . $name, ': ' ), $fields ) );
        if ( ! $verdict->act ) {
            return;
        }
        self::stash( $verdict->log_id, $record, $id );
        $ajax_handler->add_error_message( $this->message() );
        $ajax_handler->add_error( 'breakwater', '' );
    }

    /**
     * What the ajax handler needs to rebuild the form and its record. Nothing is kept for a
     * form with files attached: they are still PHP temp files here and are gone after the
     * request, so it can't be replayed.
     */
    private static function stash( int $log_id, object $record, string $form_id ): void {
        $sent = (array) $record->get( 'sent_data' );
        foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
            $type = (string) ( $field['type'] ?? '' );
            if ( 'upload' === $type && '' !== implode( '', array_map( 'strval', (array) ( $_FILES['form_fields']['name'][ $id ] ?? [] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                return;
            }
            if ( 'password' === $type || in_array( $type, self::STRIP, true ) ) {
                unset( $sent[ $id ] );
            }
        }
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        Log::stash( $log_id, [
            'post_id'       => $post_id,
            'queried_id'    => isset( $_POST['queried_id'] ) ? absint( $_POST['queried_id'] ) : $post_id, // phpcs:ignore WordPress.Security.NonceVerification
            'form_id'       => $form_id,
            'sent'          => $sent,
            'meta'          => (array) $record->get( 'meta' ),
            'referrer'      => isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( (string) $_POST['referrer'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
            'referer_title' => isset( $_POST['referer_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['referer_title'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification
        ] );
    }

    private static function available(): bool {
        return class_exists( Module::class ) && class_exists( Form_Record::class ) && class_exists( Ajax_Handler::class ) && class_exists( '\ElementorPro\Plugin' )
            && method_exists( Module::class, 'find_element_recursive' );
    }

    /**
     * The form widget with its settings, the way Ajax_Handler::ajax_send_form() builds it:
     * found in the page (or its global widget template), defaults restored.
     *
     * @return array<string, mixed>|null
     */
    private static function form( int $post_id, string $form_id ): ?array {
        $elementor = \ElementorPro\Plugin::elementor();
        $document  = $post_id ? $elementor->documents->get( $post_id ) : null;
        $form      = $document ? Module::find_element_recursive( $document->get_elements_data(), $form_id ) : null;
        $template  = null;
        if ( ! empty( $form['templateID'] ) ) {
            $template = $elementor->documents->get( $form['templateID'] );
            $form     = $template ? ( $template->get_elements_data()[0] ?? null ) : null;
        }
        $widget = is_array( $form ) && $form ? $elementor->elements_manager->create_element_instance( $form ) : null;
        if ( ! $widget ) {
            return null;
        }
        $form['settings']                 = $widget->get_settings_for_display();
        $form['settings']['id']           = $form_id;
        $form['settings']['form_post_id'] = $template ? $template->get_id() : (string) $post_id; // Strings, as posted.
        $form['settings']['edit_post_id'] = (string) $post_id;
        return empty( $form['settings']['form_fields'] ) ? null : $form;
    }

    /**
     * An ajax handler the actions can report to. Elementor's own registers the ajax hooks in its
     * constructor, keeps the form private and answers with JSON and exit in send(), so this one
     * skips the first and quietly overrides the other two.
     *
     * @param array<string, mixed> $form
     */
    private static function handler( array $form ): ?Ajax_Handler {
        foreach ( [ 'get_current_form', 'send' ] as $method ) {
            if ( ! method_exists( Ajax_Handler::class, $method ) || ( new \ReflectionMethod( Ajax_Handler::class, $method ) )->hasReturnType() ) {
                return null; // A version this override doesn't match.
            }
        }
        return new class( $form ) extends Ajax_Handler {
            /** @param array<string, mixed> $form */
            public function __construct( private array $form ) {} // phpcs:ignore -- Not the parent's: it adds the ajax hooks.

            public function get_current_form() {
                return $this->form;
            }

            public function send() {}
        };
    }

    /**
     * Ajax_Handler::ajax_send_form() from its actions_before filter to its new_record action.
     *
     * @param array<string, mixed> $form
     * @return array{0:list<string>, 1:list<string>, 2:int} actions that ran, labels that failed, the submission saved
     */
    private static function run( Form_Record $record, Ajax_Handler $handler, array $form ): array {
        $module = Module::instance();
        if ( ! isset( $module->actions_registrar ) || ! method_exists( $module->actions_registrar, 'get' ) ) {
            return [ [], [], 0 ]; // Before Elementor Pro 3.5.
        }
        $actions = $module->actions_registrar->get();
        $errors  = array_merge( $handler->messages['error'], $handler->messages['admin_error'] );
        $record  = apply_filters( 'elementor_pro/forms/record/actions_before', $record, $handler );
        $submit  = (array) apply_filters( 'elementor_pro/forms/submit_actions', (array) ( $form['settings']['submit_actions'] ?? [] ), $record, $handler );
        $ran     = [];
        $failed  = [];
        $saved   = 0;
        foreach ( $actions as $action ) {
            if ( ! in_array( $action->get_name(), $submit, true ) ) {
                continue;
            }
            $exception = null;
            try {
                $action->run( $record, $handler );
                $new = array_diff( array_merge( $handler->messages['error'], $handler->messages['admin_error'] ), $errors );
                if ( $new ) {
                    throw new \Exception( implode( ', ', $new ) ); // As Elementor's handle_bc_errors() does.
                }
                $ran[] = $action->get_name();
            } catch ( \Exception $e ) {
                $exception = $e;
                if ( ! in_array( $e->getMessage(), $handler->messages['admin_error'], true ) ) {
                    $handler->add_admin_error_message( "{$action->get_label()} {$e->getMessage()}" );
                }
                $handler->add_error_message( Ajax_Handler::get_default_message( Ajax_Handler::ERROR, $form['settings'] ) );
                $failed[] = (string) $action->get_label();
            }
            $errors = array_merge( $handler->messages['error'], $handler->messages['admin_error'] );
            do_action( 'elementor_pro/forms/actions/after_run', $action, $exception );
            if ( 'save-to-database' === $action->get_name() && property_exists( $action, 'submission_id' ) ) {
                $saved = (int) ( new \ReflectionProperty( $action, 'submission_id' ) )->getValue( $action ); // Private; for linking only.
            }
        }
        foreach ( [ 'activity_log', 'cf7db' ] as $component ) {
            $component = $module->get_component( $component );
            if ( $component ) {
                $component->run( $record, $handler );
            }
        }
        do_action( 'elementor_pro/forms/new_record', $record, $handler );
        return [ $ran, $failed, $saved ];
    }
}
