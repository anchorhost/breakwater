<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Submission;
use WP_Error;

/**
 * WooCommerce account registration on the My Account page. Checkout is left to the
 * payment gateway's own fraud checks.
 */
final class WooCommerce extends Adapter {

    public function source(): string { return 'woocommerce'; }
    public function label(): string { return 'WooCommerce registration'; }
    public function detected(): bool { return class_exists( 'WooCommerce' ); }

    public function register(): void {
        add_filter( 'woocommerce_registration_errors', $this->check( ... ), 99, 3 );
    }

    private function check( mixed $errors, mixed $username, mixed $email ): mixed {
        if ( ! isset( $_POST['woocommerce-register-nonce'] ) || ! $errors instanceof WP_Error || $errors->has_errors() ) { // phpcs:ignore WordPress.Security.NonceVerification
            return $errors;
        }
        $s = Submission::fromFields( $this->source(), 'my-account registration', [
            [ 'type' => 'username', 'label' => 'username', 'value' => (string) $username ],
            [ 'type' => 'email', 'label' => 'email', 'value' => (string) $email ],
        ] );
        if ( $this->evaluate( $s )->act ) {
            $errors->add( 'breakwater', $this->message() );
        }
        return $errors;
    }
}
