<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * WordPress's Export and Erase Personal Data tools (Tools → Export/Erase Personal Data) cover
 * the log: every submission sent with that email address, what it said and where it came from.
 * Also suggests text for the site's privacy policy.
 */
final class Privacy {

    private const PAGE = 50;

    public static function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'exporters' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'erasers' ] );
        add_action( 'admin_init', [ self::class, 'policy' ] );
    }

    public static function exporters( mixed $exporters ): mixed {
        if ( is_array( $exporters ) ) {
            $exporters['breakwater'] = [ 'exporter_friendly_name' => 'Breakwater spam log', 'callback' => [ self::class, 'export' ] ];
        }
        return $exporters;
    }

    public static function erasers( mixed $erasers ): mixed {
        if ( is_array( $erasers ) ) {
            $erasers['breakwater'] = [ 'eraser_friendly_name' => 'Breakwater spam log', 'callback' => [ self::class, 'erase' ] ];
        }
        return $erasers;
    }

    /** @return array{data: list<array<string, mixed>>, done: bool} */
    public static function export( string $email, int $page = 1 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, created_at, source, form, ip, payload FROM ' . Log::table() . ' WHERE email = %s ORDER BY id LIMIT %d OFFSET %d',
            $email, self::PAGE, self::PAGE * ( max( 1, $page ) - 1 )
        ) ) ?: [];
        $data = [];
        foreach ( $rows as $row ) {
            $payload = json_decode( (string) $row->payload, true ) ?: [];
            $items   = [
                [ 'name' => 'Sent', 'value' => $row->created_at . ' UTC' ],
                [ 'name' => 'Form', 'value' => $row->form ],
                [ 'name' => 'Page', 'value' => (string) ( $payload['url'] ?? '' ) ],
                [ 'name' => 'IP address', 'value' => $row->ip ],
                [ 'name' => 'Browser', 'value' => (string) ( $payload['agent'] ?? '' ) ],
            ];
            foreach ( (array) ( $payload['fields'] ?? [] ) as $key => $value ) {
                $items[] = [ 'name' => (string) $key, 'value' => is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) ];
            }
            $data[] = [ 'group_id' => 'breakwater', 'group_label' => 'Form submissions checked for spam', 'item_id' => 'breakwater-' . $row->id, 'data' => $items ];
        }
        return [ 'data' => $data, 'done' => count( $rows ) < self::PAGE ];
    }

    /** @return array{items_removed: int, items_retained: bool, messages: list<string>, done: bool} */
    public static function erase( string $email, int $page = 1 ): array {
        global $wpdb;
        // Each page deletes what it finds, so the next page starts again from the first row.
        $removed = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Log::table() . ' WHERE email = %s LIMIT %d', $email, self::PAGE ) );
        if ( $removed < self::PAGE ) {
            Trust::remove( $email );
        }
        return [ 'items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => $removed < self::PAGE ];
    }

    public static function policy(): void {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
            return;
        }
        wp_add_privacy_policy_content( 'Breakwater', wp_kses_post( sprintf(
            '<p>%s</p><p>%s</p>',
            sprintf( 'When you send a form or comment, this site checks it for spam on its own server. It keeps what you sent, your IP address, your browser\'s user agent and a few counts about how the form was filled in (time on the page, clicks and keys, characters typed and pasted; never what you typed) for %d days, so a message held by mistake can be found and delivered.', Log::keepDays() ),
            'Nothing is sent to an outside spam service. If this site is hosted by Anchor Hosting, one-way hashes of IP addresses, email addresses and message text are shared with Anchor Hosting\'s other sites to recognise spam sent to many of them; they can\'t be turned back into the originals.'
        ) ) );
    }
}
