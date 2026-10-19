<?php

declare(strict_types=1);

namespace Breakwater;

use Breakwater\Adapters\Registry;

/**
 * Settings, mode and wiring.
 */
final class Plugin {

    public const VERSION = '0.2.0';
    public const OPTION  = 'breakwater';

    private static string $file = '';

    public static function boot( string $file ): void {
        self::$file = $file;
        register_activation_hook( $file, [ Log::class, 'install' ] );
        register_deactivation_hook( $file, [ self::class, 'deactivate' ] );
        add_action( 'plugins_loaded', [ self::class, 'init' ], 20 );
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'breakwater', Cli::class );
        }
    }

    public static function init(): void {
        Log::maybeInstall();
        Admin::register();
        Delivery::register();
        Digest::register();

        add_action( 'breakwater_cleanup', [ Log::class, 'cleanup' ] );
        if ( ! wp_next_scheduled( 'breakwater_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'breakwater_cleanup' );
        }

        if ( Mode::Off === self::mode() ) {
            return;
        }
        Front::register();
        Registry::register();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'breakwater_cleanup' );
        wp_clear_scheduled_hook( Digest::HOOK );
    }

    public static function file(): string {
        return self::$file;
    }

    public static function dir(): string {
        return dirname( self::$file );
    }

    /** @return array{mode:string} */
    public static function settings(): array {
        $settings = get_option( self::OPTION, [] );
        return array_merge( [ 'mode' => Mode::Auto->value ], is_array( $settings ) ? $settings : [] );
    }

    /**
     * The configured mode, before Auto is resolved. BREAKWATER_MODE wins over the setting
     * so the fleet can pin a mode from wp-config.php or an mu-plugin.
     */
    public static function configuredMode(): Mode {
        $value = defined( 'BREAKWATER_MODE' ) ? (string) BREAKWATER_MODE : (string) self::settings()['mode'];
        return Mode::tryFrom( $value ) ?? Mode::Auto;
    }

    public static function mode(): Mode {
        $mode = self::configuredMode();
        if ( Mode::Auto === $mode ) {
            return self::cleantalkActive() ? Mode::Monitor : Mode::Protect;
        }
        return $mode;
    }

    public static function cleantalkActive(): bool {
        return defined( 'APBCT_VERSION' );
    }

    /**
     * The other spam service on this site, if any, whose verdicts are logged next to ours.
     */
    public static function otherChecker(): string {
        if ( self::cleantalkActive() ) {
            return 'CleanTalk';
        }
        if ( defined( 'AKISMET_VERSION' ) && get_option( 'wordpress_api_key' ) ) {
            return 'Akismet';
        }
        return '';
    }

    /**
     * Per-site secret, derived from the site's own salts. Never leaves the site.
     */
    public static function secret(): string {
        return hash_hmac( 'sha256', 'breakwater', wp_salt( 'secure_auth' ) );
    }
}
