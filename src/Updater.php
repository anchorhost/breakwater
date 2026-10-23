<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * Updates for the regular plugin from GitHub: main's manifest.json names the latest version and
 * its release zip, and WordPress offers it like any other update. The plugin's `Update URI`
 * header keeps WordPress.org out of it (a plugin registered there under the same slug can never
 * replace this one) and routes WordPress's own update check here, so GitHub is only asked when
 * WordPress checks for updates, never while an admin page loads. The mu-plugin build leaves this
 * class out (the fleet's copy is updated by redeploying it), and only breakwater.php registers it.
 */
final class Updater {

    public const MANIFEST = 'https://raw.githubusercontent.com/anchorhost/breakwater/main/manifest.json';
    /** Only release assets of this repository are installed, whatever the manifest says. */
    private const RELEASES = 'https://github.com/anchorhost/breakwater/releases/download/';
    private const CACHE    = 'breakwater_update_manifest';
    private const SLUG     = 'breakwater';

    private static string $basename = '';

    public static function register( string $file ): void {
        // A git checkout (the symlinked sandboxes) is never updated: replacing the plugin folder
        // deletes through the symlink into the repository.
        if ( file_exists( dirname( $file ) . '/.git' ) && ! apply_filters( 'breakwater_update_checkout', false ) ) {
            return;
        }
        self::$basename = plugin_basename( $file );
        add_filter( 'update_plugins_github.com', [ self::class, 'offer' ], 10, 3 );
        add_filter( 'plugins_api', [ self::class, 'details' ], 20, 3 );
        add_filter( 'upgrader_source_selection', [ self::class, 'keepFolder' ], 10, 4 );
        add_action( 'upgrader_process_complete', [ self::class, 'purge' ], 10, 2 );
    }

    /**
     * The latest release, cached for 12 hours (an hour after a failed fetch).
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array {
        $cached = get_site_transient( self::CACHE );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $url      = (string) apply_filters( 'breakwater_manifest_url', self::MANIFEST );
        $response = wp_remote_get( $url, [ 'timeout' => 5, 'headers' => [ 'Accept' => 'application/json' ] ] );
        $manifest = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
        $valid    = is_array( $manifest ) && is_string( $manifest['version'] ?? null ) && is_string( $manifest['download_url'] ?? null )
            && str_starts_with( $manifest['download_url'], (string) apply_filters( 'breakwater_release_url', self::RELEASES ) );
        $manifest = $valid ? $manifest : [];
        set_site_transient( self::CACHE, $manifest, $valid ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
        return $manifest;
    }

    /**
     * WordPress's update check for plugins whose Update URI is on github.com. It compares the
     * version and lists this as an update or as "no update" (so the auto-updates toggle shows).
     */
    public static function offer( mixed $update, mixed $plugin_data, mixed $plugin_file ): mixed {
        if ( $plugin_file !== self::$basename ) {
            return $update;
        }
        $manifest = self::manifest();
        if ( ! $manifest ) {
            return $update;
        }
        return [
            'slug'         => self::SLUG,
            'version'      => (string) $manifest['version'],
            'url'          => (string) ( $manifest['homepage'] ?? '' ),
            'package'      => (string) $manifest['download_url'],
            'tested'       => (string) ( $manifest['tested'] ?? '' ),
            'requires'     => (string) ( $manifest['requires'] ?? '' ),
            'requires_php' => (string) ( $manifest['requires_php'] ?? '' ),
        ];
    }

    /** The "View details" box. */
    public static function details( mixed $result, mixed $action, mixed $args ): mixed {
        if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
            return $result;
        }
        $manifest = self::manifest();
        if ( ! $manifest ) {
            return $result;
        }
        return (object) [
            'name'          => (string) ( $manifest['name'] ?? 'Breakwater' ),
            'slug'          => self::SLUG,
            'version'       => (string) $manifest['version'],
            'author'        => (string) ( $manifest['author'] ?? '' ),
            'homepage'      => (string) ( $manifest['homepage'] ?? '' ),
            'requires'      => (string) ( $manifest['requires'] ?? '' ),
            'tested'        => (string) ( $manifest['tested'] ?? '' ),
            'requires_php'  => (string) ( $manifest['requires_php'] ?? '' ),
            'last_updated'  => (string) ( $manifest['last_updated'] ?? '' ),
            'download_link' => (string) $manifest['download_url'],
            'sections'      => array_map( 'strval', (array) ( $manifest['sections'] ?? [] ) ),
        ];
    }

    /**
     * The release zip unpacks to breakwater/. A copy installed under another folder name (GitHub's
     * "Download ZIP" gives breakwater-main/) keeps its folder, or WordPress would deactivate it.
     *
     * @param array<string, mixed> $hook_extra
     */
    public static function keepFolder( mixed $source, mixed $remote_source, mixed $upgrader, mixed $hook_extra ): mixed {
        global $wp_filesystem;
        $folder = dirname( self::$basename );
        if ( ! is_string( $source ) || ! is_array( $hook_extra ) || ( $hook_extra['plugin'] ?? '' ) !== self::$basename || '.' === $folder || basename( $source ) === $folder ) {
            return $source;
        }
        $target = trailingslashit( dirname( $source ) ) . $folder . '/';
        return is_object( $wp_filesystem ) && $wp_filesystem->move( $source, $target, true ) ? $target : $source;
    }

    /** @param array<string, mixed> $options */
    public static function purge( mixed $upgrader, mixed $options ): void {
        if ( is_array( $options ) && 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
            delete_site_transient( self::CACHE );
        }
    }
}
