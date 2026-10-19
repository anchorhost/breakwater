<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * MailPoet subscription forms. Spam is refused before the subscriber exists, so no
 * confirmation email goes out.
 */
final class MailPoet extends Adapter {

    public function source(): string { return 'mailpoet'; }
    public function label(): string { return 'MailPoet'; }
    public function detected(): bool { return defined( 'MAILPOET_VERSION' ); }

    public function register(): void {
        add_action( 'mailpoet_subscription_before_subscribe', $this->check( ... ), 99, 3 );
    }

    /**
     * @param array<string, mixed> $data       The form's own fields: email, first_name, last_name, cf_N.
     * @param \MailPoet\Entities\FormEntity $form
     */
    private function check( mixed $data, mixed $segment_ids = [], mixed $form = null ): void {
        if ( ! is_array( $data ) ) {
            return;
        }
        $types  = [ 'email' => 'email', 'first_name' => 'first_name', 'last_name' => 'last_name' ];
        $fields = [];
        foreach ( $data as $key => $value ) {
            if ( isset( $types[ $key ] ) || str_starts_with( (string) $key, 'cf_' ) ) {
                $fields[] = [ 'type' => $types[ $key ] ?? 'text', 'label' => (string) $key, 'value' => $value ];
            }
        }
        $name = is_object( $form ) && method_exists( $form, 'getId' ) ? $form->getId() . ': ' . $form->getName() : '';
        if ( $this->evaluate( Submission::fromFields( $this->source(), $name, $fields ) )->act && class_exists( '\MailPoet\UnexpectedValueException' ) ) {
            // MailPoet's documented way to refuse: its API turns this into an error in the form.
            throw new \MailPoet\UnexpectedValueException( $this->message() );
        }
    }
}
