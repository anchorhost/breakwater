<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Divi contact form module (Divi 4 shortcode and Divi 5 block). Divi has no hook that can
 * refuse a submission, so for spam we switch on the module's own spam protection and drop
 * its token: Divi then refuses with its "You must be a human" error and sends nothing.
 */
final class Divi extends Adapter {

    private ?bool $spam = null;

    public function source(): string { return 'divi'; }
    public function label(): string { return 'Divi contact form'; }
    public function detected(): bool { return defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_CORE_VERSION' ); }

    public function register(): void {
        add_filter( 'et_pb_module_shortcode_attributes', $this->divi4( ... ), 99, 3 );
        add_filter( 'divi_module_library_register_module_attrs', $this->divi5( ... ), 99, 2 );
    }

    /** @param array<string, mixed> $props */
    private function divi4( mixed $props, mixed $attrs = [], mixed $slug = '' ): mixed {
        if ( 'et_pb_contact_form' === $slug && is_array( $props ) && $this->spam() ) {
            $props['use_spam_service'] = 'on';
            $this->dropToken();
        }
        return $props;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $args name is the block name.
     */
    private function divi5( mixed $attrs, mixed $args = [] ): mixed {
        if ( 'divi/contact-form' === ( $args['name'] ?? '' ) && is_array( $attrs ) && $this->spam() ) {
            $attrs['module']['advanced']['spamProtection']['desktop']['value']['enabled'] = 'on';
            $this->dropToken();
        }
        return $attrs;
    }

    private function dropToken(): void {
        unset( $_POST['token'], $_REQUEST['token'] );
    }

    /** Decided once per request, on the first contact form rendered while one is being submitted. */
    private function spam(): bool {
        if ( null !== $this->spam ) {
            return $this->spam;
        }
        $this->spam = false;
        $post       = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
        $submitted  = preg_grep( '/^(et_pb_contactform_submit_\d+|_wpnonce-et-pb-contact-form-submitted-.+)$/', array_keys( $post ) );
        if ( ! $submitted ) {
            return false;
        }
        $this->spam = $this->evaluate( $this->submission( $post ) )->act;
        return $this->spam;
    }

    /**
     * Divi 4 posts a JSON list of its fields (id, label, type); Divi 5 posts only the values.
     *
     * @param array<string, mixed> $post
     */
    private function submission( array $post ): Submission {
        $list = [];
        foreach ( $post as $key => $value ) {
            // Suffixed with the form number, or with the module's unique ID on some builds.
            if ( str_starts_with( (string) $key, 'et_pb_contact_email_fields_' ) && is_string( $value ) ) {
                $list = array_merge( $list, (array) json_decode( $value, true ) );
            }
        }
        $form = (string) ( $post['et_pb_contact_form_unique_id'] ?? 'contact form' );
        if ( ! $list ) {
            return $this->withTrap( Submission::fromRequest( $this->source(), $form, $post ), $post );
        }
        $fields = [];
        foreach ( $list as $field ) {
            $id       = (string) ( $field['field_id'] ?? '' );
            $type     = 'name' === ( $field['original_id'] ?? '' ) ? 'name' : (string) ( $field['field_type'] ?? 'text' );
            $fields[] = [ 'type' => 'input' === $type ? 'text' : $type, 'label' => (string) ( $field['field_label'] ?? $id ), 'value' => $post[ $id ] ?? '', 'key' => $id ];
        }
        return $this->withTrap( Submission::fromFields( $this->source(), $form, $fields ), $post );
    }

    /**
     * Divi's own honeypot (et_pb_contact_et_number_N) counts as ours when filled.
     *
     * @param array<string, mixed> $post
     */
    private function withTrap( Submission $s, array $post ): Submission {
        foreach ( $post as $key => $value ) {
            if ( '' === $s->trap && preg_match( '/^et_pb_contact_et_number_\d+$/', (string) $key ) && is_string( $value ) && '' !== $value ) {
                $s->trap = $value;
            }
        }
        return $s;
    }
}
