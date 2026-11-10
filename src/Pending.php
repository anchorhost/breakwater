<?php

declare(strict_types=1);

namespace Breakwater;

use Breakwater\Adapters\Comments;

/**
 * Comments that were waiting for moderation before Breakwater arrived, or that reached the site
 * some other way: judged on what was saved (no browser checks, so only content, sender, repeats
 * and the fleet list count), and spam moved to the Spam folder, where WordPress's Not Spam puts
 * a comment back. "Check pending with Breakwater" by the comments list checks every pending
 * comment, the "Check with Breakwater" bulk action the selected ones, and
 * `wp breakwater pending` the same from the command line.
 */
final class Pending {

    /** On every comment checked here, the score and why, so the next run moves on to the rest. */
    public const META = 'breakwater_backlog';

    /** How long one click works before handing back to the screen with the rest still to do. */
    private const SECONDS = 20;

    public static function register(): void {
        add_action( 'manage_comments_nav', [ self::class, 'button' ], 10, 2 );
        add_filter( 'bulk_actions-edit-comments', [ self::class, 'bulkActions' ] );
        add_filter( 'handle_bulk_actions-edit-comments', [ self::class, 'bulk' ], 10, 3 );
        add_action( 'admin_post_breakwater_pending', [ self::class, 'checkAll' ] );
        add_action( 'admin_notices', [ self::class, 'notice' ] );
        add_filter( 'removable_query_args', [ self::class, 'removableArgs' ] );
    }

    /** Pending comments nobody has checked: not when they were posted, not here. */
    public static function waiting(): int {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . self::unchecked() );
    }

    /** @return list<int> */
    private static function next( int $after, int $limit = 100 ): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT c.comment_ID ' . self::unchecked() . ' AND c.comment_ID > %d ORDER BY c.comment_ID LIMIT %d', $after, $limit ) ) );
    }

    private static function unchecked(): string {
        global $wpdb;
        return "FROM {$wpdb->comments} c WHERE c.comment_approved = '0' AND c.comment_type IN ('', 'comment', 'review')
            AND NOT EXISTS ( SELECT 1 FROM {$wpdb->commentmeta} m WHERE m.comment_id = c.comment_ID AND m.meta_key IN ('breakwater_log_id', '" . self::META . "') )";
    }

    /**
     * Every unchecked pending comment, oldest first, until none are left or the time runs out.
     *
     * @param callable(\WP_Comment, Verdict): void|null $each
     * @return array{checked:int, spam:int}
     */
    public static function run( int $seconds = 0, bool $dry_run = false, ?callable $each = null ): array {
        $start = time();
        $after = 0;
        $out   = [ 'checked' => 0, 'spam' => 0 ];
        while ( $ids = self::next( $after ) ) {
            foreach ( $ids as $id ) {
                $after   = $id;
                $comment = get_comment( $id );
                $verdict = $comment ? self::check( $comment, $dry_run ) : null;
                if ( $verdict ) {
                    ++$out['checked'];
                    $out['spam'] += (int) $verdict->spam;
                    if ( $each ) {
                        $each( $comment, $verdict );
                    }
                }
                if ( $seconds && time() - $start >= $seconds ) {
                    break 2;
                }
            }
        }
        return $out;
    }

    /**
     * Judge one comment and move it to Spam when it is. Comments Breakwater checked when they
     * were posted are left alone: that check had the browser proof this one lacks.
     */
    public static function check( \WP_Comment $comment, bool $dry_run = false ): ?Verdict {
        $id = (int) $comment->comment_ID;
        if ( ! in_array( $comment->comment_type, [ '', 'comment', 'review' ], true )
            || ! in_array( (string) $comment->comment_approved, [ '0', '1' ], true )
            || get_comment_meta( $id, 'breakwater_log_id', true ) ) {
            return null;
        }
        $user = (int) $comment->user_id;
        if ( $user && ( user_can( $user, 'edit_posts' ) || user_can( $user, 'moderate_comments' ) ) ) {
            if ( ! $dry_run ) {
                update_comment_meta( $id, self::META, 'Not checked: posted by someone who edits the site.' );
            }
            return null;
        }
        $s        = Comments::submission( get_object_vars( $comment ) );
        $s->ip    = (string) $comment->comment_author_IP;
        $s->agent = (string) $comment->comment_agent;
        $verdict  = Checker::check( $s, false );
        if ( ! $dry_run ) {
            $reasons = array_filter( $verdict->signals, fn ( array $signal ): bool => 0 !== (int) $signal[0] );
            update_comment_meta( $id, self::META, sprintf(
                'Score %d, %s%s',
                $verdict->score,
                $verdict->spam ? 'spam' : 'clean',
                $reasons ? ': ' . implode( ', ', array_map( fn ( string $code, array $signal ): string => $signal[1] ?: $code, array_keys( $reasons ), $reasons ) ) : ''
            ) );
            if ( $verdict->spam ) {
                wp_spam_comment( $id );
            }
        }
        return $verdict;
    }

    public static function button( mixed $status, mixed $which = 'top' ): void {
        if ( 'top' !== $which || ! in_array( $status, [ 'all', 'moderated' ], true ) || ! current_user_can( 'moderate_comments' ) ) {
            return;
        }
        $waiting = self::waiting();
        if ( $waiting ) {
            $url = wp_nonce_url( admin_url( 'admin-post.php?action=breakwater_pending' ), 'breakwater_pending' );
            printf(
                '<a class="button" href="%s" title="%s">%s</a>',
                esc_url( $url ),
                esc_attr__( 'Moves the ones Breakwater judges to be spam to the Spam folder.', 'breakwater' ),
                /* translators: %s: number of pending comments */
                esc_html( sprintf( __( 'Check pending with Breakwater (%s)', 'breakwater' ), number_format_i18n( $waiting ) ) )
            );
        }
    }

    public static function checkAll(): never {
        check_admin_referer( 'breakwater_pending' );
        if ( ! current_user_can( 'moderate_comments' ) ) {
            wp_die( 'Not allowed.' );
        }
        $out = self::run( self::SECONDS );
        wp_safe_redirect( self::result( admin_url( 'edit-comments.php?comment_status=moderated' ), $out ) );
        exit;
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public static function bulkActions( mixed $actions ): mixed {
        if ( is_array( $actions ) && current_user_can( 'moderate_comments' ) ) {
            $actions['breakwater_check'] = __( 'Check with Breakwater', 'breakwater' );
        }
        return $actions;
    }

    /** @param list<int|string> $ids */
    public static function bulk( mixed $redirect, mixed $action, mixed $ids ): mixed {
        if ( 'breakwater_check' !== $action || ! is_array( $ids ) || ! current_user_can( 'moderate_comments' ) ) {
            return $redirect;
        }
        $out = [ 'checked' => 0, 'spam' => 0 ];
        foreach ( $ids as $id ) {
            $comment = get_comment( (int) $id );
            $verdict = $comment ? self::check( $comment ) : null;
            if ( $verdict ) {
                ++$out['checked'];
                $out['spam'] += (int) $verdict->spam;
            }
        }
        return self::result( (string) $redirect, $out );
    }

    /** @param array{checked:int, spam:int} $out */
    private static function result( string $url, array $out ): string {
        return add_query_arg( [ 'bw_checked' => $out['checked'], 'bw_spam' => $out['spam'] ], remove_query_arg( [ 'bw_checked', 'bw_spam' ], $url ) );
    }

    public static function notice(): void {
        if ( ! isset( $_GET['bw_checked'] ) || 'edit-comments' !== get_current_screen()?->id ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $checked = absint( $_GET['bw_checked'] ); // phpcs:ignore WordPress.Security.NonceVerification
        $spam    = absint( $_GET['bw_spam'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
        $left    = self::waiting();
        $text    = $checked
            /* translators: 1: comments checked, 2: comments moved to Spam */
            ? sprintf( _n( 'Breakwater checked %1$s comment and moved %2$s to Spam.', 'Breakwater checked %1$s comments and moved %2$s to Spam.', $checked, 'breakwater' ), number_format_i18n( $checked ), number_format_i18n( $spam ) )
            : __( 'Breakwater had nothing to check: comments it checked when they were posted, and comments from people who edit the site, are left as they are.', 'breakwater' );
        $links   = [];
        if ( $spam ) {
            $links[] = '<a href="' . esc_url( admin_url( 'edit-comments.php?comment_status=spam' ) ) . '">' . esc_html__( 'Review the Spam folder', 'breakwater' ) . '</a> ' . esc_html__( '(Not Spam puts a comment back).', 'breakwater' );
        }
        if ( $left && current_user_can( 'moderate_comments' ) ) {
            $links[] = sprintf(
                /* translators: %s: number of pending comments not checked yet */
                esc_html( _n( '%s pending comment is still to check:', '%s pending comments are still to check:', $left, 'breakwater' ) ),
                esc_html( number_format_i18n( $left ) )
            ) . ' <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=breakwater_pending' ), 'breakwater_pending' ) ) . '">' . esc_html__( 'check the rest', 'breakwater' ) . '</a>.';
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . ( $links ? ' ' . implode( ' ', $links ) : '' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * @param list<string> $args
     * @return list<string>
     */
    public static function removableArgs( mixed $args ): mixed {
        return is_array( $args ) ? array_merge( $args, [ 'bw_checked', 'bw_spam' ] ) : $args;
    }
}
