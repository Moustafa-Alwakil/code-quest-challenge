<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutItemStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PayoutItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one instructor is owed by one run, and how far sending it has got
 * (F06-F08).
 *
 * `amount_minor` is the instructor's whole available balance at the moment of
 * reservation, not a sum of allocations. The ledger records exactly what was
 * reserved, so a clawback landing afterwards does not shrink this item — the
 * money was owed when it left `available`, and the clawback nets against the
 * next run instead (D-7).
 *
 * @property int                  $id
 * @property int                  $payout_run_id
 * @property int                  $instructor_id
 * @property int                  $amount_minor
 * @property string               $currency
 * @property PayoutItemStatus     $status
 * @property string               $idempotency_key
 * @property string|null          $provider_reference
 * @property int                  $attempts
 * @property CarbonImmutable|null $next_check_at
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $settled_at
 * @property string|null          $last_error
 * @property CarbonImmutable      $created_at
 * @property CarbonImmutable      $updated_at
 */
final class PayoutItem extends Model
{
    /** @use HasFactory<PayoutItemFactory> */
    use HasFactory;

    protected $fillable = [
        'payout_run_id',
        'instructor_id',
        'amount_minor',
        'currency',
        'status',
        'idempotency_key',
        'provider_reference',
        'attempts',
        'next_check_at',
        'submitted_at',
        'settled_at',
        'last_error',
    ];

    /**
     * @return BelongsTo<PayoutRun, $this>
     */
    public function payoutRun(): BelongsTo
    {
        return $this->belongsTo(PayoutRun::class);
    }

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    /**
     * Every interaction with the provider about this item, oldest first (F07).
     *
     * @return HasMany<PayoutAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PayoutAttempt::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => PayoutItemStatus::class,
            'attempts' => 'integer',
            'next_check_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }
}
