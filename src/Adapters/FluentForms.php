<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Fluent Forms. Checked with Fluent's validation, before the entry is saved; spam is
 * refused with a form error the way Fluent's own Akismet check does.
 */
final class FluentForms extends Adapter {

    public function source(): string { return 'fluentform'; }
    public function label(): string { return 'Fluent Forms'; }
    public function detected(): bool { return defined( 'FLUENTFORM_VERSION' ); }

    public function register(): void {
        add_filter( 'fluentform/validation_errors', $this->check( ... ), 99, 4 );
    }

    /**
     * @param array<string, mixed> $errors
     * @param array<string, mixed> $data   The submitted values, nested like the input names.
     * @param \FluentForm\App\Models\Form $form
     * @param array<string, array<string, mixed>> $fields keyed by input name: element, raw.
     */
    private function check( mixed $errors, mixed $data = [], mixed $form = null, mixed $fields = [] ): mixed {
        if ( ! is_array( $errors ) || $errors || ! is_array( $data ) || ! is_array( $fields ) ) {
            return $errors;
        }
        $list = [];
        foreach ( $fields as $name => $field ) {
            $list[] = [
                'type'  => (string) ( $field['element'] ?? '' ),
                'label' => (string) ( $field['raw']['settings']['label'] ?? $name ),
                'value' => self::path( $data, (string) $name ),
                'key'   => (string) $name,
            ];
        }
        $name = is_object( $form ) ? ( $form->id ?? '' ) . ': ' . ( $form->title ?? '' ) : '';
        if ( $this->evaluate( Submission::fromFields( $this->source(), (string) $name, $list ) )->act ) {
            $errors['_breakwater'] = [ $this->message() ];
        }
        return $errors;
    }

    /** "names[first_name]" reads $data['names']['first_name']. */
    private static function path( array $data, string $name ): mixed {
        foreach ( explode( '.', str_replace( [ '[', ']' ], [ '.', '' ], $name ) ) as $part ) {
            if ( ! is_array( $data ) || ! array_key_exists( $part, $data ) ) {
                return '';
            }
            $data = $data[ $part ];
        }
        return $data;
    }
}
