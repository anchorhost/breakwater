<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Release;
use Breakwater\Submission;

/**
 * Beaver Builder's Contact Form and Subscribe Form modules. Both post to admin-ajax with a
 * fixed set of fields (the browser proof arrives by cookie) and store nothing: a contact form
 * only emails, a subscribe form only calls its service. Spam gets the module's own error,
 * after BB's nonce, terms and reCAPTCHA checks, before anything is sent.
 *
 * Contact form: checked on fl_module_contact_form_before_send, which BB fires with the email it
 * has built; released, that same email is sent with the before/after-send hooks around it.
 * Subscribe form: BB has no hook between its checks and the service call, but it looks the
 * service up (fl_builder_subscribe_form_services) right there; released, the sign-up goes
 * through BB's own service class, only for services where nobody is subscribed without
 * confirming: "Email Address" (it emails the owner) and Mailchimp (forced to double opt-in).
 */
final class BeaverBuilder extends Adapter {

    /** Subscribe services handed back; the rest are emailed to the owner instead. */
    private const SERVICES = [ 'email-address', 'mailchimp' ];

    private bool $subscribe_checked = false;

    public function source(): string { return 'beaver-builder'; }
    public function label(): string { return 'Beaver Builder'; }
    public function detected(): bool { return defined( 'FL_BUILDER_VERSION' ); }

    public function register(): void {
        // Early, so other before-send listeners (CRMs, logs) never see spam.
        add_action( 'fl_module_contact_form_before_send', $this->contact( ... ), 0, 5 );
        add_filter( 'fl_builder_subscribe_form_services', $this->subscribe( ... ), 99 );
    }

    public function release( object $row ): ?string {
        $data = Log::replay( $row );
        if ( ! class_exists( 'FLBuilderModel' ) ) {
            return null;
        }
        return match ( $data['module'] ?? '' ) {
            'contact-form'   => self::sendMail( $data ),
            'subscribe-form' => self::signUp( $data ),
            default          => null,
        };
    }

    /** The module's "Send To Email" (contact) or the Email Address service's address (subscribe). */
    public function recipients( string $form ): array {
        preg_match( '/\[([^\]|]+)(?:\|([^\]|]+)\|([^\]|]+))?\]$/', $form, $m );
        $settings = self::settings( [ 'post_id' => self::formId( $form ), 'node_id' => $m[1] ?? '', 'template_id' => $m[2] ?? '', 'template_node_id' => $m[3] ?? '' ] );
        if ( ! $settings ) {
            return [];
        }
        if ( 'contact-form' === ( $settings->type ?? '' ) ) {
            return self::emails( (string) ( $settings->mailto_email ?? '' ) );
        }
        if ( 'email-address' === ( $settings->service ?? '' ) && class_exists( 'FLBuilderModel' ) ) {
            $accounts = (array) \FLBuilderModel::get_services();
            return self::emails( (string) ( $accounts['email-address'][ (string) ( $settings->service_account ?? '' ) ]['email'] ?? '' ) );
        }
        return [];
    }

    /**
     * The email BB built, sent the way send_mail() sends it. Its before/after-send hooks run
     * with the module's current settings; a deleted module isn't sent.
     *
     * @param array<string, mixed> $data
     */
    private static function sendMail( array $data ): ?string {
        $settings = self::settings( $data );
        $mailto   = (string) ( $data['mailto'] ?? '' );
        if ( ! $settings || '' === $mailto || ! is_string( $data['template'] ?? null ) ) {
            return null;
        }
        $subject = (string) ( $data['subject'] ?? '' );
        $headers = array_values( array_map( 'strval', (array) ( $data['headers'] ?? [] ) ) );
        do_action( 'fl_module_contact_form_before_send', $mailto, $subject, $data['template'], $headers, $settings );
        $sent = wp_mail( $mailto, $subject, $data['template'], $headers );
        do_action( 'fl_module_contact_form_after_send', $mailto, $subject, $data['template'], $headers, $settings, $sent );
        return $sent ? sprintf( 'Sent to %s the way the Beaver Builder contact form sends it.', $mailto ) : null;
    }

    /**
     * The sign-up through the module's service, as submit() runs it after its checks, then
     * BB's submission-complete hook.
     *
     * @param array<string, mixed> $data
     */
    private static function signUp( array $data ): ?string {
        $settings = self::settings( $data );
        $email    = (string) ( $data['email'] ?? '' );
        $service  = (string) ( $settings->service ?? '' );
        if ( ! $settings || ! is_email( $email ) || ! in_array( $service, self::SERVICES, true ) || ! class_exists( 'FLBuilderServices' )
            || ! isset( \FLBuilderServices::get_services_data()[ $service ] ) ) {
            return null;
        }
        $name     = '' !== (string) ( $data['name'] ?? '' ) ? (string) $data['name'] : false;
        $custom   = is_array( $data['custom'] ?? null ) && $data['custom'] ? (object) $data['custom'] : false;
        $instance = \FLBuilderServices::get_service_instance( $service );
        if ( ! is_object( $instance ) || ! method_exists( $instance, 'subscribe' ) ) {
            return null;
        }
        $outcome = null;
        if ( 'mailchimp' === $service ) {
            $status = self::mailchimpStatus( $instance, $settings, $email );
            if ( null === $status ) {
                return null; // Unreachable, or a member Mailchimp would mark subscribed without asking.
            }
            $optin = fn (): bool => true;
            add_filter( 'fl_builder_mailchimp_double_option', $optin, PHP_INT_MAX );
            try {
                $response = $instance->subscribe( $settings, $email, $name, $custom );
            } finally {
                remove_filter( 'fl_builder_mailchimp_double_option', $optin, PHP_INT_MAX );
            }
            $outcome = 'subscribed' === $status
                ? sprintf( '%s was already subscribed in Mailchimp; their details were updated.', $email )
                : sprintf( 'Sent to Mailchimp as pending, and Mailchimp emails %s a link to confirm the subscription.', $email );
        } else {
            $response = $instance->subscribe( $settings, $email, $name, $custom );
            $account  = method_exists( $instance, 'get_account_data' ) ? $instance->get_account_data( (string) ( $settings->service_account ?? '' ) ) : [];
            $outcome  = sprintf( 'Sent to %s the way the Beaver Builder subscribe form sends sign-ups.', (string) ( $account['email'] ?? 'the form\'s address' ) );
        }
        if ( ! is_array( $response ) || ! empty( $response['error'] ) ) {
            return null;
        }
        // BB passes the template's post as post_id for a global module.
        $template = (string) ( $data['template_id'] ?? '' );
        $post_id  = '' !== $template ? \FLBuilderModel::get_node_template_post_id( $template ) : (string) ( $data['post_id'] ?? '' );
        do_action( 'fl_builder_subscribe_form_submission_complete', $response, $settings, $email, $name, '' !== $template ? $template : false, $post_id );
        return $outcome;
    }

    /**
     * The member's Mailchimp status before the sign-up: "new" when not on the list. BB's client
     * re-subscribes any existing member who isn't unsubscribed (pending, cleaned, archived)
     * without a confirmation, so those return null and are emailed instead.
     */
    private static function mailchimpStatus( object $instance, object $settings, string $email ): ?string {
        $accounts = class_exists( 'FLBuilderModel' ) ? (array) \FLBuilderModel::get_services() : [];
        $key      = (string) ( $accounts['mailchimp'][ (string) ( $settings->service_account ?? '' ) ]['api_key'] ?? '' );
        if ( '' === $key || empty( $settings->list_id ) || ! method_exists( $instance, 'get_api' ) ) {
            return null;
        }
        try {
            $api    = $instance->get_api( $key );
            $member = $api->get_member( (string) $settings->list_id, $email );
            if ( ! $api->getLastError() ) {
                $status = (string) ( $member['status'] ?? '' );
                return in_array( $status, [ 'subscribed', 'unsubscribed' ], true ) ? $status : null;
            }
            $response = (array) $api->getLastResponse();
            return 404 === (int) ( $response['headers']['http_code'] ?? 0 ) ? 'new' : null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /**
     * A module's settings the way BB's AJAX handlers read them: from the global template when
     * the form is one, else from the published layout of the post it was sent from.
     *
     * @param array<string, mixed> $ids post_id, node_id, template_id, template_node_id
     */
    private static function settings( array $ids ): ?object {
        $node = (string) ( $ids['node_id'] ?? '' );
        if ( '' === $node || ! class_exists( 'FLBuilderModel' ) ) {
            return null;
        }
        $template = (string) ( $ids['template_id'] ?? '' );
        if ( '' !== $template ) {
            $layout   = \FLBuilderModel::get_layout_data( 'published', \FLBuilderModel::get_node_template_post_id( $template ) );
            $settings = $layout[ (string) ( $ids['template_node_id'] ?? '' ) ]->settings ?? null;
        } else {
            \FLBuilderModel::set_post_id( (int) ( $ids['post_id'] ?? 0 ) );
            try {
                $module = \FLBuilderModel::get_module( $node );
            } finally {
                \FLBuilderModel::reset_post_id();
            }
            $settings = is_object( $module ) ? $module->settings : null;
        }
        if ( is_object( $settings ) && class_exists( 'FLThemeBuilderFieldConnections' ) ) {
            $settings = \FLThemeBuilderFieldConnections::connect_settings( $settings );
        }
        return is_object( $settings ) && in_array( $settings->type ?? '', [ 'contact-form', 'subscribe-form' ], true ) ? $settings : null;
    }

    /**
     * The contact form's email is built and about to go out. Spam gets BB's error answer
     * (the one it uses for the terms check), which its script shows in the form.
     */
    private function contact( mixed $mailto, mixed $subject = '', mixed $template = '', mixed $headers = [], mixed $settings = null ): void {
        if ( Release::running() || ! is_string( $template ) || ! wp_doing_ajax() ) {
            return;
        }
        $post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- BB verified it.
        $ids    = self::ids( $post );
        $fields = [];
        foreach ( [ 'name' => 'name', 'email' => 'email', 'phone' => 'tel', 'subject' => 'text', 'message' => 'textarea' ] as $key => $type ) {
            if ( isset( $post[ $key ] ) && is_string( $post[ $key ] ) ) {
                $fields[] = [ 'type' => $type, 'label' => ucfirst( $key ), 'value' => $post[ $key ], 'key' => $key ];
            }
        }
        $verdict = $this->evaluate( Submission::fromFields( $this->source(), self::formLabel( $ids, 'Contact form' ), $fields ) );
        if ( ! $verdict->act ) {
            return;
        }
        if ( $verdict->log_id ) {
            Log::stash( $verdict->log_id, $ids + [
                'module'   => 'contact-form',
                'mailto'   => is_string( $mailto ) ? $mailto : '',
                'subject'  => is_string( $subject ) ? $subject : '',
                'template' => $template,
                'headers'  => array_values( array_filter( (array) $headers, 'is_string' ) ),
            ] );
        }
        wp_send_json_error( [ 'error' => true, 'message' => esc_html( $this->message() ) ] );
    }

    /**
     * BB looks up the subscribe service once its own checks (nonce, terms, reCAPTCHA) have
     * passed and just before it subscribes: the one point to judge the sign-up. Spam gets
     * submit()'s own error answer and the service is never called.
     */
    private function subscribe( mixed $services ): mixed {
        $hook = 'wp_ajax_' . ( is_user_logged_in() ? '' : 'nopriv_' ) . 'fl_builder_subscribe_form_submit';
        if ( $this->subscribe_checked || Release::running() || ! doing_action( $hook ) ) {
            return $services;
        }
        $this->subscribe_checked = true;
        $post     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- BB verified it.
        $ids      = self::ids( $post );
        $settings = self::settings( $ids );
        $email    = is_string( $post['email'] ?? null ) ? sanitize_email( $post['email'] ) : '';
        $name     = is_string( $post['name'] ?? null ) ? sanitize_text_field( $post['name'] ) : '';
        $custom   = is_array( $post['custom_field'] ?? null ) ? array_map( 'sanitize_text_field', array_filter( $post['custom_field'], 'is_string' ) ) : [];
        $fields   = [ [ 'type' => 'name', 'label' => 'Name', 'value' => $name ], [ 'type' => 'email', 'label' => 'Email', 'value' => $email ] ];
        foreach ( $custom as $label => $value ) {
            $fields[] = [ 'type' => 'text', 'label' => (string) $label, 'value' => $value ];
        }
        $verdict = $this->evaluate( Submission::fromFields( $this->source(), self::formLabel( $ids, 'Subscribe form' ), $fields ) );
        if ( ! $verdict->act ) {
            return $services;
        }
        if ( $verdict->log_id && $settings ) {
            Log::stash( $verdict->log_id, $ids + [ 'module' => 'subscribe-form', 'email' => $email, 'name' => $name, 'custom' => $custom ] );
        }
        wp_send_json_success( [ 'action' => false, 'error' => $this->message( 'subscription' ), 'message' => false, 'url' => false ] );
    }

    /**
     * Where the module lives, from the fields BB's scripts post.
     *
     * @param array<string, mixed> $post
     * @return array{post_id:int, node_id:string, template_id:string, template_node_id:string}
     */
    private static function ids( array $post ): array {
        $text = fn ( string $key ): string => is_scalar( $post[ $key ] ?? null ) ? sanitize_text_field( (string) $post[ $key ] ) : '';
        return [
            'post_id'          => absint( $text( 'post_id' ) ),
            'node_id'          => $text( 'node_id' ),
            'template_id'      => $text( 'template_id' ),
            'template_node_id' => $text( 'template_node_id' ),
        ];
    }

    /**
     * The log's form column: "<post ID>: <module> on <post title> [<node>]", with
     * "|<template>|<template node>" inside the brackets for a global module, so recipients()
     * can find it again.
     *
     * @param array{post_id:int, node_id:string, template_id:string, template_node_id:string} $ids
     */
    private static function formLabel( array $ids, string $module ): string {
        $title = $ids['post_id'] ? trim( wp_strip_all_tags( get_the_title( $ids['post_id'] ) ) ) : '';
        $where = $ids['node_id'] . ( '' !== $ids['template_id'] ? '|' . $ids['template_id'] . '|' . $ids['template_node_id'] : '' );
        return $ids['post_id'] . ': ' . $module . ( '' !== $title ? ' on ' . $title : '' ) . ( '' !== $where ? ' [' . $where . ']' : '' );
    }
}
