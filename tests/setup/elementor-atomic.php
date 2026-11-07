<?php
/**
 * Builds /atomic-form/ on protect.localhost: an Elementor 4 atomic form (the V4 editor's Form
 * element) made from Elementor's own default children (names, email, message, a checkbox, submit)
 * with its default Email action. Needs the hidden Atomic Widgets experiment on, which this turns on:
 *
 *   wp option update elementor_experiment-e_atomic_elements active
 *   wp eval-file tests/setup/elementor-atomic.php
 */

$class = 'Elementor\Modules\AtomicWidgets\Elements\Atomic_Form\Atomic_Form';
if ( ! class_exists( $class ) ) {
	WP_CLI::error( 'Atomic form class missing: turn on elementor_experiment-e_atomic_elements first.' );
}
$form     = new $class( [ 'id' => 'bwatomic', 'elType' => 'e-form', 'settings' => [], 'elements' => [] ], [] );
$children = new ReflectionMethod( $class, 'define_default_children' );
$children->setAccessible( true );

$number = 0;
$ids    = static function ( array $elements ) use ( &$ids, &$number ): array {
	foreach ( $elements as &$element ) {
		$element['id']       = $element['id'] ?? sprintf( 'bwa%05d', ++$number );
		$element['elements'] = $ids( $element['elements'] ?? [] );
	}
	return $elements;
};
$data = [ [ 'id' => 'bwatomic', 'elType' => 'e-form', 'isInner' => false, 'settings' => [], 'elements' => $ids( $children->invoke( $form ) ) ] ];

$page = get_page_by_path( 'atomic-form' );
$id   = $page ? $page->ID : wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Atomic form', 'post_name' => 'atomic-form' ] );
update_post_meta( $id, '_elementor_edit_mode', 'builder' );
update_post_meta( $id, '_elementor_template_type', 'wp-page' );
update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
delete_post_meta( $id, '_elementor_element_cache' );
WP_CLI::success( "Atomic form page $id: " . get_permalink( $id ) );
