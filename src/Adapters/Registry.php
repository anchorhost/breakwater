<?php

declare(strict_types=1);

namespace Breakwater\Adapters;

/**
 * Every integration Breakwater knows about.
 */
final class Registry {

    /** @var list<class-string<Adapter>> */
    private const ADAPTERS = [
        Comments::class,
        Registration::class,
        GravityForms::class,
        Elementor::class,
        ContactForm7::class,
        WPForms::class,
        WooCommerce::class,
        Jetpack::class,
        Divi::class,
        Avada::class,
        NinjaForms::class,
        Mailchimp::class,
        Kadence::class,
        MailPoet::class,
        Formidable::class,
        Forminator::class,
        FluentForms::class,
        UltimateMember::class,
        ConstantContact::class,
        BeaverBuilder::class,
        Breakdance::class,
    ];

    /** @var array<string, Adapter>|null keyed by source */
    private static ?array $adapters = null;

    public static function register(): void {
        foreach ( self::all() as $adapter ) {
            $adapter->register();
        }
    }

    /** @return array<string, Adapter> */
    public static function all(): array {
        if ( null === self::$adapters ) {
            self::$adapters = [];
            foreach ( (array) apply_filters( 'breakwater_adapters', self::ADAPTERS ) as $class ) {
                if ( is_string( $class ) && is_subclass_of( $class, Adapter::class ) ) {
                    $adapter                                = new $class();
                    self::$adapters[ $adapter->source() ] = $adapter;
                }
            }
        }
        return self::$adapters;
    }

    public static function forSource( string $source ): ?Adapter {
        return self::all()[ $source ] ?? null;
    }

    /** @return array<string, Adapter> the ones whose plugin is on this site */
    public static function detected(): array {
        return array_filter( self::all(), fn ( Adapter $adapter ): bool => $adapter->detected() );
    }

    /**
     * Form selectors for every detected adapter on this site. Custom integrations can add
     * their own through breakwater_form_selectors.
     *
     * @return list<string>
     */
    public static function formSelectors(): array {
        $selectors = [];
        foreach ( self::detected() as $adapter ) {
            $selectors = array_merge( $selectors, $adapter->formSelectors() );
        }
        $selectors = apply_filters( 'breakwater_form_selectors', array_values( array_unique( $selectors ) ) );
        return is_array( $selectors ) ? array_values( array_filter( $selectors, fn ( mixed $s ): bool => is_string( $s ) && '' !== trim( $s ) ) ) : [];
    }
}
