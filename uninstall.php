<?php
/**
 * Deleting the plugin removes what it kept: the log (with everything visitors sent), its
 * settings, the trusted senders and fleet list, and its scheduled events. Kept when the
 * must-use build is installed too, since that copy still uses them.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( file_exists( WPMU_PLUGIN_DIR . '/breakwater.php' ) ) {
    return;
}

function breakwater_uninstall_site() {
    global $wpdb;
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'breakwater_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    foreach ( array( 'breakwater', 'breakwater_db_version', 'breakwater_reputation', 'breakwater_trusted' ) as $option ) {
        delete_option( $option );
    }
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_breakwater\\_%' OR option_name LIKE '\\_transient\\_timeout\\_breakwater\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    foreach ( array( 'breakwater_log_id', 'breakwater_prior_approved' ) as $key ) {
        delete_metadata( 'comment', 0, $key, '', true );
        delete_metadata( 'post', 0, $key, '', true );
    }
    wp_clear_scheduled_hook( 'breakwater_cleanup' );
    wp_clear_scheduled_hook( 'breakwater_digest' );
}

if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
        switch_to_blog( $site_id );
        breakwater_uninstall_site();
        restore_current_blog();
    }
} else {
    breakwater_uninstall_site();
}
delete_site_transient( 'breakwater_update_manifest' );
