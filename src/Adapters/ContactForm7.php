<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Contact Form 7. Marked as spam the CF7 way, so Flamingo (when active) keeps it in its Spam folder.
 */
final class ContactForm7 extends Adapter {

    public function source(): string { return 'contact-form-7'; }
    public function label(): string { return 'Contact Form 7'; }
    public function detected(): bool { return defined( 'WPCF7_VERSION' ); }
    public function keepsSpam(): bool { return class_exists( 'Flamingo_Inbound_Message' ); }

    public function register(): void {
        add_filter( 'wpcf7_spam', $this->check( ... ), 99, 2 );
    }

    public function recipients( string $form ): array {
        $cf7 = class_exists( 'WPCF7_ContactForm' ) ? \WPCF7_ContactForm::get_instance( self::formId( $form ) ) : null;
        return $cf7 ? self::emails( (string) ( $cf7->prop( 'mail' )['recipient'] ?? '' ) ) : [];
    }

    private function check( mixed $spam, mixed $submission = null ): mixed {
        $contact_form = $submission instanceof \WPCF7_Submission ? $submission->get_contact_form() : null;
        if ( ! $contact_form ) {
            return (bool) $spam;
        }
        $posted = (array) $submission->get_posted_data();
        $fields = [];
        foreach ( $contact_form->scan_form_tags() as $tag ) {
            if ( '' !== $tag->name && isset( $posted[ $tag->name ] ) ) {
                $fields[] = [ 'type' => $tag->basetype, 'label' => $tag->name, 'value' => $posted[ $tag->name ] ];
            }
        }
        $s        = Submission::fromFields( $this->source(), $contact_form->id() . ': ' . $contact_form->title(), $fields );
        $s->other = (bool) $spam;

        $verdict = $this->evaluate( $s );
        if ( ! $verdict->act ) {
            return (bool) $spam;
        }
        $submission->add_spam_log( [ 'agent' => 'breakwater', 'reason' => $verdict->reason() ] );
        return true;
    }
}
