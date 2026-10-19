<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;

/**
 * Kadence Blocks forms: the Advanced Form block through its own reject filter, and the basic
 * Form block just before Kadence handles its AJAX request. Spam gets Kadence's error notice.
 */
final class Kadence extends Adapter {

    /** Whether this request's Advanced Form submission was refused by us, for the message filter. */
    private bool $rejected = false;

    public function source(): string { return 'kadence'; }
    public function label(): string { return 'Kadence Blocks forms'; }
    public function detected(): bool { return defined( 'KADENCE_BLOCKS_VERSION' ); }

    public function register(): void {
        add_filter( 'kadence_blocks_advanced_form_submission_reject', $this->checkAdvanced( ... ), 99, 4 );
        add_filter( 'kadence_blocks_advanced_form_submission_reject_message', $this->rejectMessage( ... ), 99 );
        add_action( 'wp_ajax_kb_process_ajax_submit', $this->checkBasic( ... ), 5 );
        add_action( 'wp_ajax_nopriv_kb_process_ajax_submit', $this->checkBasic( ... ), 5 );
    }

    /**
     * @param array<string, mixed>       $form_args
     * @param list<array<string, mixed>> $fields label, type, value, name per field.
     */
    private function checkAdvanced( mixed $reject, mixed $form_args = [], mixed $fields = [], mixed $post_id = 0 ): mixed {
        if ( $reject || ! is_array( $fields ) ) {
            return $reject;
        }
        $fields = array_map(
            fn ( $field ): array => [ 'type' => $field['type'] ?? '', 'label' => $field['label'] ?? '', 'value' => $field['value'] ?? '', 'key' => $field['name'] ?? '' ],
            array_filter( $fields, 'is_array' )
        );
        $s = Submission::fromFields( $this->source(), 'advanced ' . (int) $post_id . ': ' . get_the_title( (int) $post_id ), array_values( $fields ) );
        $this->rejected = $this->evaluate( $s )->act;
        return $this->rejected ? true : $reject;
    }

    private function rejectMessage( mixed $message ): mixed {
        return $this->rejected ? $this->message() : $message;
    }

    /** The basic Form block posts kb_field_N; the block's attributes say what each N is. */
    private function checkBasic(): void {
        // phpcs:disable WordPress.Security.NonceVerification
        $post_id = sanitize_text_field( wp_unslash( (string) ( $_POST['_kb_form_post_id'] ?? '' ) ) );
        $form_id = sanitize_text_field( wp_unslash( (string) ( $_POST['_kb_form_id'] ?? '' ) ) );
        $defs    = $this->blockFields( $post_id, $form_id );
        $fields  = [];
        foreach ( wp_unslash( $_POST ) as $key => $value ) {
            if ( preg_match( '/^kb_field_(\d+)$/', (string) $key, $m ) ) {
                $def      = $defs[ (int) $m[1] ] ?? [];
                $fields[] = [ 'type' => $def['type'] ?? 'text', 'label' => $def['label'] ?? $key, 'value' => $value, 'key' => $key ];
            }
        }
        // phpcs:enable
        $s = Submission::fromFields( $this->source(), trim( $post_id . ': ' . ( is_numeric( $post_id ) ? get_the_title( (int) $post_id ) : '' ) . ' ' . $form_id ), $fields );
        if ( ! $this->evaluate( $s )->act || ! class_exists( 'KB_Ajax_Form' ) ) {
            return;
        }
        $kadence = \KB_Ajax_Form::get_instance();
        $kadence->start_buffer();
        $kadence->process_bail( $this->message(), 'Spam Detected' ); // Sends Kadence's error JSON and exits.
    }

    /**
     * @return array<int, array{type?:string, label?:string}>
     */
    private function blockFields( string $post_id, string $unique_id ): array {
        $post = is_numeric( $post_id ) ? get_post( (int) $post_id ) : null;
        if ( ! $post ) {
            return [];
        }
        $find = function ( array $blocks ) use ( &$find, $unique_id ): array {
            foreach ( $blocks as $block ) {
                if ( 'kadence/form' === ( $block['blockName'] ?? '' ) && ( $block['attrs']['uniqueID'] ?? '' ) === $unique_id ) {
                    return (array) ( $block['attrs']['fields'] ?? [] );
                }
                $inner = $find( (array) ( $block['innerBlocks'] ?? [] ) );
                if ( $inner ) {
                    return $inner;
                }
            }
            return [];
        };
        return $find( parse_blocks( $post->post_content ) );
    }
}
