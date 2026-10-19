<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;
use WP_Error;

/**
 * Core registration on wp-login.php?action=register, and multisite signups.
 */
final class Registration extends Adapter {

    public function source(): string { return 'registration'; }
    public function label(): string { return 'WordPress registration'; }
    public function detected(): bool { return (bool) get_option( 'users_can_register' ) || is_multisite(); }

    public function register(): void {
        add_filter( 'registration_errors', $this->check( ... ), 99, 3 );
        add_filter( 'wpmu_validate_user_signup', $this->checkMultisite( ... ), 99 );
    }

    private function check( mixed $errors, mixed $login, mixed $email ): mixed {
        if ( ! $errors instanceof WP_Error || $errors->has_errors() ) {
            return $errors;
        }
        if ( $this->evaluate( $this->submission( (string) $login, (string) $email ) )->act ) {
            $errors->add( 'breakwater', $this->message() );
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
        if ( $this->evaluate( $this->submission( (string) $result['user_name'], (string) $result['user_email'] ) )->act ) {
            $errors->add( 'user_name', $this->message() );
        }
        return $result;
    }

    private function submission( string $login, string $email ): Submission {
        return Submission::fromFields( $this->source(), 'wp-login', [
            [ 'type' => 'username', 'label' => 'user_login', 'value' => $login ],
            [ 'type' => 'email', 'label' => 'user_email', 'value' => $email ],
        ] );
    }
}
