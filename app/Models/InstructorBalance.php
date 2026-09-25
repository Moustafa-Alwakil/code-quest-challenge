<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\InstructorBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The O(1) view of what an instructor has earned, holds, can be paid and has
 * been paid (F03).
 *
 * A cache of `ledger_entries`, not a second source of truth: `ledger:verify`
 * recomputes every column from the ledger — `currency` included (R21) — with
 * `last_ledger_entry_id` as the single exemption. Writes go through
 * InstructorBalanceService as atomic SQL increments — never a read, a change in
 * PHP and a save, which loses a concurrent posting.
 *
 * @property int             $instructor_id
 * @property string          $currency
 * @property int             $earned_minor
 * @property int             $clawed_back_minor
 * @property int             $held_minor
 * @property int             $available_minor
 * @property int             $reserved_minor
 * @property int             $paid_minor
 * @property int             $last_ledger_entry_id the last posting folded into this row, for debugging only:
 *                                                 nothing may branch on it (R19). It stores
 *                                                 `greatest(existing, last id of the posting)`, which is
 *                                                 monotonic but does *not* mean every entry at or below
 *                                                 that id is folded in — the id may belong to a platform
 *                                                 leg. "This balance is current as of entry X" is a
 *                                                 different guarantee, needing its own design and its own
 *                                                 check; escalate when F06 actually wants it
 * @property CarbonImmutable $updated_at
 */
final class InstructorBalance extends Model
{
    /** @use HasFactory<InstructorBalanceFactory> */
    use HasFactory;

    /** The row is created by the first posting; there is nothing to date-stamp. */
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'instructor_id';

    protected $fillable = [
        'instructor_id',
        'currency',
        'earned_minor',
        'clawed_back_minor',
        'held_minor',
        'available_minor',
        'reserved_minor',
        'paid_minor',
        'last_ledger_entry_id',
    ];

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    /**
     * Derived, never stored: what the platform still owes this instructor,
     * whatever state it is in. The identity `outstanding = available + held +
     * reserved` is checked for every instructor by `ledger:verify`.
     */
    public function outstandingMinor(): int
    {
        return $this->earned_minor - $this->clawed_back_minor - $this->paid_minor;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'instructor_id' => 'integer',
            'earned_minor' => 'integer',
            'clawed_back_minor' => 'integer',
            'held_minor' => 'integer',
            'available_minor' => 'integer',
            'reserved_minor' => 'integer',
            'paid_minor' => 'integer',
            'last_ledger_entry_id' => 'integer',
        ];
    }
}
