<?php

declare(strict_types=1);

namespace Breakwater;

/**
 * Outcome of checking the proof-of-work token a browser sent with a form.
 */
enum Proof: string {

    case Ok        = 'ok';
    case Missing   = 'missing';
    case Malformed = 'malformed';
    case Forged    = 'forged';
    case Wrong     = 'wrong';
    case Expired   = 'expired';
    case Replayed  = 'replayed';
}
