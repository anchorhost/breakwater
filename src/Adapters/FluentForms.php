<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Feedback;
use Breakwater\Log;
use Breakwater\Release;
use Breakwater\Submission;

/**
 * Fluent Forms. Checked with Fluent's validation, before the entry is saved; spam is
 * refused with a form error the way Fluent's own Akismet check does, and kept as a Spam
 * entry the way Fluent keeps the ones Akismet catches (no emails or integrations run for it).
 * "Not spam", here or with Fluent's own status change, moves it to the entries and runs what
 * a normal submission runs.
 */
final class FluentForms extends Adapter {

    public function source(): string { return 'fluentform'; }
    public function label(): string { return 'Fluent Forms'; }
    public function detected(): bool { return defined( 'FLUENTFORM_VERSION' ); }
    public function keepsSpam(): bool { return true; }

    public function register(): void {
        add_filter( 'fluentform/validation_errors', $this->check( ... ), 99, 4 );
        add_action( 'fluentform/after_submission_status_update', $this->statusChanged( ... ), 10, 2 );
    }

    /**
     * Out of Spam, then what Fluent runs after saving a normal entry: entry details, the
     * notification emails and integration feeds (fluentform/submission_inserted). Not through
     * handleSubmission(): its nonce, captcha and token checks are single-use.
     */
    public function release( object $row ): ?string {
        if ( ! self::available() ) {
            return null;
        }
        $replay = Log::replay( $row );
        $entry  = '' !== (string) $row->ref ? \FluentForm\App\Models\Submission::find( (int) $row->ref ) : null;
        if ( $entry && (int) \FluentForm\App\Helpers\Helper::getSubmissionMeta( (int) $entry->id, 'breakwater_log_id' ) !== (int) $row->id ) {
            $entry = null;
        }
        if ( $entry && 'trashed' === $entry->status ) {
            return null;
        }
        $form = \FluentForm\App\Models\Form::find( $entry ? (int) $entry->form_id : (int) ( $replay['form_id'] ?? 0 ) );
        if ( ! $form || ! empty( $form->has_payment ) ) {
            return null;
        }
        $saved = ! $entry;
        if ( $saved ) {
            // The spam entry couldn't be saved (or was deleted): save it now from what was stashed.
            if ( empty( $replay['data'] ) || ! is_array( $replay['data'] ) ) {
                return null;
            }
            $entry_id = self::insert( $form, $replay['data'], 'unread' );
            $entry    = $entry_id ? \FluentForm\App\Models\Submission::find( $entry_id ) : null;
            if ( ! $entry ) {
                return null;
            }
            \FluentForm\App\Helpers\Helper::setSubmissionMeta( $entry_id, 'breakwater_log_id', (int) $row->id, (int) $form->id );
            Log::link( (int) $row->id, (string) $entry_id );
        }
        $entry_id = (int) $entry->id;
        if ( 'yes' === \FluentForm\App\Helpers\Helper::getSubmissionMeta( $entry_id, 'is_form_action_fired' ) ) {
            return 'Already in the Fluent Forms entries, and the form\'s emails and integrations had run.';
        }
        if ( 'spam' === $entry->status ) {
            \FluentForm\App\Models\Submission::where( 'id', $entry_id )->update( [ 'status' => 'unread', 'updated_at' => current_time( 'mysql' ) ] );
        }
        $data = json_decode( (string) $entry->response, true );
        $data = is_array( $data ) ? $data : [];
        do_action( 'fluentform/notify_on_form_submit', $entry_id, $data, $form );
        ( new \FluentForm\App\Services\Form\SubmissionHandlerService() )->processSubmissionData( $entry_id, $data, $form );
        return ( $saved ? 'Saved to' : 'Moved to' ) . ' the Fluent Forms entries, and the form\'s emails and integrations ran.';
    }

    public function recipients( string $form ): array {
        if ( ! class_exists( '\FluentForm\App\Models\FormMeta' ) ) {
            return [];
        }
        $to = [];
        foreach ( \FluentForm\App\Models\FormMeta::where( 'form_id', self::formId( $form ) )->where( 'meta_key', 'notifications' )->get() as $meta ) {
            $feed = json_decode( (string) $meta->value, true );
            if ( is_array( $feed ) && ! empty( $feed['enabled'] ) && 'email' === ( $feed['sendTo']['type'] ?? 'email' ) ) {
                $to = array_merge( $to, self::emails( str_ireplace( '{wp.admin_email}', '{admin_email}', (string) ( $feed['sendTo']['email'] ?? '' ) ) ) );
            }
        }
        return array_values( array_unique( $to ) );
    }

    /** Fluent's own status change: out of Spam releases it like ours does; into Spam counts as feedback. */
    private function statusChanged( mixed $entry_id, mixed $status = '' ): void {
        if ( Release::running() || ! is_numeric( $entry_id ) || ! is_string( $status ) || ! self::available() ) {
            return;
        }
        $log_id = (int) \FluentForm\App\Helpers\Helper::getSubmissionMeta( (int) $entry_id, 'breakwater_log_id' );
        $row    = $log_id ? Log::get( $log_id ) : null;
        if ( ! $row || (string) (int) $entry_id !== (string) $row->ref ) {
            return;
        }
        if ( 'spam' === $status ) {
            Feedback::spam( $log_id );
        } elseif ( in_array( $status, [ 'unread', 'read' ], true ) && null === $row->delivered_at ) {
            Release::run( $row );
        }
    }

    /**
     * @param array<string, mixed> $errors
     * @param array<string, mixed> $data   The submitted values, nested like the input names.
     * @param \FluentForm\App\Models\Form $form
     * @param array<string, array<string, mixed>> $fields keyed by input name: element, raw.
     */
    private function check( mixed $errors, mixed $data = [], mixed $form = null, mixed $fields = [] ): mixed {
        if ( ! is_array( $errors ) || $errors || ! is_array( $data ) || ! is_array( $fields ) ) {
            return $errors;
        }
        $list    = [];
        $secrets = [];
        foreach ( $fields as $name => $field ) {
            $type = (string) ( $field['element'] ?? '' );
            if ( 'input_password' === $type ) {
                $secrets[] = (string) $name;
            }
            $list[] = [
                'type'  => 'input_password' === $type ? 'password' : $type,
                'label' => (string) ( $field['raw']['settings']['label'] ?? $name ),
                'value' => self::path( $data, (string) $name ),
                'key'   => (string) $name,
            ];
        }
        $name    = is_object( $form ) ? ( $form->id ?? '' ) . ': ' . ( $form->title ?? '' ) : '';
        $verdict = $this->evaluate( Submission::fromFields( $this->source(), (string) $name, $list ) );
        if ( $verdict->act ) {
            if ( is_object( $form ) ) {
                $this->hold( $verdict->log_id, (int) ( $form->id ?? 0 ), array_diff_key( $data, array_flip( $secrets ) ), $verdict->reason() );
            }
            $errors['_breakwater'] = [ $this->message() ];
        }
        return $errors;
    }

    /**
     * Keep it as a Spam entry, as Fluent does for Akismet's catches, so it can be released from
     * either side. Passwords are left out. Payment and registration forms aren't kept: their
     * payment or account can't be recreated faithfully, so those stay refused only.
     *
     * @param array<string, mixed> $data
     */
    private function hold( int $log_id, int $form_id, array $data, string $reason ): void {
        if ( ! $log_id || ! self::available() ) {
            return;
        }
        $form = \FluentForm\App\Models\Form::find( $form_id );
        if ( ! $form || ! empty( $form->has_payment ) || 'yes' === \FluentForm\App\Helpers\Helper::getFormMeta( $form_id, '_has_user_registration' ) ) {
            return;
        }
        unset( $data['g-recaptcha-response'], $data['h-captcha-response'], $data['cf-turnstile-response'] );
        Log::stash( $log_id, [ 'form_id' => $form_id, 'data' => $data ] );
        $entry_id = self::insert( $form, $data, 'spam' );
        if ( ! $entry_id ) {
            return;
        }
        \FluentForm\App\Helpers\Helper::setSubmissionMeta( $entry_id, 'breakwater_log_id', $log_id, $form_id );
        Log::link( $log_id, (string) $entry_id );
        do_action( 'fluentform/log_data', [
            'parent_source_id' => $form_id,
            'source_type'      => 'submission_item',
            'source_id'        => $entry_id,
            'component'        => 'Breakwater',
            'status'           => 'info',
            'title'            => 'Marked as spam',
            'description'      => 'Breakwater held this submission as spam' . ( '' !== $reason ? ' (' . $reason . ')' : '' ) . '. Its emails and integrations run if it is marked not spam.',
        ] );
    }

    /**
     * Save an entry the way Fluent builds one (serial number, browser, IP, the insert filters),
     * without its submit hooks. prepareInsertData() needs the handler's protected form and data.
     *
     * @param array<string, mixed> $data
     */
    private static function insert( object $form, array $data, string $status ): int {
        $handler = new class() extends \FluentForm\App\Services\Form\SubmissionHandlerService {
            /** @param array<string, mixed> $data */
            public function entry( object $form, array $data ): array {
                $this->form     = $form;
                $this->formData = $data;
                return (array) $this->prepareInsertData();
            }
        };
        $insert           = $handler->entry( $form, $data );
        $insert['status'] = $status;
        unset( $insert['spam_from'] );
        $id = (int) \FluentForm\App\Models\Submission::insertGetId( $insert );
        if ( $id ) {
            \FluentForm\App\Helpers\Helper::setSubmissionMeta( $id, '_entry_uid_hash', md5( wp_generate_uuid4() . $id ), (int) $form->id );
        }
        return $id;
    }

    /** Fluent 5+ classes this adapter calls; older versions only get the refusal. */
    private static function available(): bool {
        return class_exists( '\FluentForm\App\Services\Form\SubmissionHandlerService' )
            && class_exists( '\FluentForm\App\Models\Submission' )
            && class_exists( '\FluentForm\App\Models\Form' )
            && method_exists( '\FluentForm\App\Services\Form\SubmissionHandlerService', 'processSubmissionData' )
            && method_exists( '\FluentForm\App\Services\Form\SubmissionHandlerService', 'prepareInsertData' )
            && method_exists( '\FluentForm\App\Helpers\Helper', 'setSubmissionMeta' );
    }

    /** "names[first_name]" reads $data['names']['first_name']. */
    private static function path( array $data, string $name ): mixed {
        foreach ( explode( '.', str_replace( [ '[', ']' ], [ '.', '' ], $name ) ) as $part ) {
            if ( ! is_array( $data ) || ! array_key_exists( $part, $data ) ) {
                return '';
            }
            $data = $data[ $part ];
        }
        return $data;
    }
}
