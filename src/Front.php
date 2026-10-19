<?php

declare(strict_types=1);

namespace Breakwater;

use WP_REST_Response;

/**
 * The browser side: the script on pages with forms and the challenge endpoint it calls.
 */
final class Front {

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
        add_action( 'login_enqueue_scripts', [ self::class, 'enqueue' ] );
    }

    public static function routes(): void {
        register_rest_route( 'breakwater/v1', '/challenge', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'challenge' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function challenge(): WP_REST_Response {
        nocache_headers();
        $response = new WP_REST_Response( Challenge::issue() );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'X-Robots-Tag', 'noindex' );
        return $response;
    }

    public static function enqueue(): void {
        if ( 'login_enqueue_scripts' === current_action() && 'register' !== sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        Assets::enqueueScript();
        // Static per version, so cached pages stay valid: the challenge itself is fetched live.
        wp_add_inline_script( 'breakwater', 'window.breakwater=' . wp_json_encode( [
            'endpoint' => rest_url( 'breakwater/v1/challenge' ),
        ] ) . ';', 'before' );
    }
}
