<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A paid term (F04).
 *
 * `price_minor` is snapshotted at purchase, so a later plan price change cannot
 * reach money already taken. `status` is cosmetic (see SubscriptionStatus) — no
 * money path branches on it.
 *
 * Term dates are DATEs, cast immutable: every period boundary is
 * `term_start + k months` computed from this anchor, and an in-place mutation of
 * the attribute would drift the whole schedule by a day (R10, R22).
 *
 * @property int                  $id
 * @property int                  $user_id
 * @property int                  $plan_id
 * @property SubscriptionStatus   $status
 * @property CarbonImmutable      $term_start
 * @property CarbonImmutable      $term_end    exclusive
 * @property int                  $term_days
 * @property int                  $price_minor
 * @property string               $currency
 * @property CarbonImmutable|null $canceled_at set by a refund (F09)
 * @property CarbonImmutable      $created_at
 * @property CarbonImmutable      $updated_at
 */
final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'term_start',
        'term_end',
        'term_days',
        'price_minor',
        'currency',
        'canceled_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The single up-front payment that bought this term (UNIQUE subscription_id).
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * @return HasMany<AccrualPeriod, $this>
     */
    public function accrualPeriods(): HasMany
    {
        return $this->hasMany(AccrualPeriod::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'term_start' => 'immutable_date',
            'term_end' => 'immutable_date',
            'term_days' => 'integer',
            'price_minor' => 'integer',
            'canceled_at' => 'immutable_datetime',
        ];
    }
}
