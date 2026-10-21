<?php

declare(strict_types=1);

namespace Breakwater;

use Breakwater\Adapters\Registry;

/**
 * Tools → Breakwater: what was checked, what is being held, and one "Not spam" that hands a
 * held submission back to its form (Release::run).
 */
final class Admin {

    public const SLUG = 'breakwater';

    public static function register(): void {
        add_action( 'admin_menu', [ self::class, 'menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
        add_action( 'admin_post_breakwater_mode', [ self::class, 'saveMode' ] );
        add_action( 'admin_post_breakwater_row', [ self::class, 'rowAction' ] );
        add_filter( 'plugin_row_meta', [ self::class, 'rowMeta' ], 10, 2 );
    }

    public static function menu(): void {
        add_management_page( 'Breakwater', 'Breakwater', 'manage_options', self::SLUG, [ self::class, 'render' ] );
    }

    public static function assets( mixed $hook ): void {
        if ( 'tools_page_' . self::SLUG === $hook ) {
            Assets::enqueueAdminStyle();
        }
    }

    /**
     * "View log" after "Visit plugin site", in the plugins list and the must-use list alike
     * (a must-use plugin has no action links under its name).
     *
     * @param array<int|string, string> $meta
     * @return array<int|string, string>
     */
    public static function rowMeta( mixed $meta, mixed $file ): mixed {
        if ( is_array( $meta ) && $file === plugin_basename( Plugin::file() ) && current_user_can( 'manage_options' ) ) {
            $meta[] = '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'View log', 'breakwater' ) . '</a>';
        }
        return $meta;
    }

    /** @param array<string, string|int> $args */
    public static function url( array $args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'tools.php' ) );
    }

    public static function saveMode(): never {
        check_admin_referer( 'breakwater_mode' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        $mode = Mode::tryFrom( sanitize_key( (string) ( $_POST['mode'] ?? '' ) ) );
        if ( $mode ) {
            update_option( Plugin::OPTION, array_merge( Plugin::settings(), [ 'mode' => $mode->value ] ) );
        }
        wp_safe_redirect( self::url( [ 'note' => 'mode' ] ) );
        exit;
    }

    public static function rowAction(): never {
        $id = absint( $_GET['id'] ?? 0 );
        check_admin_referer( 'breakwater_row_' . $id );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }
        $row  = Log::get( $id );
        $note = '';
        switch ( $row ? sanitize_key( (string) ( $_GET['task'] ?? '' ) ) : '' ) {
            case 'not_spam':
                $note = null === Release::run( $row ) ? 'send_failed' : 'released';
                break;
            case 'is_spam':
                Feedback::spam( $id );
                $note = 'marked';
                break;
        }
        wp_safe_redirect( self::url( [ 'note' => $note, 'row' => $id ] ) );
        exit;
    }

    private static function rowLink( object $row, string $task, string $label, string $class = '' ): string {
        $url = wp_nonce_url( admin_url( 'admin-post.php?action=breakwater_row&task=' . $task . '&id=' . (int) $row->id ), 'breakwater_row_' . (int) $row->id );
        return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }

    /** @return array{0:string, 1:string} label and class of a row's status chip */
    private static function status( object $row ): array {
        if ( 'ham' === $row->verdict ) {
            return 'spam' === $row->feedback ? [ 'Was spam', 'held' ] : [ 'Clean', 'clean' ];
        }
        if ( $row->delivered_at ) {
            return [ 'Delivered', 'released' ];
        }
        if ( 'not_spam' === $row->feedback ) {
            return [ 'Not spam', 'released' ];
        }
        return 'blocked' === $row->action ? [ 'Held', 'held' ] : [ 'Spam, logged', 'logged' ];
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $mode       = Plugin::mode();
        $configured = Plugin::configuredMode();
        $other      = Plugin::otherChecker();
        $view       = sanitize_key( (string) ( $_GET['verdict'] ?? 'spam' ) );
        $view       = in_array( $view, [ 'spam', 'ham', 'all' ], true ) ? $view : 'spam';
        $rows       = Log::rows( [ 'limit' => 100, 'verdict' => 'all' === $view ? '' : $view ] );
        $counts     = Log::counts( Log::keepDays() );
        $agree      = [ 'agree' => 0, 'compared' => 0 ];
        foreach ( Log::stats( Log::keepDays() ) as $stat ) {
            if ( '' !== $stat->other_verdict ) {
                $agree['compared'] += (int) $stat->total;
                $agree['agree']    += $stat->other_verdict === $stat->verdict ? (int) $stat->total : 0;
            }
        }
        $note_id = absint( $_GET['row'] ?? 0 );
        $note    = sanitize_key( (string) ( $_GET['note'] ?? '' ) );
        $noted   = $note_id ? Log::get( $note_id ) : null;
        $notes   = [
            'released'    => ( $noted && $noted->outcome ? $noted->outcome : 'Marked not spam.' ) . ' Future messages from this sender get the benefit of the doubt.',
            'marked'      => 'Marked as spam.',
            'mode'        => 'Mode saved.',
            'send_failed' => 'The email could not be sent. Check the site can send mail, then try again.',
        ];
        $found = array_map( fn ( $adapter ): string => $adapter->label(), Registry::detected() );
        $mark  = Assets::markUrl();
        ?>
        <div class="wrap bw-admin">
            <h1 class="bw-title"><img src="<?php echo esc_url( $mark, [ 'http', 'https', 'data' ] ); ?>" alt="" width="32" height="32"> Breakwater</h1>
            <?php if ( isset( $notes[ $note ] ) ) : ?>
                <div class="notice <?php echo 'send_failed' === $note ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( $notes[ $note ] ); ?></p></div>
            <?php endif; ?>

            <div class="bw-summary">
                <div class="bw-card"><span class="bw-num"><?php echo esc_html( number_format_i18n( $counts['checked'] ) ); ?></span><span class="bw-label">checked in <?php echo esc_html( number_format_i18n( Log::keepDays() ) ); ?> days</span></div>
                <div class="bw-card"><span class="bw-num"><?php echo esc_html( number_format_i18n( $counts['held'] ) ); ?></span><span class="bw-label">held, waiting for you</span></div>
                <div class="bw-card"><span class="bw-num"><?php echo esc_html( number_format_i18n( $counts['released'] ) ); ?></span><span class="bw-label">marked not spam</span></div>
                <div class="bw-card bw-mode">
                    <span class="bw-label">Mode: <strong><?php echo esc_html( $mode->label() ); ?></strong><?php echo Mode::Auto === $configured ? ( Plugin::cleantalkActive() ? ' (automatic, CleanTalk is active)' : ' (automatic)' ) : ''; ?></span>
                    <?php if ( defined( 'BREAKWATER_MODE' ) ) : ?>
                        <span class="bw-hint">Set by BREAKWATER_MODE.</span>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="breakwater_mode">
                            <?php wp_nonce_field( 'breakwater_mode' ); ?>
                            <select name="mode" aria-label="Mode">
                                <?php foreach ( Mode::cases() as $case ) : ?>
                                    <option value="<?php echo esc_attr( $case->value ); ?>" <?php selected( $configured->value, $case->value ); ?>><?php echo esc_html( $case->label() ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php submit_button( 'Save', 'secondary small', 'submit', false ); ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <p class="bw-protecting">
                <?php echo esc_html( $mode->help() ); ?>
                Protecting <?php echo esc_html( $found ? implode( ', ', $found ) : 'comments' ); ?>.
                <?php if ( $agree['compared'] ) : ?>
                    Agrees with <?php echo esc_html( $other ?: 'the other spam service' ); ?> on <?php echo esc_html( (string) round( 100 * $agree['agree'] / $agree['compared'] ) ); ?>% of <?php echo esc_html( number_format_i18n( $agree['compared'] ) ); ?> submissions.
                <?php endif; ?>
            </p>

            <ul class="subsubsub">
                <?php
                $tabs = [ 'spam' => [ 'Spam', $counts['spam'] ], 'ham' => [ 'Clean', $counts['ham'] ], 'all' => [ 'All', $counts['checked'] ] ];
                $last = array_key_last( $tabs );
                foreach ( $tabs as $value => [ $label, $count ] ) :
                    ?>
                    <li><a href="<?php echo esc_url( self::url( [ 'verdict' => $value ] ) ); ?>" <?php echo $view === $value ? 'class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $count ) ); ?>)</span></a><?php echo $value !== $last ? ' |' : ''; ?></li>
                <?php endforeach; ?>
            </ul>

            <table class="wp-list-table widefat fixed striped bw-log">
                <thead><tr><th class="column-primary">Message</th><th class="bw-col-why">Why</th><th class="bw-col-form">Form</th><th class="bw-col-when">Received</th></tr></thead>
                <tbody>
                <?php if ( ! $rows ) : ?>
                    <tr><td colspan="4">Nothing here yet. Submissions appear as soon as a protected form is used.</td></tr>
                <?php endif; ?>
                <?php foreach ( $rows as $row ) : ?>
                    <?php
                    $signals = json_decode( (string) $row->signals, true ) ?: [];
                    uasort( $signals, fn ( array $a, array $b ): int => $b[0] <=> $a[0] );
                    $adapter = Registry::forSource( (string) $row->source );
                    [ $label, $class ] = self::status( $row );
                    $time    = strtotime( $row->created_at . ' UTC' );
                    ?>
                    <tr class="<?php echo $note_id === (int) $row->id ? 'bw-noted' : ''; ?>">
                        <td class="column-primary">
                            <strong class="bw-from"><?php echo esc_html( $row->email ?: 'No email given' ); ?></strong>
                            <span class="bw-chip bw-chip-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
                            <?php if ( $row->excerpt ) : ?>
                                <details class="bw-message"><summary><?php echo esc_html( wp_trim_words( $row->excerpt, 18 ) ); ?></summary><div class="bw-full"><?php echo esc_html( $row->excerpt ); ?></div></details>
                            <?php endif; ?>
                            <div class="row-actions visible">
                                <?php if ( 'spam' === $row->verdict && 'not_spam' !== $row->feedback && ! $row->delivered_at ) : ?>
                                    <span><?php echo self::rowLink( $row, 'not_spam', 'blocked' === $row->action ? 'Not spam, deliver it' : 'Not spam', 'bw-release' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                                <?php elseif ( 'ham' === $row->verdict && 'spam' !== $row->feedback ) : ?>
                                    <span><?php echo self::rowLink( $row, 'is_spam', 'Was spam' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                                <?php endif; ?>
                                <?php if ( $row->delivered_at ) : ?>
                                    <span class="bw-muted"><?php echo esc_html( $row->outcome ?: 'Delivered.' ); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="bw-col-why">
                            <span class="bw-score" title="Score; 5 or more is spam"><?php echo (int) $row->score; ?></span>
                            <?php foreach ( array_slice( $signals, 0, 3, true ) as $code => [ $points, $detail ] ) : ?>
                                <span class="bw-reason"><?php echo esc_html( $detail ?: $code ); ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="bw-col-form"><?php echo esc_html( $adapter ? $adapter->label() : $row->source ); ?><br><span class="bw-muted"><?php echo esc_html( $row->form ); ?></span></td>
                        <td class="bw-col-when"><span title="<?php echo esc_attr( $row->created_at . ' UTC' ); ?>"><?php echo esc_html( sprintf( '%s ago', human_time_diff( $time ) ) ); ?></span>
                            <?php if ( $row->other_verdict ) : ?><br><span class="bw-muted"><?php echo esc_html( ( $other ?: 'Other service' ) . ': ' . $row->other_verdict ); ?></span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="bw-muted bw-foot">Submissions are kept for <?php echo esc_html( number_format_i18n( Log::keepDays() ) ); ?> days, held or clean. The daily digest lists new held messages to each form's recipients.</p>
        </div>
        <?php
    }
}
