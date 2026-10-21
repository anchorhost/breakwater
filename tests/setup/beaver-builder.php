<?php
/**
 * Builds the Beaver Builder test pages on a sandbox: /bb-contact/ with a Contact Form module
 * (name, subject, email, message; mail to owner@protect-bb.test) and /bb-subscribe/ with a
 * Subscribe Form module on BB's "Email Address" service (it emails each sign-up to an address,
 * so it needs no outside account and every step runs through BB's own service code).
 *
 * Layouts are written through BB's own model and published with FLBuilderModel::save_layout(),
 * the way the editor's Publish button stores them (_fl_builder_data, _fl_builder_data_settings,
 * _fl_builder_enabled). Rerunning updates the same pages and modules (node IDs are kept).
 *
 * Run with: wp eval-file tests/setup/beaver-builder.php
 */

if ( ! class_exists( 'FLBuilderModel' ) ) {
    WP_CLI::error( 'Beaver Builder is not active.' );
}

const BW_BB_OWNER = 'owner@protect-bb.test';

// The suites open /bb-contact/ and /bb-subscribe/: pretty permalinks on a fresh sandbox.
if ( '' === (string) get_option( 'permalink_structure' ) ) {
    update_option( 'permalink_structure', '/%postname%/' );
    flush_rewrite_rules( false );
}

// BB's "Email Address" service account the subscribe form uses.
FLBuilderModel::update_services( 'email-address', 'Breakwater test', [ 'email' => BW_BB_OWNER ] );

// Edits go to the draft layout, as in the editor; save_layout() then publishes it.
add_filter( 'fl_builder_node_status', fn () => 'draft' );

$build = function ( string $slug, string $title, string $type, array $settings ): array {
    $page = get_page_by_path( $slug );
    $id   = $page ? (int) $page->ID : (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title, 'post_content' => '' ] );
    FLBuilderModel::set_post_id( $id );

    $data = FLBuilderModel::get_layout_data( 'draft', $id );
    if ( ! $data ) {
        $data = FLBuilderModel::get_layout_data( 'published', $id );
        FLBuilderModel::update_layout_data( $data, 'draft', $id );
        FLBuilderModel::update_layout_settings( FLBuilderModel::get_layout_settings( 'published', $id ), 'draft', $id );
    }
    $node = null;
    foreach ( $data as $item ) {
        if ( 'module' === ( $item->type ?? '' ) && $type === ( $item->settings->type ?? '' ) ) {
            $node = $item->node;
            break;
        }
    }
    if ( $node ) {
        foreach ( $settings as $key => $value ) {
            $data[ $node ]->settings->$key = $value;
        }
        FLBuilderModel::update_layout_data( $data, 'draft', $id );
    } else {
        $defaults = FLBuilderModel::get_module_defaults( $type );
        foreach ( $settings as $key => $value ) {
            $defaults->$key = $value;
        }
        $row    = FLBuilderModel::add_row( '1-col' );
        $groups = FLBuilderModel::get_nodes( 'column-group', $row->node );
        $cols   = FLBuilderModel::get_nodes( 'column', array_shift( $groups )->node );
        $node   = FLBuilderModel::add_module( $type, $defaults, array_shift( $cols )->node )->node;
    }
    $GLOBALS['post'] = get_post( $id ); // save_layout() renders the editor content from it.
    FLBuilderModel::save_layout( true );
    FLBuilderModel::reset_post_id();
    return [ $id, $node ];
};

[ $contact, $contact_node ] = $build( 'bb-contact', 'BB Contact', 'contact-form', [
    'mailto_email'     => BW_BB_OWNER,
    'name_toggle'      => 'show',
    'subject_toggle'   => 'show',
    'email_toggle'     => 'show',
    'phone_toggle'     => 'hide',
    'terms_checkbox'   => 'hide',
    'recaptcha_toggle' => 'hide',
    'success_action'   => 'show_message',
    'success_message'  => 'Thanks, your message was sent.',
] );

[ $subscribe, $subscribe_node ] = $build( 'bb-subscribe', 'BB Subscribe', 'subscribe-form', [
    'service'          => 'email-address',
    'service_account'  => 'Breakwater test',
    'show_name'        => 'show',
    'terms_checkbox'   => 'hide',
    'show_recaptcha'   => 'hide',
    'custom_subject'   => 'New newsletter sign-up',
    'success_action'   => 'message',
    'success_message'  => 'Thanks for subscribing.',
] );

WP_CLI::success( sprintf( '/bb-contact/ = page %d (module %s), /bb-subscribe/ = page %d (module %s)', $contact, $contact_node, $subscribe, $subscribe_node ) );
