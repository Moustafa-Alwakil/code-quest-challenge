<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Exceptions\ImmutableLedgerException;
use Carbon\CarbonImmutable;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One leg of one posting — append-only (D-9).
 *
 * Reads only. Postings are written by LedgerService through a chunked
 * `insertOrIgnore` against the idempotency index, because an Eloquent `create()`
 * per leg would neither be idempotent nor atomic.
 *
 * The model events below are the application-level immutability guard.
 * Production hardening (documented in F03, not built) revokes UPDATE and DELETE
 * on this table from the application's database user, so the guarantee holds
 * even for code that never loads this class.
 *
 * @property int               $id
 * @property string            $transaction_uuid
 * @property LedgerAccountType $account_type
 * @property int               $account_id
 * @property int               $amount_minor
 * @property string            $currency
 * @property LedgerEntryType   $entry_type
 * @property string            $reference_type
 * @property int               $reference_id
 * @property CarbonImmutable   $created_at
 */
final class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    /** The table has no updated_at column: a row is written once. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'transaction_uuid',
        'account_type',
        'account_id',
        'amount_minor',
        'currency',
        'entry_type',
        'reference_type',
        'reference_id',
    ];

    /**
     * Rewriting history is a bug, not an option: corrections are new entries.
     */
    protected static function booted(): void
    {
        self::updating(function (self $entry): void {
            throw ImmutableLedgerException::onUpdate($entry->id);
        });

        self::deleting(function (self $entry): void {
            throw ImmutableLedgerException::onDelete($entry->id);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => LedgerAccountType::class,
            'entry_type' => LedgerEntryType::class,
            'account_id' => 'integer',
            'amount_minor' => 'integer',
            'reference_id' => 'integer',
        ];
    }
}
