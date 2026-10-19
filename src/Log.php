<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * Every checked submission gets a row. Spam keeps its full payload for 30 days so a
 * false positive can always be recovered; clean submissions keep only the verdict.
 */
final class Log {

    public const DB_VERSION = 4;

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'breakwater_log';
    }

    public static function maybeInstall(): void {
        if ( (int) get_option( 'breakwater_db_version' ) < self::DB_VERSION ) {
            self::install();
        }
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            source varchar(40) NOT NULL DEFAULT '',
            form varchar(191) NOT NULL DEFAULT '',
            verdict varchar(10) NOT NULL DEFAULT '',
            action varchar(20) NOT NULL DEFAULT '',
            score smallint(6) NOT NULL DEFAULT 0,
            other_verdict varchar(10) NOT NULL DEFAULT '',
            signals text NOT NULL,
            ip varchar(45) NOT NULL DEFAULT '',
            email varchar(191) NOT NULL DEFAULT '',
            content_hash char(40) NOT NULL DEFAULT '',
            fingerprint char(64) NOT NULL DEFAULT '',
            bands text NOT NULL,
            excerpt text NOT NULL,
            payload longtext NOT NULL,
            feedback varchar(20) NOT NULL DEFAULT '',
            digest_at datetime DEFAULT NULL,
            delivered_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY ip_created (ip, created_at),
            KEY content_hash (content_hash)
        ) {$charset};" );
        update_option( 'breakwater_db_version', self::DB_VERSION, false );
    }

    /**
     * @param string $action blocked, logged or allowed.
     */
    public static function insert( Submission $s, Verdict $verdict, string $action ): int {
        global $wpdb;
        $spam = $verdict->spam;
        $wpdb->insert( self::table(), [
            'created_at'    => gmdate( 'Y-m-d H:i:s' ),
            'source'        => $s->source,
            'form'          => mb_substr( $s->form, 0, 191 ),
            'verdict'       => $spam ? 'spam' : 'ham',
            'action'        => $action,
            'score'         => $verdict->score,
            'other_verdict' => match ( $s->other ) { null => '', true => 'spam', false => 'ham' },
            'signals'       => (string) wp_json_encode( $verdict->signals ),
            'ip'            => $s->ip,
            'email'         => mb_substr( $s->email, 0, 191 ),
            'content_hash'  => $s->hash(),
            'fingerprint'   => Reputation::messageFor( $s ),
            'bands'         => implode( ',', Reputation::bandsFor( $s ) ),
            'excerpt'       => $spam ? mb_substr( $s->content, 0, 500 ) : '',
            'payload'       => $spam ? (string) wp_json_encode( [ 'fields' => $s->fields, 'agent' => $s->agent, 'url' => self::requestUrl() ] ) : '',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Counts behind the repeat signals: this message already caught as spam today from
     * somewhere else, and other spam from this IP in the last hour. Clean history never
     * counts against anyone, and neither does a person sending the same message again after
     * being refused: their own earlier attempt is excluded from both counts.
     *
     * @return array{spam_content:int, spam_ip:int}
     */
    public static function recent( Submission $s ): array {
        global $wpdb;
        $table = self::table();
        $hash  = $s->hash();
        $out   = [ 'spam_content' => 0, 'spam_ip' => 0 ];
        if ( '' !== $hash && strlen( $s->content ) >= 20 ) {
            $out['spam_content'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE content_hash = %s AND ip != %s AND verdict = 'spam' AND feedback != 'not_spam' AND created_at > %s",
                $hash, $s->ip, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
            ) );
        }
        if ( '' !== $s->ip ) {
            $out['spam_ip'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE ip = %s AND content_hash != %s AND verdict = 'spam' AND feedback != 'not_spam' AND created_at > %s",
                $s->ip, $hash, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
            ) );
        }
        return $out;
    }

    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ) ?: null;
    }

    /**
     * @param array{limit?:int, offset?:int, verdict?:string, source?:string} $args
     * @return list<object>
     */
    public static function rows( array $args = [] ): array {
        global $wpdb;
        $args  = array_merge( [ 'limit' => 50, 'offset' => 0, 'verdict' => '', 'source' => '' ], $args );
        $where = [ '1=1' ];
        if ( '' !== $args['verdict'] ) {
            $where[] = $wpdb->prepare( 'verdict = %s', $args['verdict'] );
        }
        if ( '' !== $args['source'] ) {
            $where[] = $wpdb->prepare( 'source = %s', $args['source'] );
        }
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            (int) $args['limit'], (int) $args['offset']
        ) ) ?: [];
    }

    /** @return list<object> source, verdict, action, other_verdict, total */
    public static function stats( int $days = 30 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT source, verdict, action, other_verdict, COUNT(*) AS total FROM ' . self::table() . ' WHERE created_at > %s GROUP BY source, verdict, action, other_verdict ORDER BY source',
            gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
        ) ) ?: [];
    }

    /**
     * The admin summary: checked, held (stopped and still waiting), released (marked not spam
     * or delivered) and the tab counts.
     *
     * @return array{checked:int, spam:int, held:int, released:int, ham:int}
     */
    public static function counts( int $days = 30 ): array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS checked,
                SUM(verdict = 'spam') AS spam,
                SUM(verdict = 'spam' AND action = 'blocked' AND feedback = '' AND delivered_at IS NULL) AS held,
                SUM(verdict = 'spam' AND (feedback = 'not_spam' OR delivered_at IS NOT NULL)) AS released,
                SUM(verdict = 'ham') AS ham
            FROM " . self::table() . ' WHERE created_at > %s',
            gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
        ), ARRAY_A ) ?: [];
        return array_map( 'intval', array_merge( [ 'checked' => 0, 'spam' => 0, 'held' => 0, 'released' => 0, 'ham' => 0 ], array_filter( $row, 'is_numeric' ) ) );
    }

    public static function setFeedback( int $id, string $feedback ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'feedback' => $feedback ], [ 'id' => $id ] );
    }

    public static function markDelivered( int $id ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'delivered_at' => gmdate( 'Y-m-d H:i:s' ) ], [ 'id' => $id ] );
    }

    /**
     * Submissions that were stopped and nobody has looked at yet: not delivered, not marked,
     * not already sent in a digest.
     *
     * @return list<object>
     */
    public static function undigested( int $limit = 200 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE action = 'blocked' AND digest_at IS NULL AND delivered_at IS NULL AND feedback = '' AND created_at > %s ORDER BY id ASC LIMIT %d",
            gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), $limit
        ) ) ?: [];
    }

    /** @param list<int> $ids */
    public static function markDigested( array $ids ): void {
        global $wpdb;
        if ( $ids ) {
            $wpdb->query( $wpdb->prepare(
                'UPDATE ' . self::table() . ' SET digest_at = %s WHERE id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')',
                gmdate( 'Y-m-d H:i:s' )
            ) );
        }
    }

    public static function cleanup(): void {
        global $wpdb;
        $table = self::table();
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE verdict = 'ham' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ) ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
    }

    private static function requestUrl(): string {
        $referer = wp_get_raw_referer();
        if ( $referer ) {
            return esc_url_raw( $referer );
        }
        return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( home_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) ) : '';
    }
}
