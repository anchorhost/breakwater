<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * The outcome of checking one submission.
 *
 * @phpstan-type Signals array<string, array{0:int, 1:string}> code => [points, detail]
 */
final class Verdict {

    /**
     * @param array<string, array{0:int, 1:string}> $signals
     * @param bool $act    Spam and the mode says to stop it.
     * @param int  $log_id Row in the Breakwater log, 0 when not logged.
     */
    public function __construct(
        public readonly bool $spam,
        public readonly int $score,
        public readonly array $signals,
        public readonly bool $act = false,
        public readonly int $log_id = 0,
    ) {}

    public static function clean(): self {
        return new self( false, 0, [] );
    }

    public function acted( bool $act, int $log_id ): self {
        return new self( $this->spam, $this->score, $this->signals, $act, $log_id );
    }

    /** The strongest few reasons, for spam notes and the log screen. */
    public function reason(): string {
        return self::describe( $this->signals );
    }

    /** @param array<string, array{0:int, 1:string}> $signals */
    public static function describe( array $signals ): string {
        uasort( $signals, fn ( array $a, array $b ): int => $b[0] <=> $a[0] );
        $parts = [];
        foreach ( $signals as $code => $signal ) {
            $parts[] = '' !== $signal[1] ? $signal[1] : (string) $code;
        }
        return implode( '; ', array_slice( $parts, 0, 4 ) );
    }
}
