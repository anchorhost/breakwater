<?php

declare(strict_types=1);

namespace Breakwater;

use Breakwater\Adapters\Registry;
use WP_REST_Response;

/**
 * The browser side: the script on pages with forms and the challenge endpoint it calls.
 */
final class Front {

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
        add_filter( 'rest_authentication_errors', [ self::class, 'allowChallenge' ], PHP_INT_MAX );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
        add_action( 'login_enqueue_scripts', [ self::class, 'enqueue' ] );
        Assets::registerExclusions();
    }

    /**
     * Security and optimization plugins that disable the REST API for logged-out visitors return
     * a WP_Error here, causing every real visitor to score pow_pending. The challenge endpoint
     * is stateless and exposes no site or user data, so always let GET requests to it through.
     */
    public static function allowChallenge( mixed $result ): mixed {
        if ( ! is_wp_error( $result ) || 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            return $result;
        }
        $route = (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' );
        if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
            $path   = (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
            $prefix = '/' . trim( rest_get_url_prefix(), '/' );
            if ( str_starts_with( $path, $prefix . '/' ) ) {
                $route = substr( $path, strlen( $prefix ) );
            }
        }
        return '/breakwater/v1/challenge' === '/' . trim( $route, '/' ) ? true : $result;
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
        if ( Guard::trusted() ) {
            return; // Editors and admins are never checked; leave front-end builders untouched.
        }
        if ( 'login_enqueue_scripts' === current_action() && 'register' !== sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $selectors = Registry::formSelectors();
        if ( ! $selectors ) {
            return;
        }
        // Static per version, so cached pages stay valid: the challenge itself is fetched live.
        Assets::enqueueScript( [
            'endpoint' => rest_url( 'breakwater/v1/challenge' ),
            'forms'    => implode( ', ', $selectors ),
        ] );
    }
}
