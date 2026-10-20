<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Feedback;
use Breakwater\Guard;
use Breakwater\Log;
use Breakwater\Release;
use Breakwater\Submission;

/**
 * Core comments and WooCommerce product reviews posted through wp-comments-post.php. Spam lands in the Spam folder. Released
 * (here or with WordPress's own Not Spam), it goes where WordPress would have put it: approved
 * with the post's author told, or pending with the moderators told.
 */
final class Comments extends Adapter {

    /** What WordPress would have done without us: 1 approved, anything else pending. */
    private static int|string $approved = 0;

    public function source(): string { return 'comment'; }
    public function label(): string { return 'Comments'; }
    public function detected(): bool { return true; }
    public function keepsSpam(): bool { return true; }

    public function register(): void {
        add_filter( 'pre_comment_approved', $this->check( ... ), 99, 2 );
        add_action( 'comment_post', $this->link( ... ), 10, 1 );
        add_action( 'transition_comment_status', $this->statusChanged( ... ), 10, 3 );
    }

    public function release( object $row ): ?string {
        // The row's ref is the comment. Older rows: find it by meta, with every type asked for,
        // since WooCommerce hides product reviews from comment queries otherwise.
        $id = (int) $row->ref;
        if ( ! $id || (int) get_comment_meta( $id, 'breakwater_log_id', true ) !== (int) $row->id ) {
            $ids = get_comments( [ 'status' => 'any', 'type' => 'all', 'meta_key' => 'breakwater_log_id', 'meta_value' => (int) $row->id, 'fields' => 'ids', 'number' => 1 ] );
            $ids = $ids ?: get_comments( [ 'status' => 'spam', 'type' => 'all', 'meta_key' => 'breakwater_log_id', 'meta_value' => (int) $row->id, 'fields' => 'ids', 'number' => 1 ] );
            $id  = (int) ( $ids[0] ?? 0 );
        }
        if ( ! $id || in_array( wp_get_comment_status( $id ), [ false, 'trash' ], true ) ) {
            return null;
        }
        if ( '1' === (string) get_comment_meta( $id, 'breakwater_prior_approved', true ) ) {
            wp_set_comment_status( $id, 'approve' ); // Core emails the post's author.
            return 'Approved and published, and the post\'s author was told.';
        }
        wp_set_comment_status( $id, 'hold' );
        wp_new_comment_notify_moderator( $id );
        return 'Moved to pending comments, and the moderators were told.';
    }

    /** Remember which log row the saved comment came from, and where WordPress would have put it. */
    private function link( mixed $comment_id ): void {
        if ( Guard::lastLogId() ) {
            add_comment_meta( (int) $comment_id, 'breakwater_log_id', Guard::lastLogId(), true );
            add_comment_meta( (int) $comment_id, 'breakwater_prior_approved', (string) self::$approved, true );
            Log::link( Guard::lastLogId(), (string) $comment_id );
        }
    }

    /** WordPress's own "Not Spam" releases it like ours does; "Spam" counts as feedback. */
    private function statusChanged( mixed $new, mixed $old, mixed $comment ): void {
        $log_id = is_object( $comment ) && ! Release::running() ? (int) get_comment_meta( (int) $comment->comment_ID, 'breakwater_log_id', true ) : 0;
        $row    = $log_id ? Log::get( $log_id ) : null;
        if ( ! $row ) {
            return;
        }
        if ( 'spam' === $old && in_array( $new, [ 'approved', 'unapproved' ], true ) ) {
            Release::run( $row );
        } elseif ( 'spam' === $new && 'spam' !== $old ) {
            Feedback::spam( $log_id );
        }
    }

    /**
     * Hook callbacks take mixed: other plugins call these filters too, and a TypeError here
     * would take the whole comment form down. The comment data arrives slashed.
     *
     * @param int|string|\WP_Error $approved
     * @param array<string, mixed> $comment
     */
    private function check( mixed $approved, mixed $comment ): mixed {
        if ( ! is_array( $comment ) || ! isset( $_POST['comment_post_ID'] ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_wp_error( $approved ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return $approved;
        }
        // Comments, and WooCommerce product reviews (typed "review" before this filter runs).
        $type = (string) ( $comment['comment_type'] ?? '' );
        if ( ! in_array( $type, [ '', 'comment', 'review' ], true ) ) {
            return $approved;
        }
        $s = Submission::fromFields( $this->source(), 'post ' . (int) $comment['comment_post_ID'], [
            [ 'type' => 'name', 'label' => 'author', 'value' => wp_unslash( (string) ( $comment['comment_author'] ?? '' ) ) ],
            [ 'type' => 'email', 'label' => 'email', 'value' => wp_unslash( (string) ( $comment['comment_author_email'] ?? '' ) ) ],
            [ 'type' => 'url', 'label' => 'url', 'value' => wp_unslash( (string) ( $comment['comment_author_url'] ?? '' ) ) ],
            [ 'type' => 'textarea', 'label' => 'comment', 'value' => wp_unslash( (string) ( $comment['comment_content'] ?? '' ) ) ],
        ] );
        // The author name is not message text for comments: judge the comment and its link.
        $s->content = trim( wp_unslash( (string) ( $comment['comment_content'] ?? '' ) ) . "\n" . $s->url );
        $s->other   = 'spam' === $approved;
        self::$approved = is_int( $approved ) || is_string( $approved ) ? $approved : 0;

        return $this->evaluate( $s )->act ? 'spam' : $approved;
    }
}
