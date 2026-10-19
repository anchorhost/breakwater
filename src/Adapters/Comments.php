<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Feedback;
use Breakwater\Guard;
use Breakwater\Submission;

/**
 * Core comments posted through wp-comments-post.php. Spam lands in the Spam folder,
 * where "Not Spam" restores it as usual.
 */
final class Comments extends Adapter {

    public function source(): string { return 'comment'; }
    public function label(): string { return 'Comments'; }
    public function detected(): bool { return true; }
    public function keepsSpam(): bool { return true; }

    public function register(): void {
        add_filter( 'pre_comment_approved', $this->check( ... ), 99, 2 );
        add_action( 'comment_post', $this->link( ... ), 10, 1 );
        add_action( 'transition_comment_status', $this->statusChanged( ... ), 10, 3 );
        add_action( 'breakwater_not_spam', $this->restore( ... ) );
    }

    /** "Not spam" from the log or a Deliver link also takes the comment out of Spam, into moderation. */
    private function restore( object $row ): void {
        if ( $this->source() !== $row->source ) {
            return;
        }
        $ids = get_comments( [ 'status' => 'spam', 'meta_key' => 'breakwater_log_id', 'meta_value' => (int) $row->id, 'fields' => 'ids', 'number' => 1 ] );
        if ( $ids ) {
            wp_set_comment_status( (int) $ids[0], 'hold' );
        }
    }

    /** Remember which log row the saved comment came from. */
    private function link( mixed $comment_id ): void {
        if ( Guard::lastLogId() ) {
            add_comment_meta( (int) $comment_id, 'breakwater_log_id', Guard::lastLogId(), true );
        }
    }

    /** WordPress's own "Not Spam" and "Spam" links count as feedback. */
    private function statusChanged( mixed $new, mixed $old, mixed $comment ): void {
        $log_id = is_object( $comment ) ? (int) get_comment_meta( (int) $comment->comment_ID, 'breakwater_log_id', true ) : 0;
        if ( ! $log_id ) {
            return;
        }
        if ( 'spam' === $old && in_array( $new, [ 'approved', 'unapproved' ], true ) ) {
            Feedback::notSpam( $log_id );
        } elseif ( 'spam' === $new && 'spam' !== $old ) {
            Feedback::spam( $log_id );
        }
    }

    /**
     * Hook callbacks take mixed: other plugins call these filters too, and a TypeError here
     * would take the whole comment form down.
     *
     * @param int|string|\WP_Error $approved
     * @param array<string, mixed> $comment
     */
    private function check( mixed $approved, mixed $comment ): mixed {
        if ( ! is_array( $comment ) || ! isset( $_POST['comment_post_ID'] ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_wp_error( $approved ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return $approved;
        }
        $type = (string) ( $comment['comment_type'] ?? '' );
        if ( '' !== $type && 'comment' !== $type ) {
            return $approved;
        }
        $s = Submission::fromFields( $this->source(), 'post ' . (int) $comment['comment_post_ID'], [
            [ 'type' => 'name', 'label' => 'author', 'value' => $comment['comment_author'] ?? '' ],
            [ 'type' => 'email', 'label' => 'email', 'value' => $comment['comment_author_email'] ?? '' ],
            [ 'type' => 'url', 'label' => 'url', 'value' => $comment['comment_author_url'] ?? '' ],
            [ 'type' => 'textarea', 'label' => 'comment', 'value' => $comment['comment_content'] ?? '' ],
        ] );
        // The author name is not message text for comments: judge the comment and its link.
        $s->content = trim( (string) ( $comment['comment_content'] ?? '' ) . "\n" . $s->url );
        $s->other   = 'spam' === $approved;

        return $this->evaluate( $s )->act ? 'spam' : $approved;
    }
}
