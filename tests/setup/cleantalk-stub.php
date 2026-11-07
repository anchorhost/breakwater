<?php
/**
 * Plugin Name: CleanTalk stand-in (Breakwater tests)
 * Description: Behaves like CleanTalk where CleanTalkWatch looks: defines APBCT_VERSION, keeps the
 * same all-time counters in $apbct->data, marks Gravity Forms entries as spam at priority 999 and
 * ends Elementor's AJAX request at priority 1, as CleanTalk 6.9x does. "Spam" is any submission
 * containing "first page of google" (the suites' spammer). Copy into wp-content/mu-plugins for a
 * run and delete it after: while it is there, Auto mode resolves to Monitor.
 */

if ( defined( 'APBCT_VERSION' ) ) {
	return; // The real CleanTalk is active.
}
define( 'APBCT_VERSION', 'stub' );

$GLOBALS['apbct'] = new class() {
	public ArrayObject $data;
	public function __construct() {
		$this->data = new ArrayObject( [ 'admin_bar__all_time_counter' => [ 'accepted' => 0, 'blocked' => 0 ] ] );
	}
};

function bw_cleantalk_stub_judge(): bool {
	global $apbct;
	$spam    = false !== stripos( (string) wp_json_encode( wp_unslash( $_POST ) ), 'first page of google' );
	$counter = $apbct->data['admin_bar__all_time_counter'];
	++$counter[ $spam ? 'blocked' : 'accepted' ];
	$apbct->data['admin_bar__all_time_counter'] = $counter;
	return $spam;
}

add_filter( 'gform_entry_is_spam', static fn ( $is_spam ) => bw_cleantalk_stub_judge() ?: $is_spam, 999 );

foreach ( [ 'wp_ajax_elementor_pro_forms_send_form', 'wp_ajax_nopriv_elementor_pro_forms_send_form' ] as $bw_hook ) {
	add_action( $bw_hook, static function () {
		if ( bw_cleantalk_stub_judge() ) {
			wp_send_json_error( [ 'message' => 'Blocked by CleanTalk (stand-in).' ] );
		}
	}, 1 );
}
