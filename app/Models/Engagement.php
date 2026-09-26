<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EngagementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consumption minutes one subscription spent with one instructor during one
 * accrual period — the weight recognition divides the pool by (D-2, F02).
 *
 * Read at recognition time and never afterwards: a row written for a period
 * that has already been recognized is ignored, which is the late-data policy
 * F02 documents rather than a bug.
 *
 * @property int             $id
 * @property int             $subscription_id
 * @property CarbonImmutable $period_start
 * @property int             $instructor_id
 * @property int             $units
 * @property CarbonImmutable $created_at
 */
final class Engagement extends Model
{
    /** @use HasFactory<EngagementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'subscription_period_engagement';

    protected $fillable = [
        'subscription_id',
        'period_start',
        'instructor_id',
        'units',
    ];

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Instructor, $this>
     */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(Instructor::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'units' => 'integer',
        ];
    }
}
