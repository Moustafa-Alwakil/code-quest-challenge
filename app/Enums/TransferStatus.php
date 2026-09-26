<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the provider says about one transfer (F07, D-8).
 *
 * Four values for a provider that really has three outcomes, because the
 * fourth — `NOT_FOUND` — is the answer that makes retrying safe: it means the
 * provider has never seen this key, so sending it again cannot duplicate
 * anything. Collapsing it into `FAILED` would return money to `available` that
 * was never sent; collapsing it into `PENDING` would wait forever.
 */
enum TransferStatus: string
{
    case SUCCEEDED = 'succeeded';

    case FAILED = 'failed';

    /** Accepted, outcome not yet decided. Reconciliation asks again later. */
    case PENDING = 'pending';

    /** The provider has no record of this key at all. */
    case NOT_FOUND = 'not_found';

    /**
     * Whether this answer settles the matter. Only these two move money.
     */
    public function isDefinitive(): bool
    {
        return match ($this) {
            self::SUCCEEDED, self::FAILED => true,
            self::PENDING, self::NOT_FOUND => false,
        };
    }
}
