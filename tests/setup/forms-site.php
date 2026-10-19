<?php
/**
 * Builds the test forms for the free form plugins on a sandbox site, one page each.
 * Run with: wp eval-file tests/setup/forms-site.php   (idempotent: existing pages are kept)
 */

global $wpdb;

$page = function ( string $slug, string $content ): int {
    $existing = get_page_by_path( $slug );
    if ( $existing ) {
        wp_update_post( [ 'ID' => $existing->ID, 'post_content' => $content ] );
        return $existing->ID;
    }
    return (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $slug, 'post_content' => $content ] );
};
$pages = [];

// Jetpack (offline mode, Forms module on).
$pages['jetpack'] = $page( 'jetpack', '[contact-form subject="Test"][contact-field label="Name" type="name" required="1"/][contact-field label="Email" type="email" required="1"/][contact-field label="Message" type="textarea" required="1"/][/contact-form]' );

// Ninja Forms: its default "Contact Me" form.
$pages['ninja'] = $page( 'ninja', '[ninja_form id=1]' );

// Fluent Forms: its "Contact Form Demo".
$pages['fluent'] = $page( 'fluent', '[fluentform id="1"]' );

// MC4WP hides forms from visitors without an API key: a placeholder one is enough to test,
// since our check runs before MC4WP calls Mailchimp. The form names a list itself.
update_option( 'mc4wp', array_merge( (array) get_option( 'mc4wp', [] ), [ 'api_key' => '00000000000000000000000000000000-us1' ] ) );
$mc = get_posts( [ 'post_type' => 'mc4wp-form', 'numberposts' => 1, 'post_status' => 'any' ] );
$mc_id = $mc ? $mc[0]->ID : wp_insert_post( [
    'post_type'    => 'mc4wp-form',
    'post_status'  => 'publish',
    'post_title'   => 'Newsletter',
    'post_content' => '<p><label>First name <input type="text" name="FNAME"></label></p><p><label>Email <input type="email" name="EMAIL" required></label></p><input type="hidden" name="_mc4wp_lists[]" value="test"><p><input type="submit" value="Sign up"></p>',
] );
$pages['mc4wp'] = $page( 'mc4wp', '[mc4wp_form id="' . $mc_id . '"]' );

// Formidable: tables are created on first admin load; make sure they exist, then build a form.
if ( class_exists( 'FrmAppController' ) ) {
    FrmAppController::install();
    $frm = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}frm_forms WHERE form_key = 'ahpcontact'" );
    if ( ! $frm ) {
        $frm = FrmForm::create( [ 'name' => 'Contact', 'form_key' => 'ahpcontact', 'status' => 'published', 'options' => [ 'submit_value' => 'Send' ] ] );
        foreach ( [ [ 'text', 'Name' ], [ 'email', 'Email' ], [ 'textarea', 'Message' ] ] as $i => [ $type, $label ] ) {
            FrmField::create( [ 'form_id' => $frm, 'type' => $type, 'name' => $label, 'field_key' => 'ahp' . $i, 'required' => 1, 'field_order' => $i ] );
        }
    }
    $pages['formidable'] = $page( 'formidable', '[formidable id=' . (int) $frm . ']' );
}

// Forminator.
if ( class_exists( 'Forminator_API' ) ) {
    $existing = array_filter( (array) Forminator_API::get_forms(), fn ( $f ) => 'AHP Contact' === ( $f->settings['formName'] ?? '' ) );
    $fid      = $existing ? reset( $existing )->id : Forminator_API::add_form( 'AHP Contact', [
        [ 'wrapper_id' => 'wrapper-1', 'fields' => [ [ 'element_id' => 'name-1', 'type' => 'name', 'cols' => 12, 'field_label' => 'Name', 'required' => 'true' ] ] ],
        [ 'wrapper_id' => 'wrapper-2', 'fields' => [ [ 'element_id' => 'email-1', 'type' => 'email', 'cols' => 12, 'field_label' => 'Email', 'required' => 'true' ] ] ],
        [ 'wrapper_id' => 'wrapper-3', 'fields' => [ [ 'element_id' => 'textarea-1', 'type' => 'textarea', 'cols' => 12, 'field_label' => 'Message', 'required' => 'true' ] ] ],
    ], [ 'formName' => 'AHP Contact', 'submission-behaviour' => 'behaviour-thankyou', 'thankyou-message' => 'Thanks Forminator', 'enable-ajax' => 'true' ] );
    $pages['forminator'] = $page( 'forminator', '[forminator_form id="' . (int) $fid . '"]' );
}

// MailPoet: one form subscribing to the newsletter list.
if ( class_exists( '\MailPoet\DI\ContainerWrapper' ) ) {
    $repo = \MailPoet\DI\ContainerWrapper::getInstance()->get( \MailPoet\Form\FormsRepository::class );
    $form = $repo->findOneBy( [ 'name' => 'AHP Newsletter' ] );
    if ( ! $form ) {
        $form = new \MailPoet\Entities\FormEntity( 'AHP Newsletter' );
        $form->setBody( [
            [ 'type' => 'text', 'name' => 'First name', 'id' => 'first_name', 'unique' => '1', 'static' => '0', 'params' => [ 'label' => 'First name' ] ],
            [ 'type' => 'text', 'name' => 'Email', 'id' => 'email', 'unique' => '0', 'static' => '1', 'params' => [ 'label' => 'Email', 'required' => '1' ] ],
            [ 'type' => 'submit', 'name' => 'Submit', 'id' => 'submit', 'unique' => '0', 'static' => '1', 'params' => [ 'label' => 'Subscribe' ] ],
        ] );
        $segment = (string) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}mailpoet_segments WHERE type = 'default' LIMIT 1" );
        $form->setSettings( [ 'segments' => [ $segment ], 'segments_selected_by' => 'admin', 'on_success' => 'message', 'success_message' => 'Thanks MailPoet' ] );
        $form->setStatus( \MailPoet\Entities\FormEntity::STATUS_ENABLED );
        $repo->persist( $form );
        $repo->flush();
    }
    // MailPoet's own CAPTCHA would stop the test before our check.
    \MailPoet\Settings\SettingsController::getInstance()->set( 'captcha.type', 'disabled' );
    $pages['mailpoet'] = $page( 'mailpoet', '[mailpoet_form id="' . $form->getId() . '"]' );
}

// Constant Contact: works without an account (submissions are emailed to the admin).
$ctct = get_posts( [ 'post_type' => 'ctct_forms', 'numberposts' => 1, 'post_status' => 'any' ] );
$ctct_id = $ctct ? $ctct[0]->ID : wp_insert_post( [ 'post_type' => 'ctct_forms', 'post_status' => 'publish', 'post_title' => 'AHP CTCT' ] );
update_post_meta( $ctct_id, 'custom_fields_group', [
    [ '_ctct_field_label' => 'First Name', '_ctct_map_select' => 'first_name' ],
    [ '_ctct_field_label' => 'Email', '_ctct_map_select' => 'email', '_ctct_required_field' => 'on' ],
    [ '_ctct_field_label' => 'Message', '_ctct_map_select' => 'custom_text_area' ],
] );
if ( ! get_post_meta( $ctct_id, '_ctct_verify_key', true ) ) {
    update_post_meta( $ctct_id, '_ctct_verify_key', wp_generate_password( 25, false ) ); // Set by CTCT's builder on save.
}
$pages['ctct'] = $page( 'ctct', '[ctct form="' . $ctct_id . '" show_title="false"]' );

// Kadence: the Advanced Form (a kadence_form post) renders on the server from its blocks.
$kf = get_posts( [ 'post_type' => 'kadence_form', 'numberposts' => 1, 'post_status' => 'any' ] );
$kf_id = $kf ? $kf[0]->ID : wp_insert_post( [ 'post_type' => 'kadence_form', 'post_status' => 'publish', 'post_title' => 'AHP Kadence' ] );
// The form post's own content is the advanced-form block wrapping its field blocks.
wp_update_post( [
    'ID'           => $kf_id,
    // The wrapper carries no "id": pointing the form at itself makes Kadence's CSS pass recurse.
    'post_content' => '<!-- wp:kadence/advanced-form {"uniqueID":"ahp-kform"} -->'
        . '<!-- wp:kadence/advanced-form-text {"uniqueID":"ahp-k1","label":"Name","required":true} /-->'
        . '<!-- wp:kadence/advanced-form-email {"uniqueID":"ahp-k2","label":"Email","required":true} /-->'
        . '<!-- wp:kadence/advanced-form-textarea {"uniqueID":"ahp-k3","label":"Message","required":true} /-->'
        . '<!-- wp:kadence/advanced-form-submit {"uniqueID":"ahp-k4","text":"Submit"} /-->'
        . '<!-- /wp:kadence/advanced-form -->',
] );
$pages['kadence'] = $page( 'kadence', '<!-- wp:kadence/advanced-form {"id":' . $kf_id . ',"uniqueID":"ahp-kform"} /-->' );

// Ultimate Member: default forms and pages, open registration.
if ( function_exists( 'UM' ) ) {
    update_option( 'users_can_register', 1 );
    wp_set_current_user( 1 ); // UM only installs its default forms for an administrator.
    UM()->setup()->run_setup();
    UM()->setup()->install_default_forms();
    $reg = $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_um_mode' AND m.meta_value = 'register' WHERE p.post_type = 'um_form' LIMIT 1" );
    $pages['um'] = $page( 'um-register', '[ultimatemember form_id="' . (int) $reg . '"]' );
}

foreach ( $pages as $name => $id ) {
    WP_CLI::line( $name . "\t" . get_permalink( $id ) );
}
