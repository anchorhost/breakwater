<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Jetpack Forms (contact form block). Spam is saved as a spam feedback entry, its email is
 * skipped, and Jetpack's Feedback screen restores it.
 */
final class Jetpack extends Adapter {

    /** The form being submitted, handed to us just before the spam check. */
    private ?object $form = null;

    public function source(): string { return 'jetpack'; }
    public function label(): string { return 'Jetpack Forms'; }
    public function detected(): bool { return class_exists( 'Automattic\Jetpack\Forms\ContactForm\Contact_Form' ) || class_exists( 'Grunion_Contact_Form' ); }
    public function keepsSpam(): bool { return true; }

    public function register(): void {
        add_filter( 'jetpack_forms_extra_webhooks', $this->remember( ... ), 1, 2 );
        add_filter( 'jetpack_contact_form_is_spam', $this->check( ... ), 20, 2 );
    }

    private function remember( mixed $webhooks, mixed $form = null ): mixed {
        $this->form = is_object( $form ) ? $form : null;
        return $webhooks;
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
        return $this->evaluate( $s )->act ? true : $is_spam;
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
