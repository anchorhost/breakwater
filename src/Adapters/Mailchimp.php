<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Submission;

/**
 * Mailchimp for WordPress (MC4WP) sign-up forms. Spam gets MC4WP's spam error, with our
 * flagged-for-review wording, and never reaches Mailchimp. Released, it is sent to Mailchimp as pending whatever
 * the form's double opt-in setting: the visitor was told their sign-up failed, so Mailchimp's
 * confirmation email lets them choose.
 */
final class Mailchimp extends Adapter {

    private const TYPES = [ 'EMAIL' => 'email', 'FNAME' => 'first_name', 'LNAME' => 'last_name', 'NAME' => 'name', 'WEBSITE' => 'url' ];
    private const SKIP  = [ 'AGREE_TO_TERMS', 'INTERESTS', 'GROUPINGS', 'MC_LANGUAGE' ];

    public function source(): string { return 'mc4wp'; }
    public function label(): string { return 'Mailchimp for WP'; }
    public function detected(): bool { return defined( 'MC4WP_VERSION' ); }

    public function register(): void {
        add_filter( 'mc4wp_form_errors', $this->check( ... ), 99, 2 );
    }

    /**
     * What MC4WP's form listener does for a subscribe form after validation: one subscribe
     * request per list, with the list fields mapped from the form data, then its hooks.
     */
    public function release( object $row ): ?string {
        $data  = Log::replay( $row );
        $email = (string) ( $data['data']['EMAIL'] ?? '' );
        $lists = array_values( array_filter( array_map( 'strval', (array) ( $data['lists'] ?? [] ) ) ) );
        if ( 'subscribe' !== ( $data['action'] ?? '' ) || ! is_email( $email ) || ! $lists || ! function_exists( 'mc4wp_get_form' ) || ! class_exists( 'MC4WP_MailChimp' ) || ! class_exists( 'MC4WP_List_Data_Mapper' ) || ! class_exists( 'MC4WP_MailChimp_Subscriber' ) ) {
            return null;
        }
        try {
            $form = mc4wp_get_form( (int) ( $data['form_id'] ?? 0 ) );
        } catch ( \Throwable $e ) {
            return null; // The form was deleted.
        }
        // The form as it was submitted, for the hooks below and anything reading it.
        $form->handle_request( array_merge( (array) $data['data'], [ '_mc4wp_lists' => $lists, '_mc4wp_action' => 'subscribe', '_mc4wp_email_type' => (string) ( $data['email_type'] ?? '' ) ] ) );

        $mailchimp = new \MC4WP_MailChimp();
        try {
            $map = ( new \MC4WP_List_Data_Mapper( (array) $data['data'], $lists ) )->map();
        } catch ( \Throwable $e ) {
            return null;
        }
        $done   = [];
        $result = null;
        foreach ( $map as $list_id => $subscriber ) {
            $subscriber->email_type = (string) ( $data['email_type'] ?? '' );
            $subscriber->ip_signup  = (string) $row->ip;
            $subscriber->tags       = (array) ( $data['tags'] ?? [] );
            $subscriber = apply_filters( 'mc4wp_subscriber_data', $subscriber, $list_id );
            $subscriber = $subscriber instanceof \MC4WP_MailChimp_Subscriber ? apply_filters( 'mc4wp_form_subscriber_data', $subscriber, $list_id ) : null;
            if ( ! $subscriber instanceof \MC4WP_MailChimp_Subscriber ) {
                continue;
            }
            $subscriber->status = 'pending'; // Always double opt-in, after the filters too.
            $result = $mailchimp->list_subscribe( (string) $list_id, $subscriber->email_address, $subscriber->to_array(), ! empty( $data['update_existing'] ), ! empty( $data['replace_interests'] ) );
            if ( is_object( $result ) && ! empty( $result->id ) ) {
                $done[ (string) $list_id ] = ! empty( $result->was_already_on_list ) ? 'updated' : 'pending';
            } elseif ( 214 === (int) $mailchimp->get_error_code() ) {
                $done[ (string) $list_id ] = 'already';
            }
        }
        if ( ! $done ) {
            return null; // Mailchimp refused or couldn't be reached: the owner gets the fields instead.
        }
        $fresh = in_array( 'pending', $done, true );
        if ( $fresh || in_array( 'updated', $done, true ) ) {
            do_action( $fresh ? 'mc4wp_form_added_subscriber' : 'mc4wp_form_updated_subscriber', $form, $email, (array) $data['data'] );
            do_action( 'mc4wp_form_subscribed', $form, $email, (array) $data['data'], $map );
        }
        $failed = count( $lists ) - count( $done );
        $note   = $failed ? sprintf( ' Mailchimp refused it for %d other list%s.', $failed, 1 === $failed ? '' : 's' ) : '';
        if ( $fresh ) {
            return sprintf( 'Sent to Mailchimp as pending, and Mailchimp emails %s a link to confirm the subscription.', $email ) . $note;
        }
        return ( in_array( 'updated', $done, true ) ? sprintf( 'Updated %s in Mailchimp; they were already subscribed.', $email ) : sprintf( '%s was already subscribed in Mailchimp, so nothing changed.', $email ) ) . $note;
    }

    /**
     * @param list<string> $errors
     * @param \MC4WP_Form  $form
     */
    private function check( mixed $errors, mixed $form = null ): mixed {
        if ( ! is_array( $errors ) || $errors || ! is_object( $form ) || ! method_exists( $form, 'get_data' ) ) {
            return $errors; // Already refused (honeypot, timestamp, validation).
        }
        $action = method_exists( $form, 'get_action' ) ? (string) $form->get_action() : 'subscribe';
        if ( 'subscribe' !== $action && version_compare( (string) MC4WP_VERSION, '4.11.2', '>=' ) ) {
            return $errors; // MC4WP refuses unsubscribe forms itself since 4.11.2.
        }
        // MC4WP uppercases every posted field: leave out Breakwater's own proof fields (BW_*).
        $data   = array_filter( (array) $form->get_data(), fn ( $key ): bool => ! str_starts_with( (string) $key, 'BW_' ), ARRAY_FILTER_USE_KEY );
        $fields = [];
        foreach ( $data as $key => $value ) {
            if ( ! in_array( $key, self::SKIP, true ) ) {
                $fields[] = [ 'type' => self::TYPES[ $key ] ?? 'text', 'label' => (string) $key, 'value' => $value ];
            }
        }
        $verdict = $this->evaluate( Submission::fromFields( $this->source(), $form->ID . ': ' . $form->name, $fields ) );
        if ( $verdict->act ) {
            $settings = (array) ( $form->settings ?? [] );
            // The form's data (groups, language and terms consent too) and the lists as MC4WP
            // resolved them for this request. An unsubscribe is never replayed.
            Log::stash( $verdict->log_id, [
                'form_id'           => (int) $form->ID,
                'action'            => $action,
                'data'              => $data,
                'lists'             => array_values( (array) $form->get_lists() ),
                'email_type'        => method_exists( $form, 'get_email_type' ) ? (string) $form->get_email_type() : '',
                'tags'              => method_exists( $form, 'get_subscriber_tags' ) ? (array) $form->get_subscriber_tags() : [],
                'update_existing'   => ! empty( $settings['update_existing'] ),
                'replace_interests' => ! empty( $settings['replace_interests'] ),
            ] );
            if ( is_array( $form->messages ?? null ) ) {
                $form->messages['spam'] = esc_html( $this->message( 'subscription' ) ); // This request only.
            }
            $errors[] = 'spam'; // MC4WP's spam error, so its spam hooks still fire.
        }
        return $errors;
    }
}
