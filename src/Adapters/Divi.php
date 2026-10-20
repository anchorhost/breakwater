<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Submission;

/**
 * Divi contact form module (Divi 4 shortcode and Divi 5 block). Divi has no hook that can
 * refuse a submission, so for spam we switch on the module's own spam protection and drop
 * its token: Divi then refuses with its "You must be a human" error and sends nothing.
 *
 * Divi stores nothing: a form only emails. Released, the email is rebuilt the way the module
 * builds it (same subject, message pattern, headers and filters) and et_pb_contact_form_submit
 * fires again, this time without the error.
 */
final class Divi extends Adapter {

    private ?bool $spam = null;
    private int $log_id = 0;
    /** @var array<string, mixed> Divi 4: the props of the contact form being rendered. */
    private array $props = [];
    /** @var array<string, array<string, mixed>> Divi 5: contact form attrs by uniqueId. */
    private array $attrs = [];
    /** @var array<string, array<string, true>> Divi 5: field IDs by the form's uniqueId. */
    private array $children = [];
    /** @var array<string, mixed> Divi 5: what to stash once Divi has finished validating. */
    private array $pending = [];

    public function source(): string { return 'divi'; }
    public function label(): string { return 'Divi contact form'; }
    public function detected(): bool { return defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_CORE_VERSION' ); }

    public function register(): void {
        add_filter( 'et_pb_module_shortcode_attributes', $this->divi4( ... ), 99, 3 );
        add_filter( 'divi_module_library_register_module_attrs', $this->divi5( ... ), 99, 2 );
        add_action( 'et_pb_contact_form_submit', $this->submitted( ... ), 99, 3 );
    }

    /**
     * Send the email the module would have sent, then tell et_pb_contact_form_submit
     * listeners it went through.
     */
    public function release( object $row ): ?string {
        $data = Log::replay( $row );
        if ( ! function_exists( 'et_get_safe_localization' ) || ! is_array( $data['fields'] ?? null ) || ! is_array( $data['info'] ?? null ) ) {
            return null;
        }
        $divi = (int) ( $data['divi'] ?? 4 );
        $mail = 5 === $divi ? self::mail5( $data ) : self::mail4( $data );
        if ( null === $mail ) {
            return null;
        }
        if ( 5 === $divi ) {
            do_action( 'et_pb_contact_form_submit', $data['fields'], false, $data['info'] ); // Divi 5 fires it before mailing.
        }
        $domain = 5 === $divi ? 'et_builder_5' : 'et_builder';
        $to     = apply_filters( 'et_contact_page_email_to', $mail['to'] );
        $ampers = function_exists( 'et_allow_ampersand' );
        if ( $ampers ) {
            add_filter( 'et_get_safe_localization', 'et_allow_ampersand' );
        }
        $sent = wp_mail(
            $to,
            et_get_safe_localization( sprintf(
                /* translators: 1: site name, 2: " - form title" */
                __( 'New Message From %1$s%2$s', $domain ), // phpcs:ignore WordPress.WP.I18n
                sanitize_text_field( html_entity_decode( (string) get_option( 'blogname' ), ENT_QUOTES, 'UTF-8' ) ),
                '' !== $mail['title'] ? sprintf( _x( ' - %s', 'contact form title separator', $domain ), $mail['title'] ) : '' // phpcs:ignore WordPress.WP.I18n
            ) ),
            '' !== $mail['message'] ? $mail['message'] : ' ',
            apply_filters( 'et_contact_page_headers', $mail['headers'], $mail['name'], $mail['email'] )
        );
        if ( $ampers ) {
            remove_filter( 'et_get_safe_localization', 'et_allow_ampersand' );
        }
        if ( 5 !== $divi ) {
            do_action( 'et_pb_contact_form_submit', $data['fields'], false, $data['info'] ); // Divi 4 fires it after.
        }
        if ( ! $sent ) {
            return null;
        }
        return sprintf( 'Sent to %s the way the Divi form sends it.', implode( ', ', array_map( 'trim', is_array( $to ) ? $to : explode( ',', (string) $to ) ) ) );
    }

    /** The form's "Email" setting, found in the page it was sent from; empty means the site admin. */
    public function recipients( string $form ): array {
        preg_match( '/\[([^\]]+)\]$/', $form, $m );
        $module = self::module( self::formId( $form ), (string) ( $m[1] ?? '' ) );
        return $module ? self::emails( $module['email'] ) : [];
    }

    /**
     * Divi 4's message, built the way ET_Builder_Module_Contact_Form::render() builds it from
     * the values it stashed (slashed, as Divi reads them from $_POST).
     *
     * @param array<string, mixed> $data
     * @return array{to:string, title:string, message:string, headers:list<string>, name:string, email:string}|null
     */
    private static function mail4( array $data ): ?array {
        $values  = $data['fields'];
        $pattern = (string) ( $data['pattern'] ?? '' );
        $name    = isset( $values['name']['value'] ) ? stripslashes( sanitize_text_field( (string) $values['name']['value'] ) ) : '';
        $email   = (string) ( $data['email'] ?? '' );
        if ( '' !== $pattern ) {
            foreach ( $values as $key => $value ) {
                $pattern = str_ireplace( "%%{$key}%%", wp_strip_all_tags( (string) ( $value['value'] ?? '' ) ), $pattern );
            }
            foreach ( (array) ( $data['hidden'] ?? [] ) as $hidden ) {
                $pattern = is_string( $hidden ) ? str_ireplace( "%%{$hidden}%%", '', $pattern ) : $pattern;
            }
        } else {
            $pattern = (string) ( $values['message']['value'] ?? '' );
            foreach ( $values as $key => $value ) {
                if ( ! in_array( $key, [ 'message', 'name', 'email' ], true ) ) {
                    $pattern .= "\r\n" . sprintf( '%1$s: %2$s', '' !== (string) ( $value['label'] ?? '' ) ? $value['label'] : $key, $value['value'] ?? '' );
                }
            }
            $pattern = wp_strip_all_tags( $pattern );
        }
        return self::headers( '' !== (string) ( $data['to'] ?? '' ) ? (string) $data['to'] : (string) get_site_option( 'admin_email' ), $data, $pattern, $name, $email );
    }

    /**
     * Divi 5's message, from ContactFormHandler::process() and its public ContactFormUtils.
     *
     * @param array<string, mixed> $data
     * @return array{to:string, title:string, message:string, headers:list<string>, name:string, email:string}|null
     */
    private static function mail5( array $data ): ?array {
        $utils = 'ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormUtils';
        if ( ! class_exists( $utils ) || ! method_exists( $utils, 'build_message_by_template' ) ) {
            return null;
        }
        $fields  = $data['fields'];
        $pattern = (string) ( $data['pattern'] ?? '' );
        $message = '' !== $pattern ? $utils::build_message_by_template( $fields, $pattern, array_values( (array) ( $data['skipped'] ?? [] ) ) ) : $utils::build_message( $fields );
        $to      = trim( (string) ( $data['to'] ?? '' ) );
        $valid   = is_email( $to ) ? [ $to ] : array_filter( array_map( 'trim', explode( ',', $to ) ), 'is_email' );
        $to      = '' !== $to && $valid ? implode( ',', $valid ) : (string) get_site_option( 'admin_email' );
        return self::headers( $to, $data, $message, (string) ( $fields['name']['value'] ?? '' ), (string) ( $fields['email']['value'] ?? '' ) );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{to:string, title:string, message:string, headers:list<string>, name:string, email:string}
     */
    private static function headers( string $to, array $data, string $message, string $name, string $email ): array {
        $host    = str_replace( 'www.', '', (string) ( $data['host'] ?? '' ) );
        $headers = [ "From: \"{$name}\" <mail@{$host}>" ];
        if ( '' !== $email ) {
            $name      = '' !== $name ? $name : $email;
            $headers[] = "Reply-To: \"{$name}\" <{$email}>";
        }
        return [ 'to' => $to, 'title' => (string) ( $data['title'] ?? '' ), 'message' => trim( stripslashes( $message ) ), 'headers' => $headers, 'name' => $name, 'email' => $email ];
    }

    /** @param array<string, mixed> $props */
    private function divi4( mixed $props, mixed $attrs = [], mixed $slug = '' ): mixed {
        if ( 'et_pb_contact_form' === $slug && is_array( $props ) && $this->spam() ) {
            $props['use_spam_service'] = 'on';
            $this->props               = $props;
            $this->dropToken();
        }
        return $props;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<string, mixed> $args name is the block name.
     */
    private function divi5( mixed $attrs, mixed $args = [] ): mixed {
        $name = is_array( $args ) ? ( $args['name'] ?? '' ) : '';
        if ( 'divi/contact-form' === $name && is_array( $attrs ) && $this->spam() ) {
            $attrs['module']['advanced']['spamProtection']['desktop']['value']['enabled'] = 'on';
            $this->attrs[ (string) ( $attrs['module']['advanced']['uniqueId']['desktop']['value'] ?? '' ) ] = $attrs;
            $this->dropToken();
        } elseif ( 'divi/contact-field' === $name && is_array( $attrs ) && $this->spam && is_array( $args['parentAttrs'] ?? null ) ) {
            $form = (string) ( $args['parentAttrs']['module']['advanced']['uniqueId']['desktop']['value'] ?? '' );
            $id   = strtolower( (string) ( $attrs['fieldItem']['advanced']['id']['desktop']['value'] ?? '' ) );
            $this->children[ $form ][ $id ] = true;
        }
        return $attrs;
    }

    private function dropToken(): void {
        unset( $_POST['token'], $_REQUEST['token'] );
    }

    /**
     * Divi has refused the held submission; keep what the email needs. Divi 5 passes its
     * validated fields; Divi 4 passes none once there is an error, so they are read the way it
     * reads them. A submission Divi would have refused anyway (a required field empty, a bad
     * email) is not kept: it was never going to be sent.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $info
     */
    private function submitted( mixed $fields, mixed $error, mixed $info ): void {
        if ( ! $this->spam || ! $this->log_id || ! is_array( $fields ) || ! is_array( $info ) ) {
            return;
        }
        $host = sanitize_text_field( wp_unslash( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
        if ( 'divi/contact-form' === ( $info['module_slug'] ?? '' ) ) {
            $uid   = (string) ( $info['contact_form_unique_id'] ?? '' );
            $attrs = $this->attrs[ $uid ] ?? $this->attrs[''] ?? null;
            if ( ! $fields || ! is_array( $attrs ) ) {
                return;
            }
            $this->pending = [
                'divi'    => 5,
                'fields'  => $fields,
                'info'    => $info,
                'to'      => $attrs['email']['advanced']['receiver']['desktop']['value'] ?? '',
                'title'   => (string) ( $attrs['title']['innerContent']['desktop']['value'] ?? '' ),
                'pattern' => (string) ( $attrs['email']['innerContent']['desktop']['value'] ?? '' ),
                'skipped' => array_values( array_diff( array_keys( $this->children[ $uid ] ?? [] ), array_keys( $fields ) ) ),
                'host'    => $host,
            ];
            // Divi 5 records its validation errors after this hook returns.
            add_action( 'shutdown', $this->stash5( ... ), 0 );
            return;
        }
        $data = self::fields4( (int) ( $info['contact_form_number'] ?? 0 ) );
        if ( null === $data ) {
            return;
        }
        $pattern = (string) ( $this->props['custom_message'] ?? '' );
        if ( '' !== $pattern && function_exists( 'et_builder_convert_line_breaks' ) ) {
            $pattern = et_builder_convert_line_breaks( html_entity_decode( $pattern ), "\r\n" );
        }
        Log::stash( $this->log_id, $data + [
            'divi'    => 4,
            'info'    => $info,
            'to'      => (string) ( $this->props['email'] ?? '' ),
            'title'   => wp_strip_all_tags( (string) ( $this->props['title'] ?? '' ) ),
            'pattern' => $pattern,
            'host'    => $host,
        ] );
    }

    /** Keep the Divi 5 submission only if our spam check was all that stopped it. */
    private function stash5(): void {
        $handler = 'ET\Builder\Packages\ModuleLibrary\ContactForm\ContactFormHandler';
        $uid     = (string) ( $this->pending['info']['contact_form_unique_id'] ?? '' );
        try {
            $snapshots = ( new \ReflectionProperty( $handler, '_submission_coordination_snapshots' ) )->getValue();
            $errors    = is_array( $snapshots[ $uid ]['errors'] ?? null ) ? array_keys( $snapshots[ $uid ]['errors'] ) : null;
        } catch ( \ReflectionException ) {
            $errors = null;
        }
        if ( null !== $errors && ! array_diff( $errors, [ 'spam_submission' ] ) ) {
            Log::stash( $this->log_id, $this->pending );
        }
    }

    /**
     * Divi 4's processed values (original_id => value, label) for form number $num, read and
     * checked as its render() does, plus the Reply-To address and the fields conditional
     * logic hid. Null when Divi would have refused it anyway.
     *
     * @return array{fields:array<string, array{value:string, label:string}>, email:string, hidden:list<string>}|null
     */
    private static function fields4( int $num ): ?array {
        $json = $_POST[ 'et_pb_contact_email_fields_' . $num ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        if ( ! is_string( $json ) || '' === $json || ! empty( $_POST[ 'et_pb_contact_et_number_' . $num ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return null;
        }
        $list   = json_decode( str_replace( '\\', '', $json ), true );
        $values = [];
        $email  = '';
        foreach ( is_array( $list ) ? $list : [] as $field ) {
            if ( ! is_array( $field ) || ! isset( $field['field_id'], $field['field_label'], $field['field_type'], $field['original_id'], $field['required_mark'] ) || 'et_pb_contact_et_number_' . $num === $field['field_id'] ) {
                continue;
            }
            $raw      = $_POST[ $field['field_id'] ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
            $value    = is_string( $raw ) ? trim( 'text' === $field['field_type'] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw ) ) : '';
            $required = 'required' === $field['required_mark'];
            if ( $required && empty( $value ) && ! is_numeric( $value ) ) {
                return null;
            }
            if ( 'email' === $field['field_type'] && '' !== $value ) {
                $email = sanitize_email( (string) $raw );
                if ( $required && ( '' === $email || ! is_email( $email ) ) ) {
                    return null;
                }
            }
            $values[ (string) $field['original_id'] ] = [ 'value' => $value, 'label' => (string) $field['field_label'] ];
        }
        $hidden = $_POST[ 'et_pb_contact_email_hidden_fields_' . $num ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        $hidden = is_string( $hidden ) && '' !== $hidden ? json_decode( str_replace( '\\', '', $hidden ), true ) : [];
        return $values ? [ 'fields' => $values, 'email' => $email, 'hidden' => array_values( array_filter( (array) $hidden, 'is_string' ) ) ] : null;
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
        $verdict      = $this->evaluate( $this->submission( $post ) );
        $this->spam   = $verdict->act;
        $this->log_id = $verdict->log_id;
        return $this->spam;
    }

    /**
     * The log's form column: "<post ID>: <title> [<form>]", where <form> is the module's
     * unique ID when it has one (Divi 5 always, Divi 4 when saved with it) or its number on
     * the page. The nonce field names the submitted form.
     *
     * @param array<string, mixed> $post
     */
    private static function formLabel( array $post ): string {
        $key = '';
        foreach ( array_keys( $post ) as $name ) {
            if ( preg_match( '/^_wpnonce-et-pb-contact-form-submitted-(.+)$/', (string) $name, $m ) ) {
                $key = $m[1];
                break;
            }
        }
        $post_id = function_exists( 'et_core_get_main_post_id' ) ? (int) et_core_get_main_post_id() : (int) get_the_ID();
        $module  = self::module( $post_id, $key );
        $key     = '' !== (string) ( $module['uid'] ?? '' ) ? $module['uid'] : $key;
        $title   = trim( wp_strip_all_tags( (string) ( $module['title'] ?? '' ) ) );
        return $post_id . ': ' . ( '' !== $title ? $title : 'Contact form' ) . ( '' !== $key ? ' [' . $key . ']' : '' );
    }

    /**
     * A contact form module's settings from the post it lives in, by unique ID or by its
     * number on the page. Forms in Theme Builder layouts aren't found (the admin gets them).
     *
     * @return array{uid:string, title:string, email:string}|null
     */
    private static function module( int $post_id, string $key ): ?array {
        $content = $post_id ? (string) get_post_field( 'post_content', $post_id ) : '';
        $forms   = [];
        if ( str_contains( $content, '<!-- wp:divi/contact-form' ) ) {
            $walk = function ( array $blocks ) use ( &$walk, &$forms ): void {
                foreach ( $blocks as $block ) {
                    if ( 'divi/contact-form' === ( $block['blockName'] ?? '' ) ) {
                        $a       = (array) ( $block['attrs'] ?? [] );
                        $forms[] = [
                            'uid'   => (string) ( $a['module']['advanced']['uniqueId']['desktop']['value'] ?? '' ),
                            'title' => (string) ( $a['title']['innerContent']['desktop']['value'] ?? '' ),
                            'email' => (string) ( $a['email']['advanced']['receiver']['desktop']['value'] ?? '' ),
                        ];
                    }
                    $walk( (array) ( $block['innerBlocks'] ?? [] ) );
                }
            };
            $walk( parse_blocks( $content ) );
        } elseif ( str_contains( $content, '[et_pb_contact_form' ) && preg_match_all( '/' . get_shortcode_regex( [ 'et_pb_contact_form' ] ) . '/s', $content, $m ) ) {
            foreach ( $m[3] as $atts ) {
                $a       = (array) shortcode_parse_atts( $atts );
                $forms[] = [ 'uid' => (string) ( $a['_unique_id'] ?? '' ), 'title' => (string) ( $a['title'] ?? '' ), 'email' => (string) ( $a['email'] ?? '' ) ];
            }
        }
        foreach ( $forms as $form ) {
            if ( '' !== $key && $key === $form['uid'] ) {
                return $form;
            }
        }
        if ( ctype_digit( $key ) && isset( $forms[ (int) $key ] ) ) {
            return $forms[ (int) $key ];
        }
        return 1 === count( $forms ) ? $forms[0] : null;
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
        $form = self::formLabel( $post );
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
