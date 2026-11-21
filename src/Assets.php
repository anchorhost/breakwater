<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * The script, the admin stylesheet, the mark and the disposable-domain list. As a regular
 * plugin they are files next to the code; the single-file mu-plugin build (bin/build-mu-plugin.php)
 * embeds them in a Bundle class instead, and this is the only place that knows the difference.
 */
final class Assets {

    public static function bundled(): bool {
        return class_exists( Bundle::class, false );
    }

    public static function enqueueScript(): void {
        if ( self::bundled() ) {
            wp_register_script( 'breakwater', false, [], Plugin::VERSION, [ 'in_footer' => true ] );
            wp_enqueue_script( 'breakwater' );
            wp_add_inline_script( 'breakwater', Bundle::SCRIPT );
            return;
        }
        wp_enqueue_script( 'breakwater', plugins_url( 'assets/breakwater.js', Plugin::file() ), [], Plugin::VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
    }

    /**
     * Keep script optimizers (WP Rocket, LiteSpeed, Cloudflare Rocket Loader, Perfmatters) from
     * delaying Breakwater: delaying it resets the page timer on first click and can drop proof.
     */
    public static function registerExclusions(): void {
        add_filter( 'wp_script_attributes', [ self::class, 'scriptAttributes' ] );
        add_filter( 'wp_inline_script_attributes', [ self::class, 'scriptAttributes' ] );
        foreach ( [ 'rocket_delay_js_exclusions', 'rocket_exclude_js', 'rocket_exclude_defer_js', 'litespeed_optimize_js_excludes' ] as $hook ) {
            add_filter( $hook, [ self::class, 'exclude' ] );
        }
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public static function scriptAttributes( mixed $attrs ): mixed {
        if ( is_array( $attrs ) && in_array( $attrs['id'] ?? '', [ 'breakwater-js', 'breakwater-js-before', 'breakwater-js-after' ], true ) ) {
            $attrs['data-no-optimize'] = '1';
            $attrs['data-cfasync']     = 'false';
            $attrs['data-nowprocket']  = '';
        }
        return $attrs;
    }

    /** @param list<string> $list */
    public static function exclude( mixed $list ): mixed {
        if ( is_array( $list ) ) {
            $list[] = 'breakwater';
        }
        return $list;
    }

    public static function enqueueAdminStyle(): void {
        if ( self::bundled() ) {
            wp_register_style( 'breakwater-admin', false, [], Plugin::VERSION );
            wp_enqueue_style( 'breakwater-admin' );
            wp_add_inline_style( 'breakwater-admin', Bundle::ADMIN_CSS );
            return;
        }
        wp_enqueue_style( 'breakwater-admin', plugins_url( 'assets/admin.css', Plugin::file() ), [], Plugin::VERSION );
    }

    public static function markUrl(): string {
        return self::bundled()
            ? 'data:image/svg+xml;base64,' . base64_encode( Bundle::MARK )
            : plugins_url( 'assets/mark.svg', Plugin::file() );
    }

    /** @return list<string> */
    public static function disposableDomains(): array {
        if ( self::bundled() ) {
            return preg_split( '/\R/', Bundle::DISPOSABLE, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
        }
        $file = Plugin::dir() . '/data/disposable-domains.txt';
        return is_readable( $file ) ? ( file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [] ) : [];
    }
}
