<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Submission;
use WP_Error;

/**
 * Core registration on wp-login.php?action=register, and multisite signups. A held sign-up is
 * refused with an error; released, the account is created the way WordPress would have and
 * WordPress sends its usual emails (set-password or activation). Nobody is logged in.
 */
final class Registration extends Adapter {

    public function source(): string { return 'registration'; }
    public function label(): string { return 'WordPress registration'; }
    public function detected(): bool { return (bool) get_option( 'users_can_register' ) || is_multisite(); }

    public function register(): void {
        add_filter( 'registration_errors', $this->check( ... ), 99, 3 );
        add_filter( 'wpmu_validate_user_signup', $this->checkMultisite( ... ), 99 );
    }

    /**
     * Create the account the visitor asked for, as register_new_user() or wp-signup.php would.
     * Those aren't called themselves: they re-run the registration filters, and captcha plugins
     * reject a request that has no form post.
     */
    public function release( object $row ): ?string {
        $data  = Log::replay( $row );
        $login = (string) ( $data['user_login'] ?? '' );
        $email = (string) ( $data['user_email'] ?? '' );
        if ( '' === $login || ! is_email( $email ) ) {
            return null;
        }
        return match ( $data['type'] ?? '' ) {
            'single' => is_multisite() ? null : self::createUser( $row, $login, $email, (string) ( $data['locale'] ?? '' ) ),
            'signup' => is_multisite() ? self::signup( $login, $email, $data ) : null,
            default  => null,
        };
    }

    /** What register_new_user() does after its checks pass. */
    private static function createUser( object $row, string $login, string $email, string $locale ): ?string {
        $illegal = array_map( 'strtolower', (array) apply_filters( 'illegal_user_logins', [] ) );
        if ( ! get_option( 'users_can_register' ) || ! validate_username( $login ) || username_exists( $login ) || email_exists( $email ) || in_array( strtolower( $login ), $illegal, true ) ) {
            return null;
        }
        $user_id = wp_create_user( $login, wp_generate_password( 12, false ), $email );
        if ( ! is_int( $user_id ) || ! $user_id ) {
            return null;
        }
        update_user_meta( $user_id, 'default_password_nag', true );
        if ( '' !== $locale && in_array( $locale, get_available_languages(), true ) ) {
            update_user_meta( $user_id, 'locale', $locale );
        }
        Log::link( (int) $row->id, (string) $user_id );
        do_action( 'register_new_user', $user_id ); // Core emails the admin, and the user a set-password link.
        return sprintf( 'Account created for %s, and a set-password email went to %s.', $login, $email );
    }

    /**
     * What wp-signup.php does after its checks pass: a pending signup, with WordPress's
     * activation email. A site signup held at its first step never got a site name, so it
     * can't be finished here.
     *
     * @param array<string, mixed> $data
     */
    private static function signup( string $login, string $email, array $data ): ?string {
        $stage   = (string) ( $data['stage'] ?? '' );
        $allowed = (string) get_site_option( 'registration' );
        $user    = wpmu_validate_user_signup( $login, $email );
        if ( ! is_array( $user ) || ! $user['errors'] instanceof WP_Error || $user['errors']->has_errors() ) {
            return null;
        }
        if ( 'validate-user-signup' === $stage && 'user' === ( $data['signup_for'] ?? '' ) && in_array( $allowed, [ 'user', 'all' ], true ) ) {
            wpmu_signup_user( $user['user_name'], $user['user_email'], apply_filters( 'add_signup_meta', [] ) );
            return sprintf( 'Signup recorded for %s, and the activation email went to %s.', $user['user_name'], $user['user_email'] );
        }
        if ( 'validate-blog-signup' !== $stage || ! in_array( $allowed, [ 'blog', 'all' ], true ) ) {
            return null;
        }
        $blog = wpmu_validate_blog_signup( (string) ( $data['blogname'] ?? '' ), (string) ( $data['blog_title'] ?? '' ) );
        if ( ! is_array( $blog ) || ! $blog['errors'] instanceof WP_Error || $blog['errors']->has_errors() ) {
            return null;
        }
        $meta = [ 'lang_id' => 1, 'public' => (int) ( $data['public'] ?? 0 ) ];
        $lang = (string) ( $data['lang'] ?? '' );
        if ( '' !== $lang && in_array( $lang, get_available_languages(), true ) ) {
            $meta['WPLANG'] = $lang;
        }
        wpmu_signup_blog( $blog['domain'], $blog['path'], $blog['blog_title'], $user['user_name'], $user['user_email'], apply_filters( 'add_signup_meta', $meta ) );
        return sprintf( 'Signup recorded for %s with the site %s, and the activation email went to %s.', $user['user_name'], $blog['domain'] . $blog['path'], $user['user_email'] );
    }

    private function check( mixed $errors, mixed $login, mixed $email ): mixed {
        if ( ! $errors instanceof WP_Error || $errors->has_errors() ) {
            return $errors;
        }
        $verdict = $this->evaluate( $this->submission( (string) $login, (string) $email ) );
        if ( $verdict->act ) {
            $errors->add( 'breakwater', $this->message( 'account' ) );
            $lang = isset( $_COOKIE['wp_lang'] ) && is_string( $_COOKIE['wp_lang'] ) ? sanitize_text_field( $_COOKIE['wp_lang'] ) : '';
            Log::stash( $verdict->log_id, [
                'type'       => 'single',
                'user_login' => (string) $login,
                'user_email' => (string) $email,
                'locale'     => in_array( $lang, get_available_languages(), true ) ? $lang : '',
            ] );
        }
        return $errors;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function checkMultisite( mixed $result ): mixed {
        $errors = is_array( $result ) ? ( $result['errors'] ?? null ) : null;
        if ( is_admin() || ! isset( $_POST['user_name'] ) || ! $errors instanceof WP_Error || $errors->has_errors() ) { // phpcs:ignore WordPress.Security.NonceVerification
            return $result;
        }
        $verdict = $this->evaluate( $this->submission( (string) $result['user_name'], (string) $result['user_email'] ) );
        if ( $verdict->act ) {
            $errors->add( 'user_name', $this->message( 'account' ) );
            Log::stash( $verdict->log_id, array_merge( [
                'type'       => 'signup',
                'user_login' => (string) $result['user_name'],
                'user_email' => (string) $result['user_email'],
            ], self::signupFields() ) );
        }
        return $result;
    }

    /**
     * Which wp-signup.php step this was and, on the site step, the site the visitor asked for.
     *
     * @return array<string, string|int>
     */
    private static function signupFields(): array {
        // phpcs:disable WordPress.Security.NonceVerification
        $post = static fn ( string $key ): string => isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
        $out  = [ 'stage' => $post( 'stage' ), 'signup_for' => $post( 'signup_for' ) ];
        if ( 'validate-blog-signup' === $out['stage'] ) {
            $out['blogname']   = $post( 'blogname' );
            $out['blog_title'] = $post( 'blog_title' );
            $out['public']     = (int) $post( 'blog_public' );
            $out['lang']       = $post( 'WPLANG' );
        }
        // phpcs:enable
        return $out;
    }

    private function submission( string $login, string $email ): Submission {
        return Submission::fromFields( $this->source(), 'wp-login', [
            [ 'type' => 'username', 'label' => 'user_login', 'value' => $login ],
            [ 'type' => 'email', 'label' => 'user_email', 'value' => $email ],
        ] );
    }
}
