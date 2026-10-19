#!/bin/bash
# Read-only export for the Breakwater backtest: recent Gravity Forms entries and
# comments with the verdict the site already reached (spam vs kept). One BT| line per site.
cd ~/public 2>/dev/null || cd ~/public_html 2>/dev/null
probe=$(cat <<'PHP'
<?php
global $wpdb;
$p     = $wpdb->prefix;
$since = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
$cap   = 100;
$cut   = function ( $v ) { return mb_substr( (string) $v, 0, 1500 ); };
$o     = [ 'home' => home_url(), 'locale' => get_locale(), 'ct' => defined( 'APBCT_VERSION' ) ? 1 : 0, 'akismet' => defined( 'AKISMET_VERSION' ) ? 1 : 0, 'gf' => [], 'comments' => [] ];
$skip  = [ 'password', 'creditcard', 'captcha', 'html', 'section', 'page', 'signature', 'fileupload', 'post_image', 'turnstile' ];

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'gf_entry' ) ) ) {
    $forms = [];
    foreach ( $wpdb->get_results( "SELECT form_id, display_meta FROM {$p}gf_form_meta" ) as $fm ) {
        $meta = json_decode( $fm->display_meta, true );
        $fields = [];
        foreach ( (array) ( $meta['fields'] ?? [] ) as $f ) {
            $fields[ (string) ( $f['id'] ?? '' ) ] = [ $f['type'] ?? '', mb_substr( wp_strip_all_tags( (string) ( $f['label'] ?? '' ) ), 0, 80 ) ];
        }
        $forms[ (int) $fm->form_id ] = [ 'title' => mb_substr( (string) ( $meta['title'] ?? '' ), 0, 120 ), 'fields' => $fields ];
    }
    foreach ( [ 'spam', 'active', 'trash' ] as $status ) {
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, form_id, date_created, ip, source_url, user_agent, status FROM {$p}gf_entry WHERE status = %s AND date_created > %s ORDER BY id DESC LIMIT %d", $status, $since, 'trash' === $status ? 30 : $cap ) );
        foreach ( $rows as $r ) {
            $form = $forms[ (int) $r->form_id ] ?? [ 'title' => '', 'fields' => [] ];
            $vals = [];
            foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$p}gf_entry_meta WHERE entry_id = %d", $r->id ) ) as $m ) {
                if ( ! preg_match( '/^(\d+)(\.\d+)?$/', $m->meta_key, $mm ) || '' === trim( (string) $m->meta_value ) ) {
                    continue;
                }
                $field = $form['fields'][ $mm[1] ] ?? [ '', '' ];
                if ( in_array( $field[0], $skip, true ) ) {
                    continue;
                }
                $vals[] = [ $m->meta_key, $field[0], $field[1], $cut( $m->meta_value ) ];
            }
            $notes = $wpdb->get_results( $wpdb->prepare( "SELECT user_name u, note_type t, sub_type s, LEFT(value, 160) v FROM {$p}gf_entry_notes WHERE entry_id = %d", $r->id ), ARRAY_A );
            $o['gf'][] = [ 'id' => (int) $r->id, 'form' => (int) $r->form_id, 'title' => $form['title'], 'date' => $r->date_created, 'ip' => $r->ip, 'url' => $cut( $r->source_url ), 'agent' => $cut( $r->user_agent ), 'status' => $r->status, 'vals' => $vals, 'notes' => $notes ];
        }
    }
}

foreach ( [ 'spam' => $cap, '1' => $cap, '0' => 30 ] as $approved => $limit ) {
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT comment_ID id, comment_author a, comment_author_email e, comment_author_url u, comment_author_IP ip, comment_agent ag, comment_content c, comment_approved s, comment_date_gmt d, user_id uid FROM {$wpdb->comments} WHERE comment_approved = %s AND comment_type IN ('', 'comment') AND comment_date_gmt > %s ORDER BY comment_ID DESC LIMIT %d", (string) $approved, $since, $limit ), ARRAY_A );
    foreach ( $rows as $r ) {
        $r['c']  = $cut( $r['c'] );
        $r['ag'] = $cut( $r['ag'] );
        $r['by'] = array_values( array_filter( [ get_comment_meta( $r['id'], 'akismet_result', true ) ? 'akismet' : '', get_comment_meta( $r['id'], 'ct_hash', true ) ? 'cleantalk' : '' ] ) );
        $o['comments'][] = $r;
    }
}
echo 'BT|' . base64_encode( gzcompress( wp_json_encode( $o, JSON_INVALID_UTF8_SUBSTITUTE ), 6 ) ) . "\n";
PHP
)
printf '%s\n' "$probe" | timeout 90 wp eval-file - 2>/dev/null || echo "BT|error"
