<?php
/**
 * Scores labelled samples with the plugin's own Checker, content and sender signals only
 * (history has no browser proof). Run inside a lab site with the plugin active:
 *
 *   wp eval-file score.php <samples.jsonl> <results.jsonl> [mx.json]
 */

use Breakwater\Checker;
use Breakwater\Submission;

list( $src, $dst ) = [ $args[0], $args[1] ];
$mx = isset( $args[2] ) && is_readable( $args[2] ) ? json_decode( file_get_contents( $args[2] ), true ) : [];

// Answer the MX cache from the precomputed lookups instead of live DNS.
foreach ( $mx as $domain => $ok ) {
    add_filter( 'pre_transient_breakwater_mx_' . md5( $domain ), function () use ( $ok ) {
        return $ok ? 'yes' : 'no';
    } );
}

// Each sample is judged as if on its own site: its locale and its home URL.
$locale = 'en_US';
$home   = home_url();
add_filter( 'locale', function () use ( &$locale ) {
    return $locale;
}, 999 );
add_filter( 'pre_option_home', function () use ( &$home ) {
    return $home;
}, 999 );

$in  = fopen( $src, 'r' );
$out = fopen( $dst, 'w' );
$n   = 0;
while ( ( $line = fgets( $in ) ) !== false ) {
    $row = json_decode( $line, true );
    if ( ! $row ) {
        continue;
    }
    $locale     = $row['locale'] ?: 'en_US';
    $home       = $row['home'];
    $s          = new Submission();
    $s->source  = 'gf' === $row['kind'] ? 'gravityforms' : 'comment';
    $s->form    = $row['form'];
    $s->ip      = $row['ip'];
    $s->agent   = substr( $row['agent'], 0, 300 );
    $s->email   = $row['email'];
    $s->name    = $row['name'];
    $s->url     = $row['url'];
    $s->content = $row['content'];
    $s->fields  = $row['fields'];
    $verdict    = Checker::check( $s, false );
    fwrite( $out, wp_json_encode( [ 'id' => $row['id'], 'score' => $verdict->score, 'signals' => $verdict->signals ] ) . "\n" );
    ++$n;
}
WP_CLI::success( "Scored {$n} samples." );
