<?php
/**
 * Cases for Checker::inconsistencies(): does the browser match its user agent?
 *
 *     php tests/consistency.php
 */

declare(strict_types=1);

const DAY_IN_SECONDS = 86400;
require dirname( __DIR__ ) . '/src/Checker.php';

use Breakwater\Checker;

$now     = gmmktime( 12, 0, 0, 10, 22, 2026 ); // Chrome 155 is current.
$chrome  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36';
$old     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
$android = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Mobile Safari/537.36';
$webview = 'Mozilla/5.0 (Linux; Android 14; Pixel 8; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/154.0.0.0 Mobile Safari/537.36';
$firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:144.0) Gecko/20100101 Firefox/144.0';
$safari  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15';
$iphone  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_3_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/154.0.0.0 Mobile/15E148 Safari/604.1';
$old_ios = 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Mobile/15E148 Safari/604.1';

$fetch = [ 'https' => '1', 'fetch_site' => 'same-origin', 'fetch_mode' => 'navigate', 'fetch_dest' => 'document', 'language' => 'en-US,en;q=0.9' ];
$hints = $fetch + [ 'ch_ua' => '"Chromium";v="154", "Google Chrome";v="154", "Not=A?Brand";v="24"', 'ch_mobile' => '?0', 'ch_platform' => '"Windows"' ];
$meta  = fn ( string $agent ): array => [ 'e' => 9000, 'u' => hash( 'fnv1a32', $agent ), 'v' => 3 ];

$cases = [
    'current Chrome, every header'           => [ $chrome, $hints, $meta( $chrome ), [] ],
    'current Chrome, plain http'             => [ $chrome, [ 'https' => '0' ], $meta( $chrome ), [] ],
    'Chrome a year and a half old'           => [ $old, [ 'ch_ua' => '"Chromium";v="120"', 'ch_mobile' => '?0', 'ch_platform' => '"Windows"' ] + $fetch, $meta( $old ), [ 'stale_browser' ] ],
    'Chrome agent, no browser headers'       => [ $chrome, [ 'https' => '1' ], null, [ 'no_fetch_metadata', 'client_hints', 'no_language' ] ],
    'headless Chrome posing as Chrome'       => [ $chrome, [ 'ch_ua' => '"HeadlessChrome";v="154", "Chromium";v="154"' ] + $hints, $meta( $chrome ), [ 'client_hints' ] ],
    'no Accept-Language'                     => [ $chrome, array_diff_key( $hints, [ 'language' => 1 ] ), $meta( $chrome ), [ 'no_language' ] ],
    'hints disagree on the version'          => [ $chrome, [ 'ch_ua' => '"Chromium";v="131"' ] + $hints, $meta( $chrome ), [ 'client_hints' ] ],
    'hints say Linux, agent says Windows'    => [ $chrome, [ 'ch_platform' => '"Linux"' ] + $hints, $meta( $chrome ), [ 'client_hints' ] ],
    'Android phone'                          => [ $android, [ 'ch_ua' => '"Chromium";v="154"', 'ch_mobile' => '?1', 'ch_platform' => '"Android"' ] + $fetch, $meta( $android ), [] ],
    'hints say desktop, agent says phone'    => [ $android, [ 'ch_ua' => '"Chromium";v="154"', 'ch_mobile' => '?0', 'ch_platform' => '"Android"' ] + $fetch, $meta( $android ), [ 'client_hints' ] ],
    'Android web view without hints'         => [ $webview, $fetch, $meta( $webview ), [] ],
    'Firefox'                                => [ $firefox, $fetch, $meta( $firefox ), [] ],
    'Firefox agent sending client hints'     => [ $firefox, $hints, $meta( $firefox ), [ 'client_hints' ] ],
    'Safari'                                 => [ $safari, $fetch, $meta( $safari ), [] ],
    'Chrome on iPhone is Safari underneath'  => [ $iphone, $fetch, $meta( $iphone ), [] ],
    'iOS 15 sends no fetch metadata'         => [ $old_ios, [ 'https' => '1', 'language' => 'en-US' ], $meta( $old_ios ), [] ],
    'script saw another agent'               => [ $chrome, $hints, $meta( $old ), [ 'agent_mismatch' ] ],
    'older script without the hash'          => [ $chrome, $hints, [ 'e' => 9000, 'v' => 2 ], [] ],
    'no agent at all'                        => [ '', [ 'https' => '1' ], null, [] ],
    'unknown agent'                          => [ 'Mozilla/5.0', [ 'https' => '1' ], null, [] ],
];

$fail = 0;
foreach ( $cases as $name => [ $agent, $headers, $browser_meta, $want ] ) {
    $got = array_keys( Checker::inconsistencies( $agent, $headers, $browser_meta, $now ) );
    sort( $got );
    sort( $want );
    if ( $got !== $want ) {
        $fail++;
        printf( "FAIL %s: want [%s], got [%s]\n", $name, implode( ', ', $want ), implode( ', ', $got ) );
    }
}
printf( "%d of %d passed\n", count( $cases ) - $fail, count( $cases ) );
exit( $fail ? 1 : 0 );
