<?php
/**
 * Builds the Breakdance test page on a sandbox site: /breakdance-form/, a page built with
 * Breakdance holding one Form Builder (name, email, message; actions Store Submission and
 * Email to owner@<site>.test), saved the way the builder saves it, with its CSS cache made.
 * Run with: wp eval-file tests/setup/breakdance.php   (idempotent: the page is rebuilt in place)
 */

if ( ! function_exists( '\Breakdance\Data\set_meta' ) || ! function_exists( '\Breakdance\Render\generateCacheForPost' ) ) {
    WP_CLI::error( 'Breakdance is not active.' );
}

// The suites open /breakdance-form/: a fresh site has plain permalinks.
if ( '' === (string) get_option( 'permalink_structure' ) ) {
    update_option( 'permalink_structure', '/%postname%/' );
    $GLOBALS['wp_rewrite']->init();
    flush_rewrite_rules( false );
}

// Breakdance without a licence runs in free mode, which disables the submit button of a form
// using a Pro action (Email). The fleet runs Pro: activate a licence on the sandbox
// (Breakdance → Settings → License) before running the suite.
if ( function_exists( '\\Breakdance\\Subscription\\isFreeMode' ) && \Breakdance\Subscription\isFreeMode() ) {
    WP_CLI::warning( 'Breakdance is in free mode here: the form\'s Email action disables its submit button until a licence is active.' );
}

$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$owner = 'owner@' . preg_replace( '/\.localhost$/', '', $host ) . '.test';

$slug    = 'breakdance-form';
$page    = get_page_by_path( $slug );
$page_id = $page ? (int) $page->ID : (int) wp_insert_post( [
    'post_type'   => 'page',
    'post_status' => 'publish',
    'post_name'   => $slug,
    'post_title'  => 'Breakdance form',
] );
if ( ! $page_id ) {
    WP_CLI::error( 'Could not create the page.' );
}

// The Form Builder's own default properties (EssentialElements\FormBuilder::defaultProperties()),
// with the email going to the owner and its From set to the admin address.
$form = [
    'content' => [
        'form'    => [
            'form_name'       => 'Contact Form',
            'fields'          => [
                [ 'type' => 'text', 'label' => 'Name', 'advanced' => [ 'required' => true, 'id' => 'name' ] ],
                [ 'type' => 'email', 'label' => 'Email', 'advanced' => [ 'required' => true, 'id' => 'email' ] ],
                [ 'type' => 'textarea', 'label' => 'Message', 'advanced' => [ 'required' => true, 'id' => 'message' ] ],
            ],
            'submit_text'     => 'Submit',
            'success_message' => 'Your message has been received!',
            'error_message'   => 'Something went wrong',
        ],
        'actions' => [
            'actions'          => [ 'store_submission', 'email' ],
            'store_submission' => [ 'submission_title' => '{email}', 'store_files' => true ],
            'email'            => [
                'emails' => [ [
                    'message'   => '{all_fields}',
                    'subject'   => 'New contact form message',
                    'from'      => (string) get_option( 'admin_email' ),
                    'from_name' => '{name}',
                    'reply_to'  => '{email}',
                    'to'        => $owner,
                ] ],
            ],
        ],
    ],
    'design'  => [ 'form' => [ 'theme' => 'default' ] ],
];

// A root, one Section, the Form Builder inside it; ids as the builder numbers them.
$node = fn ( int $id, string $type, $properties, array $children, int $parent ): array => [
    'id'        => $id,
    'data'      => [ 'type' => $type, 'properties' => $properties ],
    'children'  => $children,
    '_parentId' => $parent,
];
$builder = $node( 101, 'EssentialElements\\FormBuilder', $form, [], 100 );
$section = $node( 100, 'EssentialElements\\Section', null, [ $builder ], 1 );
$root    = [ 'id' => 1, 'data' => [ 'type' => 'root', 'properties' => [] ], 'children' => [ $section ] ];
$tree    = [
    'root'                => $root,
    '_nextNodeId'         => 102,
    'status'              => 'exported',
    'exportedLookupTable' => [ '1' => $root, '100' => $section, '101' => $builder ],
];

\Breakdance\Data\set_meta( $page_id, '_breakdance_data', [ 'tree_json_string' => wp_json_encode( $tree ) ] );
wp_update_post( [ 'ID' => $page_id, 'post_content' => '' ] );
\Breakdance\Render\generateCacheForPost( $page_id );

WP_CLI::success( sprintf( 'Page %d at %s: Form Builder node 101, emails %s.', $page_id, get_permalink( $page_id ), $owner ) );
