<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * What Breakwater does with a verdict. Auto resolves to Monitor while CleanTalk is active
 * (side-by-side comparison) and to Protect otherwise.
 */
enum Mode: string {

    case Auto    = 'auto';
    case Monitor = 'monitor';
    case Protect = 'protect';
    case Off     = 'off';

    public function label(): string {
        return match ( $this ) {
            self::Auto    => 'Automatic',
            self::Monitor => 'Monitor only',
            self::Protect => 'Protect',
            self::Off     => 'Off',
        };
    }

    public function help(): string {
        return match ( $this ) {
            self::Protect => 'Spam is stopped: sent to the spam folder where the form has one, refused otherwise.',
            self::Monitor => 'Every submission is checked and logged, nothing is stopped.',
            self::Off     => 'Nothing is checked.',
            self::Auto    => 'Monitor while CleanTalk is active, protect otherwise.',
        };
    }
}
