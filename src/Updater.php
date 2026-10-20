<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * Updates for the regular plugin from GitHub: main's manifest.json names the latest version and
 * its release zip, and WordPress offers it like any other update. The mu-plugin build leaves this
 * class out (the fleet's copy is updated by redeploying it), and only breakwater.php registers it.
 */
final class Updater {

    public const MANIFEST = 'https://raw.githubusercontent.com/anchorhost/breakwater/main/manifest.json';
    private const CACHE   = 'breakwater_update_manifest';
    private const SLUG    = 'breakwater';

    private static string $basename = '';

    public static function register( string $file ): void {
        // A git checkout (the symlinked sandboxes) is never updated: replacing the plugin folder
        // deletes through the symlink into the repository.
        if ( file_exists( dirname( $file ) . '/.git' ) && ! apply_filters( 'breakwater_update_checkout', false ) ) {
            return;
        }
        self::$basename = plugin_basename( $file );
        add_filter( 'site_transient_update_plugins', [ self::class, 'offer' ] );
        add_filter( 'plugins_api', [ self::class, 'details' ], 20, 3 );
        add_action( 'upgrader_process_complete', [ self::class, 'purge' ], 10, 2 );
    }

    /**
     * The latest release, cached for 12 hours (an hour after a failed fetch, so a missing or
     * private manifest isn't asked for on every admin page).
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array {
        $cached = get_site_transient( self::CACHE );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $url      = (string) apply_filters( 'breakwater_manifest_url', self::MANIFEST );
        $response = wp_remote_get( $url, [ 'timeout' => 10, 'headers' => [ 'Accept' => 'application/json' ] ] );
        $manifest = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
        $valid    = is_array( $manifest ) && is_string( $manifest['version'] ?? null ) && is_string( $manifest['download_url'] ?? null );
        $manifest = $valid ? $manifest : [];
        set_site_transient( self::CACHE, $manifest, $valid ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
        return $manifest;
    }

    public static function offer( mixed $transient ): mixed {
        if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
            return $transient;
        }
        $manifest = self::manifest();
        $item     = (object) [
            'id'           => 'github.com/anchorhost/breakwater',
            'slug'         => self::SLUG,
            'plugin'       => self::$basename,
            'new_version'  => (string) ( $manifest['version'] ?? Plugin::VERSION ),
            'url'          => (string) ( $manifest['homepage'] ?? '' ),
            'package'      => (string) ( $manifest['download_url'] ?? '' ),
            'tested'       => (string) ( $manifest['tested'] ?? '' ),
            'requires'     => (string) ( $manifest['requires'] ?? '' ),
            'requires_php' => (string) ( $manifest['requires_php'] ?? '' ),
        ];
        // Listed as "no update" otherwise, so the auto-updates toggle shows for it.
        if ( $manifest && version_compare( Plugin::VERSION, $item->new_version, '<' ) ) {
            $transient->response[ self::$basename ] = $item;
        } else {
            $transient->no_update[ self::$basename ] = $item;
        }
        return $transient;
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

    /** @param array<string, mixed> $options */
    public static function purge( mixed $upgrader, mixed $options ): void {
        if ( is_array( $options ) && 'update' === ( $options['action'] ?? '' ) && 'plugin' === ( $options['type'] ?? '' ) ) {
            delete_site_transient( self::CACHE );
        }
    }
}
