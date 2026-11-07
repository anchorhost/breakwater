<?php
/**
 * CaptainCore's collector (fetch-site-data) carries its own copy of the word tokenizer, because
 * it runs with plugins skipped. Both must make the same tokens or the fleet's word counts never
 * match what sites score.
 *
 * Run on a site with the plugin active:
 *   wp eval-file tests/words-parity.php ~/.captaincore/lib/remote-scripts/fetch-site-data [corpus.jsonl]
 * A corpus is optional: one JSON object per line with "content" and "email".
 */

use Breakwater\Words;

$script = file_get_contents( $args[0] ?? '' );
if ( ! $script || ! preg_match( '/(\$own\s+= explode.*?\n    \};)\n    \$day/s', $script, $m ) ) {
    WP_CLI::error( 'Could not find the word tokenizer in the collector.' );
}
$home = (string) get_option( 'home' );
eval( $m[1] ); // Defines $own and $w_tokens exactly as the collector does.

$host  = (string) wp_parse_url( $home, PHP_URL_HOST );
$cases = [
    [ "Hi {$host}, visit https://www.Example.COM/path?x=1 or www.second-site.net today! It's \u{2019}quoted\u{2019} -dashed- text.", 'Jane.Smith@Gmail.COM ' ],
    [ "Ünïcödé — straße, 東京, émoji 🙂 and punctuation!!! Ça coûte 195€. Naïve façade, jalapeño señor.", 'no-at-sign' ],
    [ '<span style="font-size:13px">By using this form you agree</span> with <b>markup</b> and a supercalifragilisticexpialidociousword here.', '' ],
    [ 'We can place your website on Google 1st page. SEO, link-building and guest posts!!! ' . str_repeat( 'Repeat this pitch. ', 400 ), 'seo@agency.example' ],
    [ '', 'only@email.example' ],
    [ "it's o'clock -- don't 'quote' me — x y z ab", 'a@b' ],
];
if ( ! empty( $args[1] ) ) {
    foreach ( file( (string) $args[1] ) ?: [] as $line ) {
        $row = json_decode( $line, true );
        if ( is_array( $row ) ) {
            $cases[] = [ (string) ( $row['content'] ?? '' ), (string) ( $row['email'] ?? '' ) ];
        }
    }
}
$fail = 0;
foreach ( $cases as [ $text, $email ] ) {
    $plugin    = Words::tokens( $text, $email, $home );
    $collector = $w_tokens( $text, $email );
    sort( $plugin );
    sort( $collector );
    $fail += (int) ( $plugin !== $collector );
}
$fail
    ? WP_CLI::error( sprintf( '%d of %d messages tokenize differently in the plugin and the collector.', $fail, count( $cases ) ) )
    : WP_CLI::success( sprintf( 'Plugin and collector make the same tokens for all %d messages.', count( $cases ) ) );
