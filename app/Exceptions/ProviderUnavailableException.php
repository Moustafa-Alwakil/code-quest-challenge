<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The request provably never reached the provider (F07).
 *
 * The distinction from `ProviderTimeoutException` is the whole point: a
 * connection refused before anything was sent is *safe to retry blindly* with
 * the same key, because there is nothing on the provider's side to duplicate.
 *
 * A worker rethrows this so the queue retries the job on its backoff ladder;
 * the item stays `submitted` and no money moves.
 */
final class ProviderUnavailableException extends RuntimeException
{
    public static function forKey(string $idempotencyKey, string $reason): self
    {
        return new self("The provider was unreachable for transfer {$idempotencyKey}: {$reason}. Nothing was sent.");
    }
}
