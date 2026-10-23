<?php
/**
 * Plugin Name:       Breakwater
 * Plugin URI:        https://github.com/anchorhost/breakwater
 * Description:       Spam protection for comments, registrations and contact forms. Works on its own: no API keys, no accounts, no setup.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Anchor Hosting
 * Author URI:        https://anchor.host
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       breakwater
 * Update URI:        https://github.com/anchorhost/breakwater
 */

// This file stays PHP 5.6-parseable so an old PHP gets a notice instead of a fatal error.

defined( 'ABSPATH' ) || exit;

// Loaded once even when present as both a regular plugin and an mu-plugin.
if ( defined( 'BREAKWATER_FILE' ) ) {
    return;
}
define( 'BREAKWATER_FILE', __FILE__ );

if ( PHP_VERSION_ID < 80100 ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-warning"><p>Breakwater needs PHP 8.1 or newer and is not running.</p></div>';
    } );
    return;
}

spl_autoload_register( function ( $class ) {
    $prefix = 'Breakwater\\';
    if ( 0 === strncmp( $class, $prefix, strlen( $prefix ) ) ) {
        $file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
        if ( is_readable( $file ) ) {
            require $file;
        }
    }
} );

Breakwater\Plugin::boot( __FILE__ );

// Updates from GitHub releases. The mu-plugin build has its own bootstrap and leaves this out.
Breakwater\Updater::register( __FILE__ );
