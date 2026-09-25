<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a posting cannot be trusted, so the caller's transaction must
 * roll back rather than leave the ledger half-written (F03).
 *
 * The messages carry the numbers involved because they are what the operator
 * sees: "expected 3 legs, inserted 2" is an actionable report; "integrity
 * error" is not.
 */
final class LedgerIntegrityException extends RuntimeException
{
    /**
     * A partial duplicate: some legs of this transaction were already on the
     * table and some were not, so neither "new posting" nor "replay" is true.
     */
    public static function partialInsert(int $expectedLegs, int $insertedLegs, string $entryType, string $referenceType, int $referenceId): self
    {
        return new self(sprintf(
            'Partial ledger posting for %s %s#%d: expected %d legs, inserted %d. The transaction is rolled back.',
            $entryType,
            $referenceType,
            $referenceId,
            $expectedLegs,
            $insertedLegs,
        ));
    }

    /**
     * Posting must share a transaction with the business state change it
     * records, or a crash between the two leaves the ledger disagreeing with
     * the rest of the database.
     */
    public static function outsideTransaction(string $entryType, string $referenceType, int $referenceId): self
    {
        return new self(sprintf(
            'Refusing to post %s for %s#%d outside a database transaction: a posting commits with the state change it records.',
            $entryType,
            $referenceType,
            $referenceId,
        ));
    }
}
