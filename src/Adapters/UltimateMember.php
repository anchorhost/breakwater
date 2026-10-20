<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Submission;

/**
 * Ultimate Member registration. Checked after UM's own validation and before the user is
 * created; spam shows an error on the email field and keeps what was typed. Released, the
 * account is created by UM's own register handler without the password the visitor chose
 * (it isn't kept), so UM marks it set-password-required and its welcome email carries the
 * "Set your password" link. Nobody is logged in and nothing redirects.
 */
final class UltimateMember extends Adapter {

    public function source(): string { return 'ultimate-member'; }
    public function label(): string { return 'Ultimate Member registration'; }
    public function detected(): bool { return function_exists( 'UM' ); }

    public function register(): void {
        add_action( 'um_submit_form_register', $this->check( ... ), 5, 2 );
    }

    /**
     * um_submit_form_register() with the form set up the way UM's form_init() left it, minus
     * the front-end tail: auto-login, the redirect (it exits) and wp-admin's second run of
     * um_user_register are swapped for the status step alone (approve and welcome email,
     * activation email, or review), so the result is the same from the log, the digest or WP-CLI.
     */
    public function release( object $row ): ?string {
        $data    = Log::replay( $row );
        $args    = (array) ( $data['args'] ?? [] );
        $form_id = (int) ( $data['form_id'] ?? 0 );
        $email   = (string) ( $args['user_email'] ?? ( is_email( (string) ( $args['username'] ?? '' ) ) ? $args['username'] : '' ) );
        $login   = (string) ( $args['user_login'] ?? ( $args['username'] ?? '' ) );
        if ( ! $form_id || ! is_email( $email ) || ! is_array( $args['submitted'] ?? null ) || ! function_exists( 'UM' ) || ! function_exists( 'um_submit_form_register' ) || ! function_exists( 'um_check_user_status' ) || ! function_exists( 'um_user' ) ) {
            return null;
        }
        if ( email_exists( $email ) || ( '' !== $login && ! is_email( $login ) && username_exists( $login ) ) ) {
            return null; // Someone has that email or username now.
        }
        if ( 'um_form' !== get_post_type( $form_id ) || 'publish' !== get_post_status( $form_id ) ) {
            return null;
        }
        $form_data = UM()->query()->post_data( $form_id );
        if ( 'register' !== ( $form_data['mode'] ?? '' ) || ( get_post_meta( $form_id, '_um_register_use_gdpr', true ) && ! isset( $args['submitted']['use_gdpr_agreement'] ) ) ) {
            return null; // The privacy agreement is required now and wasn't given.
        }

        $form                     = UM()->form();
        $form->form_id            = $form_id;
        $form->form_data          = $form_data;
        $form->post_form          = $args;
        $form->errors             = null;
        $form->usermeta_whitelist = array_values( array_map( 'strval', (array) ( $data['whitelist'] ?? [] ) ) );

        $user_id = 0;
        $capture = static function ( $id ) use ( &$user_id ): void {
            $user_id = (int) $id;
        };
        $status  = '';
        $tail    = static function ( $id, $args, $form_data = null ) use ( &$status ): void {
            um_fetch_user( $id );
            $status = (string) um_user( 'status' );
            // What um_check_user_status() does before it logs the visitor in and redirects.
            do_action( "um_post_registration_{$status}_hook", $id, $args, $form_data );
            delete_user_meta( $id, '_um_registration_in_progress' );
            do_action( "track_{$status}_user_registration", $id, $args, $form_data );
            if ( 'approved' === $status && method_exists( UM()->user(), 'generate_profile_slug' ) ) {
                UM()->user()->generate_profile_slug( $id );
            }
        };
        // um_send_registration_notification() stays quiet in wp-admin (it takes that for an
        // administrator adding a user), but a release from the log runs there and is a sign-up.
        $notify  = static function ( $id ): void {
            if ( ! function_exists( 'um_multi_admin_email' ) || ! function_exists( 'um_user_submitted_registration_formatted' ) ) {
                return;
            }
            um_fetch_user( $id );
            $template = 'pending' !== um_user( 'status' ) ? 'notification_new_user' : 'notification_review';
            $args     = [ 'admin' => true, 'fetch_user_id' => (int) $id, 'tags' => [ '{submitted_registration}' ], 'tags_replace' => wp_kses_post( um_user_submitted_registration_formatted() ) ];
            foreach ( (array) um_multi_admin_email() as $email ) {
                UM()->maybe_action_scheduler()->enqueue_async_action( 'um_dispatch_email', [ $email, $template, $args ] );
            }
        };
        $admin   = is_admin();
        $swaps   = [
            [ 'user_register', [ UM()->user(), 'user_register_via_admin' ], 10 ],
            [ 'um_registration_complete', 'um_check_user_status', 100 ],
        ];
        if ( $admin ) {
            $swaps[] = [ 'um_registration_complete', 'um_send_registration_notification', 10 ];
        }
        $removed = [];
        foreach ( $swaps as [ $hook, $callback, $priority ] ) {
            if ( false !== has_action( $hook, $callback ) ) {
                remove_action( $hook, $callback, $priority );
                $removed[] = [ $hook, $callback, $priority ];
            }
        }
        add_action( 'um_user_register', $capture, 0 );
        add_action( 'um_registration_complete', $tail, 100, 3 );
        if ( $admin ) {
            add_action( 'um_registration_complete', $notify, 10 );
        }
        try {
            um_submit_form_register( $args, $form_data );
        } finally {
            remove_action( 'um_user_register', $capture, 0 );
            remove_action( 'um_registration_complete', $tail, 100 );
            remove_action( 'um_registration_complete', $notify, 10 );
            foreach ( $removed as [ $hook, $callback, $priority ] ) {
                add_action( $hook, $callback, $priority, 'um_check_user_status' === $callback ? 3 : 1 );
            }
        }
        $user = $user_id ? get_userdata( $user_id ) : false;
        if ( isset( $form->errors ) || ! $user ) {
            return null;
        }
        Log::link( (int) $row->id, (string) $user_id );
        $on = static fn ( string $template ): bool => ! empty( UM()->options()->get( $template . '_on' ) );
        return match ( $status ) {
            'approved'  => sprintf( 'Account created for %s', $user->user_login ) . ( $on( 'welcome_email' ) ? sprintf( ', and Ultimate Member\'s welcome email went to %s with a link to set the password.', $user->user_email ) : sprintf( '. Ultimate Member\'s welcome email is turned off, so %s needs Lost password to set one.', $user->user_email ) ),
            'checkmail' => sprintf( 'Account created for %s, waiting for email activation', $user->user_login ) . ( $on( 'checkmail_email' ) ? sprintf( '; the activation email went to %s.', $user->user_email ) : '; Ultimate Member\'s activation email is turned off.' ),
            'pending'   => sprintf( 'Account created for %s and waiting for review in Ultimate Member, as its settings ask.', $user->user_login ),
            default     => sprintf( 'Account created for %s.', $user->user_login ),
        };
    }

    public function recipients( string $form ): array {
        return function_exists( 'um_multi_admin_email' ) ? array_values( array_filter( (array) um_multi_admin_email(), 'is_email' ) ) : [];
    }

    /**
     * @param array<string, mixed> $args      Submitted values keyed by meta key.
     * @param array<string, mixed> $form_data form_id, custom_fields.
     */
    private function check( mixed $args, mixed $form_data = [] ): void {
        if ( ! is_array( $args ) || ! is_array( $form_data ) || ! function_exists( 'UM' ) || isset( UM()->form()->errors ) ) {
            return;
        }
        $form_id = (int) ( $form_data['form_id'] ?? 0 );
        if ( get_post_meta( $form_id, '_um_register_use_gdpr', true ) && ! isset( $args['submitted']['use_gdpr_agreement'] ) ) {
            return; // UM refuses it itself at priority 9, so a held sign-up always carries the agreement.
        }
        $email_key = 'user_email';
        $fields    = [];
        $secret    = [ 'user_password', 'confirm_user_password', '_wpnonce' ];
        foreach ( (array) maybe_unserialize( $form_data['custom_fields'] ?? [] ) as $key => $def ) {
            if ( is_array( $def ) && 'password' === ( $def['type'] ?? '' ) ) {
                array_push( $secret, (string) $key, 'confirm_' . $key );
            }
            if ( ! is_array( $def ) || ! array_key_exists( $key, $args ) ) {
                continue;
            }
            $type = (string) ( $def['type'] ?? 'text' );
            if ( in_array( $def['validate'] ?? '', [ 'unique_email', 'unique_username_or_email' ], true ) || 'user_email' === $key ) {
                $type      = 'email';
                $email_key = (string) $key;
            } elseif ( in_array( $key, [ 'first_name', 'last_name', 'user_login', 'user_url' ], true ) ) {
                $type = (string) $key;
            }
            $fields[] = [ 'type' => $type, 'label' => (string) ( $def['label'] ?? $def['title'] ?? $key ), 'value' => $args[ $key ], 'key' => (string) $key ];
        }
        $verdict = $this->evaluate( Submission::fromFields( $this->source(), $form_id . ': ' . get_the_title( $form_id ), $fields ) );
        if ( ! $verdict->act ) {
            return;
        }
        // UM's own submission (its fields twice: top level and under "submitted"), with every
        // password left out: the account gets a set-password link instead.
        $secret = array_flip( $secret );
        $keep   = static fn ( array $values ): array => array_filter( array_diff_key( $values, $secret ), fn ( $key ): bool => ! str_starts_with( (string) $key, 'bw_' ), ARRAY_FILTER_USE_KEY );
        $stash  = $keep( $args );
        if ( is_array( $args['submitted'] ?? null ) ) {
            $stash['submitted'] = $keep( $args['submitted'] );
        }
        Log::stash( $verdict->log_id, [ 'form_id' => $form_id, 'args' => $stash, 'whitelist' => array_values( (array) UM()->form()->usermeta_whitelist ) ] );
        UM()->form()->add_error( $email_key, $this->message( 'account' ) );
    }
}
