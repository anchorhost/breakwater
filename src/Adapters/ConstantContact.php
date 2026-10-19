<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Constant Contact Forms. Uses the plugin's own spam verdict filter, so spam gets its
 * spam message and never reaches the Constant Contact API or the admin email.
 */
final class ConstantContact extends Adapter {

    private const TYPES = [ 'email' => 'email', 'first_name' => 'first_name', 'last_name' => 'last_name', 'website' => 'url', 'custom_text_area' => 'textarea' ];

    public function source(): string { return 'constant-contact'; }
    public function label(): string { return 'Constant Contact Forms'; }
    public function detected(): bool { return class_exists( 'Constant_Contact' ); }

    public function register(): void {
        add_filter( 'constant_contact_maybe_spam', $this->check( ... ), 20, 2 );
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
        return $this->evaluate( $s )->act ? true : $is_spam;
    }
}
