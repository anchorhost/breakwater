<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Submission;
use WP_Error;

/**
 * WooCommerce account registration on the My Account page. Checkout is left to the
 * payment gateway's own fraud checks. A held registration is refused with an error;
 * released, the customer account is created the way WooCommerce would have, with its
 * welcome email carrying a set-password link. Nobody is logged in.
 */
final class WooCommerce extends Adapter {

    public function source(): string { return 'woocommerce'; }
    public function label(): string { return 'WooCommerce registration'; }
    public function detected(): bool { return class_exists( 'WooCommerce' ); }

    public function register(): void {
        add_filter( 'woocommerce_registration_errors', $this->check( ... ), 99, 3 );
    }

    /**
     * wc_create_new_customer() is what the My Account form calls. The password is never kept,
     * so it is left empty: WooCommerce generates one and its new-account email links to set
     * a password. It runs woocommerce_created_customer (that email, webhooks, integrations).
     */
    public function release( object $row ): ?string {
        $data  = Log::replay( $row );
        $email = (string) ( $data['email'] ?? '' );
        if ( ! function_exists( 'wc_create_new_customer' ) || ! is_email( $email ) || 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) ) {
            return null;
        }
        $user_id = wc_create_new_customer( $email, (string) ( $data['username'] ?? '' ), '' );
        if ( ! is_int( $user_id ) || ! $user_id ) {
            return null; // WP_Error: the email or username was registered since, or another check refused it.
        }
        Log::link( (int) $row->id, (string) $user_id );
        $user = get_userdata( $user_id );
        return sprintf( 'Customer account created for %s, and WooCommerce\'s welcome email with a set-password link went to %s.', $user ? $user->user_login : $email, $email );
    }

    private function check( mixed $errors, mixed $username, mixed $email ): mixed {
        if ( ! isset( $_POST['woocommerce-register-nonce'] ) || ! $errors instanceof WP_Error || $errors->has_errors() ) { // phpcs:ignore WordPress.Security.NonceVerification
            return $errors;
        }
        $s = Submission::fromFields( $this->source(), 'my-account registration', [
            [ 'type' => 'username', 'label' => 'username', 'value' => (string) $username ],
            [ 'type' => 'email', 'label' => 'email', 'value' => (string) $email ],
        ] );
        $verdict = $this->evaluate( $s );
        if ( $verdict->act ) {
            $errors->add( 'breakwater', $this->message( 'account' ) );
            // A generated username is made again on release; one the visitor typed is kept.
            Log::stash( $verdict->log_id, array_filter( [
                'email'    => (string) $email,
                'username' => 'no' === get_option( 'woocommerce_registration_generate_username' ) ? (string) $username : '',
            ] ) );
        }
        return $errors;
    }
}
