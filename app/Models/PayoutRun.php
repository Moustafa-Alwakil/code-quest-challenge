<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutRunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PayoutRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One batch of payouts, found by its key rather than created twice (F06).
 *
 * `item_count` and `total_minor` are a running tally of what the run reserved,
 * not a target it works towards: a crashed invocation that reserved half the
 * instructors leaves both at half, and the resume adds the rest.
 *
 * @property int                  $id
 * @property string               $run_key
 * @property CarbonImmutable      $scheduled_for
 * @property PayoutRunStatus      $status
 * @property int                  $item_count
 * @property int                  $total_minor
 * @property string               $currency
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable      $created_at
 * @property CarbonImmutable      $updated_at
 */
final class PayoutRun extends Model
{
    /** @use HasFactory<PayoutRunFactory> */
    use HasFactory;

    protected $fillable = [
        'run_key',
        'scheduled_for',
        'status',
        'item_count',
        'total_minor',
        'currency',
        'started_at',
        'finished_at',
    ];

    /**
     * @return HasMany<PayoutItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_date',
            'status' => PayoutRunStatus::class,
            'item_count' => 'integer',
            'total_minor' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
