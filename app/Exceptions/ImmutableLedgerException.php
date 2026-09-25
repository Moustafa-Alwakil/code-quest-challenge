<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when something tries to rewrite history.
 *
 * `ledger_entries` is append-only (D-9): a correction is a new entry with its
 * own entry type, never an UPDATE or a DELETE. The model events that raise this
 * are the application-level guard; production hardening revokes UPDATE and
 * DELETE on the table from the application's database user as well.
 */
final class ImmutableLedgerException extends RuntimeException
{
    public static function onUpdate(int $id): self
    {
        return new self("Ledger entry #{$id} cannot be updated: the ledger is append-only. Post a correcting entry instead.");
    }

    public static function onDelete(int $id): self
    {
        return new self("Ledger entry #{$id} cannot be deleted: the ledger is append-only. Post a reversing entry instead.");
    }
}
