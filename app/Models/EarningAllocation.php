<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EarningAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One instructor's share of one recognized period, and the hold on it (D-2,
 * D-6, F05).
 *
 * The row is the hold (R2): the ledger already records that the money is owed,
 * and `released_at` alone decides when it becomes payable. That is why
 * `ledger:verify` recomputes `instructor_balances.held_minor` from these rows
 * rather than from ledger entries — there are none to recompute it from.
 *
 * @property int                  $id
 * @property int                  $accrual_period_id
 * @property int                  $instructor_id
 * @property int                  $weight_units
 * @property int                  $amount_minor
 * @property string               $currency
 * @property CarbonImmutable      $available_at
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $clawed_back_at
 * @property CarbonImmutable      $created_at
 */
final class EarningAllocation extends Model
{
    /** @use HasFactory<EarningAllocationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'accrual_period_id',
        'instructor_id',
        'weight_units',
        'amount_minor',
        'currency',
        'available_at',
        'released_at',
        'clawed_back_at',
    ];

    /**
     * @return BelongsTo<AccrualPeriod, $this>
     */
    public function accrualPeriod(): BelongsTo
    {
        return $this->belongsTo(AccrualPeriod::class);
    }

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    /**
     * Whether the money is still held: neither matured into `available` nor
     * reversed by a refund. The same condition the held recomputation sums.
     */
    public function isHeld(): bool
    {
        return $this->released_at === null && $this->clawed_back_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight_units' => 'integer',
            'amount_minor' => 'integer',
            'available_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'clawed_back_at' => 'immutable_datetime',
        ];
    }
}
