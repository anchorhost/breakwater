<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

use Breakwater\Log;
use Breakwater\Submission;

/**
 * MailPoet subscription forms. Spam is refused before the subscriber exists, so no
 * confirmation email goes out. Released, the sign-up goes through MailPoet's own subscribe
 * step, but only while MailPoet asks new subscribers to confirm: the visitor was told their
 * sign-up failed, so they must get the confirmation email and choose to act on it.
 */
final class MailPoet extends Adapter {

    public function source(): string { return 'mailpoet'; }
    public function label(): string { return 'MailPoet'; }
    public function detected(): bool { return defined( 'MAILPOET_VERSION' ); }

    public function register(): void {
        add_action( 'mailpoet_subscription_before_subscribe', $this->check( ... ), 99, 3 );
    }

    /**
     * What the form would have done after its checks: MailPoet's subscribe (a new subscriber is
     * left unconfirmed and sent the confirmation email), the form's statistics and tags, and the
     * after-subscribe hook. Its captcha, honeypot and throttle aren't run again.
     */
    public function release( object $row ): ?string {
        $data  = Log::replay( $row );
        $email = (string) ( $data['data']['email'] ?? '' );
        $lists = array_values( array_filter( array_map( 'intval', (array) ( $data['segments'] ?? [] ) ) ) );
        if ( ! is_email( $email ) || ! $lists || ! class_exists( '\MailPoet\DI\ContainerWrapper' ) || ! class_exists( '\MailPoet\Subscribers\SubscriberActions' ) || ! class_exists( '\MailPoet\Settings\SettingsController' ) ) {
            return null;
        }
        if ( ! \MailPoet\Settings\SettingsController::getInstance()->get( 'signup_confirmation.enabled' ) ) {
            return null; // Without a confirmation step this would subscribe them silently.
        }
        $container = \MailPoet\DI\ContainerWrapper::getInstance();
        $form      = $container->get( \MailPoet\Form\FormsRepository::class )->findOneById( (int) ( $data['form_id'] ?? 0 ) );
        $segments  = $container->get( \MailPoet\Segments\SegmentsRepository::class )->findByIds( $lists );
        if ( ! $form || $form->getDeletedAt() || ! $segments ) {
            return null;
        }
        $lists    = array_map( fn ( $segment ) => (int) $segment->getId(), $segments );
        $existing = $container->get( \MailPoet\Subscribers\SubscribersRepository::class )->findOneBy( [ 'email' => $email ] );
        $already  = $existing && \MailPoet\Entities\SubscriberEntity::STATUS_SUBSCRIBED === $existing->getStatus();

        [ $subscriber, $meta ] = $container->get( \MailPoet\Subscribers\SubscriberActions::class )->subscribe( (array) $data['data'], $lists );
        $sent = $meta['confirmationEmailResult'] ?? false;
        if ( ! empty( $meta['error'] ) || $sent instanceof \Throwable || ( ! $already && true !== $sent ) ) {
            return null; // No confirmation went out: the owner gets the fields instead.
        }
        Log::link( (int) $row->id, (string) $subscriber->getId() );
        $container->get( \MailPoet\Statistics\StatisticsFormsRepository::class )->record( $form, $subscriber );
        self::tag( $subscriber, (array) ( $form->getSettings()['tags'] ?? [] ) );
        do_action( 'mailpoet_subscription_after_subscribe', $subscriber, (array) $data['data'], $lists, $form );

        $names = implode( ', ', array_map( fn ( $segment ) => (string) $segment->getName(), $segments ) );
        return $already
            ? sprintf( 'Added %s to %s; they were already a confirmed subscriber.', $email, $names )
            : sprintf( 'Added to %s as unconfirmed, and MailPoet sent a confirmation email to %s.', $names, $email );
    }

    public function recipients( string $form ): array {
        if ( ! class_exists( '\MailPoet\DI\ContainerWrapper' ) ) {
            return [];
        }
        $entity = \MailPoet\DI\ContainerWrapper::getInstance()->get( \MailPoet\Form\FormsRepository::class )->findOneById( self::formId( $form ) );
        $notify = $entity ? (array) ( \MailPoet\Settings\SettingsController::getInstance()->get( 'subscriber_email_notification' ) ?: [] ) : [];
        return ! empty( $notify['enabled'] ) ? self::emails( (string) ( $notify['address'] ?? '' ) ) : [];
    }

    /** The form's tags, as MailPoet adds them after a sign-up. */
    private static function tag( object $subscriber, array $names ): void {
        $names = array_values( array_filter( array_map( 'strval', $names ) ) );
        if ( ! $names || ! class_exists( '\MailPoet\API\API' ) ) {
            return;
        }
        try {
            $api = \MailPoet\API\API::MP( 'v1' );
            foreach ( $names as $name ) {
                $api->tagSubscriber( (string) $subscriber->getEmail(), $name );
            }
        } catch ( \Throwable $e ) {
            return; // Older MailPoet without tag support in its API, or the tag is gone.
        }
    }

    /**
     * @param array<string, mixed> $data       The form's own fields: email, first_name, last_name, cf_N.
     * @param \MailPoet\Entities\FormEntity $form
     */
    private function check( mixed $data, mixed $segment_ids = [], mixed $form = null ): void {
        if ( ! is_array( $data ) ) {
            return;
        }
        $types  = [ 'email' => 'email', 'first_name' => 'first_name', 'last_name' => 'last_name' ];
        $fields = [];
        foreach ( $data as $key => $value ) {
            if ( isset( $types[ $key ] ) || str_starts_with( (string) $key, 'cf_' ) ) {
                $fields[] = [ 'type' => $types[ $key ] ?? 'text', 'label' => (string) $key, 'value' => $value ];
            }
        }
        $id      = is_object( $form ) && method_exists( $form, 'getId' ) ? (int) $form->getId() : 0;
        $name    = $id ? $id . ': ' . $form->getName() : '';
        $verdict = $this->evaluate( Submission::fromFields( $this->source(), $name, $fields ) );
        if ( $verdict->act && class_exists( '\MailPoet\UnexpectedValueException' ) ) {
            // What MailPoet's subscribe step needs: the fields as the form filtered them (with the
            // consent record it stamped) and the lists it resolved.
            Log::stash( $verdict->log_id, [ 'form_id' => $id, 'data' => $data, 'segments' => array_values( (array) $segment_ids ) ] );
            // MailPoet's documented way to refuse: its API turns this into an error in the form.
            throw new \MailPoet\UnexpectedValueException( $this->message( 'subscription' ) );
        }
    }
}
