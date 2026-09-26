<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccrualPeriodStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AccrualPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slice of a term's price, and when it is earned (D-1, F04).
 *
 * Half-open `[period_start, period_end)`, so consecutive periods share a date
 * and no day is earned twice. Σ `gross_minor` per subscription equals the
 * subscription's `price_minor` exactly — invariant I8, guaranteed by
 * `AccrualSchedule` before a row is written.
 *
 * `pool_minor` and `platform_minor` are null until F05 recognizes the period; a
 * zero would claim a split was computed and came to nothing.
 *
 * @property int                  $id
 * @property int                  $subscription_id
 * @property int                  $sequence
 * @property CarbonImmutable      $period_start
 * @property CarbonImmutable      $period_end      exclusive
 * @property int                  $days
 * @property int                  $gross_minor
 * @property int|null             $pool_minor
 * @property int|null             $platform_minor
 * @property AccrualPeriodStatus  $status
 * @property CarbonImmutable|null $recognized_at
 * @property CarbonImmutable      $created_at
 * @property CarbonImmutable      $updated_at
 */
final class AccrualPeriod extends Model
{
    /** @use HasFactory<AccrualPeriodFactory> */
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'sequence',
        'period_start',
        'period_end',
        'days',
        'gross_minor',
        'pool_minor',
        'platform_minor',
        'status',
        'recognized_at',
    ];

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'days' => 'integer',
            'gross_minor' => 'integer',
            'pool_minor' => 'integer',
            'platform_minor' => 'integer',
            'status' => AccrualPeriodStatus::class,
            'recognized_at' => 'immutable_datetime',
        ];
    }
}
