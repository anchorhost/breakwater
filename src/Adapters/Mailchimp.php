<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Mailchimp for WordPress (MC4WP) sign-up forms. Spam gets MC4WP's own "marked as spam"
 * error and never reaches Mailchimp.
 */
final class Mailchimp extends Adapter {

    private const TYPES = [ 'EMAIL' => 'email', 'FNAME' => 'first_name', 'LNAME' => 'last_name', 'NAME' => 'name', 'WEBSITE' => 'url' ];
    private const SKIP  = [ 'AGREE_TO_TERMS', 'INTERESTS', 'GROUPINGS', 'MC_LANGUAGE' ];

    public function source(): string { return 'mc4wp'; }
    public function label(): string { return 'Mailchimp for WP'; }
    public function detected(): bool { return defined( 'MC4WP_VERSION' ); }

    public function register(): void {
        add_filter( 'mc4wp_form_errors', $this->check( ... ), 99, 2 );
    }

    /**
     * @param list<string> $errors
     * @param \MC4WP_Form  $form
     */
    private function check( mixed $errors, mixed $form = null ): mixed {
        if ( ! is_array( $errors ) || $errors || ! is_object( $form ) || ! method_exists( $form, 'get_data' ) ) {
            return $errors; // Already refused (honeypot, timestamp, validation).
        }
        $fields = [];
        foreach ( (array) $form->get_data() as $key => $value ) {
            if ( ! in_array( $key, self::SKIP, true ) ) {
                $fields[] = [ 'type' => self::TYPES[ $key ] ?? 'text', 'label' => (string) $key, 'value' => $value ];
            }
        }
        if ( $this->evaluate( Submission::fromFields( $this->source(), $form->ID . ': ' . $form->name, $fields ) )->act ) {
            $errors[] = 'spam'; // MC4WP's built-in "Your submission was marked as spam."
        }
        return $errors;
    }
}
