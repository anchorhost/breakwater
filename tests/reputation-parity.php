<?php
/**
 * CaptainCore's collector (fetch-site-data) carries its own copy of the reputation hashes,
 * because it runs with plugins skipped. Both must hash identically or fleet counts split:
 * IPs, emails, the exact message hash and every MinHash band.
 *
 * Run on a site with the plugin active:
 *   wp eval-file tests/reputation-parity.php ~/.captaincore/lib/remote-scripts/fetch-site-data
 */

use Breakwater\Reputation;

$script = file_get_contents( $args[0] ?? '' );
if ( ! $script || ! preg_match( '/(\$h_ip = function.*?)\n\$items = \[\];/s', $script, $m ) ) {
    WP_CLI::error( 'Could not find the hash functions in the collector.' );
}
$home = home_url();
$salt = Reputation::SALT;
$host = strtolower( (string) preg_replace( '/^www\./', '', (string) wp_parse_url( $home, PHP_URL_HOST ) ) );
$name = explode( '.', $host )[0] ?? '';
eval( $m[1] ); // Defines $h_ip, $h_email, $normalize, $h_message exactly as the collector does.

$site     = explode( '.', $host )[0];
$messages = [
    "Hi {$site}, I just visited " . $host . ' and wondered if you have ever considered a video? It costs $195. https://example.com/x',
    "Ünïcödé — straße, 東京, émoji 🙂 and punctuation!!! It's long enough to fingerprint here, with several more words.",
    '<span style="font-size:13px">By using this form you agree</span> with <b>markup</b> around the words of a message here.',
    "  Short  ",
    "We can place your website on Google 1st page. I can give you our Complete SEO Action Plan along with pricing.",
];
$fail = 0;
foreach ( [ '203.0.113.7', ' 2001:DB8::1 ', 'not-an-ip' ] as $ip ) {
    $fail += (int) ( Reputation::ip( $ip ) !== $h_ip( $ip ) );
}
foreach ( [ 'Jane.Smith@Gmail.com ', 'no-at-sign' ] as $email ) {
    $fail += (int) ( Reputation::email( $email ) !== $h_email( $email ) );
}
foreach ( $messages as $text ) {
    $collector = $h_message( $text );
    $fail     += (int) ( Reputation::message( $text, $home ) !== $collector['m'] );
    $fail     += (int) ( Reputation::bands( $text, $home ) !== $collector['b'] );
}
$fail ? WP_CLI::error( "$fail hash(es) differ between the plugin and the collector." ) : WP_CLI::success( 'Plugin and collector hash identically, bands included.' );
