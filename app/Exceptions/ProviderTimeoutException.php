<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The request may have been processed. We do not know (D-8, F07).
 *
 * The single most important exception in this system, because the wrong
 * reaction to it costs real money in either direction: treat it as a failure
 * and the instructor's balance comes back while the provider quietly pays them;
 * treat it as a success and a transfer that never happened is recorded as one.
 *
 * The only correct response is `unknown` — a state, not an error — and asking
 * the provider again later.
 */
final class ProviderTimeoutException extends RuntimeException
{
    public static function forKey(string $idempotencyKey): self
    {
        return new self("The provider did not answer in time for transfer {$idempotencyKey}; the outcome is unknown.");
    }
}
