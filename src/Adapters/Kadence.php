<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Submission;

/**
 * Kadence Blocks forms: the Advanced Form block through its own reject filter, and the basic
 * Form block just before Kadence handles its AJAX request. Spam gets Kadence's error notice.
 * Neither block stores submissions, so a release sends what the submission would have: the
 * Advanced Form's actions (email, MailerLite, FluentCRM, Pro's), or the basic block's email.
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

    public function release( object $row ): ?string {
        $replay = Log::replay( $row );
        return match ( $replay['kind'] ?? '' ) {
            'advanced' => $this->releaseAdvanced( $replay ),
            'basic'    => $this->releaseBasic( $replay ),
            default    => null,
        };
    }

    /**
     * Who the form emails. The log's form column is "advanced <id>: <title>" for the Advanced
     * Form and "<post id>: <title> <block id>" for the basic block.
     */
    public function recipients( string $form ): array {
        if ( str_starts_with( $form, 'advanced ' ) ) {
            $email = get_post_meta( (int) substr( $form, 9 ), '_kad_form_email', true );
            $email = is_array( $email ) ? $email : [];
        } else {
            $parts = explode( ' ', trim( $form ) );
            $email = (array) ( $this->blockAttrs( (string) strtok( $form, ':' ), (string) end( $parts ) )['email'][0] ?? [] );
        }
        $to = [];
        foreach ( [ 'emailTo', 'cc', 'bcc' ] as $key ) {
            $to = array_merge( $to, self::emails( (string) ( $email[ $key ] ?? '' ) ) );
        }
        return array_values( array_unique( $to ) );
    }

    /**
     * @param array<string, mixed>       $form_args
     * @param list<array<string, mixed>> $fields label, type, value, name per field.
     */
    private function checkAdvanced( mixed $reject, mixed $form_args = [], mixed $fields = [], mixed $post_id = 0 ): mixed {
        if ( $reject || ! is_array( $fields ) ) {
            return $reject;
        }
        $fields = array_values( array_filter( $fields, 'is_array' ) );
        $list   = array_map(
            fn ( array $field ): array => [ 'type' => $field['type'] ?? '', 'label' => $field['label'] ?? '', 'value' => $field['value'] ?? '', 'key' => $field['name'] ?? '' ],
            $fields
        );
        $s       = Submission::fromFields( $this->source(), 'advanced ' . (int) $post_id . ': ' . get_the_title( (int) $post_id ), $list );
        $verdict = $this->evaluate( $s );
        $this->rejected = $verdict->act;
        if ( $verdict->act && ! in_array( 'file', array_column( $fields, 'type' ), true ) ) {
            // Kadence's processed fields, as its actions take them. Uploads can't be replayed.
            foreach ( $fields as $i => $field ) {
                if ( 'password' === ( $field['type'] ?? '' ) ) {
                    $fields[ $i ]['value'] = '';
                }
            }
            Log::stash( $verdict->log_id, [ 'kind' => 'advanced', 'post_id' => (string) $post_id, 'fields' => $fields ] );
        }
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
        $defs    = (array) ( $this->blockAttrs( $post_id, $form_id )['fields'] ?? [] );
        $fields  = [];
        $values  = [];
        foreach ( wp_unslash( $_POST ) as $key => $value ) {
            if ( preg_match( '/^kb_field_(\d+)$/', (string) $key, $m ) ) {
                $def      = $defs[ (int) $m[1] ] ?? [];
                $fields[] = [ 'type' => $def['type'] ?? 'text', 'label' => $def['label'] ?? $key, 'value' => $value, 'key' => $key ];
                $values[ (int) $m[1] ] = 'password' === ( $def['type'] ?? '' ) ? '' : $value;
            }
        }
        // phpcs:enable
        $s       = Submission::fromFields( $this->source(), trim( $post_id . ': ' . ( is_numeric( $post_id ) ? get_the_title( (int) $post_id ) : '' ) . ' ' . $form_id ), $fields );
        $verdict = $this->evaluate( $s );
        if ( ! $verdict->act || ! class_exists( 'KB_Ajax_Form' ) ) {
            return;
        }
        Log::stash( $verdict->log_id, [ 'kind' => 'basic', 'post_id' => $post_id, 'form_id' => $form_id, 'values' => $values ] );
        $kadence = \KB_Ajax_Form::get_instance();
        $kadence->start_buffer();
        $kadence->process_bail( $this->message(), 'Spam Detected' ); // Sends Kadence's error JSON and exits.
    }

    /**
     * What Kadence's AJAX handler does after its reject filter: the submission action, then
     * after_submit_actions() (email, MailerLite, FluentCRM, then Pro's kadence_advanced_form_actions).
     * The form settings are read the way its private get_form() reads them: the form post's
     * _kad_form_* meta and its field blocks.
     *
     * @param array<string, mixed> $replay
     */
    private function releaseAdvanced( array $replay ): ?string {
        $post_id = (string) ( $replay['post_id'] ?? '' );
        $post    = get_post( absint( $post_id ) );
        $fields  = array_values( array_filter( (array) ( $replay['fields'] ?? [] ), 'is_array' ) );
        if ( ! $fields || ! $post || 'kadence_form' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password
            || ! class_exists( 'KB_Ajax_Advanced_Form' ) || ! method_exists( 'KB_Ajax_Advanced_Form', 'after_submit_actions' )
            || in_array( 'file', array_column( $fields, 'type' ), true ) ) {
            return null;
        }
        $blocks = parse_blocks( $post->post_content );
        if ( 'kadence/advanced-form' !== ( $blocks[0]['blockName'] ?? '' ) || empty( $blocks[0]['innerBlocks'] ) ) {
            return null;
        }
        $meta = [];
        foreach ( (array) get_post_meta( $post->ID ) as $key => $value ) {
            if ( str_starts_with( (string) $key, '_kad_form_' ) && isset( $value[0] ) ) {
                $meta[ substr( (string) $key, 10 ) ] = maybe_unserialize( $value[0] );
            }
        }
        $form_args = [
            'attributes' => json_decode( (string) wp_json_encode( $meta ), true ) ?: [],
            'fields'     => self::advancedFields( $blocks[0]['innerBlocks'] ),
        ];
        $form_args = apply_filters( 'kadence_blocks_advanced_form_form_args', $form_args, $fields, $post_id );
        do_action( 'kadence_blocks_advanced_form_submission', $form_args, $fields, $post_id );
        $results = \KB_Ajax_Advanced_Form::get_instance()->after_submit_actions( $form_args, $fields, $post_id );
        if ( empty( $results['success'] ) ) {
            return 'Handed back to the Kadence form, but one of its actions reported a failure.';
        }
        return 'Sent through the Kadence form\'s actions (its email and any integrations), as a normal submission would be.';
    }

    /**
     * The field blocks inside an Advanced Form, as Kadence collects them.
     *
     * @param array<mixed> $blocks
     * @return list<array<string, mixed>>
     */
    private static function advancedFields( array $blocks ): array {
        $fields = [];
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) || ! isset( $block['blockName'] ) ) {
                continue;
            }
            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $fields = array_merge( $fields, self::advancedFields( $block['innerBlocks'] ) );
            } elseif ( 'kadence/advanced-form-submit' !== $block['blockName'] && str_starts_with( (string) $block['blockName'], 'kadence/advanced-form-' ) ) {
                $fields[] = array_merge( (array) $block['attrs'], [ 'type' => str_replace( 'kadence/advanced-form-', '', (string) $block['blockName'] ) ] );
            }
        }
        return $fields;
    }

    /**
     * The basic block does its email inline in its AJAX handler (which ends by sending JSON),
     * so it is rebuilt here the same way: Kadence's field sanitizing, its email template,
     * subject tags, Reply-To, From, Cc and Bcc, then kadence_blocks_form_submission. MailerLite
     * and FluentCRM steps are not run.
     *
     * @param array<string, mixed> $replay
     */
    private function releaseBasic( array $replay ): ?string {
        $post_id = (string) ( $replay['post_id'] ?? '' );
        $form_id = (string) ( $replay['form_id'] ?? '' );
        $values  = (array) ( $replay['values'] ?? [] );
        $args    = $this->blockAttrs( $post_id, $form_id );
        if ( ! $args || ! $values || ! class_exists( 'KB_Ajax_Form' ) || ! method_exists( 'KB_Ajax_Form', 'sanitize_field' ) || ! function_exists( 'kadence_blocks_get_template_html' ) ) {
            return null;
        }
        $args['actions'] ??= [ 'email' ];
        $args['email']   ??= [ [ 'emailTo' => get_bloginfo( 'admin_email' ), 'subject' => '[' . get_bloginfo( 'name' ) . ' ' . __( 'Submission', 'kadence-blocks' ) . ']', 'replyTo' => 'email_field', 'html' => true ] ];
        $args['fields']  ??= [
            [ 'label' => 'Name', 'type' => 'text', 'required' => false, 'multiSelect' => false ],
            [ 'label' => 'Email', 'type' => 'email', 'required' => true, 'multiSelect' => false ],
            [ 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'multiSelect' => false ],
        ];
        $sanitize = new \ReflectionMethod( 'KB_Ajax_Form', 'sanitize_field' ); // Private: Kadence's own rules per type.
        $kadence  = \KB_Ajax_Form::get_instance();
        $privacy  = get_option( 'wp_page_for_privacy_policy' ) ? get_the_title( (int) get_option( 'wp_page_for_privacy_policy' ) ) : '';
        $refer_id = url_to_postid( (string) wp_get_referer() );
        $fields   = [];
        foreach ( (array) $args['fields'] as $key => $def ) {
            if ( ! is_array( $def ) || ! array_key_exists( $key, $values ) ) {
                continue;
            }
            $type           = (string) ( $def['type'] ?? 'text' );
            $fields[ $key ] = [
                'type'  => $type,
                'label' => str_replace( '{privacy_policy}', $privacy, (string) ( $def['label'] ?? '' ) ),
                'value' => $sanitize->invoke( $kadence, $type, $values[ $key ], $def['multiSelect'] ?? false ),
            ];
            if ( 'hidden' === $type ) {
                $fields[ $key ]['value'] = str_replace(
                    [ '{page_title}', '{page_url}', '{remoteip}' ],
                    [ get_the_title( $refer_id ), (string) wp_get_referer(), (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ],
                    (string) $fields[ $key ]['value']
                );
            }
        }
        $sent    = false;
        $skipped = array_intersect( [ 'mailerlite', 'fluentCRM' ], (array) $args['actions'] );
        if ( in_array( 'email', (array) $args['actions'], true ) ) {
            $sent = $this->basicEmail( (array) ( $args['email'][0] ?? [] ), $fields, $refer_id );
        }
        do_action( 'kadence_blocks_form_submission', $args, $fields, $form_id, $post_id );
        $outcome = $sent ? 'The Kadence form\'s email was sent, as for a normal submission.' : 'Handed back to the Kadence form (it sends no email).';
        return $skipped ? $outcome . ' Its ' . implode( ' and ', $skipped ) . ' step was not run.' : $outcome;
    }

    /**
     * The basic block's email, built as its AJAX handler builds it.
     *
     * @param array<string, mixed>                                $email  The block's email[0] settings.
     * @param array<int, array{type:string, label:string, value:mixed}> $fields
     */
    private function basicEmail( array $email, array $fields, int $refer_id ): bool {
        $tags = function ( string $text ) use ( $fields ): string {
            if ( preg_match_all( '/{field_(.*?)}/', $text, $match ) ) {
                foreach ( $match[1] as $n ) {
                    $value = $fields[ absint( $n ) - 1 ]['value'] ?? null;
                    if ( null !== $value ) {
                        $text = str_replace( '{field_' . $n . '}', (string) $value, $text );
                    }
                }
            }
            return $text;
        };
        $to      = trim( (string) ( $email['emailTo'] ?? '' ) ) ?: (string) get_option( 'admin_email' );
        $subject = trim( (string) ( $email['subject'] ?? '' ) ) ? (string) $email['subject'] : '[' . get_bloginfo( 'name' ) . ' ' . __( 'Submission', 'kadence-blocks' ) . ']'; // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Kadence's own default.
        $subject = str_replace( '{page_title}', get_the_title( $refer_id ), $tags( $subject ) );
        $reply   = false;
        foreach ( $fields as $field ) {
            if ( 'email' === $field['type'] ) {
                $reply = $field['value'];
            }
        }
        $from = trim( (string) ( $email['fromEmail'] ?? '' ) );
        if ( 'from_email' === ( $email['replyTo'] ?? '' ) ) {
            $reply = '' !== $from ? sanitize_email( $from ) : false;
        }
        if ( ! empty( $email['html'] ) ) {
            $body    = kadence_blocks_get_template_html( 'form-email.php', [ 'fields' => $fields ] );
            $headers = "Content-Type: text/html; charset=UTF-8\r\n";
        } else {
            $body = '';
            foreach ( $fields as $field ) {
                $body .= $field['label'] . ': ' . ( is_scalar( $field['value'] ) ? $field['value'] : implode( ', ', (array) $field['value'] ) ) . "\n\n";
            }
            $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        }
        if ( $reply ) {
            $headers .= 'Reply-To: <' . $reply . ">\r\n";
        }
        if ( '' !== $from ) {
            $name     = trim( (string) ( $email['fromName'] ?? '' ) );
            $headers .= 'From: ' . ( '' !== $name ? $tags( $name . ' ' ) : '' ) . '<' . sanitize_email( $from ) . ">\r\n";
        }
        foreach ( [ 'cc' => 'Cc', 'bcc' => 'Bcc' ] as $key => $header ) {
            if ( trim( (string) ( $email[ $key ] ?? '' ) ) ) {
                $headers .= $header . ': ' . implode( ',', array_map( fn ( $a ) => sanitize_email( trim( $a ) ), explode( ',', trim( (string) $email[ $key ] ) ) ) ) . "\r\n";
            }
        }
        return (bool) wp_mail( $to, $subject, $body, $headers );
    }

    /**
     * The basic Form block's attributes (fields, email, actions...) on a post, found by its
     * uniqueID the way Kadence finds it: nested, or inside a reusable block.
     *
     * @return array<string, mixed>
     */
    private function blockAttrs( string $post_id, string $unique_id ): array {
        $post = is_numeric( $post_id ) ? get_post( (int) $post_id ) : null;
        if ( ! $post || '' === $unique_id ) {
            return [];
        }
        $find = function ( array $blocks ) use ( &$find, $unique_id ): array {
            foreach ( $blocks as $block ) {
                if ( 'kadence/form' === ( $block['blockName'] ?? '' ) && ( $block['attrs']['uniqueID'] ?? '' ) === $unique_id ) {
                    return (array) $block['attrs'];
                }
                $inner = $find( (array) ( $block['innerBlocks'] ?? [] ) );
                if ( ! $inner && 'core/block' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['ref'] ) ) {
                    $reusable = get_post( (int) $block['attrs']['ref'] );
                    $inner    = $reusable && 'wp_block' === $reusable->post_type ? $find( parse_blocks( $reusable->post_content ) ) : [];
                }
                if ( $inner ) {
                    return $inner;
                }
            }
            return [];
        };
        return $find( parse_blocks( $post->post_content ) );
    }
}
