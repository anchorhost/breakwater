<?php

declare(strict_types=1);

namespace Breakwater;

use Breakwater\Adapters\Registry;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Inspect Breakwater from the command line.
 */
final class Cli {

    /**
     * Shows the mode, the integrations found on this site and a summary of recent verdicts.
     *
     * [--days=<days>]
     * : How far back to count. Default 30.
     *
     * [--format=<format>]
     * : table or json. Default table.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function status( array $args, array $assoc ): void {
        $days  = (int) Utils\get_flag_value( $assoc, 'days', 30 );
        $stats = array_map( 'get_object_vars', Log::stats( $days ) );
        $info  = [
            'version'      => Plugin::VERSION,
            'mode'         => Plugin::mode()->value,
            'configured'   => Plugin::configuredMode()->value,
            'other'        => Plugin::otherChecker() ?: 'none',
            'integrations' => implode( ', ', array_keys( Registry::detected() ) ),
            'days'         => $days,
        ];
        if ( 'json' === Utils\get_flag_value( $assoc, 'format', 'table' ) ) {
            WP_CLI::line( (string) wp_json_encode( $info + [ 'stats' => $stats ] ) );
            return;
        }
        foreach ( $info as $key => $value ) {
            WP_CLI::line( str_pad( $key, 14 ) . $value );
        }
        if ( $stats ) {
            Utils\format_items( 'table', $stats, [ 'source', 'verdict', 'action', 'other_verdict', 'total' ] );
        }
    }

    /**
     * Lists every integration and whether its plugin is on this site.
     *
     * [--format=<format>]
     * : table, json or csv. Default table.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function integrations( array $args, array $assoc ): void {
        $items = array_map( fn ( $adapter ): array => [
            'source'     => $adapter->source(),
            'label'      => $adapter->label(),
            'detected'   => $adapter->detected() ? 'yes' : 'no',
            'spam_kept'  => $adapter->keepsSpam() ? 'spam folder' : 'refused + log',
        ], array_values( Registry::all() ) );
        Utils\format_items( Utils\get_flag_value( $assoc, 'format', 'table' ), $items, [ 'source', 'label', 'detected', 'spam_kept' ] );
    }

    /**
     * Sends the held-messages digest now (it also runs daily).
     *
     * [--dry-run]
     * : Count what would be listed without sending or marking anything.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function digest( array $args, array $assoc ): void {
        $result = Digest::run( isset( $assoc['dry-run'] ) );
        WP_CLI::success( sprintf( '%d digest email(s), %d held message(s) listed, %d obvious bot(s) counted.', $result['sent'], $result['listed'], $result['bots'] ) );
    }

    /**
     * Shows the fleet reputation list, or imports one.
     *
     * [--import=<file>]
     * : A JSON list (generated, ip, email, message, mband) to store, as a host's sync would.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function reputation( array $args, array $assoc ): void {
        if ( isset( $assoc['import'] ) ) {
            $list = json_decode( (string) file_get_contents( (string) $assoc['import'] ), true );
            is_array( $list ) && Reputation::store( $list )
                ? WP_CLI::success( 'Fleet list stored.' )
                : WP_CLI::error( 'Not a fleet list: it needs a generated date.' );
            return;
        }
        $stored = get_option( Reputation::OPTION );
        if ( ! is_array( $stored ) ) {
            WP_CLI::line( 'No fleet list. Breakwater scores with its local checks only.' );
            return;
        }
        WP_CLI::line( 'Generated: ' . $stored['generated'] . ( Reputation::list() ? '' : ' (older than 7 days: ignored)' ) );
        foreach ( [ 'ip', 'email', 'message', 'mband' ] as $kind ) {
            WP_CLI::line( str_pad( $kind, 9 ) . count( (array) ( $stored[ $kind ] ?? [] ) ) . ' hashes' );
        }
    }

    /**
     * Lists recent checks.
     *
     * [--verdict=<verdict>]
     * : spam or ham.
     *
     * [--source=<source>]
     * : An integration's source key (see `wp breakwater integrations`).
     *
     * [--limit=<limit>]
     * : Default 20.
     *
     * [--format=<format>]
     * : table, json or csv. Default table.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function log( array $args, array $assoc ): void {
        $rows  = Log::rows( [
            'limit'   => (int) Utils\get_flag_value( $assoc, 'limit', 20 ),
            'verdict' => (string) Utils\get_flag_value( $assoc, 'verdict', '' ),
            'source'  => (string) Utils\get_flag_value( $assoc, 'source', '' ),
        ] );
        $items = array_map( fn ( object $row ): array => [
            'id'      => $row->id,
            'created' => $row->created_at,
            'source'  => $row->source,
            'form'    => $row->form,
            'verdict' => $row->verdict,
            'action'  => $row->action,
            'other'   => $row->other_verdict,
            'score'   => $row->score,
            'signals' => implode( ',', array_keys( json_decode( (string) $row->signals, true ) ?: [] ) ),
            'email'   => $row->email,
            'ip'      => $row->ip,
            'outcome' => $row->outcome,
        ], $rows );
        Utils\format_items( Utils\get_flag_value( $assoc, 'format', 'table' ), $items, [ 'id', 'created', 'source', 'form', 'verdict', 'action', 'other', 'score', 'signals', 'email', 'ip', 'outcome' ] );
    }

    /**
     * Marks a logged submission not spam and hands it back to its form, exactly as the
     * "Not spam" link in Tools → Breakwater does.
     *
     * <id>
     * : The log row, from `wp breakwater log`.
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function release( array $args, array $assoc ): void {
        $row = Log::get( (int) ( $args[0] ?? 0 ) );
        if ( ! $row ) {
            WP_CLI::error( 'No such log row.' );
        }
        $outcome = Release::run( $row );
        null === $outcome ? WP_CLI::error( 'It could not be sent. Check the site can send mail.' ) : WP_CLI::success( $outcome );
    }

    /**
     * Scores a message without the browser checks. Nothing is logged.
     *
     * [--content=<content>]
     * : The message text.
     *
     * [--email=<email>]
     * : Sender email.
     *
     * [--name=<name>]
     * : Sender name.
     *
     * ## EXAMPLES
     *
     *     wp breakwater check --content="We can get your site on the first page of Google" --email=a@mailinator.com
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function check( array $args, array $assoc ): void {
        $s          = new Submission();
        $s->source  = 'cli';
        $s->content = (string) Utils\get_flag_value( $assoc, 'content', '' );
        $s->email   = (string) Utils\get_flag_value( $assoc, 'email', '' );
        $s->name    = (string) Utils\get_flag_value( $assoc, 'name', '' );
        $s->agent   = 'wp-cli (scoring only)';
        $verdict    = Checker::check( $s, false );
        foreach ( $verdict->signals as $code => [ $points, $detail ] ) {
            WP_CLI::line( sprintf( '%+d  %-18s %s', $points, $code, $detail ) );
        }
        WP_CLI::line( sprintf( 'Score %d of %d: %s', $verdict->score, Checker::threshold(), $verdict->spam ? 'spam' : 'clean' ) );
    }
}
