<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * What CleanTalk decided, for comparing it with Breakwater on sites that still run it.
 *
 * Adapters read a form plugin's spam flag when their own hook runs, and CleanTalk often decides
 * later (Gravity Forms: priority 999) or ends the request before any adapter runs (Elementor,
 * Forminator, Ninja Forms, Avada, Kadence, MailPoet and WPForms are checked on their AJAX action
 * at priority 1). So the verdict is taken from CleanTalk itself: every check it makes adds one
 * to its blocked or accepted counter, and shutdown hooks still run after it stops a request.
 *
 * At shutdown, rows this request logged get CleanTalk's verdict (spam, ham, or nothing when it
 * didn't check). A submission CleanTalk stopped before Breakwater saw it is judged from the raw
 * request and logged too, never acted on, so the log shows what Breakwater would have done.
 */
final class CleanTalkWatch {

    /** @var array{0:int, 1:int}|null CleanTalk's blocked and accepted counts when the request began. */
    private static ?array $start = null;

    /** AJAX actions CleanTalk checks before the form plugin runs, and the adapter each belongs to. */
    private const ACTIONS = [
        'elementor_pro_forms_send_form'           => 'elementor',
        'forminator_submit_form_custom-forms'     => 'forminator',
        'nf_ajax_submit'                          => 'ninja-forms',
        'fusion_form_submit_form_to_database_email' => 'avada',
        'fusion_form_submit_form_to_email'        => 'avada',
        'fusion_form_submit_ajax'                 => 'avada',
        'kb_process_ajax_submit'                  => 'kadence',
        'kb_process_advanced_form_submit'         => 'kadence',
        'mailpoet'                                => 'mailpoet',
        'wpforms_submit'                          => 'wpforms',
        'frm_entries_create'                      => 'formidable',
        'fluentform_submit'                       => 'fluentform',
        'fl_builder_subscribe_form_submit'        => 'beaver-builder',
        'fl_builder_email'                        => 'beaver-builder',
    ];

    public static function register(): void {
        if ( 'CleanTalk' !== Plugin::otherChecker() || 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            return;
        }
        if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }
        self::$start = self::counts();
        if ( null !== self::$start ) {
            add_action( 'shutdown', [ self::class, 'settle' ], 1 );
        }
    }

    /**
     * CleanTalk's all-time counters (they only go up, unlike its hourly ones), read from the
     * copy this request holds, so other requests can't move them.
     *
     * @return array{0:int, 1:int}|null blocked, accepted; null when they can't be read
     */
    public static function counts(): ?array {
        global $apbct;
        try {
            $counter = is_object( $apbct ) ? ( $apbct->data['admin_bar__all_time_counter'] ?? null ) : null;
        } catch ( \Throwable ) {
            return null;
        }
        if ( ! is_array( $counter ) && ! $counter instanceof \ArrayAccess ) {
            return null;
        }
        return [ (int) ( $counter['blocked'] ?? 0 ), (int) ( $counter['accepted'] ?? 0 ) ];
    }

    public static function settle(): void {
        try {
            $now = self::counts();
            if ( null === $now || null === self::$start ) {
                return;
            }
            $blocked  = $now[0] - self::$start[0];
            $accepted = $now[1] - self::$start[1];
            $ids      = Guard::logIds();
            if ( $ids ) {
                Log::setOther( $ids, $blocked > 0 ? 'spam' : ( $accepted > 0 ? 'ham' : '' ) );
                return;
            }
            if ( $blocked > 0 ) {
                self::logStopped();
            }
        } catch ( \Throwable $e ) {
            error_log( 'Breakwater: recording CleanTalk\'s verdict failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        }
    }

    /** A submission CleanTalk stopped before any adapter saw it: judged from the request, logged only. */
    private static function logStopped(): void {
        if ( Release::running() || Guard::trusted() || str_ends_with( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ), 'wp-login.php' ) ) {
            return;
        }
        $post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
        $action = is_string( $post['action'] ?? null ) ? sanitize_key( $post['action'] ) : '';
        if ( preg_match( '/^(apbct|cleantalk|ct_|heartbeat)/', $action ) ) {
            return; // CleanTalk's own checks (the iframe cover, bot detector) carry no submission.
        }
        $source = self::ACTIONS[ $action ] ?? ( str_ends_with( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ), 'wp-comments-post.php' ) ? 'comment' : 'request' );
        $form   = '' !== $action ? $action : (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
        if ( isset( $post['form_id'] ) && is_scalar( $post['form_id'] ) ) {
            $form .= ' ' . sanitize_text_field( (string) $post['form_id'] );
        }

        $s = Submission::fromRequest( $source, mb_substr( $form, 0, 120 ) . ' (stopped by CleanTalk)', self::expand( $post ) );
        if ( ! $s->fields ) {
            return;
        }
        $s->other = true;
        $verdict  = Checker::check( $s );
        Log::insert( $s, $verdict, $verdict->spam ? 'logged' : 'allowed' );
    }

    /**
     * Plugins that post their fields as one JSON value (Ninja Forms' formData) are opened up,
     * so the message is judged rather than the JSON around it.
     *
     * @param array<mixed> $post
     * @return array<mixed>
     */
    private static function expand( array $post ): array {
        foreach ( $post as $key => $value ) {
            if ( is_string( $value ) && strlen( $value ) < 200000 && str_starts_with( ltrim( $value ), '{' ) ) {
                $decoded = json_decode( $value, true );
                if ( is_array( $decoded ) ) {
                    $post[ $key ] = $decoded;
                }
            }
        }
        return $post;
    }
}
